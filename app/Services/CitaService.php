<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cita;
use App\Models\Cliente;
use App\Models\InventarioMovimiento;
use App\Models\Local;
use App\Models\Producto;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El corazón del producto, y el sitio donde vive la regla no negociable 1.
 *
 * Dos personas pueden pulsar «Agendar» a la vez sobre el mismo hueco. Lo único
 * que lo impide es que la comprobación y la escritura pasen dentro de la misma
 * transacción con las filas bloqueadas: si se comprueba fuera, las dos leen la
 * agenda libre, las dos escriben, y el profesional descubre el solape cuando
 * llegan los dos clientes.
 */
class CitaService
{
    public function __construct(
        private OnboardingService $onboarding,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(Tenant $negocio, array $datos, int $usuarioId): Cita
    {
        return DB::transaction(function () use ($negocio, $datos, $usuarioId) {
            $servicio = Servicio::findOrFail($datos['servicio_id']);
            $profesional = Profesional::findOrFail($datos['empleado_id']);

            $this->exigirHuecoLibre($negocio, $profesional, $datos, $servicio->duracion_min);

            $cita = Cita::create([
                'codigo' => $this->codigoLibre(),
                'local_id' => $datos['local_id'] ?? $this->localPorDefecto(),
                'profesional_id' => $profesional->id,
                'cliente_id' => $this->resolverCliente($datos)->id,
                'starts_at' => $this->inicio($datos),
                'ends_at' => $this->inicio($datos)->addMinutes($servicio->duracion_min),
                'estado' => $datos['estado'] ?? 'pendiente',
                'notas' => $datos['notas'] ?? null,
                // Del panel. La tienda pública pondrá `publica` en el Sprint 5.
                'fuente' => 'admin',
            ]);

            $this->sellarEstado($cita, null);
            $this->guardarLineas($cita, $servicio, $datos);
            $this->sincronizarStock($cita, null, $usuarioId);

            /*
             * Hook de onboarding: la primera cita marca el paso. Va aquí y no
             * en el controlador porque la reserva pública del Sprint 5 entra
             * por este mismo service y tiene que marcarlo igual.
             */
            $this->onboarding->marcar($negocio, 'reserva_prueba');

            return $this->cargar($cita);
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Tenant $negocio, Cita $cita, array $datos, int $usuarioId): Cita
    {
        return DB::transaction(function () use ($negocio, $cita, $datos, $usuarioId) {
            $servicio = Servicio::findOrFail($datos['servicio_id']);
            $profesional = Profesional::findOrFail($datos['empleado_id']);
            $estadoAnterior = $cita->estado;

            // Excluyéndose a sí misma: su propio hueco no puede estorbarle.
            $this->exigirHuecoLibre($negocio, $profesional, $datos, $servicio->duracion_min, $cita->id);

            $cita->fill([
                'local_id' => $datos['local_id'] ?? $cita->local_id,
                'profesional_id' => $profesional->id,
                'cliente_id' => $this->resolverCliente($datos, $cita)->id,
                'starts_at' => $this->inicio($datos),
                'ends_at' => $this->inicio($datos)->addMinutes($servicio->duracion_min),
                'estado' => $datos['estado'],
                'notas' => $datos['notas'] ?? null,
            ])->save();

            $this->sellarEstado($cita, $estadoAnterior);
            $this->guardarLineas($cita, $servicio, $datos);
            $this->sincronizarStock($cita, $estadoAnterior, $usuarioId);

            return $this->cargar($cita);
        });
    }

    public function eliminar(Cita $cita, int $usuarioId): void
    {
        DB::transaction(function () use ($cita, $usuarioId) {
            // Si estaba completada, lo vendido vuelve al almacén antes de que
            // la cita desaparezca: si no, el descuento se queda sin nada que lo
            // explique.
            $this->devolverAlStock($cita, $usuarioId);

            // Borrado real: `citas` no lleva soft delete y las líneas cuelgan
            // en cascada. Cancelar es un ESTADO, y es lo que el panel usa para
            // conservar el historial; borrar es para lo que nunca debió existir.
            $cita->delete();
        });
    }

    /**
     * La comprobación que hace que agendar fuera de hueco no sea alcanzable.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    private function exigirHuecoLibre(Tenant $negocio, Profesional $profesional, array $datos, int $duracion, ?int $excepto = null): void
    {
        if ($duracion < 1) {
            throw ValidationException::withMessages([
                'servicio_id' => 'Ese servicio no tiene duración, así que no se puede agendar.',
            ]);
        }

        /*
         * `SELECT … FOR UPDATE` sobre las citas del profesional ese día, ANTES
         * de calcular nada.
         *
         * MySQL no tiene EXCLUDE, así que el candado es lo único que hay. Y no
         * basta con bloquear las filas existentes: el índice
         * `(profesional_id, starts_at)` hace que este rango tome además los
         * huecos entre ellas, que es justo donde la otra petición querría
         * insertar. Sin esto, dos reservas simultáneas leen la agenda libre,
         * las dos validan y las dos escriben.
         */
        Cita::where('profesional_id', $profesional->id)
            ->whereDate('starts_at', $datos['fecha'])
            ->lockForUpdate()
            ->get(['id']);

        $huecos = (new Disponibilidad($negocio))->huecos(
            $profesional,
            $datos['fecha'],
            $duracion,
            $excepto,
        );

        if (in_array($datos['hora_inicio'], $huecos, true)) {
            return;
        }

        /*
         * La clave es `hora_inicio` porque es donde la ficha pinta el error,
         * bajo el selector de huecos. El mensaje distingue los dos motivos: no
         * es lo mismo «esa hora ya está tomada» que «ese día no trabaja», y el
         * segundo no se arregla eligiendo otra hora.
         */
        throw ValidationException::withMessages([
            'hora_inicio' => $huecos === []
                ? 'Ese profesional no tiene horas libres ese día.'
                : 'Esa hora ya no está disponible. Libres: '.implode(', ', array_slice($huecos, 0, 5)).'…',
        ]);
    }

    /**
     * El cliente SIEMPRE queda vinculado: `citas.cliente_id` es NOT NULL (§2.2).
     *
     * @param  array<string, mixed>  $datos
     */
    private function resolverCliente(array $datos, ?Cita $cita = null): Cliente
    {
        if (($datos['cliente_id'] ?? null) !== null) {
            return Cliente::findOrFail($datos['cliente_id']);
        }

        $telefono = $datos['cliente_telefono'] ?? null;
        $normalizado = Cliente::normalizarTelefono($telefono);

        $atributos = [
            'nombre' => $datos['cliente_nombre'] ?? ($cita?->cliente?->nombre ?? 'Cliente'),
            'telefono' => $telefono,
            'telefono_normalizado' => $normalizado,
            'email' => $datos['cliente_email'] ?? null,
        ];

        /*
         * Con teléfono, el cliente se BUSCA antes de crearse: es el mismo
         * criterio que la reserva pública, y el teléfono es lo único que
         * identifica a quien no tiene cuenta. Se mira también entre los
         * borrados porque el UNIQUE no los distingue — insertar encima de uno
         * daría un 500 — y porque el que vuelve es el mismo, con su historial.
         */
        if ($normalizado !== null) {
            $existente = Cliente::withTrashed()->where('telefono_normalizado', $normalizado)->first();

            if ($existente !== null) {
                $existente->restore();

                return $existente;
            }
        }

        /*
         * Sin teléfono no hay con qué reconocerlo, así que se crea uno nuevo
         * aunque se llame igual que otro. Es deliberado: fusionar por nombre
         * juntaría las fichas de dos «María» distintas, y separar dos
         * historiales mezclados es mucho peor que tener dos fichas repetidas.
         */
        return Cliente::create($atributos);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function guardarLineas(Cita $cita, Servicio $servicio, array $datos): void
    {
        /*
         * Precio y duración se CONGELAN aquí. Subir la tarifa mañana no puede
         * cambiar lo que costó una cita de ayer, y la duración congelada es lo
         * que hace que `ends_at` siga cuadrando si alguien acorta el servicio.
         *
         * El `monto` que llega del panel reescribe el precio de la línea
         * (§2.6): así el campo es editable para quien lo usa sin que
         * `monto_total` deje de ser la suma.
         */
        $cita->servicios()->sync([
            $servicio->id => [
                'cantidad' => 1,
                'precio' => $datos['monto'] ?? $servicio->precio,
                'duracion_min' => $servicio->duracion_min,
            ],
        ]);

        if (array_key_exists('productos', $datos)) {
            $lineas = [];

            foreach ((array) $datos['productos'] as $linea) {
                $producto = Producto::find($linea['id']);

                if ($producto === null) {
                    continue;
                }

                $lineas[$producto->id] = [
                    'cantidad' => (int) $linea['cantidad'],
                    // También congelado, y desde `precio_venta`: es lo que se
                    // cobró, no lo que cueste el día que se mire el informe.
                    'precio' => $producto->precio,
                ];
            }

            $cita->productos()->sync($lineas);
        }

        $this->recalcularTotal($cita);
    }

    private function recalcularTotal(Cita $cita): void
    {
        $cita->load(['servicios', 'productos']);

        $total = $cita->servicios->sum(fn ($s) => (float) $s->pivot->precio * (int) $s->pivot->cantidad)
            + $cita->productos->sum(fn ($p) => (float) $p->pivot->precio * (int) $p->pivot->cantidad);

        $cita->forceFill(['monto_total' => $total])->save();
    }

    /** Las marcas de tiempo de cada estado, que el contrato usa para el historial. */
    private function sellarEstado(Cita $cita, ?string $anterior): void
    {
        if ($cita->estado === $anterior) {
            return;
        }

        $columna = match ($cita->estado) {
            'confirmada' => 'confirmada_el',
            'completada' => 'completada_el',
            'cancelada' => 'cancelada_el',
            default => null,
        };

        if ($columna !== null && $cita->{$columna} === null) {
            $cita->forceFill([$columna => now()])->save();
        }
    }

    /**
     * El stock se mueve cuando la cita se COMPLETA, no cuando se agenda.
     *
     * Agendar no saca nada del almacén; reservar tres champús para el jueves no
     * los quita del estante hoy, y si la cita se cancela habría que devolverlos.
     */
    private function sincronizarStock(Cita $cita, ?string $anterior, int $usuarioId): void
    {
        if ($cita->estado === 'completada' && $anterior !== 'completada') {
            $this->descontarDelStock($cita, $usuarioId);

            return;
        }

        if ($anterior === 'completada' && $cita->estado !== 'completada') {
            $this->devolverAlStock($cita, $usuarioId);
        }
    }

    private function descontarDelStock(Cita $cita, int $usuarioId): void
    {
        $cita->load('productos');

        foreach ($cita->productos as $producto) {
            $cantidad = (int) $producto->pivot->cantidad;

            InventarioMovimiento::create([
                'producto_id' => $producto->id,
                'tipo' => 'venta',
                'cantidad' => $cantidad,
                'motivo' => "Cita {$cita->codigo}",
                'cita_id' => $cita->id,
                'registrado_por_user_id' => $usuarioId,
            ]);

            /*
             * Sin el tope que sí tiene el endpoint manual, y a propósito.
             *
             * Una salida a mano es alguien tecleando un número: si se pasa, es
             * un error que conviene atajar. Una venta ya OCURRIÓ — el producto
             * salió del estante— y negarse a registrarla dejaría la cita sin
             * poder cerrarse por un dato de inventario que ya estaba mal. El
             * saldo negativo es entonces el síntoma, no la causa.
             */
            Producto::whereKey($producto->id)->decrement('stock', $cantidad);
        }
    }

    /**
     * Devuelve al almacén lo que esta cita tenga descontado AHORA MISMO.
     *
     * Se calcula desde el propio libro —ventas menos devoluciones de esta
     * cita— en vez de marcar las filas ya saldadas. Es la diferencia entre
     * anotar un asiento nuevo y corregir uno viejo: el historial de un producto
     * tiene que poder enseñar que se vendió y que volvió, no un hueco donde
     * antes había una fila.
     *
     * Y hace idempotente el ir y venir: completar, cancelar y volver a
     * completar descuenta una sola vez cada vez, sin devolver dos veces lo
     * mismo.
     */
    private function devolverAlStock(Cita $cita, int $usuarioId): void
    {
        $pendiente = [];

        foreach (InventarioMovimiento::where('cita_id', $cita->id)->get() as $movimiento) {
            $signo = $movimiento->tipo === 'venta' ? 1 : -1;
            $pendiente[$movimiento->producto_id] = ($pendiente[$movimiento->producto_id] ?? 0)
                + $signo * $movimiento->cantidad;
        }

        foreach ($pendiente as $productoId => $cantidad) {
            if ($cantidad < 1) {
                continue;
            }

            InventarioMovimiento::create([
                'producto_id' => $productoId,
                'tipo' => 'entrada',
                'cantidad' => $cantidad,
                'motivo' => "Devolución de la cita {$cita->codigo}",
                'cita_id' => $cita->id,
                'registrado_por_user_id' => $usuarioId,
            ]);

            Producto::whereKey($productoId)->increment('stock', $cantidad);
        }
    }

    /** @param array<string, mixed> $datos */
    private function inicio(array $datos): Carbon
    {
        // Hora de pared, sin conversión: el DATETIME se guarda en la zona del
        // negocio y el Resource lo parte tal cual (§2.1).
        return Carbon::createFromFormat(
            'Y-m-d H:i',
            "{$datos['fecha']} {$datos['hora_inicio']}",
        );
    }

    private function localPorDefecto(): ?int
    {
        // Con una sola sede no se le pide al usuario que la elija (§2.12).
        return Local::where('es_principal', true)->value('id');
    }

    /**
     * Sin `O`, `0`, `I`, `1` ni `L`: el cliente lee este código en un WhatsApp
     * y a veces lo dicta por teléfono. Ocho caracteres de este alfabeto dan de
     * sobra, y confundir un cero con una o cuesta una llamada.
     */
    private const ALFABETO_CODIGO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private function codigoLibre(): string
    {
        do {
            $codigo = '';

            for ($i = 0; $i < 8; $i++) {
                $codigo .= self::ALFABETO_CODIGO[random_int(0, strlen(self::ALFABETO_CODIGO) - 1)];
            }
        } while (Cita::where('codigo', $codigo)->exists());

        return $codigo;
    }

    public function cargar(Cita $cita): Cita
    {
        return $cita->load(['cliente', 'profesional', 'servicios', 'productos']);
    }
}
