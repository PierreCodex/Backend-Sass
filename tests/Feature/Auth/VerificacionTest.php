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

    Mail::assertSentCount(1);
});
