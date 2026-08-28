<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\ImagenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoriaServicioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'color' => $this->color,
            'orden' => $this->orden,
            /*
             * La columna guarda la RUTA (`categorias/uuid.webp`), no la URL:
             * el dia que las imagenes se muevan a S3 cambia una linea de
             * configuracion en vez de cada fila de cada tenant.
             */
            'imagen_url' => ImagenService::url($this->imagen),
            /*
             * El dialogo de borrado lo necesita para avisar cuantos servicios
             * quedaran sin categoria (que NO es lo mismo que borrarlos).
             */
            'servicios_count' => $this->whenCounted('servicios'),
        ];
    }
}
