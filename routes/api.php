<?php

declare(strict_types=1);

use App\Http\Controllers\ArchivoTenantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\InvitacionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\Auth\VerificacionCorreoController;
use App\Http\Controllers\Catalogo\CategoriaServicioController;
use App\Http\Controllers\Catalogo\ServicioController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\LocalController;
use App\Http\Controllers\LocalProfesionalController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ProfesionalController;
use App\Http\Controllers\Publico\CategoriasNegocioController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sin sesión (registro, verificación, login, recuperación)
|--------------------------------------------------------------------------
*/

/*
 * Imagenes del catalogo. Sin sesion: la tienda publica las muestra a
 * visitantes sin cuenta. El tenant va en la ruta porque cada negocio
 * tiene su carpeta y un enlace simbolico solo apuntaria a una.
 *
 * Limitador PROPIO y con NOMBRE (definido en AppServiceProvider), fuera del
 * grupo de abajo. Un `throttle:300,1` numerico no habria bastado: sin nombre,
 * la clave es `dominio|ip` y todas las rutas comparten el mismo contador —
 * solo cambia el techo. Una pagina de la tienda con 12 fotos gastaba 12 golpes
 * del cupo de /login y dejaba al visitante sin poder entrar durante el resto
 * del minuto.
 */
Route::middleware('throttle:archivos')->group(function () {
    Route::get('/archivos/{tenant}/{ruta}', ArchivoTenantController::class)
        ->where('ruta', '.*')
        ->name('archivos.tenant');
});

Route::middleware('throttle:20,1')->group(function () {
    Route::get('/publico/categorias-negocio', CategoriasNegocioController::class);

    Route::post('/register', RegistroController::class);
    Route::post('/email/verificar', [VerificacionCorreoController::class, 'verificar']);
    Route::post('/email/reenviar', [VerificacionCorreoController::class, 'reenviar']);

    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [PasswordController::class, 'forgot']);
    Route::post('/reset-password', [PasswordController::class, 'reset']);

    // El empleado invitado elige su contrasena. Publico como el reset:
    // todavia no puede iniciar sesion, que es justo lo que viene a arreglar.
    Route::post('/invitacion/aceptar', [InvitacionController::class, 'aceptar']);
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

    // Cambiar la contraseña es una acción de SEGURIDAD: no se bloquea por
    // deber una suscripción. Editar el perfil sí es panel, y va tras la puerta.
    Route::put('/user/password', [PerfilController::class, 'password']);

    /*
     * El panel propiamente dicho: 403 suscripcion_vencida si el tenant está
     * suspendido.
     */
    Route::middleware('suscripcion.activa')->group(function () {
        Route::put('/user', [PerfilController::class, 'actualizar']);

        /*
         * Recursos que viven en la BD del negocio. `tenancy.init` va aqui y
         * no en el grupo de arriba: /user, /logout y el onboarding se
         * resuelven enteros en la central, y conectar a la BD del tenant
         * para nada tiene un coste — ademas de fallar con 503 mientras el
         * provisioning no ha terminado.
         */
        Route::middleware('tenancy.init')->group(function () {
            /*
             * Los datos del negocio viven en la BD CENTRAL, no en la del
             * tenant. Aun asi va dentro de `tenancy.init`: el logo y la
             * portada se guardan en el disco del negocio, y
             * `Storage::disk('public')` solo apunta a su carpeta con tenancy
             * inicializada.
             *
             * Sin `{id}`: el negocio sale del token. No hay ruta que apunte a
             * otro.
             */
            Route::get('configuracion', [ConfiguracionController::class, 'show']);
            Route::put('configuracion', [ConfiguracionController::class, 'update']);

            Route::apiResource('categorias-servicios', CategoriaServicioController::class)
                ->parameters(['categorias-servicios' => 'categoria']);

            Route::apiResource('servicios', ServicioController::class);
            Route::apiResource('clientes', ClienteController::class);

            /*
             * Roles del negocio. Van con Empleados porque alimentan su select
             * de rol; el `apiResource` es del dueno y nadie mas (la barandilla
             * esta en el controlador, no aqui: es cuestion de rango, no de
             * ruta).
             */
            Route::apiResource('roles', RolController::class)
                // Sin esto el parametro seria `{role}` (Str::singular en ingles)
                // y el binding no casaria con `Rol $rol` del controlador.
                ->parameters(['roles' => 'rol']);

            // Las sedes del negocio. El principal lo decide el backend y no
            // se borra.
            Route::apiResource('locales', LocalController::class)
                ->parameters(['locales' => 'local']);

            /*
             * Quien atiende en cada sede. Sin `store` ni `destroy`: el PUT hace
             * `syncWithoutDetaching`, asi que asigna y edita a la vez, y para
             * sacar a alguien se apaga `habilitado`.
             */
            Route::get('locales/{local}/profesionales', [LocalProfesionalController::class, 'index']);
            Route::put('locales/{local}/profesionales/{profesional}', [LocalProfesionalController::class, 'update']);

            // Agrupaciones de locales, profesionales y servicios. Hoy no las
            // consulta nadie mas que su propia pantalla.
            Route::apiResource('grupos', GrupoController::class);

            /*
             * Quien ENTRA al panel. Distinto de los profesionales: una
             * recepcionista esta aqui y no alli, y un barbero sin cuenta al
             * reves.
             */
            Route::post('usuarios/{usuario}/invitacion', [UsuarioController::class, 'invitar']);
            Route::apiResource('usuarios', UsuarioController::class);

            /*
             * ANTES del apiResource: si no, `profesionales/{profesional}` se
             * traga `profesionales/resumen` y el binding intenta buscar uno
             * llamado "resumen".
             */
            Route::get('profesionales/resumen', [ProfesionalController::class, 'resumen']);
            Route::apiResource('profesionales', ProfesionalController::class)
                // Sin esto el parametro seria `{profesionale}`: Str::singular
                // no sabe castellano.
                ->parameters(['profesionales' => 'profesional']);
        });

        Route::get('/onboarding', [OnboardingController::class, 'show']);
        Route::post('/onboarding/nombre', [OnboardingController::class, 'nombre']);
        Route::put('/onboarding/pasos/{clave}', [OnboardingController::class, 'marcarPaso']);
    });
});
