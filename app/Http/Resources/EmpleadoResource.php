<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\ImagenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El `Empleado` del contrato: la fila de `profesionales` (tenant) COMPUESTA
 * con su `users` (central). Ninguna de las dos por separado lo es (§1.9).
 *
 * El id que sale es el de `profesionales`, no el del usuario central: es el
 * que usan las citas, `servicio_profesional` y `local_profesional` (§2.3).
 */
class EmpleadoResource extends JsonResource
{
    /** Lunes a domingo, en ISO-8601: 1..7. */
    private const DIAS = [1, 2, 3, 4, 5, 6, 7];

    public function toArray(Request $request): array
    {
        $usuario = $this->usuarioCentral;

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'foto_url' => ImagenService::url($this->foto),

            /*
             * `usuario` lleva el EMAIL. La columna `users.usuario` se eliminó
             * (§2.9) y el login es el correo; la clave sigue viajando mientras
             * el tipo del contrato la conserve, para no romper la tabla que ya
             * la pinta. Pedido su retiro en pendientes-contrato.
             */
            'usuario' => $usuario?->email,
            'email' => $usuario?->email,
            /*
             * El rol del NEGOCIO, no el ENUM central. `rol_id` es lo que come
             * el formulario; el objeto es para pintar el nombre sin pedir la
             * lista de roles solo para traducir un id. `clave` viaja porque
             * distingue a los tres de sistema aunque el negocio los renombre.
             */
            'rol_id' => $this->rol_id,
            'rol' => $this->whenLoaded('rol', fn () => [
                'id' => $this->rol->id,
                'nombre' => $this->rol->nombre,
                'clave' => $this->rol->clave,
            ]),

            'cargo' => $this->cargo,
            'telefono' => $this->telefono,
            'activo' => $this->activo,

            /*
             * No está en el contrato pero sí en el esquema (§1.9): decide si la
             * persona aparece en la agenda y en la tienda pública. NO decide el
             * cupo del plan. Se emite para que el frontend pueda añadir el
             * interruptor sin esperar a otra versión del backend.
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
