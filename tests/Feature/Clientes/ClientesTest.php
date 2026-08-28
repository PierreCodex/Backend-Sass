<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;
});

afterEach(fn () => limpiarBasesDeTenants());

function crearCita(object $test, int $clienteId, string $cuando): void
{
    $test->tenant->run(function () use ($clienteId, $cuando) {
        DB::table('citas')->insert([
            'codigo' => substr(md5($clienteId.$cuando), 0, 8),
            'profesional_id' => DB::table('profesionales')->value('id'),
            'cliente_id' => $clienteId,
            'starts_at' => $cuando,
            'ends_at' => date('Y-m-d H:i:s', strtotime($cuando) + 1800),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
*/

test('index trae total_citas y ultima_cita en ISO', function () {
    $id = $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'ANGELICA GABINO HUERTA',
        'telefono' => '904 169 872',
        'email' => 'agabino@ucvvirtual.edu.pe',
    ])->assertCreated()->json('data.id');

    crearCita($this, $id, '2026-08-09 10:00:00');
    crearCita($this, $id, '2026-07-01 10:00:00');

    $this->withToken($this->token)->getJson('/api/clientes')
        ->assertOk()
        // Tal cual lo escribió el usuario: la tabla no normaliza nombres.
        ->assertJsonPath('data.0.nombre', 'ANGELICA GABINO HUERTA')
        // Ni teléfonos: se muestra el original, no el normalizado.
        ->assertJsonPath('data.0.telefono', '904 169 872')
        ->assertJsonPath('data.0.total_citas', 2)
        ->assertJsonPath('data.0.ultima_cita', '2026-08-09');
});

test('un cliente sin citas trae ultima_cita null, no la clave ausente', function () {
    $this->withToken($this->token)->postJson('/api/clientes', ['nombre' => 'Test'])
        ->assertCreated();

    $this->withToken($this->token)->getJson('/api/clientes')
        ->assertOk()
        ->assertJsonPath('data.0.total_citas', 0)
        // La tabla pinta un guion: necesita la clave presente y en null.
        ->assertJsonPath('data.0.ultima_cita', null)
        ->assertJsonPath('data.0.email', null);
});

test('search encuentra por nombre, email y teléfono ESCRITO DE OTRA FORMA', function () {
    $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'Angelica',
        'telefono' => '904 169 872',
        'email' => 'agabino@ucvvirtual.edu.pe',
    ])->assertCreated();

    $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'Rosa',
        'telefono' => '999',
    ])->assertCreated();

    foreach (['angel', 'ucvvirtual', '904 169', '904169872'] as $busqueda) {
        $this->withToken($this->token)->getJson('/api/clientes?search='.urlencode($busqueda))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Angelica', "Falló buscando «{$busqueda}»");
    }
});

test('buscar texto sin dígitos no devuelve a todos los que tienen teléfono', function () {
    $this->withToken($this->token)->postJson('/api/clientes', ['nombre' => 'Angelica', 'telefono' => '904169872'])->assertCreated();
    $this->withToken($this->token)->postJson('/api/clientes', ['nombre' => 'Rosa', 'telefono' => '981912809'])->assertCreated();

    // Si el teléfono normalizado de la búsqueda es null, ese LIKE quedaría en
    // '%%' y casaría con TODOS los que tienen teléfono.
    $this->withToken($this->token)->getJson('/api/clientes?search=rosa')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Rosa');
});

/*
|--------------------------------------------------------------------------
| Crear
|--------------------------------------------------------------------------
*/

test('crear devuelve 201 con total_citas 0 y ultima_cita null', function () {
    $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'ANGELICA GABINO HUERTA',
        'telefono' => '904 169 872',
        'email' => 'agabino@ucvvirtual.edu.pe',
    ])
        ->assertCreated()
        ->assertJsonPath('data.total_citas', 0)
        ->assertJsonPath('data.ultima_cita', null);
});

test('solo el nombre es obligatorio', function () {
    $this->withToken($this->token)->postJson('/api/clientes', ['nombre' => 'Test'])
        ->assertCreated()
        ->assertJsonPath('data.telefono', null);

    $this->withToken($this->token)->postJson('/api/clientes', ['nombre' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nombre']);
});

test('un correo mal escrito → 422 en el campo email', function () {
    $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'Test',
        'email' => 'esto-no-es-un-correo',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('los campos vacíos llegan como cadena y se guardan como null', function () {
    // La ficha dice que el formulario manda null, pero eso lo garantiza el
    // formulario de HOY. Un "" guardado rompería después el unique.
    $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'Test',
        'telefono' => '',
        'email' => '',
    ])
        ->assertCreated()
        ->assertJsonPath('data.telefono', null)
        ->assertJsonPath('data.email', null);
});

/*
|--------------------------------------------------------------------------
| El teléfono como clave natural
|--------------------------------------------------------------------------
*/

test('el mismo teléfono escrito de otra forma → 422, no una ficha duplicada', function () {
    $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'Angelica',
        'telefono' => '904169872',
    ])->assertCreated();

    // discrepancias.md fija el teléfono como clave del firstOrCreate de la
    // reserva pública: dos fichas de la misma persona parten su historial.
    foreach (['904 169 872', '+51 904 169 872', '(904) 169-872'] as $variante) {
        $this->withToken($this->token)->postJson('/api/clientes', [
            'nombre' => 'Angelica otra vez',
            'telefono' => $variante,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['telefono'])
            ->assertJsonPath('errors.telefono.0', 'Ya existe un cliente con ese teléfono.');
    }
});

test('varios clientes SIN teléfono conviven: el unique no cuenta los null', function () {
    foreach (['Uno', 'Dos', 'Tres'] as $nombre) {
        $this->withToken($this->token)->postJson('/api/clientes', ['nombre' => $nombre])
            ->assertCreated();
    }

    $this->withToken($this->token)->getJson('/api/clientes')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('editar un cliente conservando su propio teléfono no choca consigo mismo', function () {
    $id = $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'Angelica',
        'telefono' => '904169872',
    ])->assertCreated()->json('data.id');

    $this->withToken($this->token)->putJson('/api/clientes/'.$id, [
        'nombre' => 'Angélica Gabino',
        'telefono' => '904 169 872',
    ])
        ->assertOk()
        ->assertJsonPath('data.nombre', 'Angélica Gabino');
});

test('un número corto de prueba no se confunde con otro', function () {
    $this->withToken($this->token)->postJson('/api/clientes', ['nombre' => 'Test', 'telefono' => '999'])
        ->assertCreated();

    // Normalizar de más uniría fichas de personas distintas, que es peor que
    // dejar dos fichas de una.
    $this->withToken($this->token)->postJson('/api/clientes', ['nombre' => 'Otro', 'telefono' => '998'])
        ->assertCreated();
});

/*
|--------------------------------------------------------------------------
| Borrado
|--------------------------------------------------------------------------
*/

test('borrar es soft delete y conserva las citas del cliente', function () {
    $id = $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'Angelica',
        'telefono' => '904169872',
    ])->assertCreated()->json('data.id');

    crearCita($this, $id, '2026-08-09 10:00:00');

    $this->withToken($this->token)->deleteJson('/api/clientes/'.$id)->assertNoContent();

    $this->withToken($this->token)->getJson('/api/clientes')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->tenant->run(function () use ($id) {
        expect(DB::table('clientes')->where('id', $id)->value('deleted_at'))->not->toBeNull();
        // Sus citas pasadas siguen sabiendo de quién fueron.
        expect(DB::table('citas')->where('cliente_id', $id)->count())->toBe(1);
    });
});

test('el cliente que vuelve con el mismo teléfono RESTAURA su ficha', function () {
    $id = $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'Angelica',
        'telefono' => '904169872',
    ])->assertCreated()->json('data.id');

    crearCita($this, $id, '2026-08-09 10:00:00');

    $this->withToken($this->token)->deleteJson('/api/clientes/'.$id)->assertNoContent();

    // El UNIQUE de telefono_normalizado no distingue los borrados: un insert
    // daría un 500. Y restaurar es lo correcto, porque es la misma persona.
    $vuelta = $this->withToken($this->token)->postJson('/api/clientes', [
        'nombre' => 'Angélica Gabino Huerta',
        'telefono' => '904 169 872',
    ])->assertCreated()->json('data');

    expect($vuelta['id'])->toBe($id)
        ->and($vuelta['nombre'])->toBe('Angélica Gabino Huerta')
        // Y recupera su historial: por eso restaurar es mejor que crear otra.
        ->and($vuelta['total_citas'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Aislación entre tenants
|--------------------------------------------------------------------------
*/

test('los clientes de otro negocio: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());

    User::create([
        'tenant_id' => $otro->id,
        'nombre' => 'Luis',
        'apellido' => 'Ramos',
        'email' => 'luis+'.$otro->id.'@correo.pe',
        'password' => 'secreta123',
        'rol' => 'dueno',
    ]);

    (new ProvisionTenantDatabase($otro))->handle();

    // Id alto: cada BD de tenant arranca su autoincremento en 1.
    $otro->run(fn () => DB::table('clientes')->insert([
        'id' => 900,
        'nombre' => 'Cliente ajeno',
        'telefono' => '981912809',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->withToken($this->token)->postJson('/api/clientes', ['nombre' => 'Propio'])->assertCreated();

    $this->withToken($this->token)->getJson('/api/clientes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Propio');

    $this->withToken($this->token)->getJson('/api/clientes/900')->assertNotFound();
    $this->withToken($this->token)->deleteJson('/api/clientes/900')->assertNotFound();

    expect($otro->run(fn () => DB::table('clientes')->whereNull('deleted_at')->count()))->toBe(1);
});

test('sin sesión → 401', function () {
    $this->getJson('/api/clientes')->assertStatus(401);
});
