<?php

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;

test('los mensajes de validación y atributos principales están en español', function () {
    config(['app.locale' => 'es', 'app.fallback_locale' => 'es']);

    $validator = Validator::make([], [
        'empleado_id' => ['required'],
        'local_id' => ['required'],
        'starts_at' => ['required'],
        'usuario.email' => ['required', 'email'],
    ]);

    $mensajes = implode(' ', $validator->errors()->all());

    expect($mensajes)
        ->toContain('profesional')
        ->toContain('sede')
        ->toContain('fecha y hora de inicio')
        ->toContain('correo de la cuenta')
        ->not->toContain('The ')
        ->not->toContain(' field ');
});

test('las validaciones del login devuelven mensajes en español', function () {
    $this->postJson('/api/login', [])->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'El campo correo es obligatorio.')
        ->assertJsonPath('errors.password.0', 'El campo contraseña es obligatorio.');
});

test('el fallback español se aplica si el locale activo no tiene traducciones', function () {
    config(['app.locale' => 'zz', 'app.fallback_locale' => 'es']);

    $this->postJson('/api/login', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'El campo correo es obligatorio.')
        ->assertJsonPath('errors.password.0', 'El campo contraseña es obligatorio.');
});

test('una petición protegida sin sesión devuelve un error de autenticación en español', function () {
    $this->get('/api/user')
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Necesitas iniciar sesión para continuar.',
            'codigo' => 'no_autenticado',
        ]);
});

test('el límite de login devuelve un mensaje en español', function () {
    $respuesta = null;
    ThrottleRequests::shouldHashKeys(false);
    RateLimiter::clear('|127.0.0.1');
    RateLimiter::clear(sha1('|127.0.0.1'));

    for ($i = 0; $i < 20; $i++) {
        $respuesta = $this->postJson('/api/login', []);

        if ($respuesta->status() === 429) {
            break;
        }
    }

    $respuesta->assertStatus(429)
        ->assertJson([
            'message' => 'Has superado el límite de solicitudes. Inténtalo de nuevo más tarde.',
            'codigo' => 'limite_solicitudes',
        ]);
});
