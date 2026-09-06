<?php

declare(strict_types=1);

use App\Http\Controllers\ArchivoTenantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\InvitacionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\Auth\VerificacionCorreoController;
use App\Http\Controllers\CapacidadesController;
use App\Http\Controllers\Catalogo\CategoriaServicioController;
use App\Http\Controllers\Catalogo\ServicioController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\InventarioController;
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
            Route::get('configuracion', [ConfiguracionController::class, 'show'])
                ->middleware('puede:configuracion');
            Route::put('configuracion', [ConfiguracionController::class, 'update'])
                ->middleware('puede:configuracion,gestionar');

            /*
             * Lo que puede hacer quien esta mirando, ya resuelto. El panel lo
             * pide una vez para armar su menu — pero esconder una opcion NO es
             * autorizacion: cada endpoint comprueba lo suyo igual.
             */
            Route::get('capacidades', CapacidadesController::class);

            /*
              * Cada modulo tras su capacidad. `ver` para leer y `gestionar`
              * para escribir — y `gestionar` incluye `ver`, asi que no hace
              * falta anotar los index dos veces.
              */
            Route::middleware('puede:servicios')->group(function () {
                Route::get('categorias-servicios', [CategoriaServicioController::class, 'index']);
                Route::get('categorias-servicios/{categoria}', [CategoriaServicioController::class, 'show']);
                Route::get('servicios', [ServicioController::class, 'index']);
                Route::get('servicios/{servicio}', [ServicioController::class, 'show']);
            });

            Route::middleware('puede:servicios,gestionar')->group(function () {
                Route::post('categorias-servicios', [CategoriaServicioController::class, 'store']);
                Route::match(['put', 'patch'], 'categorias-servicios/{categoria}', [CategoriaServicioController::class, 'update']);
                Route::delete('categorias-servicios/{categoria}', [CategoriaServicioController::class, 'destroy']);

                Route::post('servicios', [ServicioController::class, 'store']);
                Route::match(['put', 'patch'], 'servicios/{servicio}', [ServicioController::class, 'update']);
                Route::delete('servicios/{servicio}', [ServicioController::class, 'destroy']);
            });

            Route::middleware('puede:clientes')->group(function () {
                Route::get('clientes', [ClienteController::class, 'index']);
                Route::get('clientes/{cliente}', [ClienteController::class, 'show']);
            });

            Route::middleware('puede:clientes,gestionar')->group(function () {
                Route::post('clientes', [ClienteController::class, 'store']);
                Route::match(['put', 'patch'], 'clientes/{cliente}', [ClienteController::class, 'update']);
                Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy']);
            });

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
            Route::middleware('puede:locales')->group(function () {
                Route::get('locales', [LocalController::class, 'index']);
                Route::get('locales/{local}', [LocalController::class, 'show']);
            });

            Route::middleware('puede:locales,gestionar')->group(function () {
                Route::post('locales', [LocalController::class, 'store']);
                Route::match(['put', 'patch'], 'locales/{local}', [LocalController::class, 'update']);
                Route::delete('locales/{local}', [LocalController::class, 'destroy']);
            });

            /*
             * Quien atiende en cada sede. Sin `store` ni `destroy`: el PUT hace
             * `syncWithoutDetaching`, asi que asigna y edita a la vez, y para
             * sacar a alguien se apaga `habilitado`.
             */
            Route::get('locales/{local}/profesionales', [LocalProfesionalController::class, 'index'])
                ->middleware('puede:locales');
            Route::put('locales/{local}/profesionales/{profesional}', [LocalProfesionalController::class, 'update'])
                ->middleware('puede:locales,gestionar');

            // Agrupaciones de locales, profesionales y servicios. Hoy no las
            // consulta nadie mas que su propia pantalla.
            Route::middleware('puede:locales')->group(function () {
                Route::get('grupos', [GrupoController::class, 'index']);
                Route::get('grupos/{grupo}', [GrupoController::class, 'show']);
            });

            Route::middleware('puede:locales,gestionar')->group(function () {
                Route::post('grupos', [GrupoController::class, 'store']);
                Route::match(['put', 'patch'], 'grupos/{grupo}', [GrupoController::class, 'update']);
                Route::delete('grupos/{grupo}', [GrupoController::class, 'destroy']);
            });

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
            Route::middleware('puede:empleados')->group(function () {
                Route::get('profesionales/resumen', [ProfesionalController::class, 'resumen']);
                Route::get('profesionales', [ProfesionalController::class, 'index']);
                Route::get('profesionales/{profesional}', [ProfesionalController::class, 'show']);
            });

            Route::middleware('puede:empleados,gestionar')->group(function () {
                Route::post('profesionales', [ProfesionalController::class, 'store']);
                Route::match(['put', 'patch'], 'profesionales/{profesional}', [ProfesionalController::class, 'update']);
                Route::delete('profesionales/{profesional}', [ProfesionalController::class, 'destroy']);
            });

            // Productos y su stock. La ruta es `inventario` y el parametro
            // `producto`: el modulo se llama como la pantalla, la entidad como
            // lo que es.
            Route::middleware('puede:inventario')->group(function () {
                Route::get('inventario', [InventarioController::class, 'index']);
                Route::get('inventario/{producto}', [InventarioController::class, 'show']);
            });

            Route::middleware('puede:inventario,gestionar')->group(function () {
                Route::post('inventario', [InventarioController::class, 'store']);
                Route::match(['put', 'patch'], 'inventario/{producto}', [InventarioController::class, 'update']);
                Route::delete('inventario/{producto}', [InventarioController::class, 'destroy']);

                /*
                 * Mover stock es GESTIONAR, no ver: es la escritura de verdad
                 * del modulo. El CRUD cambia como se llama y cuanto cuesta un
                 * producto; esto cambia cuantos hay.
                 */
                Route::post('inventario/{producto}/movimiento', [InventarioController::class, 'movimiento']);
            });
        });

        Route::get('/onboarding', [OnboardingController::class, 'show']);
        Route::post('/onboarding/nombre', [OnboardingController::class, 'nombre']);
        Route::put('/onboarding/pasos/{clave}', [OnboardingController::class, 'marcarPaso']);
    });
});
