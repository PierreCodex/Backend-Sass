<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\ConfiguracionService;
use App\Services\ImagenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El negocio, aplanado.
 *
 * En la BD unos campos son columnas de `tenants` y otros viven dentro del JSON
 * `configuracion`; el contrato los quiere todos al mismo nivel y esa costura
 * se cose aquí. La regla para saber cuál es cuál está en la migración: si hay
 * columna, manda la columna.
 *
 * @mixin Tenant
 */
class ConfiguracionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $json = $this->configuracion ?? [];

        return [
            'nombre' => $this->nombre,

            /*
             * Solo lectura. Lo fija el paso 1 del onboarding y después es
             * inmutable: forma el subdominio público, y cambiarlo dejaría
             * muerto cada enlace que el negocio haya repartido. El PUT ni lo
             * mira (no está en las reglas del Form Request).
             */
            'slug' => $this->slug,

            'descripcion' => $this->descripcion,
            'email' => $this->email,
            'telefono' => $this->telefono,
            'whatsapp' => $this->whatsapp,
            'direccion' => $this->direccion,

            // Sin columna: vive en el JSON (§1.6 de discrepancias).
            'informacion_adicional' => $json['informacion_adicional'] ?? null,

            /*
             * DECIMAL vuelve de MySQL como string ("-5.19360000") y el
             * contrato pide número. El cast va aquí y no en el modelo porque
             * `float` sobre un decimal es lo correcto para pintarlo en un
             * mapa, no para hacer cuentas de dinero.
             */
            'latitud' => $this->latitud === null ? null : (float) $this->latitud,
            'longitud' => $this->longitud === null ? null : (float) $this->longitud,

            'zona_horaria' => $this->zona_horaria,

            /*
             * El respaldo de la agenda: es el horario que se usa para un
             * profesional que no tiene el suyo. Los defaults son de
             * APLICACIÓN, no de la BD — el JSON puede estar vacío y la
             * pantalla necesita algo que pintar (§1.6).
             */
            'horario_apertura' => $json['horario']['apertura'] ?? ConfiguracionService::HORARIO_APERTURA,
            'horario_cierre' => $json['horario']['cierre'] ?? ConfiguracionService::HORARIO_CIERRE,

            'color_primario' => $this->color_primario,
            'color_secundario' => $this->color_secundario,

            'logo_url' => ImagenService::url($this->logo),
            'cover_url' => ImagenService::url($this->cover),

            'sitio_publico_activo' => $this->sitio_publico_activo,
            'mostrar_en_marketplace' => $this->mostrar_en_marketplace,
            'terminos_servicio' => $this->terminos_servicio,

            /*
             * Cómo se generan los huecos de reserva. `duracion_servicio`
             * encadena los inicios (agenda compacta); `fijo` pinta una rejilla
             * cada N minutos. Lo consume el motor de disponibilidad del
             * Sprint 4, así que sale SIEMPRE con los dos campos aunque el
             * negocio no los haya tocado.
             */
            'agenda' => [
                'modo_intervalo' => $json['agenda']['modo_intervalo'] ?? ConfiguracionService::MODO_INTERVALO,
                'intervalo_min' => (int) ($json['agenda']['intervalo_min'] ?? ConfiguracionService::INTERVALO_MIN),
            ],
        ];
    }
}
