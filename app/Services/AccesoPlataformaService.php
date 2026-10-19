<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\CodigoPlataformaMail;
use App\Models\PlatformAdmin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Entrada al panel de plataforma en dos pasos: contraseña y después un código
 * de 6 dígitos que llega por correo.
 *
 * El código:
 * - se guarda **hasheado** en la caché, nunca en claro;
 * - vale `MINUTOS` minutos y **una sola vez**: al usarse se borra;
 * - se anula tras `MAX_INTENTOS` fallos, y hay que pedir otro;
 * - pedir uno nuevo invalida el anterior (misma clave de caché).
 *
 * Prototipo del segundo factor de la Story 8.4. Todavía no emite token: el
 * guard propio de plataforma llega con esa historia.
 */
class AccesoPlataformaService
{
    public const MINUTOS = 10;

    public const MAX_INTENTOS = 5;

    /** Paso 1: comprueba la contraseña y manda el código. */
    public function enviarCodigo(string $email, string $password): void
    {
        $admin = PlatformAdmin::where('email', $email)->where('activo', true)->first();

        if ($admin === null || ! Hash::check($password, $admin->password)) {
            // El mismo mensaje para correo y contraseña: no se dice cuál falló.
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        $codigo = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $vence = now()->addMinutes(self::MINUTOS);

        Cache::put($this->clave($admin), [
            'hash' => Hash::make($codigo),
            'intentos' => 0,
            'vence' => $vence->getTimestamp(),
        ], $vence);

        Mail::to($admin->email)->send(new CodigoPlataformaMail($admin->nombre, $codigo, self::MINUTOS));
    }

    /** Paso 2: el código. Si vale, se consume y no vuelve a servir. */
    public function verificar(string $email, string $codigo): PlatformAdmin
    {
        $admin = PlatformAdmin::where('email', $email)->where('activo', true)->first();
        $guardado = $admin ? Cache::get($this->clave($admin)) : null;

        if ($admin === null || $guardado === null) {
            throw $this->codigoInvalido();
        }

        if (! Hash::check($codigo, $guardado['hash'])) {
            $guardado['intentos']++;

            if ($guardado['intentos'] >= self::MAX_INTENTOS) {
                Cache::forget($this->clave($admin));
            } else {
                // Conserva el vencimiento original: fallar no da más tiempo.
                Cache::put($this->clave($admin), $guardado, Carbon::createFromTimestamp($guardado['vence']));
            }

            throw $this->codigoInvalido();
        }

        Cache::forget($this->clave($admin));

        return $admin;
    }

    private function clave(PlatformAdmin $admin): string
    {
        return "plataforma:codigo:{$admin->id}";
    }

    private function codigoInvalido(): ValidationException
    {
        return ValidationException::withMessages([
            'codigo' => 'El código no es válido o ya caducó. Pide uno nuevo.',
        ]);
    }
}
