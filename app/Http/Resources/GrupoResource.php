<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Grupo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Grupo
 */
class GrupoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,

            /*
             * Las tres listas van con id y nombre: la tabla las pinta como
             * chips y no necesita nada más. `descripcion` NO se emite aunque la
             * columna exista — el contrato no la tiene (§2.12).
             */
            'locales' => $this->lista('locales'),
            'profesionales' => $this->lista('profesionales'),
            'servicios' => $this->lista('servicios'),
        ];
    }

    /** @return array<int, array{id: int, nombre: string}> */
    private function lista(string $relacion): array
    {
        return $this->{$relacion}
            ->map(fn ($item) => ['id' => $item->id, 'nombre' => $item->nombre])
            ->values()
            ->all();
    }
}
