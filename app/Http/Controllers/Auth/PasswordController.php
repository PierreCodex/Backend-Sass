<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordController extends Controller
{
    /**
     * 200 siempre: no revela si el email existe.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated());

        return response()->json(['message' => 'Si el correo está registrado, enviamos un enlace para restablecer la contraseña.']);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $estado = Password::reset(
            $request->validated(),
            function ($user, string $password) {
                $user->forceFill(['password' => $password])->save();
            },
        );

        if ($estado !== Password::PasswordReset) {
            return response()->json([
                'message' => 'El enlace de restablecimiento no es válido o ya venció.',
                'errors' => ['email' => ['El enlace de restablecimiento no es válido o ya venció.']],
            ], 422);
        }

        return response()->json(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }
}
