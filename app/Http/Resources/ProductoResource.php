<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Aquí se cose la única costura del módulo: la tabla dice `precio`/`costo` y el
 * contrato `precio_venta`/`precio_compra` (§1.10).
 *
 * @mixin Producto
 */
class ProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,

            /*
             * `costo` es nullable en la tabla y el contrato declara
             * `precio_compra` con 0 por defecto. Se emite 0 y no null porque la
             * columna «Compra» pasa el valor por `formatMoneda()` sin
             * comprobarlo: un null ahí pinta «S/ NaN».
             */
            'precio_compra' => (float) ($this->costo ?? 0),
            'precio_venta' => $this->precio,

            'stock' => $this->stock,
            'stock_minimo' => $this->stock_minimo,
            'activo' => $this->activo,
        ];
    }
}
