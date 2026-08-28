<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Minimo para que Categorias pueda contar (`servicios_count`). El modulo
 * completo llega en el Sprint 1.B.
 *
 * Lleva soft delete, al reves que las categorias: `cita_servicio` lo
 * referencia, asi que borrarlo de verdad reescribiria el historial. La FK de
 * `cita_servicio` es restrict, de modo que MySQL lo impediria igualmente.
 */
class Servicio extends Model
{
    use SoftDeletes;

    protected $fillable = ['categoria_servicio_id', 'nombre', 'precio', 'duracion_min'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaServicio::class, 'categoria_servicio_id');
    }
}
