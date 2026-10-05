<?php

declare(strict_types=1);

namespace App\Http\Requests\Usuarios;

use App\Models\User;
use App\Support\Rango;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Una cuenta del panel. `nombre`, `email` y `rol_id` son de `users` central y
 * de `usuarios` tenant respectivamente; aqui viajan planos y el service los
 * reparte.
 *
 * **No hay campo de contrasena**, ni al crear ni al editar: la elige la propia
 * persona desde la invitacion. El jefe no deberia conocer la clave de su
 * empleado, y no tenerla que inventar es de paso un campo menos.
 */
class UsuarioRequest extends FormRequest
{
    /**
     * Corta ANTES de validar (como `RolRequest`): si no, el `unique` global del
     * email le contestaría a un no-general si ese correo existe en CUALQUIER
     * negocio. La regla es la de `Rango`; el service la vuelve a llamar.
     */
    public function authorize(): bool
    {
        Rango::soloElAdminGeneral($this->user());

        return true;
    }

    public function rules(): array
    {
        $centralUserId = $this->route('usuario')?->central_user_id;

        return [
            'nombre' => ['required', 'string', 'max:150'],
            'apellido' => ['nullable', 'string', 'max:150'],

            // UNIQUE GLOBAL: el email identifica la cuenta en toda la
            // plataforma, no solo en este negocio (decision del Sprint 0).
            'email' => [
                'required', 'string', 'email', 'max:150',
                Rule::unique(User::class, 'email')->ignore($centralUserId),
            ],

            // Mismo formato que el registro y Mi perfil: escriben la MISMA
            // columna y el WhatsApp cuenta con el +51.
            'telefono' => ['nullable', 'string', 'regex:/^\+51\d{9}$/'],

            // Un rol del negocio, no el ENUM central: el central se deriva.
            'rol_id' => ['required', 'integer', Rule::exists('roles', 'id')],

            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'telefono.regex' => 'El teléfono debe ser +51 seguido de 9 dígitos.',
        ];
    }
}
