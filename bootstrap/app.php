<?php

use App\Http\Middleware\InicializarTenancy;
use App\Http\Middleware\SuscripcionActiva;
use App\Http\Middleware\ValidarTenantDelToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant.token' => ValidarTenantDelToken::class,
            'suscripcion.activa' => SuscripcionActiva::class,
            'tenancy.init' => InicializarTenancy::class,
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
        //
    })->create();
