<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rol del negocio. Vive en la BD del TENANT: un rol que inventa el dueño es
 * dato suyo. Los tres de sistema (`dueno`, `admin`, `profesional`) se siembran
 * al provisionar desde `App\Support\RolesSistema`.
 *
 * Los endpoints de gestión de roles llegan más adelante en este mismo sprint;
 * aquí se usa para resolver `clave` → `rol_id` al dar de alta un empleado, y
 * para contar el cupo del plan sin salir de la base del negocio.
 */
class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = ['nombre', 'clave', 'sistema', 'permisos', 'solo_propios', 'editado_at'];

    protected $casts = [
        'sistema' => 'boolean',
        'solo_propios' => 'boolean',
        'permisos' => 'array',
        'editado_at' => 'datetime',
    ];

    public function profesionales(): HasMany
    {
        return $this->hasMany(Profesional::class);
    }

    /** @return array<int, string> Las claves de los roles de sistema. */
    public static function clavesDeSistema(): array
    {
        return ['dueno', 'admin', 'profesional'];
    }
}
