<?php

declare(strict_types=1);

namespace App\Http\Requests\Locales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asignar a alguien a una sede, o editar como trabaja en ella.
 *
 * Es el MISMO endpoint para las dos cosas (`syncWithoutDetaching`): no hay
 * «desasignar», se apaga `habilitado`. Quitar la fila borraria de paso su
 * nombre publico y su perfil en esa sede, que el negocio escribio a mano.
 */
class LocalProfesionalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'habilitado' => ['nullable', 'boolean'],
            'nombre_publico' => ['nullable', 'string', 'max:150'],
            'perfil' => ['nullable', 'string', 'max:2000'],

            // Anidado al entrar y plano al salir, como fija el contrato.
            'horario' => ['nullable', 'array'],
            'horario.apertura' => ['nullable', 'date_format:H:i'],
            'horario.cierre' => ['nullable', 'date_format:H:i', 'after:horario.apertura'],
        ];
    }

    public function messages(): array
    {
        return [
            'horario.cierre.after' => 'La hora de cierre debe ser posterior a la de apertura.',
        ];
    }
}
