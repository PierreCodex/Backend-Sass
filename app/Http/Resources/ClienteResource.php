<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Tal cual lo escribio el usuario: la tabla NO normaliza nombres.
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            // El de mostrar, no el normalizado: ese es de uso interno y no
            // sale de la API.
            'telefono' => $this->telefono,
            'email' => $this->email,
            'documento' => $this->documento,
            'fecha_nacimiento' => $this->fecha_nacimiento?->toDateString(),
            'notas' => $this->notas,

            'total_citas' => $this->whenCounted('citas'),

            /*
             * `withMax('citas', 'starts_at')` deja el valor aqui. Se emite
             * SIEMPRE, null incluido: la tabla pinta un guion, y una clave
             * ausente y una clave null no son lo mismo para el frontend.
             * ISO en el JSON; el DD/MM/YYYY lo aplica el frontend.
             */
            'ultima_cita' => $this->citas_max_starts_at === null
                ? null
                : Carbon::parse($this->citas_max_starts_at)->toDateString(),
        ];
    }
}
