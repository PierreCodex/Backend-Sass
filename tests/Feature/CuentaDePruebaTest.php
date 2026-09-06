<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\Rol;
use App\Models\User;
use App\Models\Usuario;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    // El comando se niega fuera de `local` y los tests corren en `testing`.
    app()->detectEnvironment(fn () => 'local');
});

afterEach(fn () => limpiarBasesDeTenants());

/*
 * La prueba NO es que la fila exista: es que el login la deje pasar.
 *
 * El comando escribía `email_verified_at` con `create()`, y esa columna está
 * fuera de `$fillable` a propósito, así que Eloquent la descartaba EN
 * SILENCIO. La fila quedaba perfecta salvo por eso, el comando imprimía
 * «Cuenta lista» con las credenciales, y el login respondía «Verifica tu
 * correo». Un test que mirase la tabla habría pasado igual de contento.
 */
test('la cuenta que entrega puede iniciar sesión de verdad', function () {
    $this->artisan('tenant:cuenta-de-prueba', [
        'tenant' => $this->tenant->id,
        '--rol' => 'profesional',
    ])->assertSuccessful();

    $this->postJson('/api/login', [
        'email' => 'profesional@prueba.local',
        'password' => 'secreta123',
    ])
        ->assertOk()
        ->assertJsonPath('data.usuario.rol', 'profesional')
        ->assertJsonPath('data.usuario.negocio.id', $this->tenant->id);
});

test('deja la cuenta ligada a su rol dentro del negocio', function () {
    $this->artisan('tenant:cuenta-de-prueba', [
        'tenant' => $this->tenant->id,
        '--rol' => 'admin_local',
    ])->assertSuccessful();

    $central = User::where('email', 'admin_local@prueba.local')->firstOrFail();

    $clave = $this->tenant->run(
        fn () => Rol::find(Usuario::where('central_user_id', $central->id)->value('rol_id'))->clave
    );

    expect($clave)->toBe('admin_local');
});

/*
 * Un atajo de desarrollo que se salta una regla de seguridad es exactamente
 * como se cuelan: el comando escribe en la misma base que el panel.
 */
test('se niega a fabricar un segundo administrador general', function () {
    $this->artisan('tenant:cuenta-de-prueba', [
        'tenant' => $this->tenant->id,
        '--rol' => 'admin_general',
    ])->assertFailed();

    expect(User::where('email', 'admin_general@prueba.local')->exists())->toBeFalse();
});

test('fuera de local no crea nada', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('tenant:cuenta-de-prueba', [
        'tenant' => $this->tenant->id,
        '--rol' => 'profesional',
    ])->assertFailed();

    expect(User::where('email', 'profesional@prueba.local')->exists())->toBeFalse();
});
