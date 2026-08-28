<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vive en la BD del TENANT: no lleva `negocio_id` porque la base ES el
 * negocio, y por eso `nombre` es UNIQUE a secas — dentro de esa base ya
 * significa "unico por negocio".
 *
 * SIN soft delete, a diferencia de `servicios`: nada del historial apunta a
 * una categoria. Al borrarla sus servicios quedan sin categoria
 * (`nullOnDelete`), no se borran: un reporte de marzo no puede cambiar
 * porque hoy se reordene el catalogo.
 */
class CategoriaServicio extends Model
{
    protected $table = 'categoria_servicios';

    protected $fillable = ['nombre', 'descripcion', 'color', 'imagen', 'orden'];

    protected $casts = [
        'orden' => 'integer',
        'activo' => 'boolean',
    ];

    public function servicios(): HasMany
    {
        return $this->hasMany(Servicio::class, 'categoria_servicio_id');
    }
}
