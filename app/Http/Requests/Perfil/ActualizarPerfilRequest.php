<?php

declare(strict_types=1);

namespace App\Http\Requests\Perfil;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mi perfil: la cuenta de LA PERSONA (ficha `vistas/perfil.md`).
 *
 * No aparecen aquí, y es deliberado: `email` (es el login y es único global —
 * cambiarlo obliga a re-verificar y deja al usuario a medias), `rol` (nadie se
 * asciende a sí mismo; los roles se dan en Empleados) y todo lo del negocio,
 * que vive en Configuración.
 */
class ActualizarPerfilRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'apellido' => ['required', 'string', 'max:150'],

            // Mismo formato que el registro: los teléfonos ya guardados están
            // normalizados a +51 y el WhatsApp de las notificaciones cuenta con ello.
            'telefono' => ['nullable', 'string', 'regex:/^\+51\d{9}$/'],

            // Sin formato de DNI peruano: un extranjero con carné tiene 9 o 12
            // caracteres. Se acota el largo y los caracteres, no la forma.
            'documento' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],

            'foto' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'telefono.regex' => 'El teléfono debe tener el formato +51 seguido de 9 dígitos.',
            'documento.regex' => 'El documento solo admite letras, números y guiones.',
        ];
    }
}
