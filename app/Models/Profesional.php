<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perfil laboral dentro de la BD del tenant. La identidad (email, password)
 * vive en la central: `central_user_id` la referencia SIN foreign key.
 *
 * Minimo para que Servicios pueda asignar profesionales. El modulo completo
 * (Empleados) llega en el Sprint 2.
 */
class Profesional extends Model
{
    use SoftDeletes;

    // Laravel pluralizaria "Profesional" como `profesionals`.
    protected $table = 'profesionales';

    protected $fillable = ['central_user_id', 'rol_id', 'nombre', 'cargo', 'foto', 'telefono', 'atiende', 'activo'];

    protected $casts = [
        'atiende' => 'boolean',
        'activo' => 'boolean',
    ];

    public function servicios(): BelongsToMany
    {
        return $this->belongsToMany(Servicio::class, 'servicio_profesional')->withTimestamps();
    }
}
