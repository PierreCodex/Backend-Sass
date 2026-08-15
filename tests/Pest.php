<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
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
    foreach (App\Models\Tenant::withTrashed()->get() as $tenant) {
        $manager = $tenant->database()->manager();

        if ($manager->databaseExists($tenant->database()->getName())) {
            $manager->deleteDatabase($tenant);
        }
    }
}
