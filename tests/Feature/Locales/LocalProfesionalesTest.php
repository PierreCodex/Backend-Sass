<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Local;
use App\Models\Plan;
use App\Models\Profesional;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;

    // Una sede y dos barberos más, aparte del titular que crea el provisioning.
    [$this->local, $this->carmen, $this->luis] = $this->tenant->run(fn () => [
        Local::create(['nombre' => 'Piura Centro'])->id,
        Profesional::create(['nombre' => 'Carmen Ríos'])->id,
        Profesional::create(['nombre' => 'Luis Timaná'])->id,
    ]);
});

afterEach(fn () => limpiarBasesDeTenants());

/*
 * Devuelve UNA FILA POR CADA profesional del negocio, tenga o no asignación.
 * Es lo que convierte la pantalla en una sola tabla con interruptores, en vez
 * de dos listas y un botón de añadir.
 */
test('el listado trae a todo el equipo, trabaje o no en esta sede', function () {
    $data = $this->withToken($this->token)
        ->getJson("/api/locales/{$this->local}/profesionales")
        ->assertOk()
        ->json('data');

    // El titular más los dos barberos.
    expect($data)->toHaveCount(3);

    foreach ($data as $fila) {
        expect($fila['habilitado'])->toBeFalse()
            ->and($fila['nombre_publico'])->toBeNull()
            ->and($fila['horario_apertura'])->toBeNull();
    }
});

/*
 * El id es el del PROFESIONAL, no el de la fila pivote (§1.4): el mismo que
 * usan `/profesionales/{id}`, las citas y `servicio_profesional`. Dos ids para
 * la misma persona según la pantalla sería una fuente de errores silenciosos.
 */
test('el id es el del profesional y sirve en su propio endpoint', function () {
    $data = $this->withToken($this->token)
        ->getJson("/api/locales/{$this->local}/profesionales")
        ->json('data');

    $ids = collect($data)->pluck('id');

    expect($ids)->toContain($this->carmen);

    $this->withToken($this->token)->getJson("/api/profesionales/{$this->carmen}")
        ->assertOk()
        ->assertJsonPath('data.nombre', 'Carmen Ríos');
});

test('el mismo PUT asigna la primera vez y edita después', function () {
    $url = "/api/locales/{$this->local}/profesionales/{$this->carmen}";

    $this->withToken($this->token)->putJson($url, [
        'habilitado' => true,
        'nombre_publico' => 'Dra. Carmen',
        'perfil' => 'Médica general',
        'horario' => ['apertura' => '09:00', 'cierre' => '18:00'],
    ])->assertOk()
        ->assertJsonPath('data.habilitado', true)
        ->assertJsonPath('data.nombre_publico', 'Dra. Carmen')
        // TIME vuelve como «09:00:00» y el contrato pide HH:MM.
        ->assertJsonPath('data.horario_apertura', '09:00')
        ->assertJsonPath('data.horario_cierre', '18:00');

    $this->withToken($this->token)->putJson($url, ['nombre_publico' => 'Carmen R.'])
        ->assertOk()
        ->assertJsonPath('data.nombre_publico', 'Carmen R.');
});

/*
 * El interruptor de Habilitado guarda al momento y manda una petición con ese
 * campo casi solo. Si el resto se interpretara como vacío, encender a alguien
 * le borraría su nombre público y su perfil en esa sede.
 */
test('encender el interruptor no borra lo que ya había escrito', function () {
    $url = "/api/locales/{$this->local}/profesionales/{$this->carmen}";

    $this->withToken($this->token)->putJson($url, [
        'nombre_publico' => 'Dra. Carmen',
        'perfil' => 'Médica general',
        'horario' => ['apertura' => '09:00', 'cierre' => '18:00'],
    ])->assertOk();

    $this->withToken($this->token)->putJson($url, ['habilitado' => true])
        ->assertOk()
        ->assertJsonPath('data.habilitado', true)
        ->assertJsonPath('data.nombre_publico', 'Dra. Carmen')
        ->assertJsonPath('data.perfil', 'Médica general')
        ->assertJsonPath('data.horario_apertura', '09:00');
});

/*
 * No hay endpoint de «desasignar»: se apaga el interruptor. Borrar la fila se
 * llevaría de paso el nombre público y el perfil de esa sede, que el negocio
 * escribió a mano.
 */
test('para sacar a alguien de la sede se apaga el interruptor, no se borra', function () {
    $url = "/api/locales/{$this->local}/profesionales/{$this->carmen}";

    $this->withToken($this->token)->putJson($url, [
        'habilitado' => true,
        'nombre_publico' => 'Dra. Carmen',
    ])->assertOk();

    $this->withToken($this->token)->putJson($url, ['habilitado' => 0])
        ->assertOk()
        ->assertJsonPath('data.habilitado', false)
        // Su nombre público sigue ahí, listo para cuando vuelva.
        ->assertJsonPath('data.nombre_publico', 'Dra. Carmen');
});

test('una hora de cierre anterior a la de apertura da 422', function () {
    $this->withToken($this->token)
        ->putJson("/api/locales/{$this->local}/profesionales/{$this->carmen}", [
            'horario' => ['apertura' => '21:00', 'cierre' => '16:07'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('horario.cierre');
});

/*
 * `atiende` lo pide la ficha; `activo` lo añadimos porque una tabla de «quién
 * atiende en esta sede» no debería ofrecer a alguien dado de baja.
 */
test('quien no atiende o está de baja no sale en la lista', function () {
    $this->tenant->run(function () {
        Profesional::create(['nombre' => 'Recepción', 'atiende' => false]);
        Profesional::create(['nombre' => 'De baja', 'activo' => false]);
    });

    $data = $this->withToken($this->token)
        ->getJson("/api/locales/{$this->local}/profesionales")
        ->json('data');

    expect(collect($data)->pluck('nombre'))
        ->not->toContain('Recepción')
        ->not->toContain('De baja');
});

test('el local de otro negocio: 404', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    /*
     * Varias sedes en el otro negocio para que su id NO exista aqui: las dos
     * bases empiezan a numerar en 1, asi que pedir el id 1 devolveria el local
     * propio y el test pasaria sin probar nada. Ya nos paso en empleados.
     */
    $ajeno = $otro->run(function () {
        foreach (range(1, 4) as $i) {
            $ultimo = Local::create(['nombre' => "Del vecino {$i}"])->id;
        }

        return $ultimo;
    });

    expect($this->tenant->run(fn () => Local::find($ajeno)))->toBeNull();

    $this->withToken($this->token)->getJson("/api/locales/{$ajeno}/profesionales")->assertNotFound();
});

test('sin sesión → 401', function () {
    $this->getJson("/api/locales/{$this->local}/profesionales")->assertStatus(401);
});
