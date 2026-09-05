<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AceptarInvitacionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class InvitacionController extends Controller
{
    /**
     * El empleado elige su contraseña y la cuenta queda usable.
     *
     * Gemelo de `PasswordController::reset` pero con el broker `invitaciones`,
     * que tiene su propia tabla y sus 7 días. Que sean brokers distintos es lo
     * que impide canjear un enlace de reset por aquí, donde la ventana es
     * mucho más larga.
     */
    public function aceptar(AceptarInvitacionRequest $request): JsonResponse
    {
        $estado = Password::broker('invitaciones')->reset(
            $request->validated(),
            function ($user, string $password) {
                /*
                 * `email_verified_at` de paso: llegar hasta aquí exige haber
                 * abierto un enlace enviado a ese correo, que es exactamente lo
                 * que demuestra la verificación. Pedírsela después sería
                 * mandarle un segundo correo para probar lo mismo.
                 */
                $user->forceFill([
                    'password' => $password,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            },
        );

        if ($estado !== Password::PasswordReset) {
            return response()->json([
                'message' => 'La invitación no es válida o ya venció.',
                'errors' => ['email' => ['La invitación no es válida o ya venció. Pídele a quien te dio de alta que te la reenvíe.']],
            ], 422);
        }

        return response()->json(['message' => 'Contraseña creada. Ya puedes iniciar sesión.']);
    }
}
