<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Cita;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Cita
 */
class CitaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $servicios = $this->whenLoaded('servicios');
        $primero = $servicios instanceof Collection ? $servicios->first() : null;

        return [
            'id' => $this->id,
            // Público, y el único identificador que viaja por WhatsApp: por él
            // gestiona su cita quien no tiene cuenta.
            'codigo' => $this->codigo,

            // Un DATETIME partido en dos (§2.1). `hora_fin` la calculó el
            // backend sumando la duración congelada de las líneas.
            'fecha' => $this->starts_at->format('Y-m-d'),
            'hora_inicio' => $this->starts_at->format('H:i'),
            'hora_fin' => $this->ends_at->format('H:i'),

            'estado' => $this->estado,

            /*
             * `monto` es la suma de las LÍNEAS DE SERVICIO, no el total.
             *
             * Es el campo que el panel deja editar, y editarlo reescribe el
             * precio congelado de la línea (§2.6). Si aquí saliera el total con
             * productos, reenviarlo tal cual subiría el precio del servicio con
             * el importe de lo vendido. `monto_total` va aparte, para mostrar.
             */
            'monto' => $this->montoDeServicios(),
            'monto_total' => $this->monto_total,

            'notas' => $this->notas,

            'cliente_id' => $this->cliente_id,
            'cliente_nombre' => $this->whenLoaded('cliente', fn () => trim($this->cliente->nombre.' '.($this->cliente->apellido ?? ''))),
            'cliente_telefono' => $this->whenLoaded('cliente', fn () => $this->cliente->telefono),
            'cliente_email' => $this->whenLoaded('cliente', fn () => $this->cliente->email),

            /*
             * `servicio` singular es el PUENTE de §2.4: el contrato lo declara
             * así y una cita de la tienda puede traer varios. Sale el primero
             * para que el panel de hoy siga funcionando, y `servicios` completo
             * al lado para que pueda migrar sin que el backend cambie otra vez.
             */
            'servicio' => $primero === null ? null : self::linea($primero),
            'servicios' => $servicios instanceof Collection
                ? $servicios->map(self::linea(...))->all()
                : [],

            'empleado' => $this->whenLoaded('profesional', fn () => [
                'id' => $this->profesional->id,
                'nombre' => $this->profesional->nombre,
            ]),

            'local_id' => $this->local_id,

            // Asimetría documentada en el contrato: al escribir es `id`, al
            // leer `producto_id`.
            'productos' => $this->whenLoaded('productos', fn () => $this->productos->map(fn ($p) => [
                'producto_id' => $p->id,
                'nombre' => $p->nombre,
                'cantidad' => (int) $p->pivot->cantidad,
                'precio_unitario' => (float) $p->pivot->precio,
            ])->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function linea(object $servicio): array
    {
        return [
            'id' => $servicio->id,
            'nombre' => $servicio->nombre,
            // De la PIVOTE, no del servicio: es el precio y la duración que
            // tenía el día que se reservó.
            'duracion_min' => (int) $servicio->pivot->duracion_min,
            'precio' => (float) $servicio->pivot->precio,
            'cantidad' => (int) $servicio->pivot->cantidad,
            'color' => $servicio->color,
        ];
    }

    private function montoDeServicios(): float
    {
        $servicios = $this->whenLoaded('servicios');

        if (! $servicios instanceof Collection) {
            return 0.0;
        }

        return (float) $servicios->sum(fn ($s) => (float) $s->pivot->precio * (int) $s->pivot->cantidad);
    }
}
