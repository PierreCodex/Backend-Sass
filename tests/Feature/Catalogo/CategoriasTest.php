<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $plan = Plan::where('slug', 'prueba')->firstOrFail();

    $this->tenant = crearTenantRegistrado($plan);
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;
});

afterEach(fn () => limpiarBasesDeTenants());

function crearCategorias(Tenant $tenant, array $filas): void
{
    // Fila a fila y no en bloque: un insert masivo de MySQL exige que todas
    // las filas traigan las MISMAS columnas, y aquí unas llevan color y otras no.
    $tenant->run(function () use ($filas) {
        foreach ($filas as $fila) {
            DB::table('categoria_servicios')->insert(
                $fila + ['created_at' => now(), 'updated_at' => now()],
            );
        }
    });
}

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
*/

test('index pagina, ordena por orden y trae servicios_count', function () {
    crearCategorias($this->tenant, [
        ['nombre' => 'Tintes', 'orden' => 3, 'color' => '#E4572E'],
        ['nombre' => 'Cortes', 'orden' => 1, 'color' => '#5D87FF'],
        ['nombre' => 'Barba y afeitado', 'orden' => 2],
    ]);

    $this->tenant->run(function () {
        $cortes = DB::table('categoria_servicios')->where('nombre', 'Cortes')->value('id');

        DB::table('servicios')->insert([
            ['categoria_servicio_id' => $cortes, 'nombre' => 'Corte clásico', 'precio' => 25, 'duracion_min' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['categoria_servicio_id' => $cortes, 'nombre' => 'Corte a máquina', 'precio' => 18, 'duracion_min' => 20, 'created_at' => now(), 'updated_at' => now()],
        ]);
    });

    $this->withToken($this->token)->getJson('/api/categorias-servicios')
        ->assertOk()
        ->assertJsonPath('data.0.nombre', 'Cortes')
        ->assertJsonPath('data.0.servicios_count', 2)
        ->assertJsonPath('data.1.nombre', 'Barba y afeitado')
        ->assertJsonPath('data.2.nombre', 'Tintes')
        ->assertJsonPath('data.2.servicios_count', 0)
        ->assertJsonPath('meta.total', 3);
});

test('search filtra por nombre y por descripción', function () {
    crearCategorias($this->tenant, [
        ['nombre' => 'Cortes', 'descripcion' => 'Caballero y niño'],
        ['nombre' => 'Tintes', 'descripcion' => 'Coloración y mechas'],
    ]);

    $this->withToken($this->token)->getJson('/api/categorias-servicios?search=corte')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Cortes');

    // El contrato dice que busca por nombre Y descripción.
    $this->withToken($this->token)->getJson('/api/categorias-servicios?search=mechas')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Tintes');
});

/*
|--------------------------------------------------------------------------
| Crear y editar
|--------------------------------------------------------------------------
*/

test('crear devuelve 201 con la categoría completa', function () {
    $this->withToken($this->token)->postJson('/api/categorias-servicios', [
        'nombre' => 'Tratamientos capilares',
        'descripcion' => 'Hidratación y keratina',
        'color' => '#22C55E',
        'orden' => 4,
    ])
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Tratamientos capilares')
        ->assertJsonPath('data.orden', 4)
        ->assertJsonPath('data.imagen_url', null)
        ->assertJsonPath('data.servicios_count', 0);
});

test('el nombre repetido dentro del negocio → 422', function () {
    crearCategorias($this->tenant, [['nombre' => 'Cortes']]);

    $this->withToken($this->token)
        ->postJson('/api/categorias-servicios', ['nombre' => 'Cortes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nombre'])
        ->assertJsonPath('errors.nombre.0', 'Ya existe una categoría con ese nombre.');
});

test('editar no exige reenviar la imagen: sin el campo, se conserva', function () {
    Storage::fake('public');

    $creada = $this->withToken($this->token)->post('/api/categorias-servicios', [
        'nombre' => 'Barba',
        'imagen' => UploadedFile::fake()->image('barba.jpg', 400, 400),
    ])->assertCreated()->json('data');

    expect($creada['imagen_url'])->not->toBeNull();

    $editada = $this->withToken($this->token)->post('/api/categorias-servicios/'.$creada['id'], [
        '_method' => 'PUT',
        'nombre' => 'Barba y afeitado',
    ])->assertOk()->json('data');

    // No mandar `imagen` en multipart significa "déjala como está", no
    // "bórrala". Es el error clásico de este endpoint.
    expect($editada['imagen_url'])->toBe($creada['imagen_url']);
});

/*
|--------------------------------------------------------------------------
| Imagen
|--------------------------------------------------------------------------
*/

test('la imagen se re-encodifica a webp con nombre UUID', function () {
    Storage::fake('public');

    $url = $this->withToken($this->token)->post('/api/categorias-servicios', [
        'nombre' => 'Cortes',
        'imagen' => UploadedFile::fake()->image('mi foto del negocio.jpg', 300, 200),
    ])->assertCreated()->json('data.imagen_url');

    // El nombre original lo escribe un desconocido y nunca toca el disco.
    expect($url)->toContain('categorias/')
        ->and($url)->toEndWith('.webp')
        ->and($url)->not->toContain('mi foto');

    $ruta = 'categorias/'.basename($url);
    Storage::disk('public')->assertExists($ruta);

    // Lo guardado es una imagen de verdad, no un archivo que se llamaba .jpg.
    expect(substr(Storage::disk('public')->get($ruta), 8, 4))->toBe('WEBP');
});

test('una imagen de más de 2 MB → 422', function () {
    Storage::fake('public');

    // El Accept explícito importa: sin él, un fallo de validación en multipart
    // responde 302 (redirect del formulario clásico) en vez del 422 que el BFF
    // espera. Los otros tests no lo notan porque no fallan.
    $this->withToken($this->token)->post('/api/categorias-servicios', [
        'nombre' => 'Cortes',
        'imagen' => UploadedFile::fake()->create('grande.jpg', 3000, 'image/jpeg'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['imagen']);
});

/*
|--------------------------------------------------------------------------
| Borrado
|--------------------------------------------------------------------------
*/

test('borrar responde 204 y deja sus servicios SIN categoría, no los borra', function () {
    crearCategorias($this->tenant, [['nombre' => 'Cortes']]);

    $id = $this->tenant->run(fn () => DB::table('categoria_servicios')->value('id'));

    $this->tenant->run(fn () => DB::table('servicios')->insert([
        'categoria_servicio_id' => $id,
        'nombre' => 'Corte clásico',
        'precio' => 25,
        'duracion_min' => 30,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->withToken($this->token)->deleteJson('/api/categorias-servicios/'.$id)
        ->assertNoContent();

    $this->tenant->run(function () {
        expect(DB::table('categoria_servicios')->count())->toBe(0);

        // Un reporte de marzo no puede cambiar porque hoy se reordene el catálogo.
        $servicio = DB::table('servicios')->first();
        expect($servicio)->not->toBeNull()
            ->and($servicio->categoria_servicio_id)->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| Aislación entre tenants (regla 4 del CLAUDE.md)
|--------------------------------------------------------------------------
*/

test('las categorías de otro negocio NO se ven ni se tocan: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());

    $otroDueno = User::create([
        'tenant_id' => $otro->id,
        'nombre' => 'Luis',
        'apellido' => 'Ramos',
        'email' => 'luis+'.$otro->id.'@correo.pe',
        'password' => 'secreta123',
        'rol' => 'dueno',
    ]);

    (new ProvisionTenantDatabase($otro))->handle();

    // Id explícito y alto: cada BD de tenant arranca su autoincremento en 1,
    // así que sin esto los dos negocios tendrían una categoría con id 1 y el
    // test pasaría por casualidad — devolviendo la PROPIA, no un 404.
    crearCategorias($otro, [['id' => 900, 'nombre' => 'Manicure']]);
    $idAjeno = 900;

    crearCategorias($this->tenant, [['nombre' => 'Cortes']]);

    // El listado de uno no contiene nada del otro.
    $this->withToken($this->token)->getJson('/api/categorias-servicios')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Cortes');

    // Y su id concreto no existe aquí. 404 y no 403: un 403 confirmaría que
    // ese registro existe en algún sitio.
    $this->withToken($this->token)->getJson('/api/categorias-servicios/'.$idAjeno)
        ->assertNotFound();

    $this->withToken($this->token)->deleteJson('/api/categorias-servicios/'.$idAjeno)
        ->assertNotFound();

    // Y sigue intacta en su casa.
    expect($otro->run(fn () => DB::table('categoria_servicios')->count()))->toBe(1);

    // El dueño del otro negocio sí la ve. `forgetGuards()` porque dentro de un
    // mismo test el guard de Sanctum conserva al usuario ya resuelto.
    $this->app['auth']->forgetGuards();

    $this->withToken($otroDueno->createToken('t')->plainTextToken)
        ->getJson('/api/categorias-servicios/'.$idAjeno)
        ->assertOk()
        ->assertJsonPath('data.nombre', 'Manicure');
});

test('sin sesión → 401', function () {
    $this->getJson('/api/categorias-servicios')->assertStatus(401);
});

test('la imagen se sirve por una URL con el tenant, y la de otro negocio da 404', function () {
    Storage::fake('public');

    $url = $this->withToken($this->token)->post('/api/categorias-servicios', [
        'nombre' => 'Cortes',
        'imagen' => UploadedFile::fake()->image('cortes.jpg', 300, 200),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.imagen_url');

    // La URL lleva el id del negocio: sin él, todos los tenants compartirían
    // el espacio /storage y solo una carpeta física podría estar detrás.
    expect($url)->toContain('/api/archivos/'.$this->tenant->id.'/categorias/');

    // Se sirve sin sesión: la tienda pública la enseña a visitantes sin cuenta.
    $this->get($url)->assertOk()->assertHeader('content-type', 'image/webp');

    // Y el mismo archivo pedido bajo OTRO negocio no existe.
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    (new ProvisionTenantDatabase($otro))->handle();

    $this->get(str_replace($this->tenant->id, $otro->id, $url))->assertNotFound();

    // Y no se puede salir de la carpeta del negocio.
    $this->get('/api/archivos/'.$this->tenant->id.'/../../.env')->assertNotFound();
});

test('imagen_eliminar deja la categoría sin imagen', function () {
    Storage::fake('public');

    $creada = $this->withToken($this->token)->post('/api/categorias-servicios', [
        'nombre' => 'Cortes',
        'imagen' => UploadedFile::fake()->image('cortes.jpg', 300, 200),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');

    $ruta = 'categorias/'.basename($creada['imagen_url']);
    Storage::disk('public')->assertExists($ruta);

    // Hace falta un campo propio porque la AUSENCIA de `imagen` ya significa
    // "déjala como está" — si no, cada edición borraría la foto.
    $this->withToken($this->token)->post('/api/categorias-servicios/'.$creada['id'], [
        '_method' => 'PUT',
        'nombre' => 'Cortes',
        'imagen_eliminar' => 1,
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.imagen_url', null);

    // Y el archivo se va del disco, no solo la referencia.
    Storage::disk('public')->assertMissing($ruta);
});
