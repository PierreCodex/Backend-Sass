<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload cerrado del registro (contrato § Registro y onboarding).
 * Sin nombre del negocio: eso lo fija el paso 1 del onboarding.
 * Solo dueños se registran (§2.9). Email único GLOBAL (§2.10).
 */
class RegisterRequest extends FormRequest
{
    public const RANGOS_PROFESIONALES = ['independiente', '2', '3-5', '6-15', '+16'];

    public function rules(): array
    {
        return [
            'tipo_negocio_id' => ['required', 'integer', 'exists:business_categories,id'],
            'rango_profesionales' => ['required', 'in:'.implode(',', self::RANGOS_PROFESIONALES)],
            'nombre' => ['required', 'string', 'max:150'],
            'apellido' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'telefono' => ['required', 'string', 'regex:/^\+51\d{9}$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Este correo ya está registrado.',
            'telefono.regex' => 'El teléfono debe tener el formato +51 seguido de 9 dígitos.',
        ];
    }
}
