<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Local;
use App\Services\ImagenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Local
 */
class LocalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'direccion' => $this->direccion,
            'descripcion_publica' => $this->descripcion_publica,
            'telefono' => $this->telefono,
            'email' => $this->email,

            // DECIMAL vuelve de MySQL como string y el contrato pide numero.
            'latitud' => $this->latitud === null ? null : (float) $this->latitud,
            'longitud' => $this->longitud === null ? null : (float) $this->longitud,

            'color' => $this->color,

            /*
             * El par plano que pide el contrato, derivado del JSON. Un solo
             * rango: a diferencia del profesional, el local no aporta
             * restriccion por dia de la semana.
             */
            'horario_desde' => $this->horario['apertura'] ?? null,
            'horario_hasta' => $this->horario['cierre'] ?? null,

            'banner_url' => ImagenService::url($this->banner),
            'logo_url' => ImagenService::url($this->logo),

            /*
             * El principal no se borra. Sale resuelto para que el frontend
             * esconda el boton en vez de deducirlo — el 422 salta igual.
             */
            'es_principal' => $this->es_principal,
        ];
    }
}
