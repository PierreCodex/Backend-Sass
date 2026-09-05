<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\Rol;
use App\Models\User;
use App\Models\Usuario;
use App\Support\RolesSistema;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;
});

afterEach(fn () => limpiarBasesDeTenants());

function rolValido(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Recepcionista',
        'permisos' => [
            'dashboard' => 'ver',
            'citas' => 'gestionar',
            'clientes' => 'gestionar',
            'caja' => 'ver',
        ],
        'solo_propios' => false,
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
*/

test('el listado trae los tres roles de sistema, el general primero', function () {
    $respuesta = $this->withToken($this->token)->getJson('/api/roles');

    $respuesta->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.clave', 'admin_general')
        ->assertJsonPath('data.1.clave', 'admin_local')
        ->assertJsonPath('data.2.clave', 'profesional');
});

test('el listado incluye la lista de módulos para pintar la matriz', function () {
    $this->withToken($this->token)->getJson('/api/roles')
        ->assertOk()
        ->assertJsonPath('modulos', RolesSistema::MODULOS);
});

/*
 * El preset de Profesional guarda cinco claves de catorce. Si el Resource
 * emitiera el JSON crudo, el formulario tendría que conocer la lista de
 * módulos para rellenar los huecos — y acabaría habiendo dos listas.
 */
test('cada rol emite los 14 módulos aunque su JSON guarde menos', function () {
    $respuesta = $this->withToken($this->token)->getJson('/api/roles');

    $profesional = collect($respuesta->json('data'))->firstWhere('clave', 'profesional');

    expect(array_keys($profesional['permisos']))->toBe(RolesSistema::MODULOS)
        ->and($profesional['permisos']['citas'])->toBe('gestionar')
        ->and($profesional['permisos']['caja'])->toBeNull();
});

test('el listado dice cuántas cuentas usan cada rol', function () {
    $respuesta = $this->withToken($this->token)->getJson('/api/roles');

    $dueno = collect($respuesta->json('data'))->firstWhere('clave', 'admin_general');

    // Cuenta CUENTAS, no fichas de profesional: un barbero sin acceso al panel
    // no lleva rol. El provisioning le crea la suya al dueño.
    expect($dueno['usuarios_count'])->toBe(1);
});

test('las barandillas viajan resueltas, no las deduce el cliente', function () {
    $roles = collect($this->withToken($this->token)->getJson('/api/roles')->json('data'))
        ->keyBy('clave');

    expect($roles['admin_general'])->toMatchArray(['editable' => false, 'borrable' => false, 'duplicable' => false])
        ->and($roles['admin_local'])->toMatchArray(['editable' => true, 'borrable' => false, 'duplicable' => true]);
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

test('el administrador general crea un rol propio', function () {
    $respuesta = $this->withToken($this->token)->postJson('/api/roles', rolValido());

    $respuesta->assertCreated()
        ->assertJsonPath('data.nombre', 'Recepcionista')
        ->assertJsonPath('data.clave', null)
        ->assertJsonPath('data.sistema', false)
        ->assertJsonPath('data.borrable', true)
        ->assertJsonPath('data.permisos.citas', 'gestionar')
        ->assertJsonPath('data.permisos.reportes', null);
});

test('los módulos sin permiso NO se guardan en el JSON, solo se emiten', function () {
    $this->withToken($this->token)->postJson('/api/roles', rolValido())->assertCreated();

    $guardado = $this->tenant->run(fn () => Rol::where('nombre', 'Recepcionista')->first()->permisos);

    // Cuatro claves guardadas, catorce emitidas.
    expect($guardado)->toHaveCount(4)
        ->and($guardado)->not->toHaveKey('reportes');
});

test('clave y sistema se ignoran aunque se manden a mano', function () {
    $this->withToken($this->token)
        ->postJson('/api/roles', rolValido(['clave' => 'admin_general', 'sistema' => true]))
        ->assertCreated()
        ->assertJsonPath('data.clave', null)
        ->assertJsonPath('data.sistema', false);
});

test('un nombre repetido da 422 en su campo', function () {
    $this->withToken($this->token)->postJson('/api/roles', rolValido())->assertCreated();

    $this->withToken($this->token)->postJson('/api/roles', rolValido())
        ->assertStatus(422)
        ->assertJsonValidationErrors('nombre');
});

test('renombrar un rol con el nombre de otro da 422', function () {
    $this->withToken($this->token)->postJson('/api/roles', rolValido())->assertCreated();

    $id = $this->withToken($this->token)
        ->postJson('/api/roles', rolValido(['nombre' => 'Cajera']))
        ->json('data.id');

    $this->withToken($this->token)
        ->putJson("/api/roles/{$id}", rolValido(['nombre' => 'Recepcionista']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('nombre');
});

test('un módulo que no existe da 422 en permisos', function () {
    $this->withToken($this->token)
        ->postJson('/api/roles', rolValido(['permisos' => ['contabilidad' => 'ver']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('permisos');
});

test('un nivel de acceso inventado da 422', function () {
    $this->withToken($this->token)
        ->postJson('/api/roles', rolValido(['permisos' => ['caja' => 'borrar']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('permisos.caja');
});

/*
 * `bail` en la regla: sin él, el closure que mira las claves corre con un
 * string y `array_keys()` revienta con un TypeError — un 500 en vez del 422
 * que ya estaba listo. Misma lección que el teléfono de clientes.
 */
test('permisos que no es un array da 422, no un error de servidor', function () {
    $this->withToken($this->token)
        ->postJson('/api/roles', rolValido(['permisos' => 'todo']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('permisos');
});

/*
|--------------------------------------------------------------------------
| Edición y borrado: las barandillas
|--------------------------------------------------------------------------
*/

test('el rol de administrador general no se puede editar', function () {
    $id = $this->tenant->run(fn () => Rol::where('clave', 'admin_general')->value('id'));

    $this->withToken($this->token)
        ->putJson("/api/roles/{$id}", rolValido(['nombre' => 'Jefazo']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('rol');
});

test('el rol de administrador sí se edita, y queda marcado como tocado', function () {
    $id = $this->tenant->run(fn () => Rol::where('clave', 'admin_local')->value('id'));

    $this->withToken($this->token)
        ->putJson("/api/roles/{$id}", rolValido([
            'nombre' => 'Encargada',
            'permisos' => ['caja' => 'gestionar'],
        ]))
        ->assertOk()
        ->assertJsonPath('data.nombre', 'Encargada')
        // La clave sobrevive al cambio de nombre: es lo que deja al backend
        // seguir reconociéndolo.
        ->assertJsonPath('data.clave', 'admin_local');

    $rol = $this->tenant->run(fn () => Rol::find($id));

    expect($rol->editado_at)->not->toBeNull();
});

test('los roles de sistema no se borran', function () {
    $id = $this->tenant->run(fn () => Rol::where('clave', 'profesional')->value('id'));

    $this->withToken($this->token)->deleteJson("/api/roles/{$id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('rol');
});

test('un rol en uso no se borra: 422 con la cuenta, no un error de integridad', function () {
    $id = $this->withToken($this->token)->postJson('/api/roles', rolValido())->json('data.id');

    $this->tenant->run(function () use ($id) {
        Usuario::query()->update(['rol_id' => $id]);
    });

    $this->withToken($this->token)->deleteJson("/api/roles/{$id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('rol');

    expect($this->tenant->run(fn () => Rol::find($id)))->not->toBeNull();
});

test('un rol que no usa nadie se borra', function () {
    $id = $this->withToken($this->token)->postJson('/api/roles', rolValido())->json('data.id');

    $this->withToken($this->token)->deleteJson("/api/roles/{$id}")->assertStatus(204);

    expect($this->tenant->run(fn () => Rol::find($id)))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Quién puede
|--------------------------------------------------------------------------
*/

/*
 * Leer sí, escribir no.
 *
 * Si un administrador pudiera crear roles, se haría uno con todo marcado y se
 * lo asignaría: escalada en dos clics. Pero tiene que poder LEERLOS, o el
 * select de rol se queda vacío en el formulario de empleados, que él sí puede
 * usar. Dar de alta gente y decidir qué puede hacer la gente son permisos
 * distintos; consultar la lista no es ninguno de los dos.
 */
test('quien no es administrador general lee los roles, pero no los toca', function () {
    $admin = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Lucía',
        'email' => 'lucia@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'admin_local',
    ]);
    $token = $admin->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/roles')->assertOk()->assertJsonCount(3, 'data');

    $id = $this->tenant->run(fn () => Rol::where('clave', 'admin_local')->value('id'));
    $this->withToken($token)->getJson("/api/roles/{$id}")->assertOk();

    $this->withToken($token)->postJson('/api/roles', rolValido())->assertForbidden();
    $this->withToken($token)->putJson("/api/roles/{$id}", rolValido())->assertForbidden();
    $this->withToken($token)->deleteJson("/api/roles/{$id}")->assertForbidden();
});

test('un rol de otro negocio responde 404, no 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    /*
     * Un rol NUEVO en el otro negocio, no uno de sistema: los tres presets
     * existen con los mismos ids en las dos bases, así que pedir el id 1
     * pasaría el test sin probar nada.
     */
    $ajeno = $otro->run(fn () => Rol::create([
        'nombre' => 'Solo del otro negocio',
        'permisos' => ['caja' => 'ver'],
    ])->id);

    expect($this->tenant->run(fn () => Rol::find($ajeno)))->toBeNull();

    $this->withToken($this->token)->getJson("/api/roles/{$ajeno}")->assertNotFound();
    $this->withToken($this->token)->deleteJson("/api/roles/{$ajeno}")->assertNotFound();
});
