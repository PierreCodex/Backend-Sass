<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\Auth\VerificacionCorreoController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Publico\CategoriasNegocioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sin sesión (registro, verificación, login, recuperación)
|--------------------------------------------------------------------------
*/

Route::middleware('throttle:20,1')->group(function () {
    Route::get('/publico/categorias-negocio', CategoriasNegocioController::class);

    Route::post('/register', RegistroController::class);
    Route::post('/email/verificar', [VerificacionCorreoController::class, 'verificar']);
    Route::post('/email/reenviar', [VerificacionCorreoController::class, 'reenviar']);

    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [PasswordController::class, 'forgot']);
    Route::post('/reset-password', [PasswordController::class, 'reset']);
});

/*
|--------------------------------------------------------------------------
| Panel (Bearer del BFF). El tenant se deriva del token; X-Tenant es pista
| redundante que SIEMPRE se valida (middleware tenant.token).
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'tenant.token'])->group(function () {
    /*
     * Accesible AUNQUE la suscripción esté vencida. Si se cortara todo, el
     * negocio suspendido no tendría por dónde pagar. Aquí van también
     * /plan y /soporte cuando lleguen (Sprint 7).
     */
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    /*
     * El panel propiamente dicho: 403 suscripcion_vencida si el tenant está
     * suspendido.
     */
    Route::middleware('suscripcion.activa')->group(function () {
        Route::get('/onboarding', [OnboardingController::class, 'show']);
        Route::post('/onboarding/nombre', [OnboardingController::class, 'nombre']);
        Route::put('/onboarding/pasos/{clave}', [OnboardingController::class, 'marcarPaso']);
    });
});
