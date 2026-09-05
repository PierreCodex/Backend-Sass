<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Una petición COMO OTRO usuario, dentro del mismo test.
 *
 * El guard de Sanctum resuelve el usuario una vez y lo cachea en la instancia
 * de la aplicación, que en los tests se reutiliza entre peticiones. Sin esto,
 * cambiar de token no cambia de usuario: la segunda petición sigue siendo la
 * primera persona, con su rol y con su tenant ya cargado — así que un test de
 * permisos pasa en verde midiendo la caché en vez del middleware.
 *
 * En producción no ocurre: cada petición es un proceso nuevo. Es una trampa
 * exclusiva de los tests, y ya nos costó dos veces.
 */
function comoOtro(object $test, string $token)
{
    // Por el contenedor y no por `$test->app`, que es protegido.
    app('auth')->forgetGuards();

    return $test->withToken($token);
}

/**
 * Borra las BDs físicas (tenant_{id}) de todos los tenants creados por un
 * test. RefreshDatabase revierte la central, pero las BDs de tenant son
 * reales y hay que eliminarlas a mano.
 */
function limpiarBasesDeTenants(): void
{
    /*
     * Soltar la conexion ANTES de borrar. El middleware inicializa tenancy
     * y nadie la termina al acabar la peticion, asi que la conexion `tenant`
     * sigue viva apuntando a una base que estamos a punto de tirar — y el
     * test siguiente hereda ese puntero muerto (1049 Unknown database).
     */
    tenancy()->end();
    DB::purge('tenant');

    foreach (Tenant::withTrashed()->get() as $tenant) {
        $manager = $tenant->database()->manager();

        if ($manager->databaseExists($tenant->database()->getName())) {
            $manager->deleteDatabase($tenant);
        }
    }
}
