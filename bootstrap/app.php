<?php

use App\Http\Middleware\InicializarTenancy;
use App\Http\Middleware\VerificarCapacidad;
use App\Http\Middleware\SuscripcionActiva;
use App\Http\Middleware\ValidarTenantDelToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(function (Request $request) {
            return $request->is('api/*') ? null : route('login');
        });

        $middleware->alias([
            'tenant.token' => ValidarTenantDelToken::class,
            'suscripcion.activa' => SuscripcionActiva::class,
            'tenancy.init' => InicializarTenancy::class,
            // `puede:clientes,gestionar` — la puerta de cada modulo.
            'puede' => VerificarCapacidad::class,
        ]);

        /*
         * Sin esto, SubstituteBindings resuelve el modelo ANTES de que
         * InicializarTenancy cambie de conexion, y Laravel busca la categoria
         * en la BD central — donde esa tabla ni existe. El orden de los alias
         * en la ruta no manda: la lista de prioridad si.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: InicializarTenancy::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Necesitas iniciar sesión para continuar.',
                    'codigo' => 'no_autenticado',
                ], 401);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Has superado el límite de solicitudes. Inténtalo de nuevo más tarde.',
                    'codigo' => 'limite_solicitudes',
                ], 429);
            }
        });
    })->create();
