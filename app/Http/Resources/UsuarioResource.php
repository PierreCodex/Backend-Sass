<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La forma `Usuario` del contrato (§3): name = nombre + apellido;
 * negocio = el tenant del usuario (de ahí saca el BFF su X-Tenant).
 * negocio.nombre puede venir null hasta que el onboarding lo fije.
 */
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => trim($this->nombre.' '.$this->apellido),
            'email' => $this->email,
            'avatar_url' => $this->foto,
            'rol' => $this->rol,
            'negocio' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'nombre' => $this->tenant->nombre,
            ]),
        ];
    }
}
