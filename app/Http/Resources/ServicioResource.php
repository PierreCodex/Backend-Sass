<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\ImagenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ServicioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imagenes = $this->whenLoaded('imagenes');

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            // El color es del SERVICIO, no de su categoría: en la tabla hay
            // servicios sin categoría que igual pintan su punto de color.
            'color' => $this->color,
            'categoria' => $this->whenLoaded('categoria', fn () => $this->categoria === null ? null : [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
            ]),
            'tipo' => $this->tipo,
            'max_sesiones' => $this->max_sesiones,
            'precio' => $this->precio,
            'duracion_min' => $this->duracion_min,
            'activo' => $this->activo,
            'visible_publico' => $this->visible_publico,

            'imagen_principal' => $this->when(
                $imagenes instanceof Collection,
                fn () => self::url($imagenes->firstWhere('orden', 0)?->ruta),
            ),

            /*
             * Objetos {id, url} y no URLs sueltas: al editar, el formulario
             * devuelve los ids de las que conserva. Casar por URL se rompe en
             * silencio si cambia APP_URL o el disco, y lo que se pierde son
             * las fotos del negocio.
             */
            'galeria' => $this->when(
                $imagenes instanceof Collection,
                fn () => $imagenes->where('orden', '>', 0)->values()
                    ->map(fn ($img) => ['id' => $img->id, 'url' => self::url($img->ruta)])
                    ->all(),
            ),

            'empleados' => $this->whenLoaded('profesionales', fn () => $this->profesionales
                ->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre])
                ->all()),
        ];
    }

    private static function url(?string $ruta): ?string
    {
        return ImagenService::url($ruta);
    }
}
