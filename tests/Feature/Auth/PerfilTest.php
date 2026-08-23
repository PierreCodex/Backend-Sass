<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed();
    Mail::fake();
    Queue::fake();

    $this->postJson('/api/register', payloadRegistro());
    $this->user = User::where('email', 'maria@correo.pe')->firstOrFail();
    $this->user->forceFill(['email_verified_at' => now()])->save();

    $this->token = $this->postJson('/api/login', [
        'email' => 'maria@correo.pe',
        'password' => 'secreta123',
    ])->json('data.token');
});

function perfilValido(array $extra = []): array
{
    return array_merge([
        'nombre' => 'María Fernanda',
        'apellido' => 'Quispe Rojas',
        'telefono' => '+51999888777',
        'documento' => '70123456',
        'foto' => null,
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| GET /user — la forma completa del contrato
|--------------------------------------------------------------------------
*/

test('GET /user trae nombre y apellido SUELTOS, telefono, documento y negocio.slug', function () {
    $this->withToken($this->token)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.nombre', 'María')
        ->assertJsonPath('data.apellido', 'Quispe')
        ->assertJsonPath('data.name', 'María Quispe')
        ->assertJsonPath('data.documento', null)
        ->assertJsonPath('data.negocio.slug', null); // hasta el paso 1 del onboarding
});

/*
|--------------------------------------------------------------------------
| PUT /user
|--------------------------------------------------------------------------
*/

test('PUT /user guarda y devuelve { data: Usuario }', function () {
    $this->withToken($this->token)->putJson('/api/user', perfilValido())
        ->assertOk()
        ->assertJsonPath('data.nombre', 'María Fernanda')
        ->assertJsonPath('data.apellido', 'Quispe Rojas')
        ->assertJsonPath('data.name', 'María Fernanda Quispe Rojas')
        ->assertJsonPath('data.telefono', '+51999888777')
        ->assertJsonPath('data.documento', '70123456');

    expect($this->user->refresh()->documento)->toBe('70123456');
});

test('PUT /user NO deja cambiar email ni rol aunque viajen en el payload', function () {
    $this->withToken($this->token)
        ->putJson('/api/user', perfilValido([
            'email' => 'otra@correo.pe',
            'rol' => 'admin',
        ]))
        ->assertOk();

    $this->user->refresh();
    expect($this->user->email)->toBe('maria@correo.pe');
    expect($this->user->rol)->toBe('dueno');
});

test('PUT /user valida: nombre vacío y teléfono mal formado → 422 por campo', function () {
    $this->withToken($this->token)
        ->putJson('/api/user', perfilValido(['nombre' => '', 'telefono' => '999888777']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nombre', 'telefono']);
});

test('PUT /user acepta documento de carné de extranjería (no solo DNI de 8)', function () {
    $this->withToken($this->token)
        ->putJson('/api/user', perfilValido(['documento' => '001234567890']))
        ->assertOk()
        ->assertJsonPath('data.documento', '001234567890');
});

test('PUT /user propaga nombre, teléfono y foto a profesionales del tenant', function () {
    // Aquí hace falta la BD real del tenant: el job corre en línea.
    (new ProvisionTenantDatabase($this->user->tenant))->handle();

    $this->withToken($this->token)
        ->putJson('/api/user', perfilValido(['foto' => 'avatares/maria.jpg']))
        ->assertOk();

    $fila = $this->user->tenant->run(fn () => DB::table('profesionales')
        ->where('central_user_id', $this->user->id)
        ->first());

    expect($fila->nombre)->toBe('María Fernanda Quispe Rojas');
    expect($fila->telefono)->toBe('+51999888777');
    expect($fila->foto)->toBe('avatares/maria.jpg');
});

test('PUT /user sin sesión → 401', function () {
    $this->putJson('/api/user', perfilValido())->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| PUT /user/password
|--------------------------------------------------------------------------
*/

test('cambiar contraseña con la actual correcta → 200 y la nueva sirve para entrar', function () {
    $this->withToken($this->token)->putJson('/api/user/password', [
        'password_actual' => 'secreta123',
        'password' => 'nuevaclave456',
        'password_confirmation' => 'nuevaclave456',
    ])->assertOk();

    expect(Hash::check('nuevaclave456', $this->user->refresh()->password))->toBeTrue();

    $this->postJson('/api/login', ['email' => 'maria@correo.pe', 'password' => 'nuevaclave456'])
        ->assertOk();
});

test('contraseña actual equivocada → 422 errors.password_actual', function () {
    $this->withToken($this->token)->putJson('/api/user/password', [
        'password_actual' => 'la-que-no-es',
        'password' => 'nuevaclave456',
        'password_confirmation' => 'nuevaclave456',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password_actual']);
});

test('la confirmación que no coincide → 422 errors.password', function () {
    $this->withToken($this->token)->putJson('/api/user/password', [
        'password_actual' => 'secreta123',
        'password' => 'nuevaclave456',
        'password_confirmation' => 'otra-cosa',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('cambiar la contraseña revoca las OTRAS sesiones y conserva la actual', function () {
    // Una segunda sesión, como si hubiera entrado desde otro dispositivo.
    $otro = $this->postJson('/api/login', [
        'email' => 'maria@correo.pe',
        'password' => 'secreta123',
    ])->json('data.token');

    $this->withToken($this->token)->putJson('/api/user/password', [
        'password_actual' => 'secreta123',
        'password' => 'nuevaclave456',
        'password_confirmation' => 'nuevaclave456',
    ])->assertOk();

    // La que hizo el cambio sigue viva: castigar al que hace lo correcto, no.
    $this->withToken($this->token)->getJson('/api/user')->assertOk();

    // La otra, fuera. `forgetGuards()` porque dentro de un mismo test el guard
    // de Sanctum conserva al usuario ya resuelto; en HTTP real cada petición
    // parte de cero. Sin esto el test pasaría aunque no se revocara nada.
    expect($this->user->tokens()->count())->toBe(1);

    $this->app['auth']->forgetGuards();
    $this->withToken($otro)->getJson('/api/user')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Suscripción vencida
|--------------------------------------------------------------------------
*/

test('suspendido: PUT /user cae con 403, pero cambiar la contraseña NO', function () {
    $this->user->tenant->update(['estado' => 'suspendida']);

    $this->withToken($this->token)->putJson('/api/user', perfilValido())
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'suscripcion_vencida');

    $this->withToken($this->token)->putJson('/api/user/password', [
        'password_actual' => 'secreta123',
        'password' => 'nuevaclave456',
        'password_confirmation' => 'nuevaclave456',
    ])->assertOk();
});
