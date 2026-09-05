<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PerfilService
{
    /**
     * Guarda el perfil y PROPAGA a `profesionales`.
     *
     * `profesionales.nombre`, `foto` y `telefono` son copias denormalizadas de
     * `users` ("denormalizado p/ mostrar sin ir a central", dice la
     * migración). Sin propagar, el dueño se cambia el nombre y en Empleados y
     * en la tienda pública sigue el viejo.
     *
     * SOBRE LA ATOMICIDAD: son dos bases de datos distintas y MySQL no hace
     * transacciones entre ellas. Lo que sí se consigue es que un fallo al
     * propagar deshaga la escritura central — la propagación va DENTRO de la
     * transacción, así que si revienta, el rollback alcanza a `users` y no
     * queda un perfil central que la ficha del tenant contradice. El caso
     * inverso (tenant escrito y central caída después) no se puede cubrir
     * desde aquí y es infinitamente menos probable.
     */
    public function actualizar(User $user, array $datos): User
    {
        return DB::transaction(function () use ($user, $datos) {
            $user->fill($datos)->save();

            $tenant = $user->tenant;

            // Sin BD provisionada no hay a dónde propagar: el usuario aún no
            // verificó el correo, y el job creará la fila con los datos ya nuevos.
            if ($tenant !== null && $tenant->db_provisionada) {
                $tenant->run(function () use ($user) {
                    /*
                     * Por su cuenta del tenant: desde la separacion de
                     * usuarios y profesionales, `profesionales` ya no guarda el
                     * id central. Si esta persona no tiene ficha de profesional
                     * —una recepcionista, o un dueno que no atiende— el update
                     * no encuentra nada y no pasa nada, que es lo correcto.
                     */
                    $usuarioId = DB::table('usuarios')
                        ->where('central_user_id', $user->id)
                        ->value('id');

                    DB::table('profesionales')
                        ->where('usuario_id', $usuarioId)
                        ->update([
                            'nombre' => trim($user->nombre.' '.$user->apellido),
                            'telefono' => $user->telefono,
                            'foto' => $user->foto,
                            'updated_at' => now(),
                        ]);
                });
            }

            return $user->refresh();
        });
    }

    /**
     * Cambia la contraseña y REVOCA las demás sesiones, conservando la actual.
     *
     * Es lo que espera quien la cambia porque sospecha que entraron a su
     * cuenta. Cerrar también la sesión desde la que se hace el cambio sería
     * castigar al que hace lo correcto (contrato § Autenticación).
     */
    public function cambiarPassword(User $user, string $nueva, ?int $tokenActualId): void
    {
        DB::transaction(function () use ($user, $nueva, $tokenActualId) {
            $user->forceFill(['password' => $nueva])->save();

            $user->tokens()
                ->when($tokenActualId !== null, fn ($q) => $q->where('id', '!=', $tokenActualId))
                ->delete();
        });
    }
}
