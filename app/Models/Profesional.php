<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Quien PRESTA los servicios.
 *
 * Independiente de quien entra al panel: un barbero puede no tener cuenta, y
 * una recepcionista tiene cuenta y no está aquí. `usuario_id` es la unión
 * opcional entre ambos mundos, y es `nullOnDelete`: quitarle el acceso a
 * alguien no se lleva por delante su ficha, sus citas ni sus comisiones.
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
        'usuario_id', 'nombre', 'cargo', 'foto', 'telefono',
        'tipo_pago', 'comision_pct', 'sueldo_monto', 'sueldo_periodo',
        'horario', 'atiende', 'activo',
    ];

    protected $casts = [
        'atiende' => 'boolean',
        'activo' => 'boolean',
        // {dias: [...], excepciones: [...]} — ver ProfesionalResource.
        'horario' => 'array',
        'comision_pct' => 'decimal:2',
        'sueldo_monto' => 'decimal:2',
    ];

    /** Su cuenta del panel, si tiene. NULL = no entra al sistema. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** Atajo: el rol sale de la cuenta, no de la ficha. */
    public function rol(): ?Rol
    {
        return $this->usuario?->rol;
    }

    /** Las sedes donde atiende, con sus datos propios de cada una. */
    public function locales(): BelongsToMany
    {
        return $this->belongsToMany(Local::class, 'local_profesional')
            ->withPivot(['habilitado', 'nombre_publico', 'perfil', 'horario_apertura', 'horario_cierre'])
            ->withTimestamps();
    }

    public function servicios(): BelongsToMany
    {
        return $this->belongsToMany(Servicio::class, 'servicio_profesional')->withTimestamps();
    }
}
