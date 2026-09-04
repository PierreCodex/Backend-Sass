<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una cuenta del panel de ESTE negocio: la fila de `usuarios` compuesta con su
 * `users` central.
 *
 * No confundir con `UsuarioResource`, que es el usuario AUTENTICADO —el tipo
 * `Usuario` del contrato, el que devuelven `/login` y `GET /user`—. Aquel
 * describe a quien está mirando; este, a cada miembro del equipo que puede
 * entrar.
 *
 * @mixin Usuario
 */
class CuentaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $central = $this->central;

        return [
            'id' => $this->id,
            'nombre' => $central?->nombre,
            'apellido' => $central?->apellido,
            'email' => $central?->email,
            'telefono' => $central?->telefono,
            'activo' => (bool) $central?->activo,

            /*
             * El rol del negocio. `clave` viaja porque distingue a los tres de
             * sistema aunque se los renombre — el nombre es para leer, la clave
             * para decidir.
             */
            'rol_id' => $this->rol_id,
            'rol' => $this->whenLoaded('rol', fn () => [
                'id' => $this->rol->id,
                'nombre' => $this->rol->nombre,
                'clave' => $this->rol->clave,
            ]),

            /*
             * Si además presta servicios, su ficha. `null` en una recepcionista
             * — y eso es exactamente lo que esta separación vino a permitir.
             */
            'profesional' => $this->whenLoaded('profesional', fn () => $this->profesional === null ? null : [
                'id' => $this->profesional->id,
                'nombre' => $this->profesional->nombre,
                'atiende' => $this->profesional->atiende,
            ]),

            /*
             * Sin contraseña ni marca de verificación: la primera no existe
             * hasta que la persona la elige, y la segunda es asunto suyo, no
             * del listado de su jefe. Para saber si ya entró alguna vez está
             * `activo` más el botón de reenviar invitación.
             */
        ];
    }
}
