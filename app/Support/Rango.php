<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * El rango: lo que se decide mirando quién es, y no lo que puede.
 *
 * Es la excepción a la regla general del panel, que pregunta por CAPACIDAD y
 * nunca por rol. Gestionar roles y cuentas no se puede expresar como una
 * capacidad más de la matriz sin abrir un agujero: quien reparte permisos
 * puede darse los que le falten, así que ese permiso concreto no puede vivir
 * en la misma matriz que administra.
 *
 * Vive aquí y no en el controlador porque hay DOS sitios que lo preguntan en
 * momentos distintos —el `authorize()` de `RolRequest`, que corre antes de
 * validar, y el propio controlador— y una regla de seguridad con dos copias no
 * es el doble de segura: es dos sitios que divergen. Ya nos pasó con la
 * escalada del 2026-09-05, y volvió a pasar aquí en pequeño: la copia del
 * FormRequest devolvía un 403 sin `codigo` mientras la del controlador sí lo
 * llevaba, y nadie lo notó porque la del FormRequest salta primero.
 */
final class Rango
{
    /**
     * Aborta con 403 si quien pide no es el administrador general.
     *
     * 403 y no 404: el recurso existe y es del negocio de quien pregunta; lo
     * que falta es rango. El 404 se reserva para lo que es de otro tenant.
     *
     * Lleva el MISMO `codigo` que el middleware `puede`, aunque el guardia sea
     * otro: para quien recibe la respuesta esto ES falta de permiso, y el
     * matiz de que venga del rango y no de la matriz ya lo cuenta el
     * `message`. Un código por cada guardia obligaría al cliente a conocer
     * nuestra estructura interna para acabar pintando el mismo aviso.
     */
    public static function soloElAdminGeneral(?User $user): void
    {
        if ($user?->rol === 'admin_general') {
            return;
        }

        abort(response()->json([
            'message' => 'Solo el administrador general puede hacer esto.',
            'codigo' => 'sin_permiso',
        ], 403));
    }
}
