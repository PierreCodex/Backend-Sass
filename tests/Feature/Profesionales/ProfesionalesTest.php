<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\Profesional;
use App\Models\Rol;
use App\Models\User;
use App\Models\Usuario;
use App\Notifications\InvitacionNotification;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;

    Storage::fake('public');
    Notification::fake();
});

afterEach(fn () => limpiarBasesDeTenants());

/** Multipart con Accept explícito: sin él un 422 sale como redirect 302. */
function enviarProfesional(object $test, array $datos, ?int $id = null): TestResponse
{
    $url = $id === null ? '/api/profesionales' : '/api/profesionales/'.$id;

    if ($id !== null) {
        $datos['_method'] = 'PUT';
    }

    return $test->withToken($test->token)->post($url, $datos, ['Accept' => 'application/json']);
}

function profesionalValido(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Dra. Carmen Ríos',
        'cargo' => 'doctor cirujano',
        'telefono' => '+51987441220',
        'tipo_pago' => 'comision',
        'comision_porcentaje' => 30,
        'horario' => [
            ['dia' => 1, 'activo' => 1, 'desde' => '09:00', 'hasta' => '18:00',
                'breaks' => [['desde' => '13:00', 'hasta' => '14:00']]],
            ['dia' => 2, 'activo' => 1, 'desde' => '09:00', 'hasta' => '18:00', 'breaks' => []],
        ],
    ], $extra);
}

function rolDe(string $clave): int
{
    return test()->tenant->run(fn () => Rol::where('clave', $clave)->value('id'));
}

/*
|--------------------------------------------------------------------------
| Alta: con cuenta y sin ella
|--------------------------------------------------------------------------
*/

/*
 * El caso que motivó separar usuarios de profesionales. Una barbería con cinco
 * barberos que nunca tocan el sistema no debería tener que inventarles cinco
 * correos — y un correo inventado es peor que ninguno: parece un canal y no lo es.
 */
test('un profesional SIN cuenta se crea con lo que se sabe de él', function () {
    $data = enviarProfesional($this, profesionalValido())->assertCreated()->json('data');

    expect($data['nombre'])->toBe('Dra. Carmen Ríos')
        ->and($data['usuario'])->toBeNull()
        ->and((float) $data['comision_porcentaje'])->toBe(30.0);

    // Ni una fila de más en la central.
    expect(User::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('con «darle acceso» se crea la cuenta y se le manda la invitación', function () {
    $data = enviarProfesional($this, profesionalValido([
        'usuario' => ['email' => 'carmen@elrosal.pe', 'rol_id' => rolDe('profesional')],
    ]))->assertCreated()->json('data');

    expect($data['usuario']['email'])->toBe('carmen@elrosal.pe')
        ->and($data['usuario']['rol']['clave'])->toBe('profesional');

    $central = User::where('email', 'carmen@elrosal.pe')->firstOrFail();

    Notification::assertSentTo($central, InvitacionNotification::class);

    /*
     * Nadie escribió una contraseña: ni el dueño en el formulario ni nosotros
     * una por defecto. La cuenta existe pero todavía no se puede usar.
     */
    expect($central->email_verified_at)->toBeNull();

    $this->postJson('/api/login', ['email' => 'carmen@elrosal.pe', 'password' => 'secreta123'])
        ->assertStatus(422);
});

test('el email ya usado en OTRO negocio da 422 y no deja ficha a medias', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());

    User::create([
        'tenant_id' => $otro->id,
        'nombre' => 'Ya', 'apellido' => 'Existe',
        'email' => 'carmen@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
    ]);

    $errores = enviarProfesional($this, profesionalValido([
        'usuario' => ['email' => 'carmen@elrosal.pe', 'rol_id' => rolDe('profesional')],
    ]))->assertStatus(422)
        ->assertJsonValidationErrors(['usuario.email'])
        ->json('errors');

    /*
     * Y en castellano. La clave del mensaje lleva el atributo COMPLETO
     * (`usuario.email.unique`): con `email.unique` a secas Laravel no lo
     * encuentra y cae al suyo, en ingles.
     *
     * Se lee del array y no con `assertJsonPath`, que interpreta el punto de
     * `usuario.email` como un nivel mas de anidamiento.
     */
    expect($errores['usuario.email'][0])->toBe('Ya existe una cuenta con ese correo.');

    // El alta se para ANTES de tocar la base del negocio.
    $this->tenant->run(function () {
        expect(Profesional::where('nombre', 'Dra. Carmen Ríos')->count())->toBe(0);
    });
});

/*
 * La escalada que reporto el frontend el 2026-09-05.
 *
 * El candado de «un solo administrador general» vivia SOLO en
 * `UsuarioController`, y este camino entra por debajo: `/profesionales` acepta
 * el objeto `usuario` y llama derecho a `UsuarioService::crear()`. Como
 * `/profesionales` esta detras de `puede:empleados,gestionar` —que el
 * administrador LOCAL tiene— cualquiera de ellos podia darse de alta con el
 * `rol_id` del general y quedarse con facturacion y con la capacidad de
 * repartir roles. Y la cuenta resultante no se podia borrar, porque `destroy`
 * se niega sobre el general.
 *
 * Ahora el candado esta en el service, que es por donde pasan los dos caminos.
 */
test('no se puede fabricar un segundo administrador general desde profesionales', function () {
    enviarProfesional($this, profesionalValido([
        'usuario' => ['email' => 'colado@elrosal.pe', 'rol_id' => rolDe('admin_general')],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('usuario.rol_id');

    // Ni cuenta central, ni fila en el negocio, ni ficha de profesional.
    expect(User::where('email', 'colado@elrosal.pe')->withTrashed()->count())->toBe(0);

    $this->tenant->run(function () {
        expect(Usuario::count())->toBe(1)
            ->and(Profesional::where('nombre', 'Dra. Carmen Ríos')->count())->toBe(0);
    });
});

test('a quien ya tiene cuenta se le puede dar acceso al editar', function () {
    $id = enviarProfesional($this, profesionalValido())->assertCreated()->json('data.id');

    enviarProfesional($this, profesionalValido([
        'usuario' => ['email' => 'carmen@elrosal.pe', 'rol_id' => rolDe('profesional')],
    ]), $id)->assertOk()->assertJsonPath('data.usuario.email', 'carmen@elrosal.pe');
});

/*
|--------------------------------------------------------------------------
| Horario
|--------------------------------------------------------------------------
*/

test('el horario vuelve SIEMPRE como 7 días, con la forma que se envió', function () {
    $data = enviarProfesional($this, profesionalValido())->assertCreated()->json('data');

    expect($data['horario'])->toHaveCount(7)
        ->and($data['horario'][0]['dia'])->toBe(1)
        ->and($data['horario'][0]['breaks'][0]['desde'])->toBe('13:00')
        // Un día que no se mandó vuelve apagado, no ausente.
        ->and($data['horario'][6]['activo'])->toBeFalse();
});

test('un break fuera de la jornada o solapado con otro → 422', function () {
    enviarProfesional($this, profesionalValido(['horario' => [
        ['dia' => 1, 'activo' => 1, 'desde' => '09:00', 'hasta' => '18:00',
            'breaks' => [['desde' => '19:00', 'hasta' => '20:00']]],
    ]]))->assertStatus(422)->assertJsonValidationErrors(['horario.0.breaks.0.desde']);

    enviarProfesional($this, profesionalValido(['horario' => [
        ['dia' => 1, 'activo' => 1, 'desde' => '09:00', 'hasta' => '18:00', 'breaks' => [
            ['desde' => '13:00', 'hasta' => '14:00'],
            ['desde' => '13:30', 'hasta' => '15:00'],
        ]],
    ]]))->assertStatus(422)->assertJsonValidationErrors(['horario.0.breaks.1.desde']);
});

test('un día activo sin horas, y una hora de fin anterior a la de inicio → 422', function () {
    enviarProfesional($this, profesionalValido(['horario' => [
        ['dia' => 1, 'activo' => 1, 'breaks' => []],
    ]]))->assertStatus(422)->assertJsonValidationErrors(['horario.0.desde']);

    enviarProfesional($this, profesionalValido(['horario' => [
        ['dia' => 1, 'activo' => 1, 'desde' => '18:00', 'hasta' => '09:00', 'breaks' => []],
    ]]))->assertStatus(422)->assertJsonValidationErrors(['horario.0.hasta']);
});

/*
|--------------------------------------------------------------------------
| Pago
|--------------------------------------------------------------------------
*/

test('cambiar a un tipo de pago sin sueldo LIMPIA el monto y el período', function () {
    $id = enviarProfesional($this, profesionalValido([
        'tipo_pago' => 'ambos', 'comision_porcentaje' => 20,
        'monto_sueldo' => 1200, 'periodo_pago' => 'mensual',
    ]))->assertCreated()->json('data.id');

    enviarProfesional($this, profesionalValido(['tipo_pago' => 'comision', 'comision_porcentaje' => 20]), $id)
        ->assertOk()
        ->assertJsonPath('data.monto_sueldo', null)
        ->assertJsonPath('data.periodo_pago', null);
});

test('el tipo de pago con sueldo exige monto y período', function () {
    enviarProfesional($this, profesionalValido(['tipo_pago' => 'sueldo']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['monto_sueldo', 'periodo_pago']);
});

/*
|--------------------------------------------------------------------------
| Cupo del plan
|--------------------------------------------------------------------------
*/

/*
 * Desde la separación, el cupo no mira roles ni flags: una fila activa aquí es
 * una plaza. Quien está en esta tabla presta servicios, y punto.
 */
test('con el plan lleno, el alta da 422', function () {
    // Básico: 2. El dueño ya ocupa una desde el provisioning.
    $this->tenant->update(['plan_id' => Plan::where('slug', 'basico')->value('id')]);

    enviarProfesional($this, profesionalValido(['nombre' => 'Uno']))->assertCreated();

    enviarProfesional($this, profesionalValido(['nombre' => 'Dos']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['activo']);

    $this->withToken($this->token)->getJson('/api/profesionales/resumen')
        ->assertOk()
        ->assertJsonPath('data.profesionales_activos', 2)
        ->assertJsonPath('data.limite_profesionales', 2);
});

test('una cuenta sin ficha de profesional NO consume plaza', function () {
    $this->tenant->update(['plan_id' => Plan::where('slug', 'basico')->value('id')]);

    // La recepcionista entra al panel y no presta servicios: es gratis.
    $this->withToken($this->token)->postJson('/api/usuarios', [
        'nombre' => 'Lucía',
        'email' => 'recepcion@elrosal.pe',
        'rol_id' => rolDe('admin_local'),
    ])->assertCreated();

    $this->withToken($this->token)->getJson('/api/profesionales/resumen')
        ->assertOk()
        ->assertJsonPath('data.profesionales_activos', 1);
});

test('reactivar a alguien también pasa por el cupo', function () {
    $this->tenant->update(['plan_id' => Plan::where('slug', 'basico')->value('id')]);

    $id = enviarProfesional($this, profesionalValido(['nombre' => 'Uno']))->assertCreated()->json('data.id');

    enviarProfesional($this, profesionalValido(['nombre' => 'Uno', 'activo' => 0]), $id)->assertOk();
    enviarProfesional($this, profesionalValido(['nombre' => 'Dos']))->assertCreated();

    /*
     * Si el cupo solo mirase el alta, bastaría con dar de baja a uno, crear a
     * otro y reactivar al primero para tener tres con un plan de dos.
     */
    enviarProfesional($this, profesionalValido(['nombre' => 'Uno', 'activo' => 1]), $id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['activo']);
});

/*
|--------------------------------------------------------------------------
| Listado, baja y aislación
|--------------------------------------------------------------------------
*/

test('el listado trae el resumen del cupo, para ahorrarse una petición', function () {
    $this->withToken($this->token)->getJson('/api/profesionales')
        ->assertOk()
        ->assertJsonPath('resumen.profesionales_activos', 1)
        ->assertJsonPath('resumen.limite_profesionales', 5);
});

test('search encuentra por nombre, por cargo y por el correo de su cuenta', function () {
    enviarProfesional($this, profesionalValido([
        'usuario' => ['email' => 'carmen@elrosal.pe', 'rol_id' => rolDe('profesional')],
    ]))->assertCreated();

    foreach (['Carmen', 'cirujano', 'carmen@elrosal'] as $busqueda) {
        $this->withToken($this->token)->getJson('/api/profesionales?search='.$busqueda)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
});

/*
 * La diferencia práctica más visible de haber separado los dos conceptos: dejar
 * de atender y quedarse fuera del sistema dejan de ser la misma decisión.
 */
test('dar de baja la ficha NO le quita la cuenta', function () {
    $id = enviarProfesional($this, profesionalValido([
        'usuario' => ['email' => 'carmen@elrosal.pe', 'rol_id' => rolDe('profesional')],
    ]))->assertCreated()->json('data.id');

    $this->withToken($this->token)->deleteJson('/api/profesionales/'.$id)->assertStatus(204);

    $this->tenant->run(function () {
        expect(Profesional::count())->toBe(1)          // solo queda el dueño
            ->and(Usuario::count())->toBe(2);           // la cuenta sigue en pie
    });

    expect(User::where('email', 'carmen@elrosal.pe')->exists())->toBeTrue();
});

test('los profesionales de otro negocio: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    // Uno NUEVO en el otro negocio: el id 1 existe en las dos bases y pedirlo
    // pasaría el test sin probar nada.
    $ajeno = $otro->run(fn () => Profesional::create(['nombre' => 'Del vecino'])->id);

    expect($this->tenant->run(fn () => Profesional::find($ajeno)))->toBeNull();

    $this->withToken($this->token)->getJson("/api/profesionales/{$ajeno}")->assertNotFound();
    $this->withToken($this->token)->deleteJson("/api/profesionales/{$ajeno}")->assertNotFound();
});

test('sin sesión → 401', function () {
    $this->getJson('/api/profesionales')->assertStatus(401);
    $this->getJson('/api/profesionales/resumen')->assertStatus(401);
});

test('la foto se sube, se conserva sin reenviarla y se quita con su bandera', function () {
    $id = enviarProfesional($this, profesionalValido(['foto' => UploadedFile::fake()->image('c.png')]))
        ->assertCreated()->json('data.id');

    $url = $this->withToken($this->token)->getJson('/api/profesionales/'.$id)->json('data.foto_url');
    expect($url)->not->toBeNull();

    // No mandar el archivo significa «déjala como está».
    enviarProfesional($this, profesionalValido(), $id)->assertOk()->assertJsonPath('data.foto_url', $url);

    enviarProfesional($this, profesionalValido(['foto_eliminar' => 1]), $id)
        ->assertOk()
        ->assertJsonPath('data.foto_url', null);
});

test('el administrador general aparece en el listado si atiende', function () {
    $this->withToken($this->token)->getJson('/api/profesionales')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'María Quispe')
        ->assertJsonPath('data.0.usuario.rol.clave', 'admin_general');

    // Y su fila la creó el provisioning solo porque se registró como
    // `independiente`. Un negocio con equipo empieza sin profesionales.
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail(), '3-5');
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    expect($otro->run(fn () => Profesional::count()))->toBe(0)
        ->and($otro->run(fn () => Usuario::count()))->toBe(1);
});

test('el JSON del horario no se filtra crudo: excepciones van aparte', function () {
    $data = enviarProfesional($this, profesionalValido([
        'excepciones' => [['fecha' => '2026-12-25', 'disponible' => 0, 'nota' => 'Navidad']],
    ]))->assertCreated()->json('data');

    expect($data['excepciones'])->toHaveCount(1)
        ->and($data['excepciones'][0]['fecha'])->toBe('2026-12-25')
        ->and($data['horario'])->toHaveCount(7);

    // En la columna es un solo JSON; el Resource lo separa.
    $guardado = $this->tenant->run(fn () => DB::table('profesionales')
        ->where('nombre', 'Dra. Carmen Ríos')->value('horario'));

    expect(json_decode($guardado, true))->toHaveKeys(['dias', 'excepciones']);
});
