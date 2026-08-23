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
    /**
     * §1.6 de discrepancias: `tenants.estado` tiene 6 valores de lifecycle,
     * el contrato solo 3. El almacenamiento manda; el Resource traduce, y el
     * estado interno nunca sale de la API.
     */
    private const ESTADOS = [
        'registrada' => 'prueba',
        'prueba' => 'prueba',
        'activa' => 'activa',
        'suspendida' => 'vencida',
        'purga_pendiente' => 'vencida',
        'eliminada' => 'vencida',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => trim($this->nombre.' '.$this->apellido),
            // Sueltos ADEMÁS de `name`: es como los edita Mi perfil, y partir
            // `name` por el espacio es lossy ("Ana María Quispe").
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'email' => $this->email,
            'telefono' => $this->telefono,
            'documento' => $this->documento,
            'avatar_url' => $this->foto,
            'rol' => $this->rol,
            'negocio' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'nombre' => $this->tenant->nombre,
                // Null hasta el paso 1 del onboarding. Sin él, el panel no
                // puede construir el enlace a la tienda.
                'slug' => $this->tenant->slug,
                'estado' => self::ESTADOS[$this->tenant->estado] ?? 'prueba',
            ]),
        ];
    }
}
