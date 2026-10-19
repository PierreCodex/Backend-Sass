<?php

use App\Mail\CodigoPlataformaMail;
use App\Models\PlatformAdmin;
use App\Services\AccesoPlataformaService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    $this->admin = PlatformAdmin::create([
        'nombre' => 'Admin Plataforma',
        'email' => 'admin@chiraflow.pe',
        'password' => 'clave-segura-123',
        'rol' => 'superadmin',
    ]);
});

/** Pide un código y devuelve el que salió en el correo. */
function pedirCodigo(object $test): string
{
    $test->postJson('/api/plataforma/acceso/codigo', [
        'email' => 'admin@chiraflow.pe',
        'password' => 'clave-segura-123',
    ])->assertStatus(202);

    $codigo = null;
    Mail::assertQueued(CodigoPlataformaMail::class, function ($mail) use (&$codigo) {
        $codigo = $mail->codigo;

        return $mail->hasTo('admin@chiraflow.pe');
    });

    return $codigo;
}

test('con la contraseña correcta llega un código de 6 dígitos al correo', function () {
    expect(pedirCodigo($this))->toMatch('/^\d{6}$/');
});

test('con la contraseña mal no se manda nada, y el error no dice qué falló', function () {
    $this->postJson('/api/plataforma/acceso/codigo', [
        'email' => 'admin@chiraflow.pe',
        'password' => 'otra',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);

    $this->postJson('/api/plataforma/acceso/codigo', [
        'email' => 'nadie@chiraflow.pe',
        'password' => 'clave-segura-123',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);

    Mail::assertNothingQueued();
});

test('el código correcto deja entrar y no sirve una segunda vez', function () {
    $codigo = pedirCodigo($this);

    $this->postJson('/api/plataforma/acceso/verificar', ['email' => 'admin@chiraflow.pe', 'codigo' => $codigo])
        ->assertOk()
        ->assertJsonPath('data.email', 'admin@chiraflow.pe')
        ->assertJsonPath('data.rol', 'superadmin')
        ->assertJsonMissingPath('data.password');

    $this->postJson('/api/plataforma/acceso/verificar', ['email' => 'admin@chiraflow.pe', 'codigo' => $codigo])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['codigo']);
});

test('tras demasiados intentos fallidos el código queda anulado aunque luego se acierte', function () {
    $codigo = pedirCodigo($this);
    $malo = $codigo === '000000' ? '111111' : '000000';

    for ($i = 0; $i < AccesoPlataformaService::MAX_INTENTOS; $i++) {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
            ->postJson('/api/plataforma/acceso/verificar', ['email' => 'admin@chiraflow.pe', 'codigo' => $malo])
            ->assertStatus(422);
    }

    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
        ->postJson('/api/plataforma/acceso/verificar', ['email' => 'admin@chiraflow.pe', 'codigo' => $codigo])
        ->assertStatus(422);
});

test('el código caduca a los pocos minutos', function () {
    $codigo = pedirCodigo($this);

    $this->travel(AccesoPlataformaService::MINUTOS + 1)->minutes();

    $this->postJson('/api/plataforma/acceso/verificar', ['email' => 'admin@chiraflow.pe', 'codigo' => $codigo])
        ->assertStatus(422);
});

test('pedir un código nuevo anula el anterior', function () {
    $viejo = pedirCodigo($this);
    Mail::fake();
    $nuevo = pedirCodigo($this);

    if ($viejo !== $nuevo) {
        $this->postJson('/api/plataforma/acceso/verificar', ['email' => 'admin@chiraflow.pe', 'codigo' => $viejo])
            ->assertStatus(422);
    }

    $this->postJson('/api/plataforma/acceso/verificar', ['email' => 'admin@chiraflow.pe', 'codigo' => $nuevo])
        ->assertOk();
});

test('un administrador desactivado no recibe código', function () {
    $this->admin->update(['activo' => false]);

    $this->postJson('/api/plataforma/acceso/codigo', [
        'email' => 'admin@chiraflow.pe',
        'password' => 'clave-segura-123',
    ])->assertStatus(422);

    Mail::assertNothingQueued();
});
