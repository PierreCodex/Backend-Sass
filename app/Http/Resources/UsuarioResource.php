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

                /*
                 * Lo que el dueno respondio en el registro: `independiente`,
                 * `2`, `3-5`, `6-15` o `+16`. Sale para que el panel pueda
                 * esconder el grupo Equipo a quien trabaja solo — un
                 * independiente que abre Empleados se encuentra una pantalla
                 * con una sola persona: el mismo.
                 *
                 * Es una PISTA de interfaz, jamas autorizacion: /empleados y
                 * /roles responden igual pase lo que pase aqui. Esconder un
                 * menu no puede cerrar una puerta, o el dia que contrate a
                 * alguien habria que migrar algo.
                 */
                'rango_profesionales' => $this->tenant->rango_profesionales,
            ]),
        ];
    }
}
