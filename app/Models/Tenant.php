<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * `id` (aleatorio, inmutable, nombra la BD) y `slug` (subdominio público,
 * lo fija el paso 1 del onboarding) son cosas DISTINTAS — ver CLAUDE.md.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains, SoftDeletes;

    public const ESTADOS = [
        'registrada', 'prueba', 'activa', 'suspendida', 'purga_pendiente', 'eliminada',
    ];

    protected $casts = [
        'onboarding_pasos' => 'array',
        'configuracion' => 'array',
        'db_provisionada' => 'boolean',
        'onboarding_completado' => 'boolean',
        'sitio_publico_activo' => 'boolean',
        'mostrar_en_marketplace' => 'boolean',
        'pagos_qr_activo' => 'boolean',
        'suscripcion_vence_el' => 'date',
        'whatsapp_mes_periodo' => 'date',
        'purga_programada_el' => 'date',
        'suspendida_el' => 'datetime',
        'aviso_purga_enviado_el' => 'datetime',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'slug',
            'plan_id',
            'business_category_id',
            'categoria_otro_detalle',
            'rango_profesionales',
            'nombre',
            'descripcion',
            'email',
            'telefono',
            'whatsapp',
            'direccion',
            'latitud',
            'longitud',
            'zona_horaria',
            'logo',
            'cover',
            'color_primario',
            'color_secundario',
            'sitio_publico_activo',
            'mostrar_en_marketplace',
            'terminos_servicio',
            'mapa_embed',
            'estado',
            'db_provisionada',
            'onboarding_completado',
            'onboarding_pasos',
            'suscripcion_vence_el',
            'suspendida_el',
            'aviso_purga_enviado_el',
            'purga_programada_el',
            'extra_profesionales',
            'extra_whatsapp',
            'whatsapp_mensajes_enviados_mes',
            'whatsapp_mes_periodo',
            'pagos_qr_activo',
            'qr_imagen',
            'instrucciones_pago',
            'configuracion',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function businessCategory(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function elegiblePromo(): bool
    {
        return $this->estado === 'prueba';
    }
}
