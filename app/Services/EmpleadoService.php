<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Profesional;
use App\Models\Rol;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Altas, bajas y ediciones de personal, que cruzan las DOS bases (§2.3).
 *
 * **Sobre la atomicidad.** MySQL no hace transacciones entre bases, así que
 * aquí no hay "todo o nada" de verdad: hay dos transacciones y una
 * COMPENSACIÓN. La central se escribe primero y la del negocio después; si la
 * segunda falla, se deshace la primera a mano. Se hace en ese orden porque el
 * choque probable —un email ya registrado— revienta en la central, y es mejor
 * que reviente antes de haber tocado nada del negocio.
 *
 * Lo que queda sin cubrir, y conviene tenerlo escrito: si el proceso muere
 * ENTRE las dos escrituras, queda un `users` central sin su `profesionales`.
 * Esa persona no aparece en el panel pero su email ocupa sitio en el UNIQUE
 * global. Es raro y no corrompe datos de nadie más; el día que moleste, se
 * detecta con un comando que cruce ambas tablas.
 */
class EmpleadoService
{
    private const CARPETA = 'empleados';

    public function __construct(private ImagenService $imagenes) {}

    /**
     * Rellena `usuarioCentral` de una tanda con UNA sola consulta.
     *
     * Sin esto, el Resource pediría el usuario fila por fila: una página de 10
     * empleados serían 10 consultas a la central (el N+1 clásico, agravado por
     * ser contra otra base).
     *
     * @param  Collection<int, Profesional>  $profesionales
     * @return Collection<int, Profesional>
     */
    public function conUsuarios(Collection $profesionales): Collection
    {
        $usuarios = User::whereIn('id', $profesionales->pluck('central_user_id'))
            ->get()
            ->keyBy('id');

        return $profesionales->each(function (Profesional $p) use ($usuarios) {
            $p->usuarioCentral = $usuarios->get($p->central_user_id);
        });
    }

    public function cargar(Profesional $profesional): Profesional
    {
        $profesional->usuarioCentral = User::find($profesional->central_user_id);

        // `loadMissing` y no `load`: tras una edicion el rol puede venir ya
        // cargado y volver a pedirlo seria una consulta de mas.
        $profesional->loadMissing('rol');

        return $profesional;
    }

    /**
     * @param  array{foto?: ?UploadedFile}  $archivos
     */
    public function crear(array $datos, array $archivos): Profesional
    {
        $rol = $this->rolDelNegocio($datos['rol_id']);

        $this->validarCupo(
            (bool) ($datos['activo'] ?? true),
            (bool) ($datos['atiende'] ?? true),
        );

        $tenant = $this->tenant();

        // La central primero: si el email choca, no hemos tocado el negocio.
        $usuario = DB::connection($this->conexionCentral())->transaction(function () use ($datos, $tenant, $rol) {
            $usuario = new User([
                'tenant_id' => $tenant->id,
                'nombre' => $datos['nombre'],
                'email' => $datos['email'],
                'password' => $datos['password'],
                'rol' => $this->rolCentral($rol),
                'telefono' => $datos['telefono'] ?? null,
                'activo' => (bool) ($datos['activo'] ?? true),
            ]);

            /*
             * Verificado de entrada, y FUERA del `fill`: `email_verified_at` no
             * está en `$fillable` —el registro no debe poder mandarlo— así que
             * pasarlo por el constructor lo descarta EN SILENCIO y el empleado
             * nace sin verificar. Se descubre tarde: el alta responde 201 tan
             * campante y el 403 le salta a la persona al intentar entrar.
             *
             * Aquí sí toca marcarlo: no es alguien que llega solo, es el dueño
             * dando de alta a su empleado. Mandarle un correo para que confirme
             * que quiere entrar al panel de su jefe es pedirle permiso a quien
             * no lo tiene que dar.
             */
            $usuario->email_verified_at = now();

            $usuario->save();

            return $usuario;
        });

        try {
            $profesional = DB::transaction(function () use ($datos, $archivos, $usuario) {
                $profesional = new Profesional(['central_user_id' => $usuario->id]);

                return $this->rellenar($profesional, $datos, $archivos);
            });
        } catch (Throwable $e) {
            $this->compensarAlta($usuario);

            throw $e;
        }

        return $this->cargar($profesional);
    }

    /**
     * @param  array{foto?: ?UploadedFile}  $archivos
     */
    public function actualizar(Profesional $profesional, array $datos, array $archivos): Profesional
    {
        $usuario = User::findOrFail($profesional->central_user_id);

        $rol = $this->rolDelNegocio($datos['rol_id']);

        $this->validarCupo(
            (bool) ($datos['activo'] ?? $profesional->activo),
            (bool) ($datos['atiende'] ?? $profesional->atiende),
            $profesional->id,
        );

        // Copia para poder deshacer si la escritura del negocio falla.
        $antes = $usuario->getOriginal();

        DB::connection($this->conexionCentral())->transaction(function () use ($usuario, $datos, $rol) {
            $usuario->fill([
                'nombre' => $datos['nombre'],
                'email' => $datos['email'],
                'rol' => $this->rolCentral($rol),
                'telefono' => $datos['telefono'] ?? null,
                'activo' => (bool) ($datos['activo'] ?? $usuario->activo),
            ]);

            // Ausente o null = "no la cambies" (ficha § Contraseña).
            if (($datos['password'] ?? null) !== null) {
                $usuario->password = $datos['password'];
            }

            $usuario->save();
        });

        try {
            $profesional = DB::transaction(fn () => $this->rellenar($profesional, $datos, $archivos));
        } catch (Throwable $e) {
            $this->compensarEdicion($usuario, $antes);

            throw $e;
        }

        /*
         * Si le quitaron el acceso, sus tokens se van. Dejarlos vivos
         * significaría que un empleado desactivado sigue usando el panel hasta
         * que caduque su sesión, que es justo lo que el dueño creyó impedir.
         */
        if (! $profesional->activo) {
            $usuario->tokens()->delete();
        }

        return $this->cargar($profesional);
    }

    /**
     * Baja: soft delete en las dos bases.
     *
     * No se borra de verdad porque las citas apuntan a `profesionales.id` y
     * hacerlo reescribiría el historial. El usuario central también se va —con
     * sus tokens—, o seguiría pudiendo entrar al panel con su contraseña.
     */
    public function eliminar(Profesional $profesional): void
    {
        $usuario = User::find($profesional->central_user_id);

        DB::transaction(fn () => $profesional->delete());

        if ($usuario !== null) {
            DB::connection($this->conexionCentral())->transaction(function () use ($usuario) {
                $usuario->tokens()->delete();
                $usuario->delete();
            });
        }
    }

    /**
     * La tarjeta "Profesionales activos en tu plan".
     *
     * Se cuenta DENTRO de la base del negocio y no cruzando a la central: es
     * una consulta en vez de dos, y no hay JOIN posible entre bases. Cuenta a
     * quien está activo Y atiende — ver `validarCupo` para el porqué.
     *
     * @return array{profesionales_activos: int, limite_profesionales: int}
     */
    public function resumen(): array
    {
        return [
            'profesionales_activos' => $this->profesionalesActivos(),
            'limite_profesionales' => $this->limite(),
        ];
    }

    /**
     * @param  array{foto?: ?UploadedFile}  $archivos
     */
    private function rellenar(Profesional $profesional, array $datos, array $archivos): Profesional
    {
        $conSueldo = in_array($datos['tipo_pago'], Profesional::TIPOS_CON_SUELDO, true);

        $profesional->fill([
            'rol_id' => $datos['rol_id'],
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
     * `hasta`, `disponible`) y no con las claves del Laravel viejo
     * (`lunes`, `inicio`, `fin`, `activo`). Traducir dos veces —al guardar y al
     * leer— solo añade dos sitios donde equivocarse, y aquí no hay datos
     * antiguos que respetar: la tabla nace en este esquema.
     *
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

    /**
     * El cupo del plan: **cuenta quien está activo Y atiende**.
     *
     * El eje no es el rol, es la agenda. Un usuario que entra al panel no
     * cuesta nada; una persona que aparece en la agenda y en la tienda
     * pública, sí — el coste del producto escala con citas, no con logins. Por
     * eso una recepcionista no ocupa plaza y un barbero sí, aunque el negocio
     * les haya inventado los roles.
     *
     * Es además la única versión que no hay que vigilar: apagar `atiende` para
     * no pagar te quita justo aquello por lo que pagabas, porque a esa persona
     * deja de poder reservársele. Un límite que se rodea perdiendo lo que
     * querías robar se defiende solo.
     *
     * El dueño no es excepción: si atiende, ocupa su plaza. Un independiente
     * consume 1, que es la suya.
     *
     * Se comprueba al crear, al REACTIVAR y al ENCENDER `atiende`. Las tres,
     * porque si solo mirase el alta bastaría con dar de baja a alguien, crear
     * a otro y reactivar al primero; y si no mirase `atiende`, con dar de alta
     * a diez apagados y encenderlos después.
     */
    private function validarCupo(bool $activo, bool $atiende, ?int $excluyendo = null): void
    {
        if (! $activo || ! $atiende) {
            return;
        }

        if ($this->profesionalesActivos($excluyendo) >= $this->limite()) {
            throw ValidationException::withMessages([
                /*
                 * El error cae en `atiende` y no en el rol: es el campo que lo
                 * provoca y el que ofrece la salida. Quien llega al tope puede
                 * subir de plan o dar acceso al panel sin agenda, que es
                 * gratis.
                 */
                'atiende' => 'Alcanzaste el límite de profesionales de tu plan. Puedes darle acceso al panel sin agenda, o ampliar tu plan.',
            ]);
        }
    }

    private function profesionalesActivos(?int $excluyendo = null): int
    {
        return Profesional::where('activo', true)
            ->where('atiende', true)
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
     * El rol elegido, buscado en la base del negocio.
     *
     * `findOrFail` y no una consulta suelta: el Form Request ya comprobó que
     * existe, así que llegar aquí sin él significa que alguien lo borró entre
     * la validación y el guardado — un 500 honesto es mejor que seguir con un
     * null.
     */
    private function rolDelNegocio(int $id): Rol
    {
        return Rol::findOrFail($id);
    }

    /**
     * El `users.rol` central se DERIVA del rol del negocio; no lo elige nadie.
     *
     * En la central el rol solo sirve para una cosa: saber quién es el dueño,
     * que es quien maneja facturación. Los permisos del panel viven en la
     * tabla `roles` del negocio. Un rol propio («Recepcionista») no tiene
     * equivalente central, así que cae en `profesional`: es el suelo, no una
     * descripción de su puesto.
     */
    private function rolCentral(Rol $rol): string
    {
        return $rol->clave === 'admin' ? 'admin' : 'profesional';
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return $tenant->loadMissing('plan');
    }

    private function conexionCentral(): string
    {
        return (string) config('tenancy.database.central_connection');
    }

    /** El usuario recién creado se va del todo: no tiene historial que guardar. */
    private function compensarAlta(User $usuario): void
    {
        try {
            $usuario->forceDelete();
        } catch (Throwable $e) {
            // No se puede tapar el error original con este. Queda el rastro
            // para el comando que cruce ambas tablas.
            Log::error('No se pudo compensar el alta de empleado', [
                'central_user_id' => $usuario->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param  array<string, mixed>  $antes */
    private function compensarEdicion(User $usuario, array $antes): void
    {
        try {
            $usuario->forceFill($antes)->save();
        } catch (Throwable $e) {
            Log::error('No se pudo revertir la edicion central de un empleado', [
                'central_user_id' => $usuario->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
