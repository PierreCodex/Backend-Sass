<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Vive en la BD del TENANT.
 *
 * `starts_at`/`ends_at` son DATETIME en la zona horaria del negocio (§2.1): se
 * guarda la hora de pared, tal cual la escribió quien agendó. El contrato habla
 * de `fecha` + `hora_inicio`/`hora_fin` y esa partición la hace el Resource.
 *
 * **No existe `citas.servicio_id`**: los servicios viven en `cita_servicio`
 * (§2.4), que es la única fuente de verdad. La reserva pública puede encadenar
 * varios en una sola cita; el panel inserta una línea.
 */
class Cita extends Model
{
    public const ESTADOS = [
        'pendiente', 'confirmada', 'en_curso', 'completada', 'cancelada', 'no_asistio',
    ];

    /** Los que el contrato del panel ya conocía (§2.5). Los otros dos son de la BD. */
    public const ESTADOS_DEL_CONTRATO = ['pendiente', 'confirmada', 'completada', 'cancelada'];

    protected $fillable = [
        'codigo', 'local_id', 'profesional_id', 'cliente_id',
        'starts_at', 'ends_at', 'estado', 'notas', 'fuente', 'monto_total',
        'cancelada_motivo', 'cancelada_el', 'confirmada_el', 'completada_el',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'monto_total' => 'float',
        'cancelada_el' => 'datetime',
        'confirmada_el' => 'datetime',
        'completada_el' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    /**
     * Precio y duración van EN LA PIVOTE porque están congelados: subir la
     * tarifa mañana no puede cambiar lo que costó una cita de ayer.
     */
    public function servicios(): BelongsToMany
    {
        return $this->belongsToMany(Servicio::class, 'cita_servicio')
            ->withPivot(['cantidad', 'precio', 'duracion_min'])
            ->withTimestamps();
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'cita_producto')
            ->withPivot(['cantidad', 'precio'])
            ->withTimestamps();
    }
}
