<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmailReenviarRequest;
use App\Http\Requests\Auth\VerificarEmailRequest;
use App\Jobs\ProvisionTenantDatabase;
use App\Mail\VerificarCorreoMail;
use App\Models\User;
use App\Support\FirmaVerificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class VerificacionCorreoController extends Controller
{
    /**
     * Marca email_verified_at y ENCOLA el provisioning de la BD del tenant
     * (regla 2: al verificar el correo, no al completar el onboarding).
     * Idempotente: verificar dos veces no re-encola nada.
     */
    public function verificar(VerificarEmailRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $user = User::find($datos['id']);

        $firmaValida = $user !== null
            && hash_equals(sha1((string) $user->email), $datos['hash'])
            && FirmaVerificacion::validar(
                (int) $datos['id'],
                $datos['hash'],
                (int) $datos['expires'],
                $datos['signature'],
            );

        if (! $firmaValida) {
            return response()->json([
                'message' => 'El enlace de verificación no es válido o ya venció. Pide uno nuevo.',
            ], 422);
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();

            ProvisionTenantDatabase::dispatch($user->tenant);
        }

        return response()->json(['message' => 'Correo verificado. Ya puedes iniciar sesión.']);
    }

    /**
     * 200 SIEMPRE: no revela si el email existe (contrato).
     */
    public function reenviar(EmailReenviarRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated()['email'])
            ->whereNull('email_verified_at')
            ->first();

        if ($user !== null) {
            Mail::to($user->email)->send(new VerificarCorreoMail($user));
        }

        return response()->json(['message' => 'Si el correo está registrado, enviamos un nuevo enlace.']);
    }
}
