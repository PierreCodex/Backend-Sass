<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;

    Storage::fake('public');
});

afterEach(fn () => limpiarBasesDeTenants());

/** Multipart con Accept explícito: sin él un 422 sale como redirect 302. */
function enviarServicio(object $test, array $datos, ?int $id = null): TestResponse
{
    $url = $id === null ? '/api/servicios' : '/api/servicios/'.$id;

    if ($id !== null) {
        $datos['_method'] = 'PUT';
    }

    return $test->withToken($test->token)->post($url, $datos, ['Accept' => 'application/json']);
}

function servicioValido(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Corte clásico',
        'color' => '#5D87FF',
        'tipo' => 'normal',
        'precio' => 25,
        'duracion_min' => 30,
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
*/

test('index trae categoria como objeto, precio numérico y el color del servicio', function () {
    $categoriaId = $this->tenant->run(function () {
        DB::table('categoria_servicios')->insert(['nombre' => 'Cortes', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('categoria_servicios')->value('id');
    });

    enviarServicio($this, servicioValido(['categoria_id' => $categoriaId]))->assertCreated();
    enviarServicio($this, servicioValido(['nombre' => 'Afeitado', 'color' => '#E4572E']))->assertCreated();

    $r = $this->withToken($this->token)->getJson('/api/servicios')->assertOk();

    // Orden alfabético: Afeitado antes que Corte clásico.
    $r->assertJsonPath('data.0.nombre', 'Afeitado')
        // Sin categoría se emite null, y el color sigue siendo suyo: en la
        // tabla hay servicios sin categoría que igual pintan su punto.
        ->assertJsonPath('data.0.categoria', null)
        ->assertJsonPath('data.0.color', '#E4572E')
        ->assertJsonPath('data.1.categoria.nombre', 'Cortes')
        // Numérico, no la cadena "25.00": si no, el frontend pinta S/ 25.00.00
        ->assertJsonPath('data.1.precio', 25)
        ->assertJsonPath('meta.total', 2);
});

test('search filtra por nombre', function () {
    enviarServicio($this, servicioValido())->assertCreated();
    enviarServicio($this, servicioValido(['nombre' => 'Tinte completo']))->assertCreated();

    $this->withToken($this->token)->getJson('/api/servicios?search=tinte')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Tinte completo');
});

/*
|--------------------------------------------------------------------------
| Crear y validar
|--------------------------------------------------------------------------
*/

test('crear devuelve 201 con el servicio completo', function () {
    $todos = $this->tenant->run(fn () => DB::table('profesionales')->whereNull('deleted_at')
        ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());

    $r = enviarServicio($this, servicioValido(['descripcion' => 'Tijera y máquina']))
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Corte clásico')
        ->assertJsonPath('data.tipo', 'normal')
        ->assertJsonPath('data.precio', 25)
        ->assertJsonPath('data.duracion_min', 30)
        ->assertJsonPath('data.activo', true)
        ->assertJsonPath('data.imagen_principal', null)
        ->assertJsonPath('data.galeria', []);

    // Sin `empleado_ids`, lo presta todo el equipo (Story 1.3).
    expect($todos)->not->toBe([])
        ->and(collect($r->json('data.empleados'))->pluck('id')->sort()->values()->all())->toBe($todos);
});

test('categoria_id vacío significa "sin categoría", no un 422', function () {
    // El select manda "" y el multipart lo entrega como cadena vacía.
    enviarServicio($this, servicioValido(['categoria_id' => '', 'descripcion' => '']))
        ->assertCreated()
        ->assertJsonPath('data.categoria', null)
        ->assertJsonPath('data.descripcion', null);
});

test('los tipos por sesiones exigen max_sesiones; los normales lo limpian', function () {
    enviarServicio($this, servicioValido(['nombre' => 'Paquete novia', 'tipo' => 'paquete']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['max_sesiones']);

    $id = enviarServicio($this, servicioValido([
        'nombre' => 'Paquete novia',
        'tipo' => 'paquete',
        'max_sesiones' => 5,
    ]))->assertCreated()->json('data.id');

    // Al pasar a normal el valor viejo no puede quedarse de fantasma.
    enviarServicio($this, servicioValido(['nombre' => 'Paquete novia', 'tipo' => 'normal']), $id)
        ->assertOk()
        ->assertJsonPath('data.max_sesiones', null);
});

test('un tipo inventado → 422', function () {
    enviarServicio($this, servicioValido(['tipo' => 'suscripcion']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tipo']);
});

/*
|--------------------------------------------------------------------------
| Profesionales
|--------------------------------------------------------------------------
*/

test('asignar y desasignar profesionales', function () {
    $profesionalId = $this->tenant->run(fn () => DB::table('profesionales')->value('id'));

    $id = enviarServicio($this, servicioValido(['empleado_ids' => [$profesionalId]]))
        ->assertCreated()
        ->assertJsonPath('data.empleados.0.id', $profesionalId)
        ->json('data.id');

    // Mandar la lista vacía SÍ desasigna (distinto de no mandar el campo).
    enviarServicio($this, servicioValido(['empleado_ids' => []]), $id)
        ->assertOk()
        ->assertJsonPath('data.empleados', []);
});

/*
 * Lo nuevo nace ASIGNADO (Story 1.3): el formulario manda `empleado_ids: []` si
 * no se eligió a nadie, y un servicio sin nadie no se podría agendar. En el
 * ALTA, ausente o vacío = todos los profesionales vivos. Al EDITAR, `[]` sigue
 * desasignando (prueba de arriba).
 */
test('un servicio nuevo sin profesionales elegidos lo prestan todos', function () {
    [$todos, $borrado] = $this->tenant->run(function () {
        DB::table('profesionales')->insert(['nombre' => 'Luis', 'created_at' => now(), 'updated_at' => now()]);
        $borrado = DB::table('profesionales')->insertGetId([
            'nombre' => 'Ex', 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            DB::table('profesionales')->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all(),
            $borrado,
        ];
    });

    $vacio = enviarServicio($this, servicioValido(['empleado_ids' => []]))->assertCreated()->json('data');
    $ausente = enviarServicio($this, servicioValido(['nombre' => 'Afeitado']))->assertCreated()->json('data');

    foreach ([$vacio, $ausente] as $servicio) {
        expect(collect($servicio['empleados'])->pluck('id')->sort()->values()->all())->toBe($todos)
            ->and(collect($servicio['empleados'])->pluck('id')->all())->not->toContain($borrado);
    }

    // Elegir a alguien sigue siendo elegir: no se ensancha.
    enviarServicio($this, servicioValido(['nombre' => 'Tinte', 'empleado_ids' => [$todos[0]]]))
        ->assertCreated()
        ->assertJsonCount(1, 'data.empleados');
});

test('restaurar un servicio borrado sin elegir a nadie también lo asigna a todos', function () {
    $todos = $this->tenant->run(fn () => DB::table('profesionales')->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all());

    $id = enviarServicio($this, servicioValido(['empleado_ids' => []]))->assertCreated()->json('data.id');
    enviarServicio($this, servicioValido(['empleado_ids' => []]), $id)->assertOk()->assertJsonPath('data.empleados', []);
    $this->withToken($this->token)->deleteJson('/api/servicios/'.$id)->assertNoContent();

    $nuevo = enviarServicio($this, servicioValido())->assertCreated()->json('data');

    expect($nuevo['id'])->toBe($id)
        ->and(collect($nuevo['empleados'])->pluck('id')->all())->toBe($todos);
});

/*
|--------------------------------------------------------------------------
| Imágenes
|--------------------------------------------------------------------------
*/

test('imagen principal y galería: se suben, se conservan y se borran por id', function () {
    $id = enviarServicio($this, servicioValido([
        'imagen_principal' => UploadedFile::fake()->image('principal.jpg', 400, 400),
        'galeria' => [
            UploadedFile::fake()->image('uno.jpg', 300, 300),
            UploadedFile::fake()->image('dos.jpg', 300, 300),
        ],
    ]))->assertCreated()->json('data');

    expect($id['imagen_principal'])->toEndWith('.webp')
        ->and($id['galeria'])->toHaveCount(2);

    $conservar = $id['galeria'][0]['id'];

    $editado = enviarServicio($this, servicioValido([
        'galeria_conservar' => [$conservar],
    ]), $id['id'])->assertOk()->json('data');

    // Se queda la que el formulario devolvió, se va la otra.
    expect($editado['galeria'])->toHaveCount(1)
        ->and($editado['galeria'][0]['id'])->toBe($conservar)
        // Y la principal sigue: no mandar el archivo no la borra.
        ->and($editado['imagen_principal'])->toBe($id['imagen_principal']);
});

test('no mandar galeria_conservar NO borra la galería', function () {
    $creado = enviarServicio($this, servicioValido([
        'galeria' => [UploadedFile::fake()->image('uno.jpg', 300, 300)],
    ]))->assertCreated()->json('data');

    // Que el formulario no envíe el campo no puede significar "bórralo todo".
    enviarServicio($this, servicioValido(['nombre' => 'Corte clásico renombrado']), $creado['id'])
        ->assertOk()
        ->assertJsonCount(1, 'data.galeria');
});

test('la galería admite como máximo 4 imágenes', function () {
    enviarServicio($this, servicioValido([
        'galeria' => array_map(
            fn (int $i) => UploadedFile::fake()->image("g{$i}.jpg", 200, 200),
            range(1, 5),
        ),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['galeria']);
});

/*
|--------------------------------------------------------------------------
| Borrado y el nombre repetido
|--------------------------------------------------------------------------
*/

test('borrar es soft delete: desaparece del listado aunque tenga citas', function () {
    $id = enviarServicio($this, servicioValido())->assertCreated()->json('data.id');

    // Una cita que ya lo usó. El precio queda congelado en cita_servicio.
    $this->tenant->run(function () use ($id) {
        DB::table('clientes')->insert(['nombre' => 'Ana', 'telefono' => '+51999111222', 'created_at' => now(), 'updated_at' => now()]);
        $profesional = DB::table('profesionales')->value('id');
        $cliente = DB::table('clientes')->value('id');

        DB::table('citas')->insert([
            'codigo' => 'ABC12345',
            'profesional_id' => $profesional,
            'cliente_id' => $cliente,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cita_servicio')->insert([
            'cita_id' => DB::table('citas')->value('id'),
            'servicio_id' => $id,
            'precio' => 25,
            'duracion_min' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    // 204, no 409: el dueño que deja de ofrecer algo puede quitarlo del
    // catálogo. El historial queda intacto porque la fila no desaparece.
    $this->withToken($this->token)->deleteJson('/api/servicios/'.$id)->assertNoContent();

    $this->withToken($this->token)->getJson('/api/servicios')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->tenant->run(function () use ($id) {
        expect(DB::table('servicios')->where('id', $id)->value('deleted_at'))->not->toBeNull();
        // La cita sigue apuntando a él, con su precio congelado.
        expect(DB::table('cita_servicio')->where('servicio_id', $id)->value('precio'))->toBe('25.00');
    });
});

test('volver a crear un servicio borrado lo RESTAURA en vez de dar 422', function () {
    $id = enviarServicio($this, servicioValido(['precio' => 25]))->assertCreated()->json('data.id');

    $this->withToken($this->token)->deleteJson('/api/servicios/'.$id)->assertNoContent();

    // El índice UNIQUE de `nombre` no distingue los borrados, así que un
    // insert chocaría contra la fila que sigue ahí. El dueño leería "ya
    // existe" mirando una lista donde no está.
    $nuevo = enviarServicio($this, servicioValido(['precio' => 30]))
        ->assertCreated()
        ->json('data');

    expect($nuevo['id'])->toBe($id)
        ->and($nuevo['precio'])->toBe(30);

    // Y no hay dos filas con el mismo nombre.
    $this->tenant->run(function () {
        expect(DB::table('servicios')->where('nombre', 'Corte clásico')->count())->toBe(1);
    });
});

test('el nombre repetido de un servicio VIVO sigue dando 422', function () {
    enviarServicio($this, servicioValido())->assertCreated();

    enviarServicio($this, servicioValido(['precio' => 40]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nombre'])
        ->assertJsonPath('errors.nombre.0', 'Ya existe un servicio con ese nombre.');
});

/*
|--------------------------------------------------------------------------
| Aislación entre tenants
|--------------------------------------------------------------------------
*/

test('los servicios de otro negocio: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());

    User::create([
        'tenant_id' => $otro->id,
        'nombre' => 'Luis',
        'apellido' => 'Ramos',
        'email' => 'luis+'.$otro->id.'@correo.pe',
        'password' => 'secreta123',
        'rol' => 'admin_general',
    ]);

    (new ProvisionTenantDatabase($otro))->handle();

    // Id alto y explícito: cada BD de tenant arranca su autoincremento en 1,
    // así que sin esto el test pasaría devolviendo el servicio PROPIO.
    $otro->run(fn () => DB::table('servicios')->insert([
        'id' => 900,
        'nombre' => 'Manicure',
        'color' => '#5D87FF',
        'precio' => 40,
        'duracion_min' => 45,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    enviarServicio($this, servicioValido())->assertCreated();

    $this->withToken($this->token)->getJson('/api/servicios')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Corte clásico');

    $this->withToken($this->token)->getJson('/api/servicios/900')->assertNotFound();
    $this->withToken($this->token)->deleteJson('/api/servicios/900')->assertNotFound();

    expect($otro->run(fn () => DB::table('servicios')->whereNull('deleted_at')->count()))->toBe(1);
});

test('sin sesión → 401', function () {
    $this->getJson('/api/servicios')->assertStatus(401);
});

test('galeria_vaciar quita todas las fotos, que un array vacío no puede', function () {
    $creado = enviarServicio($this, servicioValido([
        'galeria' => [
            UploadedFile::fake()->image('uno.jpg', 200, 200),
            UploadedFile::fake()->image('dos.jpg', 200, 200),
        ],
    ]))->assertCreated()->json('data');

    // Un array vacío no viaja en multipart: llegaría como campo ausente, que
    // significa "no borres nada". Por eso hace falta la bandera.
    enviarServicio($this, servicioValido(['galeria_vaciar' => 1]), $creado['id'])
        ->assertOk()
        ->assertJsonPath('data.galeria', []);
});

test('imagen_principal_eliminar deja el servicio sin foto principal', function () {
    $creado = enviarServicio($this, servicioValido([
        'imagen_principal' => UploadedFile::fake()->image('principal.jpg', 300, 300),
        'galeria' => [UploadedFile::fake()->image('uno.jpg', 200, 200)],
    ]))->assertCreated()->json('data');

    expect($creado['imagen_principal'])->not->toBeNull();

    enviarServicio($this, servicioValido(['imagen_principal_eliminar' => 1]), $creado['id'])
        ->assertOk()
        ->assertJsonPath('data.imagen_principal', null)
        // Y la galería no se toca: son banderas independientes.
        ->assertJsonCount(1, 'data.galeria');
});

/*
|--------------------------------------------------------------------------
| Revisión del Sprint 1
|--------------------------------------------------------------------------
*/

test('la galería cuenta el TOTAL, no solo las de esta petición', function () {
    $creado = enviarServicio($this, servicioValido([
        'galeria' => array_map(
            fn (int $i) => UploadedFile::fake()->image("g{$i}.jpg", 200, 200),
            range(1, 4),
        ),
    ]))->assertCreated()->json('data');

    /*
     * El formulario reenvía sus 4 ids y sube una más. El `max:4` del Form
     * Request solo mira los archivos de ESTA llamada, así que dejaba pasar la
     * quinta y el servicio acababa con más fotos de las que promete el
     * mensaje de error.
     */
    enviarServicio($this, servicioValido([
        'galeria_conservar' => array_column($creado['galeria'], 'id'),
        'galeria' => [UploadedFile::fake()->image('g5.jpg', 200, 200)],
    ]), $creado['id'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['galeria']);

    // Y el 422 no puede haber podado nada por el camino.
    $this->withToken($this->token)->getJson('/api/servicios/'.$creado['id'])
        ->assertOk()
        ->assertJsonCount(4, 'data.galeria');
});

test('recrear un servicio borrado no arrastra las fotos ni el estado del viejo', function () {
    $creado = enviarServicio($this, servicioValido([
        'activo' => 0,
        'galeria' => [UploadedFile::fake()->image('vieja.jpg', 200, 200)],
    ]))->assertCreated()->json('data');

    $this->withToken($this->token)->deleteJson('/api/servicios/'.$creado['id'])->assertNoContent();

    /*
     * Restaurar la fila es un truco para no chocar con el UNIQUE, pero para el
     * dueño esto es un ALTA: rellenó un formulario en blanco. Devolvérsela tal
     * cual hacía nacer el servicio "nuevo" con la galería del viejo y apagado.
     */
    $nuevo = enviarServicio($this, servicioValido())->assertCreated()->json('data');

    expect($nuevo['id'])->toBe($creado['id'])
        ->and($nuevo['galeria'])->toBeEmpty()
        ->and($nuevo['activo'])->toBeTrue();
});

test('renombrar un servicio con el nombre de otro BORRADO da 422, no un 500', function () {
    $viejo = enviarServicio($this, servicioValido(['nombre' => 'Corte clásico']))
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->deleteJson('/api/servicios/'.$viejo)->assertNoContent();

    $otro = enviarServicio($this, servicioValido(['nombre' => 'Barba']))
        ->assertCreated()->json('data.id');

    // El índice UNIQUE no distingue el soft delete: ignorar los borrados aquí
    // hacía pasar la validación y reventar el UPDATE.
    enviarServicio($this, servicioValido(['nombre' => 'Corte clásico']), $otro)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nombre']);
});

test('un color que no es hexadecimal de 6 dígitos → 422, no un 500', function () {
    // La columna es char(7) y la BD va en modo estricto: con `max:20` esto
    // llegaba a MySQL y volvía como "Data too long".
    enviarServicio($this, servicioValido(['color' => '#5D87FF80']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['color']);

    enviarServicio($this, servicioValido(['color' => 'rebeccapurple']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['color']);
});
