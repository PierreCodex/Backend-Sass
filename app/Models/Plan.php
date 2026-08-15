<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `max_profesionales` y `max_sucursales` usan 999 como centinela de
 * "ilimitado" — nunca NULL (discrepancias §1.5).
 */
class Plan extends Model
{
    protected $table = 'planes';

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
        'precio_mensual',
        'precio_anual',
        'precio_promo',
        'promo_duracion_meses',
        'promo_activa',
        'max_profesionales',
        'max_sucursales',
        'max_whatsapp_mes',
        'precio_profesional_extra',
        'precio_whatsapp_extra',
        'mensajes_whatsapp_extra',
        'destacado',
        'features',
        'activo',
    ];

    protected $casts = [
        'precio_mensual' => 'decimal:2',
        'precio_anual' => 'decimal:2',
        'precio_promo' => 'decimal:2',
        'precio_profesional_extra' => 'decimal:2',
        'precio_whatsapp_extra' => 'decimal:2',
        'promo_activa' => 'boolean',
        'destacado' => 'boolean',
        'activo' => 'boolean',
        'features' => 'array',
    ];
}
