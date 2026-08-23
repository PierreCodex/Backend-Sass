<?php

declare(strict_types=1);

namespace App\Http\Requests\Perfil;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Cambio de contraseña CON sesión abierta.
 *
 * Exige la actual a propósito: sin eso, cualquiera que se siente frente a una
 * sesión abierta se queda con la cuenta. El flujo por correo
 * (`/forgot-password`) es el otro caso — el de quien no puede entrar — y no se
 * reusa aquí: mandar a la bandeja a alguien ya autenticado es fricción gratis.
 */
class CambiarPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', Password::min(8), 'confirmed', 'different:password_actual'],
        ];
    }

    public function messages(): array
    {
        return [
            'password_actual.current_password' => 'Tu contraseña actual no es correcta.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ];
    }
}
