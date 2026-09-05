<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Profesional;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quien presta los servicios.
 *
 * A diferencia del antiguo `EmpleadoService`, esto vive **entero en la base del
 * negocio**: sin cuenta no hay nada que escribir en la central y por tanto nada
 * que compensar. Solo cuando se pide «darle acceso al panel» entra en juego
 * `UsuarioService`, que es quien sabe cruzar las dos bases.
 */
class ProfesionalService
{
    private const CARPETA = 'empleados';

    public function __construct(
        private ImagenService $imagenes,
        private UsuarioService $usuarios,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{foto?: ?UploadedFile}  $archivos
     */
    public function crear(array $datos, array $archivos): Profesional
    {
        $this->validarCupo((bool) ($datos['activo'] ?? true));

        /*
         * La cuenta ANTES que la ficha, por lo mismo que antes: el choque
         * probable es un email ya registrado y revienta en la central. Mejor
         * que reviente sin haber creado media ficha de profesional.
         */
        $usuario = $this->cuentaSiSePide($datos);

        $profesional = DB::transaction(fn () => $this->rellenar(
            new Profesional(['usuario_id' => $usuario?->id]),
            $datos,
            $archivos,
        ));

        return $this->cargar($profesional);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{foto?: ?UploadedFile}  $archivos
     */
    public function actualizar(Profesional $profesional, array $datos, array $archivos): Profesional
    {
        $this->validarCupo(
            (bool) ($datos['activo'] ?? $profesional->activo),
            $profesional->id,
        );

        /*
         * Darle acceso a alguien que no lo tenía es el caso normal: un barbero
         * lleva meses sin cuenta y un día la necesita. Si ya la tiene, este
         * endpoint no la toca — eso se edita en `/usuarios`, que es su sitio.
         */
        if ($profesional->usuario_id === null) {
            $usuario = $this->cuentaSiSePide($datos);

            if ($usuario !== null) {
                $profesional->usuario_id = $usuario->id;
            }
        }

        $profesional = DB::transaction(fn () => $this->rellenar($profesional, $datos, $archivos));

        return $this->cargar($profesional);
    }

    /**
     * Baja de la FICHA, no de la persona.
     *
     * Soft delete porque las citas apuntan a `profesionales.id` y borrarlo de
     * verdad reescribiría el historial. Y su cuenta del panel **sobrevive**: si
     * además hay que quitarle el acceso, eso se hace en `/usuarios`. Son dos
     * decisiones distintas y mezclarlas haría que dejar de atender significara
     * quedarse fuera del sistema.
     */
    public function eliminar(Profesional $profesional): void
    {
        DB::transaction(fn () => $profesional->delete());
    }

    public function cargar(Profesional $profesional): Profesional
    {
        $profesional->loadMissing(['usuario.rol']);

        if ($profesional->usuario !== null) {
            $this->usuarios->cargar($profesional->usuario);
        }

        return $profesional;
    }

    /**
     * La tarjeta «Profesionales activos en tu plan».
     *
     * @return array{profesionales_activos: int, limite_profesionales: int}
     */
    public function resumen(): array
    {
        return [
            'profesionales_activos' => $this->activos(),
            'limite_profesionales' => $this->limite(),
        ];
    }

    /**
     * El cupo del plan: **una fila activa aquí es una plaza**.
     *
     * Desde que profesionales y usuarios son cosas distintas, esto no necesita
     * mirar roles ni flags: quien está en esta tabla presta servicios, y punto.
     * `atiende` no influye — ese booleano solo decide si sale en la tienda
     * pública, y un barbero que solo recibe reservas por teléfono cuesta lo
     * mismo que uno que las recibe por la web.
     *
     * Se comprueba al crear y al REACTIVAR: si solo mirase el alta, bastaría
     * con dar de baja a alguien, crear a otro y reactivar al primero.
     */
    private function validarCupo(bool $activo, ?int $excluyendo = null): void
    {
        if (! $activo) {
            return;
        }

        if ($this->activos($excluyendo) >= $this->limite()) {
            throw ValidationException::withMessages([
                'activo' => 'Alcanzaste el límite de profesionales de tu plan. Amplía tu plan para agregar a alguien más.',
            ]);
        }
    }

    private function activos(?int $excluyendo = null): int
    {
        return Profesional::where('activo', true)
            ->when($excluyendo !== null, fn ($q) => $q->whereKeyNot($excluyendo))
            ->count();
    }

    private function limite(): int
    {
        $tenant = $this->tenant();

        // 999 es el centinela de "ilimitado" del contrato (§1.5): es un int de
        // verdad, no un NULL mágico, así que la resta y la comparación salen
        // solas y `limite_profesionales` siempre es un número.
        return (int) ($tenant->plan?->max_profesionales ?? 0) + (int) $tenant->extra_profesionales;
    }

    /**
     * Crea la cuenta si el payload trae `usuario`, y manda la invitación.
     *
     * @param  array<string, mixed>  $datos
     */
    private function cuentaSiSePide(array $datos): ?Usuario
    {
        $cuenta = $datos['usuario'] ?? null;

        if (! is_array($cuenta) || ($cuenta['email'] ?? null) === null) {
            return null;
        }

        return $this->usuarios->crear([
            'nombre' => $datos['nombre'],
            'email' => $cuenta['email'],
            'telefono' => $datos['telefono'] ?? null,
            'rol_id' => $cuenta['rol_id'],
        ], 'usuario.rol_id');
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{foto?: ?UploadedFile}  $archivos
     */
    private function rellenar(Profesional $profesional, array $datos, array $archivos): Profesional
    {
        $conSueldo = in_array($datos['tipo_pago'], Profesional::TIPOS_CON_SUELDO, true);

        $profesional->fill([
            'nombre' => $datos['nombre'],
            'cargo' => $datos['cargo'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'tipo_pago' => $datos['tipo_pago'],
            'comision_pct' => $datos['comision_porcentaje'] ?? 0,

            // Si el tipo no lleva sueldo, los campos se LIMPIAN en vez de
            // quedarse de fantasma (mismo criterio que `max_sesiones`).
            'sueldo_monto' => $conSueldo ? ($datos['monto_sueldo'] ?? null) : null,
            'sueldo_periodo' => $conSueldo ? ($datos['periodo_pago'] ?? null) : null,

            'horario' => $this->armarHorario($datos),
        ]);

        foreach (['activo', 'atiende'] as $flag) {
            if (array_key_exists($flag, $datos) && $datos[$flag] !== null) {
                $profesional->{$flag} = (bool) $datos[$flag];
            }
        }

        $this->guardarFoto($profesional, $datos, $archivos);

        $profesional->save();

        return $profesional;
    }

    /**
     * Del par de arrays del contrato al JSON único de la columna.
     *
     * Se guarda con la MISMA forma que viaja por la API (`dia`, `desde`,
     * `hasta`, `disponible`). Traducir dos veces —al guardar y al leer— solo
     * añade dos sitios donde equivocarse.
     *
     * @param  array<string, mixed>  $datos
     * @return array{dias: array<int, mixed>, excepciones: array<int, mixed>}
     */
    private function armarHorario(array $datos): array
    {
        $dias = [];

        foreach ((array) ($datos['horario'] ?? []) as $dia) {
            $activo = filter_var($dia['activo'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $dias[] = [
                'dia' => (int) $dia['dia'],
                'activo' => $activo,
                // Un día apagado no guarda horas: si mañana se enciende, que
                // las ponga quien lo encienda.
                'desde' => $activo ? ($dia['desde'] ?? null) : null,
                'hasta' => $activo ? ($dia['hasta'] ?? null) : null,
                'breaks' => $activo ? array_map(fn (array $b) => [
                    'desde' => $b['desde'],
                    'hasta' => $b['hasta'],
                ], (array) ($dia['breaks'] ?? [])) : [],
            ];
        }

        usort($dias, fn (array $a, array $b) => $a['dia'] <=> $b['dia']);

        $excepciones = [];

        foreach ((array) ($datos['excepciones'] ?? []) as $excepcion) {
            $disponible = filter_var($excepcion['disponible'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $excepciones[] = [
                'fecha' => $excepcion['fecha'],
                'disponible' => $disponible,
                'desde' => $disponible ? ($excepcion['desde'] ?? null) : null,
                'hasta' => $disponible ? ($excepcion['hasta'] ?? null) : null,
                'nota' => $excepcion['nota'] ?? null,
            ];
        }

        return ['dias' => $dias, 'excepciones' => $excepciones];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{foto?: ?UploadedFile}  $archivos
     */
    private function guardarFoto(Profesional $profesional, array $datos, array $archivos): void
    {
        $nueva = $archivos['foto'] ?? null;

        if ($nueva !== null) {
            $this->imagenes->borrar($profesional->foto);
            $profesional->foto = $this->imagenes->guardar($nueva, self::CARPETA);

            return;
        }

        // Bandera propia: no mandar el archivo ya significa "déjala como está".
        if (filter_var($datos['foto_eliminar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->imagenes->borrar($profesional->foto);
            $profesional->foto = null;
        }
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return $tenant->loadMissing('plan');
    }
}
