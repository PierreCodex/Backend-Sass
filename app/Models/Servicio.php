<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vive en la BD del TENANT.
 *
 * Lleva soft delete, al reves que las categorias: `cita_servicio` lo
 * referencia con FK restrict, asi que un borrado real lo impediria MySQL — y
 * con razon, porque reescribiria el historial. El soft delete es justo lo que
 * permite que el boton "Eliminar" funcione.
 *
 * `cita_servicio` congela `precio` y `duracion_min` al reservar, de modo que
 * cambiar (o restaurar) un servicio nunca altera lo que costo una cita vieja.
 */
class Servicio extends Model
{
    use SoftDeletes;

    public const TIPOS = ['normal', 'sesiones', 'clases', 'paquete'];

    /** Los tipos que ademas necesitan `max_sesiones`. */
    public const TIPOS_CON_SESIONES = ['sesiones', 'paquete'];

    protected $fillable = [
        'categoria_servicio_id',
        'nombre',
        'descripcion',
        'color',
        'tipo',
        'max_sesiones',
        'precio',
        'duracion_min',
        'visible_publico',
        'activo',
    ];

    protected $casts = [
        // Sin esto `precio` sale como la cadena "20.00" y el frontend pinta
        // "S/ 20.00.00" al formatear.
        'precio' => 'float',
        'duracion_min' => 'integer',
        'max_sesiones' => 'integer',
        'visible_publico' => 'boolean',
        'activo' => 'boolean',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaServicio::class, 'categoria_servicio_id');
    }

    public function imagenes(): HasMany
    {
        // orden=0 es la principal, el resto galeria (comentario de la migracion).
        return $this->hasMany(ServicioImagen::class)->orderBy('orden');
    }

    public function profesionales(): BelongsToMany
    {
        return $this->belongsToMany(Profesional::class, 'servicio_profesional')
            ->withPivot('precio_override')
            ->withTimestamps();
    }
}
