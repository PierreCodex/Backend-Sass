<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Rol;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Usuario;
use App\Notifications\InvitacionNotification;
use App\Support\Capacidades;
use App\Support\Rango;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    /**
     * Nadie concede lo que no tiene (G-4): invitar es solo del administrador
     * general, y el rol y el alcance que recibe la cuenta caben en los de quien
     * la da. Se comprueba AQUÍ, antes de escribir nada, porque aquí llegan
     * todos los caminos —`/usuarios` y `/profesionales`—; la regla en sí vive
     * en `Rango`.
     *
     * @param  array<string, mixed>  $datos
     * @param  int  $actorCentralId  el `users.id` de quien da el alta; las
     *                               capacidades se resuelven aquí, no las trae el llamador
     * @param  string  $campo  dónde cae el 422 (`usuario.rol_id` desde profesionales)
     */
    public function crear(array $datos, int $actorCentralId, string $campo = 'rol_id'): Usuario
    {
        Rango::soloElAdminGeneral(User::find($actorCentralId));

        $actor = Capacidades::deUsuarioCentral($actorCentralId);

        $rol = Rol::findOrFail($datos['rol_id']);

        $this->prohibirAdminGeneral($rol, $campo);

        Rango::rolContenido($actor, $rol);
        // La cuenta nace con `todos_los_locales` (default de la columna):
        // asignar un alcance acotado llega con la Story 1.8.
        Rango::alcanceContenido($actor, null);

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

        /*
         * Si la invitacion falla, se deshace el alta entera.
         *
         * Dejar la cuenta creada parece inofensivo —existe el boton de
         * reenviar— pero el alta responde 500, el dueño cree que no se creo, lo
         * reintenta y se come un «correo ya registrado» sin entender por que:
         * el email es UNICO GLOBAL y ya esta ocupado por la fila que no ve.
         * Nos dejo seis cuentas huerfanas con el 500 de `invitacion_tokens`.
         *
         * Compensando, el fallo es atomico: el reintento funciona. El boton de
         * reenviar sigue cubriendo el caso comun, que es que el correo se envie
         * y no llegue.
         */
        try {
            $this->invitar($usuario, $central);
        } catch (Throwable $e) {
            $this->compensarAlta($central);
            DB::transaction(fn () => $usuario->delete());

            throw $e;
        }

        return $this->cargar($usuario);
    }

    /**
     * El alcance no se edita aquí (llega con la Story 1.8): solo se comprueba
     * que el rol asignado quepa en el de quien edita.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Usuario $usuario, array $datos, int $actorCentralId): Usuario
    {
        // Editar cuentas también es solo del general, por cualquier camino: el
        // candado no puede vivir solo en el controlador.
        Rango::soloElAdminGeneral(User::find($actorCentralId));

        $actor = Capacidades::deUsuarioCentral($actorCentralId);

        $central = User::findOrFail($usuario->central_user_id);
        $rol = Rol::findOrFail($datos['rol_id']);

        $this->protegerAlAdminGeneral($usuario, $rol);

        Rango::rolContenido($actor, $rol);

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

        // `protegerAlAdminGeneral` ya cargó el rol VIEJO; sin recargarlo, la
        // respuesta devolvía el rol de antes del cambio.
        $usuario->load('rol');

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

        $central->notify(new InvitacionNotification(
            $token,
            $this->tenant(),
            /*
             * Quién invita. Va en el correo porque es lo que lo distingue de
             * una estafa: recibir sin haberlo pedido un enlace para «crear una
             * contraseña» tiene exactamente la forma del phishing, y lo único
             * que lo desmiente es reconocer el nombre de quien te dio de alta.
             *
             * Sale de la sesión, no de un parámetro: invitar siempre ocurre
             * dentro de una petición autenticada. Si algún día lo hiciera un
             * comando, el correo se queda sin ese nombre y la plantilla lo
             * resuelve con un texto impersonal en vez de mentir.
             */
            auth()->user()?->nombre,
        ));
    }

    /**
     * El rol de administrador general no se reparte NUNCA por esta vía.
     *
     * Hay exactamente uno por negocio y lo crea el registro; ningún otro flujo
     * debe poder fabricar un segundo. Vive aquí y no en el controlador porque
     * es un invariante del service, no la regla de un endpoint — y eso importa:
     * el candado estaba solo en `UsuarioController` y
     * `ProfesionalService::cuentaSiSePide()` entraba por debajo, asi que un
     * administrador local podia darse de alta como profesional con el `rol_id`
     * del general y quedarse con facturacion y con la capacidad de repartir
     * roles. Dos peticiones. Y la cuenta resultante no se podia borrar, porque
     * `destroy` se niega sobre el general.
     *
     * Puesto aqui, el dia que aparezca un tercer camino —una importacion, un
     * comando— ya esta cubierto.
     */
    private function prohibirAdminGeneral(Rol $rol, string $campo): void
    {
        if ($rol->esAdminGeneral()) {
            throw ValidationException::withMessages([
                $campo => 'Ya hay un administrador general en este negocio.',
            ]);
        }
    }

    /**
     * Ni se le quita a quien lo tiene, ni se le da a quien no.
     *
     * Quitárselo lo deja sin acceso a su propia facturación; dárselo a otro
     * fabrica un segundo superusuario. Cambiar de titular es una operación de
     * soporte, no un select del formulario.
     */
    private function protegerAlAdminGeneral(Usuario $usuario, Rol $nuevo): void
    {
        $esGeneral = (bool) $usuario->rol?->esAdminGeneral();

        if ($esGeneral && ! $nuevo->esAdminGeneral()) {
            throw ValidationException::withMessages([
                'rol_id' => 'El administrador general no puede cambiar de rol.',
            ]);
        }

        if (! $esGeneral && $nuevo->esAdminGeneral()) {
            throw ValidationException::withMessages([
                'rol_id' => 'Ya hay un administrador general en este negocio.',
            ]);
        }
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
        return $rol->clave === 'admin_local' ? 'admin_local' : 'profesional';
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
