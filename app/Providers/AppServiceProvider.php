<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        /*
         * Cubo PROPIO para las imagenes del catalogo.
         *
         * Tiene que ser un limitador CON NOMBRE, no un `throttle:300,1` suelto:
         * el identificador que usa `throttle` sin nombre es `dominio|ip`, no
         * incluye la ruta ni el maximo, asi que todas las rutas con throttle
         * numerico comparten el MISMO contador — solo cambia el techo contra el
         * que se compara. Con las imagenes ahi dentro, una pagina de la tienda
         * con doce fotos gastaba doce golpes del cupo de /login y dejaba al
         * visitante fuera del panel durante el resto del minuto. Peor detras del
         * NAT de una oficina, donde muchos salen por la misma IP.
         *
         * Los limitadores con nombre prefijan su clave, asi que este cuenta
         * aparte de verdad.
         */
        RateLimiter::for('archivos', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        // El enlace de reset apunta al FRONTEND, que luego POSTea
        // token+email a /reset-password (contrato § Autenticación).
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return config('app.frontend_url').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);
        });
    }
}
