<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perfil laboral dentro de la BD del tenant. La identidad (email, password,
 * rol) vive en la central: `central_user_id` la referencia SIN foreign key.
 *
 * El `Empleado` del contrato (§1.9) es la COMPOSICIÓN de esta fila con su
 * `users` central; ninguna de las dos por separado lo es. Quien lo arma es
 * `EmpleadoResource`, y quien mantiene las dos en pie, `EmpleadoService`.
 */
class Profesional extends Model
{
    use SoftDeletes;

    // Laravel pluralizaria "Profesional" como `profesionals`.
    protected $table = 'profesionales';

    public const TIPOS_PAGO = ['comision', 'sueldo', 'ambos'];

    public const PERIODOS_PAGO = ['semanal', 'quincenal', 'mensual'];

    /** Los tipos de pago que llevan sueldo: los demás dejan sus campos en null. */
    public const TIPOS_CON_SUELDO = ['sueldo', 'ambos'];

    /** Los que llevan comisión. */
    public const TIPOS_CON_COMISION = ['comision', 'ambos'];

    protected $fillable = [
        'central_user_id', 'rol_id', 'nombre', 'cargo', 'foto', 'telefono',
        'tipo_pago', 'comision_pct', 'sueldo_monto', 'sueldo_periodo',
        'horario', 'atiende', 'activo',
    ];

    protected $casts = [
        'atiende' => 'boolean',
        'activo' => 'boolean',
        // {dias: [...], excepciones: [...]} — ver EmpleadoResource.
        'horario' => 'array',
        'comision_pct' => 'decimal:2',
        'sueldo_monto' => 'decimal:2',
    ];

    /**
     * El usuario central de esta persona.
     *
     * NO es una relación de Eloquent: `users` vive en otra base y MySQL no
     * hace JOIN entre bases. La rellena a mano `EmpleadoService::conUsuarios()`
     * con una sola consulta por página, no una por fila.
     */
    public ?User $usuarioCentral = null;

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function servicios(): BelongsToMany
    {
        return $this->belongsToMany(Servicio::class, 'servicio_profesional')->withTimestamps();
    }
}
