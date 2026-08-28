<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Los tres roles que todo negocio recibe al provisionar.
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
        'inventario', 'caja', 'reportes', 'locales', 'empleados',
        'whatsapp', 'configuracion', 'facturacion', 'soporte',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presets(): array
    {
        return [
            [
                'nombre' => 'Dueño',
                'clave' => 'dueno',
                'sistema' => true,
                'solo_propios' => false,
                'permisos' => array_fill_keys(self::MODULOS, 'gestionar'),
            ],
            [
                'nombre' => 'Administrador',
                'clave' => 'admin',
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
                    'reportes' => 'ver',
                    'locales' => 'gestionar',
                    'empleados' => 'gestionar',
                    'whatsapp' => 'gestionar',
                    'configuracion' => 'gestionar',
                    // La suscripción y el método de pago NO se delegan: es lo
                    // único que separa al dueño de su mano derecha.
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
        ];
    }
}
