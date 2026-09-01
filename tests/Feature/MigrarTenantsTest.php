<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
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

test('un tenant que revienta no deja sin migrar a los que vienen detrás', function () {
    /*
     * El roto va PRIMERO: es el orden lo que prueba que la tanda continúa. El
     * comando recorre los tenants en el orden en que se registraron.
     */
    $roto = crearTenantRegistrado($this->plan);
    crearDueno($roto);
    (new ProvisionTenantDatabase($roto))->handle();

    $sano = crearTenantRegistrado($this->plan);
    crearDueno($sano);
    (new ProvisionTenantDatabase($sano))->handle();

    /*
     * Al roto le borramos la FILA de `migrations` pero le dejamos la tabla: la
     * migración se cree pendiente, intenta el CREATE TABLE y choca con que ya
     * existe. Es el caso del docblock del comando —una migración que se pelea
     * con el estado de ESE negocio— y falla lanzando, no devolviendo un código:
     * la consola de Laravel corre con `setCatchExceptions(false)`, así que la
     * excepción sube por `Artisan::call`. Sin el try/catch se llevaba por
     * delante la tanda entera.
     */
    $roto->run(function () {
        DB::table('migrations')->where('migration', 'like', '%plantilla_whatsapps%')->delete();
    });

    // Al sano le dejamos una migración pendiente de verdad, esta sí sana.
    $sano->run(function () {
        Schema::drop('plantilla_whatsapps');
        DB::table('migrations')->where('migration', 'like', '%plantilla_whatsapps%')->delete();
    });

    $this->artisan('tenants:migrar-provisionados')->assertFailed();

    // Lo que importa: el que venía detrás del fallo SÍ se migró.
    expect($sano->run(fn () => Schema::hasTable('plantilla_whatsapps')))->toBeTrue();
});
