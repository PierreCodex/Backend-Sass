<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\User;
use App\Support\FirmaVerificacion;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed();
    Mail::fake();
    Queue::fake();

    $this->postJson('/api/register', payloadRegistro());
    $this->user = User::where('email', 'maria@correo.pe')->firstOrFail();
});

test('verificar con firma válida marca el correo y encola el provisioning', function () {
    $params = FirmaVerificacion::parametros($this->user);

    $this->postJson('/api/email/verificar', $params)->assertOk();

    expect($this->user->refresh()->email_verified_at)->not->toBeNull();

    Queue::assertPushed(ProvisionTenantDatabase::class, 1);
});

test('verificar dos veces es idempotente: no re-encola el job', function () {
    $params = FirmaVerificacion::parametros($this->user);

    $this->postJson('/api/email/verificar', $params)->assertOk();
    $this->postJson('/api/email/verificar', $params)->assertOk();

    Queue::assertPushed(ProvisionTenantDatabase::class, 1);
});

test('firma manipulada → 422 y no encola nada', function () {
    $params = FirmaVerificacion::parametros($this->user);
    $params['signature'] = str_repeat('0', 64);

    $this->postJson('/api/email/verificar', $params)->assertStatus(422);

    expect($this->user->refresh()->email_verified_at)->toBeNull();
    Queue::assertNothingPushed();
});

test('enlace vencido → 422', function () {
    $params = FirmaVerificacion::parametros($this->user);

    $this->travel(49)->hours();

    $this->postJson('/api/email/verificar', $params)->assertStatus(422);
    Queue::assertNothingPushed();
});

test('reenviar responde 200 exista o no el correo (no filtra usuarios)', function () {
    Mail::fake();

    $this->postJson('/api/email/reenviar', ['email' => 'maria@correo.pe'])->assertOk();
    $this->postJson('/api/email/reenviar', ['email' => 'nadie@correo.pe'])->assertOk();

    Mail::assertQueuedCount(1);
});

test('reenviar dos veces seguidas al mismo correo → 429 con retry_after', function () {
    Mail::fake();

    $this->postJson('/api/email/reenviar', ['email' => 'maria@correo.pe'])
        ->assertOk()
        ->assertJsonPath('retry_after', 60);

    $this->postJson('/api/email/reenviar', ['email' => 'maria@correo.pe'])
        ->assertStatus(429)
        ->assertJsonStructure(['message', 'retry_after']);

    Mail::assertQueuedCount(1);
});

test('el cooldown de reenviar es por correo, no global', function () {
    Mail::fake();

    $this->postJson('/api/email/reenviar', ['email' => 'maria@correo.pe'])->assertOk();
    $this->postJson('/api/email/reenviar', ['email' => 'otra@correo.pe'])->assertOk();
});

test('pasado el cooldown se puede volver a pedir el enlace', function () {
    Mail::fake();

    $this->postJson('/api/email/reenviar', ['email' => 'maria@correo.pe'])->assertOk();

    $this->travel(61)->seconds();

    $this->postJson('/api/email/reenviar', ['email' => 'maria@correo.pe'])->assertOk();

    Mail::assertQueuedCount(2);
});

test('el correo inexistente también respeta el cooldown (no filtra usuarios)', function () {
    $this->postJson('/api/email/reenviar', ['email' => 'nadie@correo.pe'])->assertOk();
    $this->postJson('/api/email/reenviar', ['email' => 'nadie@correo.pe'])->assertStatus(429);
});
