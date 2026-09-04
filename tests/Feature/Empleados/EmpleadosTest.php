<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\Profesional;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
function enviarEmpleado(object $test, array $datos, ?int $id = null): TestResponse
{
    $url = $id === null ? '/api/empleados' : '/api/empleados/'.$id;

    if ($id !== null) {
        $datos['_method'] = 'PUT';
    }

    return $test->withToken($test->token)->post($url, $datos, ['Accept' => 'application/json']);
}

/** El id de un rol de sistema dentro de la base del negocio. */
function rolDe(string $clave): int
{
    return test()->tenant->run(fn () => Rol::where('clave', $clave)->value('id'));
}

function empleadoValido(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Dra. Carmen Ríos',
        'email' => 'carmen.rios@elrosal.pe',
        'password' => 'secreta123',
        'rol_id' => rolDe('profesional'),
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

/*
|--------------------------------------------------------------------------
| Alta: la entidad cruza las dos bases
|--------------------------------------------------------------------------
*/

test('crear escribe en la central y en el negocio, y devuelve el empleado compuesto', function () {
    $data = enviarEmpleado($this, empleadoValido())->assertCreated()->json('data');

    expect($data['nombre'])->toBe('Dra. Carmen Ríos')
        ->and($data['rol']['clave'])->toBe('profesional')
        ->and($data['rol_id'])->toBe(rolDe('profesional'))
        // `usuario` lleva el email: la columna `users.usuario` se eliminó.
        ->and($data['usuario'])->toBe('carmen.rios@elrosal.pe')
        ->and($data['email'])->toBe('carmen.rios@elrosal.pe')
        ->and((float) $data['comision_porcentaje'])->toBe(30.0)
        // Sin sueldo: los campos se quedan en null, no de fantasma.
        ->and($data['monto_sueldo'])->toBeNull()
        ->and($data['periodo_pago'])->toBeNull();

    // La identidad, en la central.
    $usuario = User::where('email', 'carmen.rios@elrosal.pe')->first();

    expect($usuario)->not->toBeNull()
        ->and($usuario->tenant_id)->toBe($this->tenant->id)
        // Verificado de entrada: lo da de alta su jefe, no se registra solo.
        ->and($usuario->email_verified_at)->not->toBeNull();

    // El perfil laboral, en la del negocio, y el id que sale es el de aquí.
    $this->tenant->run(function () use ($data, $usuario) {
        $fila = DB::table('profesionales')->find($data['id']);

        expect($fila->central_user_id)->toBe($usuario->id)
            ->and($fila->cargo)->toBe('doctor cirujano');
    });
});

test('nunca se devuelve la contraseña, ni hasheada', function () {
    $data = enviarEmpleado($this, empleadoValido())->assertCreated()->json('data');

    expect($data)->not->toHaveKey('password');
});

test('el email ya usado en OTRO negocio da 422 y no deja fila huérfana', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());

    User::create([
        'tenant_id' => $otro->id,
        'nombre' => 'Ya', 'apellido' => 'Existe',
        'email' => 'carmen.rios@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
    ]);

    // El email es único GLOBAL: choca contra CUALQUIER negocio, no solo el propio.
    enviarEmpleado($this, empleadoValido())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email'])
        ->assertJsonPath('errors.email.0', 'Ya existe una cuenta con ese correo.');

    expect(User::where('email', 'carmen.rios@elrosal.pe')->count())->toBe(1);

    $this->tenant->run(function () {
        expect(DB::table('profesionales')->count())->toBe(1); // solo el dueño
    });
});

test('si la escritura del negocio falla, el usuario central NO se queda huérfano', function () {
    /*
     * La compensación es la única red que hay: son dos bases y MySQL no hace
     * transacciones entre ellas. Se rompe la tabla del negocio a propósito para
     * que el segundo paso reviente después de que el primero ya escribió.
     */
    $this->tenant->run(fn () => DB::statement('ALTER TABLE profesionales DROP COLUMN horario'));

    enviarEmpleado($this, empleadoValido())->assertStatus(500);

    // Sin compensación quedaría un usuario central invisible en el panel,
    // ocupando su email en el UNIQUE global para siempre.
    expect(User::where('email', 'carmen.rios@elrosal.pe')->withTrashed()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Horario
|--------------------------------------------------------------------------
*/

test('el horario vuelve SIEMPRE como 7 días, con la forma que se envió', function () {
    $data = enviarEmpleado($this, empleadoValido())->assertCreated()->json('data');

    expect($data['horario'])->toHaveCount(7)
        ->and(array_column($data['horario'], 'dia'))->toBe([1, 2, 3, 4, 5, 6, 7]);

    // Los dos que se mandaron vuelven tal cual, con sus breaks.
    expect($data['horario'][0]['activo'])->toBeTrue()
        ->and($data['horario'][0]['desde'])->toBe('09:00')
        ->and($data['horario'][0]['breaks'])->toBe([['desde' => '13:00', 'hasta' => '14:00']]);

    // Los cinco que no, vienen apagados: el formulario necesita 7 tarjetas.
    expect($data['horario'][6]['dia'])->toBe(7)
        ->and($data['horario'][6]['activo'])->toBeFalse()
        ->and($data['horario'][6]['breaks'])->toBe([]);
});

test('las excepciones viajan aparte del horario, aunque compartan columna', function () {
    $data = enviarEmpleado($this, empleadoValido([
        'excepciones' => [
            ['fecha' => '2026-09-10', 'disponible' => 0, 'nota' => 'Permiso médico'],
            ['fecha' => '2026-09-14', 'disponible' => 1, 'desde' => '09:00', 'hasta' => '13:00', 'nota' => 'Medio turno'],
        ],
    ]))->assertCreated()->json('data');

    expect($data['excepciones'])->toHaveCount(2)
        // Un día libre no guarda horas.
        ->and($data['excepciones'][0]['disponible'])->toBeFalse()
        ->and($data['excepciones'][0]['desde'])->toBeNull()
        // Una excepción disponible REEMPLAZA el horario de ese día.
        ->and($data['excepciones'][1]['desde'])->toBe('09:00');

    // En la columna es un solo JSON; separarlo es cosa del Resource.
    $this->tenant->run(function () use ($data) {
        $guardado = json_decode(DB::table('profesionales')->find($data['id'])->horario, true);

        expect($guardado)->toHaveKeys(['dias', 'excepciones']);
    });
});

test('un break fuera de la jornada o solapado con otro → 422', function () {
    // De este JSON sale la disponibilidad del Sprint 4: un break imposible
    // produce huecos imposibles en la agenda, y se descubre con citas dentro.
    enviarEmpleado($this, empleadoValido([
        'horario' => [
            ['dia' => 1, 'activo' => 1, 'desde' => '09:00', 'hasta' => '13:00',
                'breaks' => [['desde' => '14:00', 'hasta' => '15:00']]],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors(['horario.0.breaks.0.desde']);

    enviarEmpleado($this, empleadoValido([
        'horario' => [
            ['dia' => 1, 'activo' => 1, 'desde' => '09:00', 'hasta' => '18:00', 'breaks' => [
                ['desde' => '13:00', 'hasta' => '14:00'],
                ['desde' => '13:30', 'hasta' => '15:00'],
            ]],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors(['horario.0.breaks.1.desde']);
});

test('un día activo sin horas, y una hora de fin anterior a la de inicio → 422', function () {
    enviarEmpleado($this, empleadoValido([
        'horario' => [['dia' => 1, 'activo' => 1, 'breaks' => []]],
    ]))->assertStatus(422)->assertJsonValidationErrors(['horario.0.desde']);

    enviarEmpleado($this, empleadoValido([
        'horario' => [['dia' => 1, 'activo' => 1, 'desde' => '18:00', 'hasta' => '09:00', 'breaks' => []]],
    ]))->assertStatus(422)->assertJsonValidationErrors(['horario.0.hasta']);
});

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

test('editar con la contraseña vacía no la cambia; con una nueva, el login funciona', function () {
    $id = enviarEmpleado($this, empleadoValido())->assertCreated()->json('data.id');

    $hash = User::where('email', 'carmen.rios@elrosal.pe')->value('password');

    // La ficha manda el campo vacío para decir "no la toques".
    enviarEmpleado($this, empleadoValido(['password' => '', 'cargo' => 'jefa de piso']), $id)
        ->assertOk()
        ->assertJsonPath('data.cargo', 'jefa de piso');

    expect(User::where('email', 'carmen.rios@elrosal.pe')->value('password'))->toBe($hash);

    enviarEmpleado($this, empleadoValido(['password' => 'otraclave99']), $id)->assertOk();

    $nuevo = User::where('email', 'carmen.rios@elrosal.pe')->value('password');

    expect($nuevo)->not->toBe($hash)
        ->and(Hash::check('otraclave99', $nuevo))->toBeTrue();

    // Y entra de verdad con ella.
    $this->postJson('/api/login', [
        'email' => 'carmen.rios@elrosal.pe',
        'password' => 'otraclave99',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

test('desactivar a alguien le revoca los tokens y le cierra el login', function () {
    $id = enviarEmpleado($this, empleadoValido())->assertCreated()->json('data.id');

    $empleado = User::where('email', 'carmen.rios@elrosal.pe')->first();
    $empleado->createToken('sesion');

    expect($empleado->tokens()->count())->toBe(1);

    enviarEmpleado($this, empleadoValido(['activo' => 0]), $id)->assertOk();

    /*
     * Sin esto, el desactivado seguiría usando el panel hasta que caducara su
     * sesión, que es justo lo que el dueño creyó impedir.
     *
     * Se comprueba sobre la tabla y sobre el login, y no repitiendo una
     * petición con su token: el guard de Sanctum cachea el usuario resuelto
     * dentro del mismo test, así que ese camino mediría el caché, no la
     * revocación.
     */
    expect($empleado->tokens()->count())->toBe(0);

    $this->postJson('/api/login', [
        'email' => 'carmen.rios@elrosal.pe',
        'password' => 'secreta123',
    ])->assertStatus(422);
});

test('cambiar a un tipo de pago sin sueldo LIMPIA el monto y el período', function () {
    $id = enviarEmpleado($this, empleadoValido([
        'tipo_pago' => 'ambos',
        'monto_sueldo' => 1500,
        'periodo_pago' => 'quincenal',
    ]))->assertCreated()->json('data.id');

    enviarEmpleado($this, empleadoValido(['tipo_pago' => 'comision']), $id)
        ->assertOk()
        ->assertJsonPath('data.monto_sueldo', null)
        ->assertJsonPath('data.periodo_pago', null);
});

test('el tipo de pago con sueldo exige monto y período', function () {
    enviarEmpleado($this, empleadoValido(['tipo_pago' => 'sueldo']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['monto_sueldo', 'periodo_pago']);
});

/*
|--------------------------------------------------------------------------
| Cupo del plan
|--------------------------------------------------------------------------
*/

test('con el plan lleno, el alta da 422 en el campo atiende', function () {
    // Básico: 2 profesionales. El dueño ya ocupa uno desde el provisioning.
    $this->tenant->update(['plan_id' => Plan::where('slug', 'basico')->value('id')]);

    enviarEmpleado($this, empleadoValido(['email' => 'uno@elrosal.pe']))->assertCreated();

    enviarEmpleado($this, empleadoValido(['email' => 'dos@elrosal.pe']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['atiende']);

    $this->withToken($this->token)->getJson('/api/empleados/resumen')
        ->assertOk()
        ->assertJsonPath('data.profesionales_activos', 2)
        ->assertJsonPath('data.limite_profesionales', 2);
});

/*
 * El eje del cupo es la AGENDA, no el rol ni el login. Un usuario del panel no
 * cuesta nada; una persona a la que se le puede reservar, sí. Y no hay nada
 * que vigilar: apagar `atiende` para no pagar quita justo aquello por lo que
 * se pagaba.
 */
test('quien no atiende no ocupa plaza, tenga el rol que tenga', function () {
    $this->tenant->update(['plan_id' => Plan::where('slug', 'basico')->value('id')]);

    // El dueño atiende desde el provisioning: no es excepción, ocupa la suya.
    $this->withToken($this->token)->getJson('/api/empleados/resumen')
        ->assertOk()
        ->assertJsonPath('data.profesionales_activos', 1);

    // Una recepcionista entra al panel pero no sale en la agenda: gratis.
    enviarEmpleado($this, empleadoValido([
        'email' => 'recepcion@elrosal.pe',
        'rol_id' => rolDe('admin'),
        'atiende' => 0,
    ]))->assertCreated();

    $this->withToken($this->token)->getJson('/api/empleados/resumen')
        ->assertOk()
        ->assertJsonPath('data.profesionales_activos', 1);

    // Y todavía cabe el segundo barbero.
    enviarEmpleado($this, empleadoValido(['email' => 'barbero@elrosal.pe']))->assertCreated();

    $this->withToken($this->token)->getJson('/api/empleados/resumen')
        ->assertOk()
        ->assertJsonPath('data.profesionales_activos', 2);
});

/*
 * La otra mitad de la barandilla: si el cupo solo mirase el alta, bastaría con
 * dar de alta a diez con la agenda apagada y encenderlas después.
 */
test('encender la agenda de alguien también pasa por el cupo', function () {
    $this->tenant->update(['plan_id' => Plan::where('slug', 'basico')->value('id')]);

    $id = enviarEmpleado($this, empleadoValido([
        'email' => 'recepcion@elrosal.pe',
        'atiende' => 0,
    ]))->assertCreated()->json('data.id');

    // Dueño + este: el plan se llena.
    enviarEmpleado($this, empleadoValido(['email' => 'barbero@elrosal.pe']))->assertCreated();

    enviarEmpleado($this, empleadoValido([
        'email' => 'recepcion@elrosal.pe',
        'atiende' => 1,
    ]), $id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['atiende']);
});

test('un rol propio del negocio se le puede asignar a alguien', function () {
    $rolId = $this->withToken($this->token)->postJson('/api/roles', [
        'nombre' => 'Recepcionista',
        'permisos' => ['citas' => 'gestionar', 'clientes' => 'gestionar'],
    ])->assertCreated()->json('data.id');

    $data = enviarEmpleado($this, empleadoValido(['rol_id' => $rolId]))
        ->assertCreated()
        ->json('data');

    expect($data['rol']['nombre'])->toBe('Recepcionista')
        // Un rol propio no tiene equivalente central: la `clave` es null.
        ->and($data['rol']['clave'])->toBeNull();

    /*
     * `users.rol` se DERIVA y cae en `profesional`, que es el suelo. En la
     * central el rol solo sirve para saber quién es el dueño; los permisos del
     * panel viven en la tabla `roles` del negocio.
     */
    expect(User::where('email', 'carmen.rios@elrosal.pe')->value('rol'))->toBe('profesional');
});

/*
 * El agujero que motivó la regla del cupo: con la anterior, el negocio creaba
 * «Barbero senior», se lo ponía a todo el mundo y el límite del plan dejaba de
 * existir.
 */
test('un rol propio también consume cupo', function () {
    $this->tenant->update(['plan_id' => Plan::where('slug', 'basico')->value('id')]);

    $rolId = $this->withToken($this->token)->postJson('/api/roles', [
        'nombre' => 'Barbero senior',
        'permisos' => ['citas' => 'gestionar'],
    ])->assertCreated()->json('data.id');

    // Con el dueño dentro, el Básico solo admite uno más.
    enviarEmpleado($this, empleadoValido(['email' => 'uno@elrosal.pe', 'rol_id' => $rolId]))->assertCreated();

    enviarEmpleado($this, empleadoValido(['email' => 'dos@elrosal.pe', 'rol_id' => $rolId]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['atiende']);
});

test('reactivar a un profesional también pasa por el cupo', function () {
    $this->tenant->update(['plan_id' => Plan::where('slug', 'basico')->value('id')]);

    $id = enviarEmpleado($this, empleadoValido(['email' => 'uno@elrosal.pe']))->assertCreated()->json('data.id');

    enviarEmpleado($this, empleadoValido(['email' => 'uno@elrosal.pe', 'activo' => 0]), $id)->assertOk();
    enviarEmpleado($this, empleadoValido(['email' => 'dos@elrosal.pe']))->assertCreated();

    /*
     * Si el cupo solo mirase el alta, bastaría con dar de baja a uno, crear a
     * otro y reactivar al primero para tener tres con un plan de dos.
     */
    enviarEmpleado($this, empleadoValido(['email' => 'uno@elrosal.pe', 'activo' => 1]), $id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['atiende']);
});

test('el listado trae el resumen del cupo, para ahorrarse una petición', function () {
    $this->withToken($this->token)->getJson('/api/empleados')
        ->assertOk()
        // El dueño atiende, así que la cuenta arranca en 1, no en 0.
        ->assertJsonPath('resumen.profesionales_activos', 1)
        ->assertJsonPath('resumen.limite_profesionales', 5);
});

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
*/

test('search encuentra por nombre, por cargo y por el email (el ex-usuario)', function () {
    enviarEmpleado($this, empleadoValido())->assertCreated();

    foreach (['nombre' => 'Carmen', 'cargo' => 'cirujano', 'email' => 'carmen.rios@elrosal'] as $busqueda) {
        $this->withToken($this->token)->getJson('/api/empleados?search='.$busqueda)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Dra. Carmen Ríos');
    }

    $this->withToken($this->token)->getJson('/api/empleados?search=nadie')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('el dueño aparece en el listado: todo el staff tiene ficha', function () {
    $this->withToken($this->token)->getJson('/api/empleados')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rol.clave', 'dueno')
        ->assertJsonPath('data.0.nombre', 'María Quispe');
});

/*
|--------------------------------------------------------------------------
| Barandillas y aislación
|--------------------------------------------------------------------------
*/

test('al dueño no se le cambia el rol ni se le da de baja', function () {
    $id = $this->tenant->run(fn () => DB::table('profesionales')->value('id'));

    enviarEmpleado($this, empleadoValido(['email' => $this->user->email, 'rol_id' => rolDe('admin')]), $id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rol_id']);

    $this->withToken($this->token)->deleteJson('/api/empleados/'.$id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['empleado']);
});

test('nadie se asciende a dueño: ya hay uno', function () {
    enviarEmpleado($this, empleadoValido(['rol_id' => rolDe('dueno')]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rol_id'])
        ->assertJsonPath('errors.rol_id.0', 'Ya hay un dueño en este negocio.');
});

test('dar de baja borra el perfil y la cuenta, y el ex-empleado no entra', function () {
    $id = enviarEmpleado($this, empleadoValido())->assertCreated()->json('data.id');

    $this->withToken($this->token)->deleteJson('/api/empleados/'.$id)->assertNoContent();

    // Soft delete: las citas apuntan aquí y borrarlo reescribiría el historial.
    $this->tenant->run(function () use ($id) {
        expect(DB::table('profesionales')->find($id)->deleted_at)->not->toBeNull();
    });

    $this->postJson('/api/login', [
        'email' => 'carmen.rios@elrosal.pe',
        'password' => 'secreta123',
    ])->assertStatus(422);
});

test('los empleados de otro negocio: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    /*
     * Ojo al montar esto: el id 1 es el dueño en AMBAS bases, así que pedirlo
     * devolvería el propio y el test pasaría sin probar nada. Hace falta un id
     * que exista solo en la otra: se le añade un segundo profesional.
     */
    $ajeno = $otro->run(fn () => DB::table('profesionales')->insertGetId([
        'central_user_id' => 999999,
        'nombre' => 'Ajeno',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($this->tenant->run(fn () => DB::table('profesionales')->find($ajeno)))->toBeNull();

    $this->withToken($this->token)->getJson('/api/empleados/'.$ajeno)->assertNotFound();
    $this->withToken($this->token)->deleteJson('/api/empleados/'.$ajeno)->assertNotFound();

    $this->withToken($this->token)->getJson('/api/empleados')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/*
 * El panel esconde el grupo Equipo a quien trabaja solo: un independiente que
 * abre Empleados se encuentra una pantalla con una sola persona, él mismo, y
 * una matriz de permisos para repartir entre nadie.
 *
 * Es una pista de interfaz, no autorización — de ahí la segunda mitad del
 * test. Esconder un menú no puede cerrar una puerta, o el día que contrate a
 * alguien habría que migrar algo.
 */
test('negocio.rango_profesionales viaja, y no cierra ninguna puerta', function () {
    $this->tenant->update(['rango_profesionales' => 'independiente']);

    $this->withToken($this->token)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.negocio.rango_profesionales', 'independiente');

    $this->withToken($this->token)->getJson('/api/empleados')->assertOk();
    $this->withToken($this->token)->getJson('/api/roles')->assertOk();
});

test('sin sesión → 401', function () {
    $this->getJson('/api/empleados')->assertStatus(401);
    $this->getJson('/api/empleados/resumen')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Foto
|--------------------------------------------------------------------------
*/

test('la foto se sube, se conserva sin reenviarla y se quita con su bandera', function () {
    $data = enviarEmpleado($this, empleadoValido([
        'foto' => UploadedFile::fake()->image('carmen.jpg', 400, 400),
    ]))->assertCreated()->json('data');

    expect($data['foto_url'])->toContain('/api/archivos/'.$this->tenant->id.'/empleados/');

    // No mandar el archivo significa "déjala como está".
    $sinTocar = enviarEmpleado($this, empleadoValido(), $data['id'])->assertOk()->json('data');

    expect($sinTocar['foto_url'])->toBe($data['foto_url']);

    // Y por eso quitarla necesita bandera propia.
    enviarEmpleado($this, empleadoValido(['foto_eliminar' => 1]), $data['id'])
        ->assertOk()
        ->assertJsonPath('data.foto_url', null);
});
