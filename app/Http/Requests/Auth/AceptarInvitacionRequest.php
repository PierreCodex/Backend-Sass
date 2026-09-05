<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AceptarInvitacionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            // `min:8` y `confirmed`, igual que el registro y el reset: la
            // credencial es la misma y su suelo no puede depender de por donde
            // se cree.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
