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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class VerificacionCorreoController extends Controller
{
    /** Cooldown del botón "reenviar" del panel "Revisa tu correo". */
    private const REENVIO_ESPERA = 60;

    /** Techo por correo: corta el bombardeo de bandeja rotando de IP. */
    private const REENVIO_MAX_HORA = 5;

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
     *
     * Los límites se cuentan por correo ANTES de mirar si existe, así que
     * tampoco filtran nada: el mismo email siempre responde igual. El
     * throttle de ruta es por IP y no impide que alguien reviente la bandeja
     * de un tercero; este sí.
     */
    public function reenviar(EmailReenviarRequest $request): JsonResponse
    {
        $email = Str::lower($request->validated()['email']);
        $clave = 'reenviar-verificacion:'.sha1($email);

        $espera = max(
            RateLimiter::availableIn($clave.':cooldown'),
            RateLimiter::tooManyAttempts($clave.':hora', self::REENVIO_MAX_HORA)
                ? RateLimiter::availableIn($clave.':hora')
                : 0,
        );

        if ($espera > 0) {
            return response()->json([
                'message' => 'Ya enviamos un enlace hace poco. Espera un momento antes de pedir otro.',
                'retry_after' => $espera,
            ], 429);
        }

        RateLimiter::hit($clave.':cooldown', self::REENVIO_ESPERA);
        RateLimiter::hit($clave.':hora', 3600);

        $user = User::where('email', $email)
            ->whereNull('email_verified_at')
            ->first();

        if ($user !== null) {
            Mail::to($user->email)->send(new VerificarCorreoMail($user));
        }

        return response()->json([
            'message' => 'Si el correo está registrado, enviamos un nuevo enlace.',
            'retry_after' => self::REENVIO_ESPERA,
        ]);
    }
}
