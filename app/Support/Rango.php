<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Rol;
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

        self::sinPermiso('Solo el administrador general puede hacer esto.');
    }

    /*
    |--------------------------------------------------------------------------
    | Nadie concede lo que no tiene (Story 1.4, hueco G-4)
    |--------------------------------------------------------------------------
    |
    | `/usuarios` ya era solo del general, pero `/profesionales` creaba cuentas
    | por debajo con solo `empleados: gestionar`: un administrador de sede daba
    | cualquier rol salvo el general —uno propio con Configuración o
    | Facturación— y la cuenta nacía con todas las sedes. La regla vive aquí y
    | la llama `UsuarioService`, que es por donde pasan TODOS los caminos que
    | crean o editan una cuenta. Nunca una copia en un controlador.
    |
    | Invitar (crear una cuenta) y editar cuentas es solo del general (A-1), y
    | eso lo decide `soloElAdminGeneral`: una sola definición de «general».
    |
    | 403 `sin_permiso` también para «concede más» y no 422: es falta de
    | permiso, no un dato mal escrito, y el panel pinta el mismo aviso.
    */

    /**
     * El rol que se asigna no puede tener más de lo que tiene quien lo asigna.
     *
     * Módulo a módulo, el nivel del rol no supera el del actor
     * (`null` < `ver` < `gestionar`); y si el actor solo ve lo suyo, el rol
     * también. El general no necesita excepción: con `gestionar` en los 14
     * módulos y sin `solo_propios`, cumple por sí solo. Que no fabrique un
     * segundo general lo cuida `UsuarioService` con su 422 de siempre.
     */
    public static function rolContenido(Capacidades $actor, Rol $rol): void
    {
        self::exigirCuenta($actor);

        $suyos = $actor->permisos();

        foreach ($rol->permisosCompletos() as $modulo => $nivel) {
            if (self::nivel($nivel) > self::nivel($suyos[$modulo] ?? null)) {
                self::sinPermiso('No puedes asignar un rol con permisos que tú no tienes.');
            }
        }

        if ($actor->soloPropios() && ! $rol->solo_propios) {
            self::sinPermiso('No puedes asignar un rol que ve más allá de lo propio: tú solo ves lo tuyo.');
        }
    }

    /**
     * El alcance que se asigna cabe en el de quien lo asigna.
     *
     * `$locales` con la misma convención que `Capacidades::locales()`: `null`
     * es `todos_los_locales`, una lista son esas sedes. Todas las sedes solo
     * si el actor las tiene; una lista, solo con sedes de su alcance.
     *
     * @param  list<int|string>|null  $locales
     */
    public static function alcanceContenido(Capacidades $actor, ?array $locales): void
    {
        self::exigirCuenta($actor);

        $suyas = $actor->locales();

        if ($suyas === null) {
            return;
        }

        if ($locales === null) {
            self::sinPermiso('No puedes dar acceso a todas las sedes: tú no lo tienes.');
        }

        $suyas = array_map('intval', $suyas);
        $ajenas = array_diff(array_map('intval', $locales), $suyas);

        if ($ajenas !== []) {
            self::sinPermiso('No puedes dar acceso a una sede que no es tuya.');
        }
    }

    /**
     * Falla cerrado sin cuenta en el negocio.
     *
     * Sin cuenta, `Capacidades` no tiene matriz (`permisos()` vacío; con un rol
     * siempre trae los 14 módulos) y `locales()` es `null`, que se leería como
     * «todas las sedes»: sin esto, quien no es nadie aquí pasaría las dos
     * reglas.
     */
    private static function exigirCuenta(Capacidades $actor): void
    {
        if ($actor->permisos() === []) {
            self::sinPermiso('Sin una cuenta en este negocio no puedes conceder nada.');
        }
    }

    /** `null` < `ver` < `gestionar`. Un nivel desconocido no suma. */
    private static function nivel(?string $nivel): int
    {
        return match ($nivel) {
            'gestionar' => 2,
            'ver' => 1,
            default => 0,
        };
    }

    /**
     * El mismo cuerpo de 403 para todos los guardias de rango, con el MISMO
     * `codigo` que el middleware `puede` (ver `soloElAdminGeneral`).
     */
    private static function sinPermiso(string $mensaje): never
    {
        abort(response()->json([
            'message' => $mensaje,
            'codigo' => 'sin_permiso',
        ], 403));
    }
}
