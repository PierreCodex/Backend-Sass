<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Rol;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Usuario;
use App\Notifications\InvitacionNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cuentas del panel: la mitad central (credencial) más la fila del tenant que
 * le cuelga el rol del negocio.
 *
 * Cruza las dos bases, así que no hay «todo o nada» de verdad: hay dos
 * transacciones y una COMPENSACIÓN. La central se escribe primero porque el
 * choque probable —un email ya registrado— revienta ahí, y es mejor que
 * reviente antes de haber tocado nada del negocio.
 *
 * Nadie escribe aquí una contraseña. La cuenta nace con una aleatoria que no
 * conoce ni quien da el alta, y la persona elige la suya desde la invitación.
 */
class UsuarioService
{
    /**
     * Rellena la cuenta central de una tanda con UNA sola consulta.
     *
     * Sin esto el Resource pediría el usuario fila por fila: una página de 10
     * serían 10 consultas contra la otra base.
     *
     * @param  Collection<int, Usuario>  $usuarios
     * @return Collection<int, Usuario>
     */
    public function conCentrales(Collection $usuarios): Collection
    {
        $centrales = User::whereIn('id', $usuarios->pluck('central_user_id'))
            ->get()
            ->keyBy('id');

        return $usuarios->each(function (Usuario $u) use ($centrales) {
            $u->central = $centrales->get($u->central_user_id);
        });
    }

    public function cargar(Usuario $usuario): Usuario
    {
        $usuario->central = User::find($usuario->central_user_id);
        $usuario->loadMissing(['rol', 'profesional']);

        return $usuario;
    }

    /** @param  array<string, mixed>  $datos */
    public function crear(array $datos): Usuario
    {
        $rol = Rol::findOrFail($datos['rol_id']);
        $tenant = $this->tenant();

        $central = DB::connection($this->conexionCentral())->transaction(function () use ($datos, $tenant, $rol) {
            $central = new User([
                'tenant_id' => $tenant->id,
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'] ?? null,
                'email' => $datos['email'],
                /*
                 * Aleatoria y larga: la cuenta tiene que existir para poder
                 * emitirle un token de invitación, pero nadie —tampoco quien la
                 * crea— debe poder entrar con ella. La persona pondrá la suya.
                 */
                'password' => Str::random(40),
                'rol' => $this->rolCentral($rol),
                'telefono' => $datos['telefono'] ?? null,
                'activo' => true,
            ]);

            /*
             * SIN `email_verified_at`: aquí no se da por verificado nada. Se
             * marcará al aceptar la invitación, que es cuando la persona
             * demuestra que ese correo es suyo abriendo el enlace.
             */
            $central->save();

            return $central;
        });

        try {
            $usuario = DB::transaction(fn () => Usuario::create([
                'central_user_id' => $central->id,
                'rol_id' => $rol->id,
            ]));
        } catch (Throwable $e) {
            $this->compensarAlta($central);

            throw $e;
        }

        $this->invitar($usuario, $central);

        return $this->cargar($usuario);
    }

    /** @param  array<string, mixed>  $datos */
    public function actualizar(Usuario $usuario, array $datos): Usuario
    {
        $central = User::findOrFail($usuario->central_user_id);
        $rol = Rol::findOrFail($datos['rol_id']);

        $antes = $central->getOriginal();

        DB::connection($this->conexionCentral())->transaction(function () use ($central, $datos, $rol) {
            $central->fill([
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'] ?? null,
                'email' => $datos['email'],
                'rol' => $this->rolCentral($rol),
                'telefono' => $datos['telefono'] ?? null,
                'activo' => (bool) ($datos['activo'] ?? $central->activo),
            ])->save();
        });

        try {
            DB::transaction(fn () => $usuario->update(['rol_id' => $rol->id]));
        } catch (Throwable $e) {
            $this->compensarEdicion($central, $antes);

            throw $e;
        }

        /*
         * Si le quitaron el acceso, sus tokens se van. Dejarlos vivos
         * significaría que sigue usando el panel hasta que caduque su sesión,
         * que es justo lo que se creyó impedir.
         */
        if (! $central->activo) {
            $central->tokens()->delete();
        }

        return $this->cargar($usuario);
    }

    /**
     * Quitar el acceso NO borra a la persona del negocio.
     *
     * Si además era profesional, su ficha sobrevive entera —con sus citas, su
     * horario y sus comisiones— y solo se queda sin cuenta: la foreign key es
     * `nullOnDelete`. Es la diferencia práctica más visible de haber separado
     * los dos conceptos.
     */
    public function eliminar(Usuario $usuario): void
    {
        $central = User::find($usuario->central_user_id);

        /*
         * La central primero, y a propósito: es lo que corta el acceso. Si
         * fallara el borrado del tenant quedaría una fila huérfana que no deja
         * entrar a nadie — molesta, pero inofensiva. Al revés, un usuario
         * central vivo sin su rol podría seguir entrando.
         */
        if ($central !== null) {
            $central->tokens()->delete();
            $central->delete();
        }

        DB::transaction(fn () => $usuario->delete());
    }

    /** (Re)envía la invitación para que elija su contraseña. */
    public function invitar(Usuario $usuario, ?User $central = null): void
    {
        $central ??= User::findOrFail($usuario->central_user_id);

        $token = Password::broker('invitaciones')->createToken($central);

        $central->notify(new InvitacionNotification($token, $this->tenant()->nombre));
    }

    /**
     * El `users.rol` central se DERIVA del rol del negocio; no lo elige nadie.
     *
     * En la central el rol sirve para una sola cosa: saber quién es el dueño,
     * que es quien maneja facturación. Los permisos del panel viven en `roles`,
     * en la base del negocio. Un rol propio («Recepcionista») no tiene
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

    /** La cuenta recién creada se va del todo: no tiene historial que guardar. */
    private function compensarAlta(User $central): void
    {
        try {
            $central->forceDelete();
        } catch (Throwable $e) {
            // No se puede tapar el error original con este. Queda el rastro
            // para el comando que cruce ambas tablas.
            Log::error('No se pudo compensar el alta de usuario', [
                'central_user_id' => $central->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param  array<string, mixed>  $antes */
    private function compensarEdicion(User $central, array $antes): void
    {
        try {
            $central->forceFill($antes)->save();
        } catch (Throwable $e) {
            Log::error('No se pudo revertir la edicion central de un usuario', [
                'central_user_id' => $central->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
