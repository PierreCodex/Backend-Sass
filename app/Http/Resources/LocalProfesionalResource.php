<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Profesional;
use App\Services\ImagenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un profesional VISTO DESDE una sede.
 *
 * El `id` es el del profesional, no el de la fila pivote (§1.4): es el mismo
 * que usan `/profesionales/{id}`, las citas y `servicio_profesional`. Tener dos
 * ids para la misma persona segun la pantalla seria una fuente de errores
 * silenciosos.
 *
 * @mixin Profesional
 */
class LocalProfesionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fila = $this->pivot;

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'foto_url' => ImagenService::url($this->foto),

            /*
             * `false` y nulls cuando todavia no trabaja aqui: el listado
             * devuelve UNA FILA POR CADA profesional del negocio, tenga o no
             * asignacion. Asi la pantalla es una sola tabla con interruptores
             * en vez de dos listas y un boton de anadir.
             */
            'habilitado' => (bool) ($fila?->habilitado ?? false),

            // Como aparece en la tienda publica de ESTA sede. Puede ser distinto
            // de su nombre interno.
            'nombre_publico' => $fila?->nombre_publico,
            'perfil' => $fila?->perfil,

            /*
             * OJO: este horario NO controla la disponibilidad. El motor de
             * reservas usa el horario del PROFESIONAL, no el de su fila en el
             * local — esto es informativo para la pagina publica. Si algun dia
             * tiene que restringir de verdad, hay que tocar el calculo de
             * huecos del Sprint 4, y es decision de producto (ficha § El
             * horario del local NO controla la disponibilidad).
             */
            'horario_apertura' => $this->hora($fila?->horario_apertura),
            'horario_cierre' => $this->hora($fila?->horario_cierre),
        ];
    }

    /** TIME vuelve como «09:00:00» y el contrato pide «HH:MM». */
    private function hora(?string $valor): ?string
    {
        return $valor === null ? null : substr($valor, 0, 5);
    }
}
