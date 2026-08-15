<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeder ÚNICO de planes (valores de vistas/mi-plan.md — nada de migraciones
 * de datos que se pisan). updateOrCreate por slug: re-ejecutarlo actualiza,
 * no duplica.
 *
 * El plan `prueba` (activo=false, no sale en GET /planes) es el que recibe
 * todo tenant al registrarse; límites espejo de Premium durante los 7 días.
 * 999 = centinela de "ilimitado" (§1.5) — nunca NULL.
 */
class PlanSeeder extends Seeder
{
    private const FEATURES_BASICO = [
        'agenda', 'agenda_online', 'gestion_clientes', 'recordatorios',
        'notificaciones_alertas', 'dashboard_stats', 'caja', 'inventario',
        'whatsapp', 'sitio_publico', 'subdominio',
    ];

    private const FEATURES_PREMIUM_EXTRA = [
        'multi_sede', 'encuesta_satisfaccion', 'ficha_personal', 'giftcard',
        'presupuestos', 'historial_producto', 'soporte_prioritario',
    ];

    private const FEATURES_PRO_EXTRA = [
        'dominio_personalizado', 'reportes_avanzados', 'exportaciones',
        'backups', 'api', 'soporte_telefonico', 'asesoria_personalizada',
    ];

    public function run(): void
    {
        $featuresPremium = [...self::FEATURES_BASICO, ...self::FEATURES_PREMIUM_EXTRA];
        $featuresPro = [...$featuresPremium, ...self::FEATURES_PRO_EXTRA];

        $planes = [
            [
                'slug' => 'prueba',
                'nombre' => 'Prueba',
                'descripcion' => 'Período de prueba de 7 días con todas las funciones de Premium',
                'precio_mensual' => 0,
                'precio_anual' => null,
                'precio_promo' => null,
                'promo_duracion_meses' => 0,
                'promo_activa' => false,
                'max_profesionales' => 5,
                'max_sucursales' => 999,
                'max_whatsapp_mes' => 100,
                'precio_profesional_extra' => 0,
                'precio_whatsapp_extra' => 0,
                'mensajes_whatsapp_extra' => 50,
                'destacado' => false,
                'features' => $featuresPremium,
                'activo' => false, // no aparece en GET /planes
            ],
            [
                'slug' => 'basico',
                'nombre' => 'Básico',
                'descripcion' => 'Toma el control de tu negocio',
                'precio_mensual' => 99,
                'precio_anual' => 990,
                'precio_promo' => 9,
                'promo_duracion_meses' => 3,
                'promo_activa' => true,
                'max_profesionales' => 2,
                'max_sucursales' => 1,
                'max_whatsapp_mes' => 0,
                'precio_profesional_extra' => 11,
                'precio_whatsapp_extra' => 17,
                'mensajes_whatsapp_extra' => 50,
                'destacado' => false,
                'features' => self::FEATURES_BASICO,
                'activo' => true,
            ],
            [
                'slug' => 'premium',
                'nombre' => 'Premium',
                'descripcion' => 'Más seguimiento, mejor atención, mayor control, personalización de tu sitio',
                'precio_mensual' => 149,
                'precio_anual' => 1490,
                'precio_promo' => 9,
                'promo_duracion_meses' => 3,
                'promo_activa' => true,
                'max_profesionales' => 5,
                'max_sucursales' => 999,
                'max_whatsapp_mes' => 100,
                'precio_profesional_extra' => 11,
                'precio_whatsapp_extra' => 17,
                'mensajes_whatsapp_extra' => 50,
                'destacado' => true,
                'features' => $featuresPremium,
                'activo' => true,
            ],
            [
                'slug' => 'pro',
                'nombre' => 'Pro',
                // Texto provisional: la ficha lo trunca ("Integraciones…") —
                // anotado en docs/pendientes-contrato.md
                'descripcion' => 'Integraciones y herramientas avanzadas para negocios en crecimiento',
                'precio_mensual' => 449,
                'precio_anual' => 4490,
                'precio_promo' => null,
                'promo_duracion_meses' => 0,
                'promo_activa' => false,
                'max_profesionales' => 15,
                'max_sucursales' => 999,
                'max_whatsapp_mes' => 500,
                'precio_profesional_extra' => 11,
                'precio_whatsapp_extra' => 17,
                'mensajes_whatsapp_extra' => 50,
                'destacado' => false,
                'features' => $featuresPro,
                'activo' => true,
            ],
        ];

        foreach ($planes as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
