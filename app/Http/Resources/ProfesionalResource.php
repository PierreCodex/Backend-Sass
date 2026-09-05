<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\ImagenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quien presta los servicios.
 *
 * El id es el de `profesionales`: el que usan las citas,
 * `servicio_profesional` y `local_profesional` (§2.3).
 *
 * `usuario` sale como objeto y es `null` en quien no entra al panel — que es
 * lo normal en un barbero. Su credencial vive en la central y este Resource no
 * la toca más allá del email, que es lo único que el listado necesita mostrar.
 */
class ProfesionalResource extends JsonResource
{
    /** Lunes a domingo, en ISO-8601: 1..7. */
    private const DIAS = [1, 2, 3, 4, 5, 6, 7];

    public function toArray(Request $request): array
    {
        $central = $this->usuario?->central;

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'foto_url' => ImagenService::url($this->foto),

            /*
             * Su cuenta del panel, o `null` si no entra al sistema. Es la
             * respuesta a «¿este barbero puede ver su agenda?», y el frontend
             * la usa para pintar el interruptor de darle acceso.
             */
            'usuario' => $this->usuario === null ? null : [
                'id' => $this->usuario->id,
                'email' => $central?->email,
                'activo' => (bool) $central?->activo,
                'rol_id' => $this->usuario->rol_id,
                'rol' => $this->usuario->rol === null ? null : [
                    'id' => $this->usuario->rol->id,
                    'nombre' => $this->usuario->rol->nombre,
                    'clave' => $this->usuario->rol->clave,
                ],
            ],

            'cargo' => $this->cargo,
            'telefono' => $this->telefono,
            'activo' => $this->activo,

            /*
             * Si acepta reservas desde la tienda pública. Y SOLO eso: desde que
             * usuarios y profesionales son cosas distintas, este booleano dejó
             * de decidir el cupo del plan (lo decide estar aquí y estar activo)
             * y dejó de significar «es staff» (lo dice tener cuenta).
             */
            'atiende' => $this->atiende,

            'tipo_pago' => $this->tipo_pago,

            // Naming del contrato: en la columna son `comision_pct`,
            // `sueldo_monto` y `sueldo_periodo` (§1.9).
            'comision_porcentaje' => (float) $this->comision_pct,
            'monto_sueldo' => $this->sueldo_monto === null ? null : (float) $this->sueldo_monto,
            'periodo_pago' => $this->sueldo_periodo,

            /*
             * En la columna es UN solo JSON `{dias, excepciones}`; el contrato
             * los quiere como dos arrays hermanos. Los separa el Resource, así
             * que el frontend NO necesita el adaptador que preveía la ficha.
             */
            'horario' => $this->dias(),
            'excepciones' => array_values((array) ($this->horario['excepciones'] ?? [])),
        ];
    }

    /**
     * Siempre los 7 días, aunque estén a medias en la columna.
     *
     * La fila del dueño nace en el provisioning con `horario` NULL, y el
     * formulario del panel necesita 7 tarjetas que pintar: devolverle null o
     * tres días le obligaría a rellenar huecos con su propia idea de qué es un
     * día vacío. Los que sí están se devuelven tal cual llegaron.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dias(): array
    {
        $guardados = [];

        foreach ((array) ($this->horario['dias'] ?? []) as $dia) {
            if (isset($dia['dia'])) {
                $guardados[(int) $dia['dia']] = $dia;
            }
        }

        return array_map(fn (int $dia) => $guardados[$dia] ?? [
            'dia' => $dia,
            'activo' => false,
            'desde' => null,
            'hasta' => null,
            'breaks' => [],
        ], self::DIAS);
    }
}
