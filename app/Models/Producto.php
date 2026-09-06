<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vive en la BD del TENANT.
 *
 * Los nombres de las columnas NO son los del API: aquí son `precio` y `costo`,
 * y el contrato habla de `precio_venta` y `precio_compra` (§1.10). La traducción
 * la hace el Resource en un solo sitio.
 *
 * Soft delete por lo mismo que `servicios`: `cita_producto` lo referencia, y un
 * borrado real reescribiría lo que costó una venta vieja.
 */
class Producto extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio',
        'costo',
        'stock',
        'stock_minimo',
        'activo',
    ];

    protected $casts = [
        // Sin esto los decimales salen como la cadena "18.00" y el formateador
        // del panel pinta "S/ 18.00.00". Misma razón que en `servicios`.
        'precio' => 'float',
        'costo' => 'float',
        'stock' => 'integer',
        'stock_minimo' => 'integer',
        'activo' => 'boolean',
    ];

    public function movimientos(): HasMany
    {
        return $this->hasMany(InventarioMovimiento::class)->latest();
    }
}
