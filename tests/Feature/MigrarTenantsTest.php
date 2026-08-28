<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->plan = Plan::where('slug', 'prueba')->firstOrFail();
});

afterEach(fn () => limpiarBasesDeTenants());

test('migrar tenants salta los que se registraron y no verificaron el correo', function () {
    // Uno con BD y otro a medio registrar. El segundo es el caso NORMAL:
    // siempre habra gente que se registra y no verifica.
    $conBd = crearTenantRegistrado($this->plan);
    crearDueno($conBd);
    (new ProvisionTenantDatabase($conBd))->handle();

    $sinBd = crearTenantRegistrado($this->plan);

    expect($sinBd->db_provisionada)->toBeFalse();

    /*
     * `tenants:migrate` de stancl aborta en el primero sin base y deja sin
     * migrar a TODOS los que vienen detras, en silencio. Este no.
     */
    $this->artisan('tenants:migrar-provisionados')
        ->expectsOutputToContain('se registraron y aun no verifican')
        ->assertSuccessful();

    expect($conBd->run(fn () => Schema::hasTable('roles')))->toBeTrue();
});
