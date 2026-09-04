<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\Tenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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
function enviarConfiguracion(object $test, array $datos): TestResponse
{
    return $test->withToken($test->token)->post(
        '/api/configuracion',
        $datos + ['_method' => 'PUT'],
        ['Accept' => 'application/json'],
    );
}

/*
|--------------------------------------------------------------------------
| Lectura: columnas y JSON, aplanados
|--------------------------------------------------------------------------
*/

/*
 * Los defaults son de APLICACIÓN, no de la BD: un negocio recién provisionado
 * tiene el JSON vacío y la pantalla necesita algo que pintar.
 */
test('GET devuelve el objeto completo con defaults aunque el JSON esté vacío', function () {
    $this->withToken($this->token)->getJson('/api/configuracion')
        ->assertOk()
        ->assertJsonPath('data.horario_apertura', '09:00')
        ->assertJsonPath('data.horario_cierre', '20:00')
        ->assertJsonPath('data.agenda.modo_intervalo', 'duracion_servicio')
        ->assertJsonPath('data.agenda.intervalo_min', 15)
        ->assertJsonPath('data.informacion_adicional', null)
        // Las columnas sí traen su default de la migración.
        ->assertJsonPath('data.zona_horaria', 'America/Lima')
        ->assertJsonPath('data.sitio_publico_activo', true)
        ->assertJsonPath('data.mostrar_en_marketplace', false);
});

test('las coordenadas salen como números, no como el string del DECIMAL', function () {
    $this->tenant->update(['latitud' => -5.1936, 'longitud' => -80.6328]);

    $data = $this->withToken($this->token)->getJson('/api/configuracion')->json('data');

    expect($data['latitud'])->toBe(-5.1936)
        ->and($data['longitud'])->toBe(-80.6328);
});

/*
|--------------------------------------------------------------------------
| Escritura parcial: la razón de ser de este módulo
|--------------------------------------------------------------------------
*/

/*
 * La pantalla está partida en cuatro secciones de Administración y cada una
 * guarda lo suyo. Si el PUT rellenara el modelo entero, guardar el horario
 * borraría el email y la dirección. Es la misma trampa que se llevó por
 * delante el `telefono_normalizado` de Clientes.
 */
test('guardar una sección NO borra lo que escribió otra', function () {
    enviarConfiguracion($this, [
        'nombre' => 'Clínica El Rosal',
        'email' => 'contacto@elrosal.pe',
        'direccion' => 'Av. Arequipa 1250',
        'informacion_adicional' => 'Estacionamiento para pacientes.',
    ])->assertOk();

    // Otra sección, otra petición: solo el horario.
    enviarConfiguracion($this, [
        'horario_apertura' => '08:30',
        'horario_cierre' => '19:00',
    ])->assertOk();

    $this->withToken($this->token)->getJson('/api/configuracion')
        ->assertOk()
        ->assertJsonPath('data.horario_apertura', '08:30')
        ->assertJsonPath('data.email', 'contacto@elrosal.pe')
        ->assertJsonPath('data.direccion', 'Av. Arequipa 1250')
        // También sobrevive lo que vive en el JSON, no solo las columnas.
        ->assertJsonPath('data.informacion_adicional', 'Estacionamiento para pacientes.');
});

/*
 * `false` no es «vacío»: es un valor que hay que escribir. La distinción es
 * entre clave ausente y clave con valor falso.
 */
test('un booleano en false se guarda; ausente, se respeta el que había', function () {
    enviarConfiguracion($this, ['sitio_publico_activo' => 0])->assertOk()
        ->assertJsonPath('data.sitio_publico_activo', false);

    // Una petición de otra sección no puede resucitarlo.
    enviarConfiguracion($this, ['nombre' => 'Clínica El Rosal'])->assertOk()
        ->assertJsonPath('data.sitio_publico_activo', false);
});

test('un campo se puede vaciar de verdad mandándolo vacío', function () {
    enviarConfiguracion($this, ['email' => 'contacto@elrosal.pe'])->assertOk();

    // En multipart el vacío llega como "", no como null.
    enviarConfiguracion($this, ['email' => ''])->assertOk()
        ->assertJsonPath('data.email', null);
});

test('el reparto: unas cosas a columnas y otras al JSON', function () {
    enviarConfiguracion($this, [
        'nombre' => 'Clínica El Rosal',
        'informacion_adicional' => 'Estacionamiento.',
        'horario_apertura' => '08:00',
        'agenda' => ['modo_intervalo' => 'fijo', 'intervalo_min' => 30],
    ])->assertOk();

    $tenant = Tenant::find($this->tenant->id);

    expect($tenant->nombre)->toBe('Clínica El Rosal')
        ->and($tenant->configuracion['informacion_adicional'])->toBe('Estacionamiento.')
        ->and($tenant->configuracion['horario']['apertura'])->toBe('08:00')
        // `toMatchArray` y no `toBe`: el orden de las claves de un JSON no
        // significa nada, y `validated()` no promete conservarlo.
        ->and($tenant->configuracion['agenda'])->toMatchArray(['modo_intervalo' => 'fijo', 'intervalo_min' => 30])
        // Lo del JSON no se cuela como columna.
        ->and($tenant->getAttributes())->not->toHaveKey('informacion_adicional');
});

/*
 * El slug forma el subdominio público: cambiarlo dejaría muerto cada enlace
 * que el negocio haya repartido. Lo fija el paso 1 del onboarding y se acabó.
 */
test('el slug sale en el GET pero el PUT lo ignora', function () {
    $this->tenant->update(['slug' => 'el-rosal']);

    enviarConfiguracion($this, ['slug' => 'otro-nombre', 'nombre' => 'Clínica El Rosal'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'el-rosal');

    expect(Tenant::find($this->tenant->id)->slug)->toBe('el-rosal');
});

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

test('una latitud fuera del mundo da 422 en su campo', function () {
    enviarConfiguracion($this, ['latitud' => 200])
        ->assertStatus(422)
        ->assertJsonValidationErrors('latitud');
});

/*
 * Cada timestamp de negocio se interpreta en esta zona (regla 6). Con texto
 * libre entraría "Lima" o "GMT-5", que no son zonas IANA, y a partir de ahí
 * todas las horas de la agenda saldrían mal sin que nadie sepa por qué.
 */
test('una zona horaria que no existe da 422', function () {
    enviarConfiguracion($this, ['zona_horaria' => 'Lima'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('zona_horaria');

    enviarConfiguracion($this, ['zona_horaria' => 'America/Lima'])->assertOk();
});

/*
 * El select se llena con esto, asi que la garantia que importa no es cuantas
 * hay: es que **todas las que se ofrecen las acepte el validador**. Un select
 * que propone algo que luego da 422 es peor que un campo de texto, porque el
 * usuario elige de una lista y aun asi se le rechaza.
 */
test('el GET trae las zonas horarias, y el PUT acepta TODAS las que ofrece', function () {
    $zonas = $this->withToken($this->token)->getJson('/api/configuracion')
        ->assertOk()
        ->json('zonas_horarias');

    expect($zonas)->toContain('America/Lima')
        ->and($zonas)->toContain('UTC');

    $rechazadas = collect($zonas)->reject(
        fn (string $zona) => Validator::make(
            ['zona_horaria' => $zona],
            ['zona_horaria' => 'timezone'],
        )->passes(),
    );

    expect($rechazadas)->toBeEmpty();
});

test('el PUT no repite la lista: quien guarda ya la tiene', function () {
    enviarConfiguracion($this, ['zona_horaria' => 'America/Bogota'])
        ->assertOk()
        ->assertJsonPath('data.zona_horaria', 'America/Bogota')
        ->assertJsonMissingPath('zonas_horarias');
});

test('un color que no es hex de 6 da 422, no un error de servidor', function () {
    enviarConfiguracion($this, ['color_primario' => 'azul'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('color_primario');
});

test('la rejilla fija exige decir cada cuántos minutos', function () {
    enviarConfiguracion($this, ['agenda' => ['modo_intervalo' => 'fijo']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('agenda.intervalo_min');

    // Con `duracion_servicio` el paso lo pone el servicio: no hace falta.
    enviarConfiguracion($this, ['agenda' => ['modo_intervalo' => 'duracion_servicio']])->assertOk();
});

test('el nombre se puede omitir, pero no mandar vacío', function () {
    enviarConfiguracion($this, ['email' => 'contacto@elrosal.pe'])->assertOk();

    enviarConfiguracion($this, ['nombre' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('nombre');
});

/*
|--------------------------------------------------------------------------
| Logo y portada
|--------------------------------------------------------------------------
*/

test('el logo se sube, se conserva sin reenviarlo y se quita con su bandera', function () {
    $url = enviarConfiguracion($this, ['logo' => UploadedFile::fake()->image('logo.png')])
        ->assertOk()
        ->json('data.logo_url');

    // La URL lleva el segmento del tenant: cada negocio tiene su espacio.
    expect($url)->toContain('/archivos/'.$this->tenant->id.'/negocio/');

    // Guardar otra sección no puede borrar el logo.
    enviarConfiguracion($this, ['nombre' => 'Clínica El Rosal'])
        ->assertOk()
        ->assertJsonPath('data.logo_url', $url);

    enviarConfiguracion($this, ['logo_eliminar' => 1])
        ->assertOk()
        ->assertJsonPath('data.logo_url', null);
});

/*
|--------------------------------------------------------------------------
| Onboarding y aislación
|--------------------------------------------------------------------------
*/

test('informar el horario marca el paso del onboarding', function () {
    $paso = fn () => collect($this->withToken($this->token)->getJson('/api/onboarding')->json('data.pasos'))
        ->firstWhere('clave', 'horario_local')['completado'];

    expect($paso())->toBeFalse();

    enviarConfiguracion($this, ['horario_apertura' => '08:00'])->assertOk();

    expect($paso())->toBeTrue();
});

test('guardar otra cosa NO marca el paso del horario', function () {
    enviarConfiguracion($this, ['nombre' => 'Clínica El Rosal'])->assertOk();

    $paso = collect($this->withToken($this->token)->getJson('/api/onboarding')->json('data.pasos'))
        ->firstWhere('clave', 'horario_local')['completado'];

    expect($paso)->toBeFalse();
});

/*
 * La aislación de este módulo es distinta a la de los demás: no hay `{id}` en
 * la ruta ni base separada que haga de red. La fila del vecino está en la
 * MISMA tabla, a un id de distancia. Lo único que la protege es que el negocio
 * salga del token.
 */
test('un PUT del negocio A no roza la fila del negocio B', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    $otro->update(['nombre' => 'Barbería del vecino', 'email' => 'vecino@barberia.pe']);

    enviarConfiguracion($this, [
        'nombre' => 'Clínica El Rosal',
        'email' => 'contacto@elrosal.pe',
    ])->assertOk();

    $vecino = Tenant::find($otro->id);

    expect($vecino->nombre)->toBe('Barbería del vecino')
        ->and($vecino->email)->toBe('vecino@barberia.pe');
});

test('sin sesión → 401', function () {
    $this->getJson('/api/configuracion')->assertStatus(401);
    $this->putJson('/api/configuracion', ['nombre' => 'Nada'])->assertStatus(401);
});
