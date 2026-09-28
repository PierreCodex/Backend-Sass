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
use App\Support\Capacidades;
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
            // UNA resolución por llamada al service, compartida por los dos
            // ejes del alcance: a nombre de quién y en qué sede. (La que ya
            // resolvió el middleware `puede:` es aparte y no llega aquí.)
            $capacidades = Capacidades::deUsuarioCentral($usuarioId);

            $this->exigirProfesionalEnAlcance($capacidades, $datos);
            $localId = $this->sedeEnAlcance($capacidades, $datos);

            $servicio = Servicio::findOrFail($datos['servicio_id']);
            $profesional = Profesional::findOrFail($datos['empleado_id']);

            $this->exigirHuecoLibre($negocio, $profesional, $datos, $servicio->duracion_min);

            $cita = Cita::create([
                'codigo' => $this->codigoLibre(),
                'local_id' => $localId,
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
            // También al actualizar: sin esto, reasignar la cita a un compañero
            // era la puerta de atrás — y además la hacía desaparecer de la
            // vista de quien la creó. Y lo mismo con la sede: moverla a una
            // ajena la sacaba del alcance de quien la edita.
            $capacidades = Capacidades::deUsuarioCentral($usuarioId);

            $this->exigirProfesionalEnAlcance($capacidades, $datos);
            $localId = $this->sedeEnAlcance($capacidades, $datos, $cita);

            $servicio = Servicio::findOrFail($datos['servicio_id']);
            $profesional = Profesional::findOrFail($datos['empleado_id']);
            $estadoAnterior = $cita->estado;

            // Excluyéndose a sí misma: su propio hueco no puede estorbarle.
            $this->exigirHuecoLibre($negocio, $profesional, $datos, $servicio->duracion_min, $cita->id);

            $cita->fill([
                'local_id' => $localId,
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
     * Con `solo_propios`, el DESTINO de la cita es su propia ficha o no se guarda.
     *
     * Vive en el service y no en el controlador ni en el Form Request
     * (NFR-12): un candado en un endpoint protege ese endpoint, el mismo
     * candado aquí protege los dos caminos que escriben `profesional_id`
     * —`crear()` y `actualizar()`— sin dos copias de la regla. Hasta ahora
     * `solo_propios` solo filtraba al LEER, así que un barbero no veía las
     * citas de sus compañeros pero sí podía crearlas, y al reasignar la suya
     * la hacía desaparecer de su propia vista.
     *
     * **Cubre UN solo eje: a nombre de quién queda la cita.** Lo que NO cubre,
     * y conviene saberlo antes de apoyarse en ello:
     *
     * - **De quién ES la cita** que se edita o se borra. Eso sigue en
     *   `CitaController::exigirVisibilidad()`, con su 404; `eliminar()` no
     *   comprueba nada por su cuenta.
     * - **La sede**: es el otro eje, y lo cierra `sedeEnAlcance()` (G-2).
     *
     * Por eso la reserva pública de la Épica 6 NO puede entrar por `crear()`
     * dando esto por suficiente: tendrá que traer cerrado antes de quién es la
     * cita, y resolver sus propias `Capacidades` (sin cuenta, `locales()` es
     * `null` y el eje sede no restringe nada).
     *
     * Las `Capacidades` las resuelven `crear()`/`actualizar()` desde el
     * `$usuarioId` que este service ya recibe, UNA vez por escritura, y llegan
     * aquí por un parámetro de método PRIVADO. El llamador externo sigue sin
     * poder entregarlas —ni entregar `null`—: si pudiera, el candado volvería
     * a depender de quién llama, que es justo lo que este arreglo viene a
     * quitar.
     *
     * **Falla cerrado**: sin ficha de profesional no agenda para nadie.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    private function exigirProfesionalEnAlcance(Capacidades $capacidades, array $datos): void
    {
        if (! $capacidades->soloPropios()) {
            return;
        }

        $ficha = $capacidades->profesional();

        /*
         * Sin ficha, mensaje propio. «Solo puedes agendar citas para ti» no es
         * accionable aquí: para esta cuenta no existe ese «ti», y quien lo lea
         * se pondría a buscar el profesional correcto en vez de pedir su ficha.
         */
        if ($ficha === null) {
            throw ValidationException::withMessages([
                'empleado_id' => 'Tu cuenta no tiene ficha de profesional, así que no puede agendar citas.',
            ]);
        }

        /*
         * `?? null` y no `$datos['empleado_id']` a pelo: quien llame a este
         * service sin pasar por `CitaRequest` merece un rechazo, no un
         * «undefined array key». `(int) null` es 0 y no es la ficha de nadie.
         *
         * Y el `(int)` importa: la regla `integer` del Form Request ACEPTA la
         * cadena "3" y `validated()` la entrega tal cual, sin castear — sin
         * esto, el candado bloquearía al propio profesional.
         */
        if ($ficha->id === (int) ($datos['empleado_id'] ?? null)) {
            return;
        }

        /*
         * 422 en `empleado_id` y no 403: el campo es el que está fuera de
         * alcance, y es donde la ficha del panel pinta el error, bajo el
         * selector de profesional. El 404 se reserva para la cita ajena, que ya
         * lo devuelve `exigirVisibilidad()`.
         */
        throw ValidationException::withMessages([
            'empleado_id' => 'Solo puedes agendar citas para ti.',
        ]);
    }

    /**
     * La sede donde cae la cita, siempre dentro del alcance de quien escribe.
     *
     * El alcance por sedes solo existía al LEER (`CitaController::acotar()` y
     * `exigirVisibilidad()`): `POST` escribía el `local_id` que llegara sin
     * mirarlo, sin `local_id` asignaba la principal aunque quedara fuera, y
     * `PUT` movía una cita propia a una sede ajena — donde dejaba de verla
     * quien la había movido. Es el hueco G-2. Vive aquí, junto al eje
     * `solo_propios`, por la misma razón (NFR-12): una regla, un sitio, para
     * los dos caminos que escriben `local_id`.
     *
     * - `local_id` explícito → tiene que estar en el alcance, o 422.
     * - Sin `local_id` al ACTUALIZAR → conserva la suya; no se inventa otra.
     *   Si tiene sede, `exigirVisibilidad()` ya dio 404 cuando está fuera del
     *   alcance. Una cita SIN sede (`local_id` NULL) no la comprueba nadie:
     *   `exigirVisibilidad()` se salta ese caso. Es un hueco previo, diferido.
     * - Sin `local_id` al CREAR → la principal si está en el alcance; si no, la
     *   sede activa del alcance con el id más bajo (determinista); sin ninguna,
     *   422. El contrato ya delega en el backend la sede cuando no hay
     *   elección.
     *
     * Con `todos_los_locales` (`locales()` = `null`) nada cambia respecto a
     * antes. Lo que NO mira: que la sede esté activa ni que el profesional o
     * el servicio estén habilitados en ella (G-3, Story 1.3).
     *
     * 422 en `local_id` y no 403: es el campo que está fuera de alcance, igual
     * que `empleado_id` en el otro eje. La cita ajena sigue siendo 404.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    private function sedeEnAlcance(Capacidades $capacidades, array $datos, ?Cita $cita = null): ?int
    {
        $alcance = $capacidades->locales();
        $pedida = $datos['local_id'] ?? null;

        if ($pedida !== null) {
            // `(int)`: la regla `integer` deja pasar "3" como cadena, igual
            // que en `empleado_id`.
            $pedida = (int) $pedida;

            if ($alcance !== null && ! in_array($pedida, array_map('intval', $alcance), true)) {
                throw ValidationException::withMessages([
                    'local_id' => 'No puedes agendar citas en esa sede.',
                ]);
            }

            return $pedida;
        }

        if ($cita !== null) {
            return $cita->local_id;
        }

        if ($alcance === null) {
            return $this->localPorDefecto();
        }

        /*
         * Una consulta para las dos preferencias: la principal primero, y si no
         * está en el alcance (o no está activa), la de id más bajo. Un alcance
         * vacío no llega a preguntar: `whereIn` con lista vacía no encuentra
         * nada, que es justo la respuesta.
         */
        $localId = $alcance === [] ? null : Local::whereIn('id', $alcance)
            ->where('activo', true)
            ->orderByDesc('es_principal')
            ->orderBy('id')
            ->value('id');

        if ($localId === null) {
            throw ValidationException::withMessages([
                'local_id' => 'Tu cuenta no tiene ninguna sede activa a su alcance, así que no puede agendar citas.',
            ]);
        }

        return (int) $localId;
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
