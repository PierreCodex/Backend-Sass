<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Grupo;
use App\Models\Local;
use App\Models\Plan;
use App\Models\Profesional;
use App\Models\Servicio;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;

    [$this->local, $this->profesional, $this->servicio] = $this->tenant->run(fn () => [
        Local::create(['nombre' => 'Piura Centro'])->id,
        Profesional::create(['nombre' => 'Dr. Julio Mendoza'])->id,
        Servicio::create(['nombre' => 'Limpieza dental', 'precio' => 60, 'duracion_min' => 30])->id,
    ]);
});

afterEach(fn () => limpiarBasesDeTenants());

function grupoValido(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Odontología',
        'locales' => [test()->local],
        'profesionales' => [test()->profesional],
        'servicios' => [test()->servicio],
    ], $extra);
}

test('crear un grupo asigna las tres listas', function () {
    $data = $this->withToken($this->token)->postJson('/api/grupos', grupoValido())
        ->assertCreated()
        ->json('data');

    expect($data['nombre'])->toBe('Odontología')
        ->and($data['locales'][0]['nombre'])->toBe('Piura Centro')
        ->and($data['profesionales'][0]['nombre'])->toBe('Dr. Julio Mendoza')
        ->and($data['servicios'][0]['nombre'])->toBe('Limpieza dental');
});

/*
 * `descripcion` existe en la tabla desde el Sprint 0 pero el contrato no la
 * tiene (§2.12). Emitirla obligaría al frontend a decidir qué hacer con un
 * campo que su tipo no declara.
 */
test('la descripción no se emite aunque la columna exista', function () {
    $data = $this->withToken($this->token)->postJson('/api/grupos', grupoValido())
        ->assertCreated()
        ->json('data');

    expect($data)->not->toHaveKey('descripcion');
});

/*
 * Las tres listas van con `sync()`. Un array VACÍO deja el grupo sin nada de
 * esa categoría — que es distinto de no mandar la clave, que lo deja como
 * estaba.
 */
test('un array vacío desasigna; una clave ausente no toca nada', function () {
    $id = $this->withToken($this->token)->postJson('/api/grupos', grupoValido())
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->putJson("/api/grupos/{$id}", [
        'nombre' => 'Odontología',
        'servicios' => [],
    ])
        ->assertOk()
        ->assertJsonCount(0, 'data.servicios')
        // No se mandaron: siguen como estaban.
        ->assertJsonCount(1, 'data.locales')
        ->assertJsonCount(1, 'data.profesionales');
});

test('un id de otro negocio da 422, no lo asigna en silencio', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    $ajeno = $otro->run(fn () => Local::create(['nombre' => 'Del vecino'])->id);

    // Ojo: el id ajeno puede coincidir con uno propio, así que se pide uno que
    // NO exista aquí para que la prueba signifique algo.
    $inexistente = $ajeno + 500;

    $this->withToken($this->token)->postJson('/api/grupos', grupoValido([
        'locales' => [$inexistente],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('locales.0');
});

test('un nombre repetido da 422 en su campo', function () {
    $this->withToken($this->token)->postJson('/api/grupos', grupoValido())->assertCreated();

    $this->withToken($this->token)->postJson('/api/grupos', grupoValido())
        ->assertStatus(422)
        ->assertJsonValidationErrors('nombre');
});

test('borrar un grupo se lleva sus asignaciones y nada más', function () {
    $id = $this->withToken($this->token)->postJson('/api/grupos', grupoValido())
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->deleteJson("/api/grupos/{$id}")->assertStatus(204);

    $this->tenant->run(function () {
        expect(Grupo::count())->toBe(0)
            // El local, el profesional y el servicio siguen ahí.
            ->and(Local::count())->toBe(1)
            ->and(Servicio::count())->toBe(1);
    });
});

test('el listado pagina y busca por nombre', function () {
    $this->withToken($this->token)->postJson('/api/grupos', grupoValido())->assertCreated();
    $this->withToken($this->token)->postJson('/api/grupos', grupoValido([
        'nombre' => 'Pediatría', 'locales' => [], 'profesionales' => [], 'servicios' => [],
    ]))->assertCreated();

    $this->withToken($this->token)->getJson('/api/grupos')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data', 'meta']);

    $this->withToken($this->token)->getJson('/api/grupos?search=Pedia')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('los grupos de otro negocio: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    $ajeno = $otro->run(fn () => Grupo::create(['nombre' => 'Del vecino'])->id);

    expect($this->tenant->run(fn () => Grupo::find($ajeno)))->toBeNull();

    $this->withToken($this->token)->getJson("/api/grupos/{$ajeno}")->assertNotFound();
});

test('sin sesión → 401', function () {
    $this->getJson('/api/grupos')->assertStatus(401);
});
