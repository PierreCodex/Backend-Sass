<?php

declare(strict_types=1);

namespace App\Http\Requests\Citas;

use App\Models\Cita;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear y editar una cita. JSON.
 *
 * Lo que NO se valida aquí y sí en el service: que la hora esté libre. Esa
 * comprobación tiene que leer las citas del profesional con el candado puesto,
 * y hacerlo desde un Form Request la sacaría de la transacción — dos peticiones
 * simultáneas pasarían las dos.
 */
class CitaRequest extends FormRequest
{
    public function rules(): array
    {
        $creando = $this->route('cita') === null;

        return [
            'empleado_id' => ['required', 'integer', Rule::exists('profesionales', 'id')->whereNull('deleted_at')],
            'servicio_id' => ['required', 'integer', Rule::exists('servicios', 'id')->whereNull('deleted_at')],

            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', 'date_format:H:i'],

            /*
             * `hora_fin` NO se acepta: la calcula el backend sumando la
             * duración del servicio, y así lo declara el contrato. Aceptarla
             * dejaría reservar 20 minutos de un servicio de 60 y romper la
             * agenda desde el primer día.
             */

            'local_id' => ['nullable', 'integer', Rule::exists('locales', 'id')],

            // El cliente puede venir por id o suelto (§2.2). Que los dos sean
            // opcionales es a propósito: el service decide, y sin nada de esto
            // el 422 sale con el nombre.
            'cliente_id' => ['nullable', 'integer', Rule::exists('clientes', 'id')->whereNull('deleted_at')],
            'cliente_nombre' => [
                Rule::requiredIf(fn () => $this->input('cliente_id') === null),
                'nullable', 'string', 'max:150',
            ],
            'cliente_telefono' => ['nullable', 'string', 'max:30'],
            'cliente_email' => ['nullable', 'email', 'max:150'],

            'monto' => ['nullable', 'numeric', 'min:0'],

            /*
             * Los seis de la BD, no los cuatro del contrato (§2.5).
             *
             * `en_curso` y `no_asistio` existen en el ENUM y el propio contrato
             * los necesita — su bloque de inasistencias es imposible sin
             * `no_asistio`. Aceptar solo cuatro obligaría a inventar un séptimo
             * camino el día que el panel los ofrezca.
             */
            'estado' => [$creando ? 'nullable' : 'required', Rule::in(Cita::ESTADOS)],

            'notas' => ['nullable', 'string', 'max:500'],

            'productos' => ['nullable', 'array'],
            'productos.*.id' => ['required', 'integer', Rule::exists('productos', 'id')->whereNull('deleted_at')],
            'productos.*.cantidad' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_nombre.required' => 'Dinos a nombre de quién es la cita.',
            'empleado_id.exists' => 'Ese profesional no existe.',
            'servicio_id.exists' => 'Ese servicio no existe.',
            'hora_inicio.date_format' => 'La hora tiene que ser como 09:30.',
        ];
    }
}
