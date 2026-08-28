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
