<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Los cuatro roles que todo negocio recibe al provisionar.
 *
 * Fuente única de la matriz de permisos: los endpoints preguntan por
 * capacidades (`caja.gestionar`), nunca por el rol. Así, el día que el dueño
 * cree sus propios roles, no se toca ni un endpoint — solo cambia de dónde
 * sale la lista.
 *
 * Dos niveles por módulo: `ver` consulta, `gestionar` crea/edita/borra.
 * `null` (o ausente) es sin acceso.
 */
final class RolesSistema
{
    /**
     * Los módulos del panel. Añadir uno aquí NO migra nada: los permisos
     * viven en un JSON. Pero sí hay que decidir qué presets lo reciben, y a
     * los roles con `editado_at` no nulo se les pregunta en vez de pisarlos.
     */
    public const MODULOS = [
        'dashboard', 'citas', 'calendario', 'clientes', 'servicios',
        'inventario', 'caja', 'pagos', 'reportes', 'locales', 'empleados',
        'whatsapp', 'configuracion', 'facturacion', 'soporte',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presets(): array
    {
        return [
            [
                /*
                 * «Administrador general» y no «Dueño»: quien registra la
                 * cuenta no siempre es el propietario del negocio —en una
                 * clínica o un salón con socios suele ser la administradora—
                 * y decirle «Dueño» en su panel sería falso. Esto describe lo
                 * que hace, que es cierto siempre.
                 *
                 * Hay exactamente uno por negocio, lo crea el registro, no se
                 * borra ni cambia de rol, y es el único que toca facturación,
                 * configuración, cuentas y roles.
                 */
                'nombre' => 'Administrador general',
                'clave' => 'admin_general',
                'sistema' => true,
                'solo_propios' => false,
                'permisos' => array_fill_keys(self::MODULOS, 'gestionar'),
            ],
            [
                /*
                 * «Administrador local», no «Administrador» a secas: contra el
                 * general no se distinguía por permisos —una fila, facturación—
                 * y eso no es un rol distinto, es el mismo con un permiso
                 * menos. Lo que de verdad los separa es el ALCANCE: uno manda
                 * en la empresa, el otro en su sede.
                 *
                 * Por eso pierde `configuracion`: ahí viven el nombre del
                 * negocio, el slug, la marca y el horario base — cosas de la
                 * empresa, no de un local. Cuentas y roles ya eran del general.
                 *
                 * El alcance por sedes en sí llega con Locales (Sprint 3). En
                 * un negocio de una sola sede el rol ya se lee bien: es el
                 * encargado del local.
                 *
                 * En pantalla se llama «Administrador de sede» desde la Story
                 * 1.5, que es lo que es. La clave `admin_local` no cambia.
                 */
                'nombre' => 'Administrador de sede',
                'clave' => 'admin_local',
                'sistema' => true,
                'solo_propios' => false,
                'permisos' => [
                    'dashboard' => 'ver',
                    'citas' => 'gestionar',
                    'calendario' => 'gestionar',
                    'clientes' => 'gestionar',
                    'servicios' => 'gestionar',
                    'inventario' => 'gestionar',
                    'caja' => 'gestionar',
                    /*
                     * Verificar el pago de una cita (Story 1.6). Va aparte de
                     * `citas` a propósito: agendar no es tocar dinero, y el
                     * negocio tiene que poder dar lo uno sin lo otro. No es
                     * `facturacion`, que es la suscripción del negocio.
                     */
                    'pagos' => 'gestionar',
                    'reportes' => 'ver',
                    'locales' => 'gestionar',
                    'empleados' => 'gestionar',
                    'whatsapp' => 'gestionar',
                    /*
                     * Ni configuración ni facturación. La primera es de la
                     * empresa y no de una sede; la segunda no se delega —
                     * cambiar de plan o dar de baja la suscripción es del
                     * titular de la cuenta y de nadie más.
                     */
                    'configuracion' => null,
                    'facturacion' => null,
                    'soporte' => 'gestionar',
                ],
            ],
            [
                'nombre' => 'Profesional',
                'clave' => 'profesional',
                'sistema' => true,
                // Ve su agenda y sus citas, no las de sus compañeros.
                'solo_propios' => true,
                'permisos' => [
                    'dashboard' => 'ver',
                    'citas' => 'gestionar',
                    'calendario' => 'ver',
                    'clientes' => 'ver',
                    'servicios' => 'ver',
                ],
            ],
            [
                /*
                 * Story 1.5: la persona que más usa el panel en un salón, y que
                 * hasta ahora cada negocio tenía que inventarse —dándole de más
                 * por comodidad—. Matriz A-1 de la propuesta (§2.3) y PRD FR-64.
                 *
                 * Agenda la de todos (no `solo_propios`), atiende a los
                 * clientes, cobra y verifica pagos. Ve el catálogo, el equipo
                 * y el stock para poder agendar y responder, pero no los
                 * cambia. Nada de la empresa: ni configuración ni facturación,
                 * ni sedes, ni reportes.
                 *
                 * «En sus sedes»: el alcance no es del rol sino de cada cuenta
                 * (`usuarios.todos_los_locales` y `local_usuario`), así que dos
                 * recepcionistas de sedes distintas comparten este mismo rol.
                 *
                 * Va el último para no mover los ids de los tres anteriores en
                 * los negocios nuevos. Su propio id NO es fijo: en un negocio
                 * que ya tenía roles propios, la migración le da el siguiente
                 * libre. Se busca siempre por `clave`, nunca por id.
                 *
                 * La migración 2026_10_19_000002 copia esta matriz: si se
                 * cambia aquí, los negocios ya migrados no se enteran.
                 */
                'nombre' => 'Recepción',
                'clave' => 'recepcion',
                'sistema' => true,
                'solo_propios' => false,
                'permisos' => [
                    'dashboard' => 'ver',
                    'citas' => 'gestionar',
                    'calendario' => 'gestionar',
                    'clientes' => 'gestionar',
                    'servicios' => 'ver',
                    'inventario' => 'ver',
                    'caja' => 'gestionar',
                    // Decidido el 2026-10-05: «gestionar», no «ver» (antes por error).
                    'pagos' => 'gestionar',
                    // «Profesionales» en pantalla: los ve para poder agendarles.
                    'empleados' => 'ver',
                ],
            ],
        ];
    }
}
