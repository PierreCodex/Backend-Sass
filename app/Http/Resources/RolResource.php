<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rol
 */
class RolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,

            /*
             * La clave es de solo lectura y solo la traen los tres de sistema.
             * Sale porque el negocio puede RENOMBRARLOS —«Administrador» pasa
             * a «Encargada»— y sin ella el frontend no podria reconocer cual
             * es cual para pintar sus barandillas.
             */
            'clave' => $this->clave,
            'sistema' => $this->sistema,

            /*
             * SIEMPRE los 14 modulos, con `null` donde no hay acceso, aunque
             * el JSON guardado solo tenga tres claves (el preset de
             * Profesional guarda cinco). Mismo criterio que el horario de
             * empleados: si la matriz llegara con huecos, el formulario
             * tendria que saber la lista de modulos para rellenarlos, y
             * acabaria habiendo dos listas que divergen.
             */
            'permisos' => $this->permisosCompletos(),
            'solo_propios' => $this->solo_propios,

            /*
             * Las barandillas resueltas por el backend, no deducidas por el
             * cliente. Si el formulario decidiera por su cuenta a quien deja
             * editar, habria dos matrices de reglas: la de aqui, que manda, y
             * la suya, que se olvida de actualizar. El 422 sigue saltando
             * igual — esto solo sirve para deshabilitar el boton antes.
             */
            'editable' => $this->editable(),
            'borrable' => $this->borrable(),
            'duplicable' => $this->duplicable(),

            // Para el dialogo de borrado y para explicar por que un rol en uso
            // no se puede quitar.
            'empleados_count' => $this->whenCounted('profesionales'),
        ];
    }
}
