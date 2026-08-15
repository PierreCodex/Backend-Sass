<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Firma de los enlaces de verificación de correo.
 *
 * El correo lleva un enlace al FRONTEND (/verificar-correo?id&hash&expires
 * &signature); el frontend reenvía esos mismos parámetros a
 * POST /email/verificar y aquí se validan (contrato § Registro y onboarding).
 */
class FirmaVerificacion
{
    private const VIGENCIA_HORAS = 48;

    public static function parametros(User $user): array
    {
        $id = $user->id;
        $hash = sha1((string) $user->email);
        $expires = now()->addHours(self::VIGENCIA_HORAS)->getTimestamp();

        return [
            'id' => $id,
            'hash' => $hash,
            'expires' => $expires,
            'signature' => self::firmar($id, $hash, $expires),
        ];
    }

    public static function url(User $user): string
    {
        return config('app.frontend_url').'/verificar-correo?'.http_build_query(self::parametros($user));
    }

    public static function validar(int $id, string $hash, int $expires, string $signature): bool
    {
        if ($expires < now()->getTimestamp()) {
            return false;
        }

        return hash_equals(self::firmar($id, $hash, $expires), $signature);
    }

    private static function firmar(int $id, string $hash, int $expires): string
    {
        return hash_hmac('sha256', "{$id}|{$hash}|{$expires}", (string) config('app.key'));
    }
}
