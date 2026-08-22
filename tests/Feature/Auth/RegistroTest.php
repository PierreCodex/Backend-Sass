<?php

use App\Mail\VerificarCorreoMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed();
    Mail::fake();
});

function payloadRegistro(array $extra = []): array
{
    return array_merge([
        'tipo_negocio_id' => App\Models\BusinessCategory::first()->id,
        'rango_profesionales' => '3-5',
        'nombre' => 'María',
        'apellido' => 'Quispe',
        'email' => 'maria@correo.pe',
        'telefono' => '+51987654321',
        'password' => 'secreta123',
        'password_confirmation' => 'secreta123',
    ], $extra);
}

test('el registro crea tenant y dueño atómicamente, sin sesión y sin BD de tenant', function () {
    $respuesta = $this->postJson('/api/register', payloadRegistro());

    $respuesta->assertStatus(201)
        ->assertJsonPath('data.name', 'María Quispe')
        ->assertJsonPath('data.rol', 'dueno')
        ->assertJsonMissingPath('data.token');

    $user = User::where('email', 'maria@correo.pe')->firstOrFail();
    $tenant = $user->tenant;

    expect($tenant->estado)->toBe('registrada')
        ->and($tenant->nombre)->toBeNull()
        ->and($tenant->slug)->toBeNull()
        ->and($tenant->rango_profesionales)->toBe('3-5')
        ->and($tenant->plan->slug)->toBe('prueba')
        ->and($tenant->db_provisionada)->toBeFalse();

    // Cero BDs de tenant creadas (lazy provisioning)
    $manager = $tenant->database()->manager();
    expect($manager->databaseExists('tenant_'.$tenant->id))->toBeFalse();

    Mail::assertQueued(VerificarCorreoMail::class, fn ($mail) => $mail->hasTo('maria@correo.pe'));
});

test('email repetido → 422 errors.email y no queda tenant huérfano', function () {
    $this->postJson('/api/register', payloadRegistro())->assertStatus(201);

    $tenantsAntes = Tenant::count();

    $this->postJson('/api/register', payloadRegistro(['telefono' => '+51911111111']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    expect(Tenant::count())->toBe($tenantsAntes)
        ->and(User::where('email', 'maria@correo.pe')->count())->toBe(1);
});

test('teléfono sin formato +51 → 422 errors.telefono', function () {
    $this->postJson('/api/register', payloadRegistro(['telefono' => '987654321']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['telefono']);
});

test('rango de profesionales fuera de la lista → 422', function () {
    $this->postJson('/api/register', payloadRegistro(['rango_profesionales' => '100']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rango_profesionales']);
});

test('las categorías públicas alimentan el select del registro sin sesión', function () {
    $this->getJson('/api/publico/categorias-negocio')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'nombre']]]);
});
