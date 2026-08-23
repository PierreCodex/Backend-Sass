<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed();
    Mail::fake();
    Queue::fake();

    $this->postJson('/api/register', payloadRegistro());
    $this->user = User::where('email', 'maria@correo.pe')->firstOrFail();
});

function verificarCorreoDe(User $user): void
{
    $user->forceFill(['email_verified_at' => now()])->save();
}

test('login sin verificar el correo → 403 con mensaje claro', function () {
    $this->postJson('/api/login', ['email' => 'maria@correo.pe', 'password' => 'secreta123'])
        ->assertStatus(403)
        ->assertJsonStructure(['message']);
});

test('login verificado → { data: { token, usuario } } con negocio.id para el X-Tenant', function () {
    verificarCorreoDe($this->user);

    $respuesta = $this->postJson('/api/login', [
        'email' => 'maria@correo.pe',
        'password' => 'secreta123',
    ]);

    $respuesta->assertOk()
        ->assertJsonStructure(['data' => ['token', 'usuario' => ['id', 'name', 'email', 'rol', 'negocio']]])
        ->assertJsonPath('data.usuario.negocio.id', $this->user->tenant_id)
        ->assertJsonPath('data.usuario.negocio.nombre', null); // aún sin onboarding
});

test('credenciales malas → 422 errors.email', function () {
    verificarCorreoDe($this->user);

    $this->postJson('/api/login', ['email' => 'maria@correo.pe', 'password' => 'incorrecta'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('logout revoca SOLO el token en uso', function () {
    verificarCorreoDe($this->user);

    $tokenA = $this->user->createToken('panel')->plainTextToken;
    $tokenB = $this->user->createToken('panel')->plainTextToken;

    $this->withToken($tokenA)->postJson('/api/logout')->assertNoContent();

    $this->app->make('auth')->forgetGuards(); // limpiar el usuario cacheado del guard

    $this->withToken($tokenA)->getJson('/api/user')->assertUnauthorized();
    $this->app->make('auth')->forgetGuards();
    $this->withToken($tokenB)->getJson('/api/user')->assertOk();
});

test('GET /user devuelve el Usuario del contrato; sin token → 401', function () {
    verificarCorreoDe($this->user);
    $token = $this->user->createToken('panel')->plainTextToken;

    $this->withToken($token)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.name', 'María Quispe');

    $this->flushHeaders(); // withToken deja el Authorization pegado entre requests
    $this->app->make('auth')->forgetGuards();
    $this->getJson('/api/user')->assertUnauthorized();
});

test('X-Tenant que no coincide con el tenant del token → 404, nunca 403', function () {
    verificarCorreoDe($this->user);
    $token = $this->user->createToken('panel')->plainTextToken;

    $this->withToken($token)->withHeader('X-Tenant', 'otroid123')
        ->getJson('/api/user')
        ->assertNotFound();

    $this->app->make('auth')->forgetGuards();

    $this->withToken($token)->withHeader('X-Tenant', $this->user->tenant_id)
        ->getJson('/api/user')
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Ciclo de vida del negocio (traspaso FE → BE del 2026-08-22)
|
| Decisión de producto: el suspendido ENTRA y paga; si se le cierra el login
| no tiene por dónde regularizar. El corte del panel lo hace el middleware
| `suscripcion.activa`. Los estados de purga sí bloquean el login.
|--------------------------------------------------------------------------
*/

test('tenant suspendido → login OK, y el usuario ve negocio.estado = vencida', function () {
    verificarCorreoDe($this->user);
    $this->user->tenant->update(['estado' => 'suspendida']);

    $this->postJson('/api/login', ['email' => 'maria@correo.pe', 'password' => 'secreta123'])
        ->assertOk()
        ->assertJsonPath('data.usuario.negocio.estado', 'vencida');
});

test('tenant suspendido → el panel responde 403 suscripcion_vencida', function () {
    verificarCorreoDe($this->user);
    $this->user->tenant->update(['estado' => 'suspendida']);

    $token = $this->postJson('/api/login', [
        'email' => 'maria@correo.pe',
        'password' => 'secreta123',
    ])->json('data.token');

    $this->withToken($token)->getJson('/api/onboarding')
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'suscripcion_vencida');
});

test('tenant suspendido: /user y /logout siguen abiertos (por ahí paga)', function () {
    verificarCorreoDe($this->user);
    $this->user->tenant->update(['estado' => 'suspendida']);

    $token = $this->postJson('/api/login', [
        'email' => 'maria@correo.pe',
        'password' => 'secreta123',
    ])->json('data.token');

    $this->withToken($token)->getJson('/api/user')->assertOk();
    $this->withToken($token)->postJson('/api/logout')->assertNoContent();
});

test('tenant en purga → 403 y NO se emite token', function () {
    verificarCorreoDe($this->user);
    $this->user->tenant->update(['estado' => 'purga_pendiente']);

    $this->postJson('/api/login', ['email' => 'maria@correo.pe', 'password' => 'secreta123'])
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'cuenta_dada_de_baja')
        ->assertJsonMissingPath('data.token');
});

test('tenant activo → el panel no se corta', function () {
    verificarCorreoDe($this->user);
    $this->user->tenant->update(['estado' => 'activa']);

    $token = $this->postJson('/api/login', [
        'email' => 'maria@correo.pe',
        'password' => 'secreta123',
    ])->json('data.token');

    $this->withToken($token)->getJson('/api/onboarding')->assertOk();
});
