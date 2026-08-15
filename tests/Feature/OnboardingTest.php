<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed();
    Mail::fake();
    Queue::fake();

    $this->postJson('/api/register', payloadRegistro());
    $this->user = User::where('email', 'maria@correo.pe')->firstOrFail();
    $this->user->forceFill(['email_verified_at' => now()])->save();
    $this->token = $this->user->createToken('panel')->plainTextToken;
});

function conSesion($test)
{
    return $test->withToken($test->token);
}

test('recién registrado: 6 pasos, 0 completados, en el orden del checklist', function () {
    conSesion($this)->getJson('/api/onboarding')
        ->assertOk()
        ->assertJsonPath('data.completado', false)
        ->assertJsonPath('data.pasos.0.clave', 'nombre_negocio')
        ->assertJsonPath('data.pasos.5.clave', 'sitio_publico')
        ->assertJsonCount(6, 'data.pasos')
        ->assertJsonMissing(['completado' => true]);
});

test('fijar el nombre deriva el slug, marca el paso 1 y es inmutable', function () {
    conSesion($this)->postJson('/api/onboarding/nombre', ['nombre' => 'Barbería El Cairo'])
        ->assertOk()
        ->assertJsonPath('data.nombre', 'Barbería El Cairo')
        ->assertJsonPath('data.slug', 'barberia-el-cairo');

    conSesion($this)->getJson('/api/onboarding')
        ->assertJsonPath('data.pasos.0.completado', true);

    // Segundo intento → 422 errors.nombre (el enlace de la tienda no cambia)
    conSesion($this)->postJson('/api/onboarding/nombre', ['nombre' => 'Otro Nombre'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nombre']);

    expect($this->user->tenant->refresh()->slug)->toBe('barberia-el-cairo');
});

test('colisión de slug → sufijo numérico', function () {
    Tenant::create([
        'plan_id' => App\Models\Plan::where('slug', 'prueba')->first()->id,
        'nombre' => 'Barbería El Cairo',
        'slug' => 'barberia-el-cairo',
    ]);

    conSesion($this)->postJson('/api/onboarding/nombre', ['nombre' => 'Barbería El Cairo'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'barberia-el-cairo-2');
});

test('un nombre cuyo slug está reservado recibe sufijo', function () {
    conSesion($this)->postJson('/api/onboarding/nombre', ['nombre' => 'Admin'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'admin-2');
});

test('el cliente solo puede marcar sitio_publico; los demás pasos → 422', function () {
    conSesion($this)->putJson('/api/onboarding/pasos/sitio_publico')
        ->assertOk()
        ->assertJsonPath('data.pasos.5.completado', true);

    // Idempotente
    conSesion($this)->putJson('/api/onboarding/pasos/sitio_publico')->assertOk();

    conSesion($this)->putJson('/api/onboarding/pasos/primer_servicio')
        ->assertStatus(422);

    conSesion($this)->putJson('/api/onboarding/pasos/inventada')
        ->assertStatus(422);
});

test('el onboarding exige sesión', function () {
    $this->getJson('/api/onboarding')->assertUnauthorized();
});
