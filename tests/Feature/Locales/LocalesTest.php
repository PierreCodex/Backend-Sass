<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Local;
use App\Models\Plan;
use App\Models\Profesional;
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
function enviarLocal(object $test, array $datos, ?int $id = null): TestResponse
{
    $url = $id === null ? '/api/locales' : '/api/locales/'.$id;

    if ($id !== null) {
        $datos['_method'] = 'PUT';
    }

    return $test->withToken($test->token)->post($url, $datos, ['Accept' => 'application/json']);
}

function localValido(array $extra = []): array
{
    return array_merge([
        'nombre' => 'El Rosal Piura Centro',
        'direccion' => 'Av. Grau 145',
        'telefono' => '073 445889',
        'color' => '#4f46e5',
        'horario_desde' => '09:00',
        'horario_hasta' => '20:00',
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| El principal
|--------------------------------------------------------------------------
*/

/*
 * Lo decide el backend, no el formulario. Si lo eligiera el negocio podría
 * quedarse sin ninguno con dos peticiones — y el principal es del que cuelga
 * la tienda pública y el único que no se puede borrar.
 */
test('el primer local nace como principal; el segundo, no', function () {
    enviarLocal($this, localValido())
        ->assertCreated()
        ->assertJsonPath('data.es_principal', true);

    enviarLocal($this, localValido(['nombre' => 'El Rosal Castilla']))
        ->assertCreated()
        ->assertJsonPath('data.es_principal', false);
});

/*
 * Lo nuevo nace ASIGNADO (Story 1.3): desde G-3 solo se agenda a quien está
 * habilitado en la sede. Una sede vacía dejaría sin agenda a un negocio de una
 * sola sede; entran los profesionales activos y el negocio recorta después.
 */
test('una sede nueva habilita a todos los profesionales, también los de baja, no a los borrados', function () {
    [$activo, $inactivo] = $this->tenant->run(function () {
        $inactivo = Profesional::create(['nombre' => 'De baja', 'activo' => false]);
        $borrado = Profesional::create(['nombre' => 'Borrado']);
        $borrado->delete();

        // El dueño independiente ya tiene ficha activa desde el provisioning.
        return [Profesional::where('activo', true)->value('id'), $inactivo->id];
    });

    $id = enviarLocal($this, localValido())->assertCreated()->json('data.id');

    $filas = $this->tenant->run(fn () => DB::table('local_profesional')->where('local_id', $id)->orderBy('profesional_id')->get());

    expect($filas->pluck('profesional_id')->map(fn ($v) => (int) $v)->all())->toBe([$activo, $inactivo])
        ->and($filas->every(fn ($f) => (bool) $f->habilitado))->toBeTrue();
});

test('`es_principal` se ignora aunque se mande a mano', function () {
    enviarLocal($this, localValido())->assertCreated();

    enviarLocal($this, localValido(['nombre' => 'Castilla', 'es_principal' => 1]))
        ->assertCreated()
        ->assertJsonPath('data.es_principal', false);
});

test('el local principal no se puede eliminar; otro sí', function () {
    $principal = enviarLocal($this, localValido())->assertCreated()->json('data.id');
    $segundo = enviarLocal($this, localValido(['nombre' => 'Castilla']))->assertCreated()->json('data.id');

    $this->withToken($this->token)->deleteJson('/api/locales/'.$principal)
        ->assertStatus(422)
        ->assertJsonValidationErrors('local');

    $this->withToken($this->token)->deleteJson('/api/locales/'.$segundo)->assertStatus(204);

    expect($this->tenant->run(fn () => Local::count()))->toBe(1);
});

test('el listado pone el principal primero', function () {
    enviarLocal($this, localValido(['nombre' => 'Zzz Castilla']))->assertCreated();
    enviarLocal($this, localValido(['nombre' => 'Aaa Sullana']))->assertCreated();

    $this->withToken($this->token)->getJson('/api/locales')
        ->assertOk()
        // «Zzz» va primero pese al orden alfabético: es el principal.
        ->assertJsonPath('data.0.nombre', 'Zzz Castilla')
        ->assertJsonPath('data.0.es_principal', true);
});

/*
|--------------------------------------------------------------------------
| El horario
|--------------------------------------------------------------------------
*/

/*
 * Se guarda como JSON aunque el contrato lo pida plano: el día que un negocio
 * quiera «sábado hasta la 1, domingo cerrado» cabe sin migrar N bases.
 */
test('el horario se guarda en JSON y sale plano', function () {
    enviarLocal($this, localValido())
        ->assertCreated()
        ->assertJsonPath('data.horario_desde', '09:00')
        ->assertJsonPath('data.horario_hasta', '20:00');

    $guardado = $this->tenant->run(fn () => DB::table('locales')->value('horario'));

    // `toMatchArray`: el orden de las claves de un JSON no significa nada.
    expect(json_decode($guardado, true))->toMatchArray(['apertura' => '09:00', 'cierre' => '20:00']);
});

/*
 * En los datos reales hay un local con «21:00 – 16:07»: la hora de fin antes
 * que la de inicio. La ficha lo señala y pide validarlo — es un error de
 * captura, y de ahí saldrían huecos imposibles cuando el Sprint 4 calcule la
 * disponibilidad.
 */
test('una hora de cierre anterior a la de apertura da 422', function () {
    enviarLocal($this, localValido(['horario_desde' => '21:00', 'horario_hasta' => '16:07']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('horario_hasta');
});

test('el local puede quedarse sin horario', function () {
    enviarLocal($this, localValido(['horario_desde' => '', 'horario_hasta' => '']))
        ->assertCreated()
        ->assertJsonPath('data.horario_desde', null)
        ->assertJsonPath('data.horario_hasta', null);
});

/*
|--------------------------------------------------------------------------
| Validación e imágenes
|--------------------------------------------------------------------------
*/

test('un color que no es hex de 6 da 422, no un error de servidor', function () {
    enviarLocal($this, localValido(['color' => 'azul']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('color');
});

test('una latitud fuera del mundo da 422', function () {
    enviarLocal($this, localValido(['latitud' => 200]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('latitud');
});

test('las coordenadas salen como números, no como el string del DECIMAL', function () {
    $data = enviarLocal($this, localValido(['latitud' => -5.1936, 'longitud' => -80.6328]))
        ->assertCreated()
        ->json('data');

    expect($data['latitud'])->toBe(-5.1936)
        ->and($data['longitud'])->toBe(-80.6328);
});

test('el banner se sube, se conserva sin reenviarlo y se quita con su bandera', function () {
    $id = enviarLocal($this, localValido(['banner' => UploadedFile::fake()->image('b.png')]))
        ->assertCreated()->json('data.id');

    $url = $this->withToken($this->token)->getJson('/api/locales/'.$id)->json('data.banner_url');

    expect($url)->toContain('/archivos/'.$this->tenant->id.'/locales/');

    // No mandar el archivo significa «déjalo como está».
    enviarLocal($this, localValido(), $id)->assertOk()->assertJsonPath('data.banner_url', $url);

    enviarLocal($this, localValido(['banner_eliminar' => 1]), $id)
        ->assertOk()
        ->assertJsonPath('data.banner_url', null);
});

/*
|--------------------------------------------------------------------------
| Listado y aislación
|--------------------------------------------------------------------------
*/

test('search encuentra por nombre y por dirección', function () {
    enviarLocal($this, localValido())->assertCreated();

    foreach (['Piura', 'Grau'] as $busqueda) {
        $this->withToken($this->token)->getJson('/api/locales?search='.$busqueda)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
});

test('los locales de otro negocio: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    $ajeno = $otro->run(fn () => Local::create(['nombre' => 'Del vecino'])->id);

    expect($this->tenant->run(fn () => Local::find($ajeno)))->toBeNull();

    $this->withToken($this->token)->getJson("/api/locales/{$ajeno}")->assertNotFound();
    $this->withToken($this->token)->deleteJson("/api/locales/{$ajeno}")->assertNotFound();
});

test('sin sesión → 401', function () {
    $this->getJson('/api/locales')->assertStatus(401);
});
