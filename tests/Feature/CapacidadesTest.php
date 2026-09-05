<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Local;
use App\Models\Plan;
use App\Models\Rol;
use App\Models\User;
use App\Models\Usuario;
use App\Support\RolesSistema;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->titular = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->titular->createToken('test')->plainTextToken;
});

afterEach(fn () => limpiarBasesDeTenants());

/**
 * Una cuenta con el rol que se le pida, y su token.
 *
 * `$permisos` reemplaza la matriz del rol para probar niveles concretos sin
 * depender de los presets, que pueden cambiar.
 */
function cuentaCon(string $clave, ?array $permisos = null, ?array $locales = null): string
{
    $tenant = test()->tenant;

    $central = User::create([
        'tenant_id' => $tenant->id,
        'nombre' => 'Prueba '.$clave.uniqid(),
        'email' => uniqid().'@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
        'email_verified_at' => now(),
    ]);

    $tenant->run(function () use ($central, $clave, $permisos, $locales) {
        $rol = Rol::where('clave', $clave)->firstOrFail();

        if ($permisos !== null) {
            $rol->update(['permisos' => $permisos]);
        }

        $cuenta = Usuario::create([
            'central_user_id' => $central->id,
            'rol_id' => $rol->id,
            'todos_los_locales' => $locales === null,
        ]);

        if ($locales !== null) {
            $cuenta->locales()->sync($locales);
        }
    });

    return $central->createToken('test')->plainTextToken;
}

/*
|--------------------------------------------------------------------------
| La puerta
|--------------------------------------------------------------------------
*/

/*
 * Hasta hoy `roles.permisos` se guardaba y no lo leía nadie: un profesional
 * cuyo rol dice «clientes: ver» podía crear y borrar clientes igual que el
 * titular. Esto es lo que hace que la matriz signifique algo.
 */
test('«ver» deja leer y NO deja escribir', function () {
    $token = cuentaCon('profesional');

    comoOtro($this, $token)->getJson('/api/clientes')->assertOk();

    comoOtro($this, $token)->postJson('/api/clientes', ['nombre' => 'Ana'])
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'sin_permiso');
});

/*
 * `gestionar` incluye `ver`: pedir `ver` en un index tiene que dejar pasar
 * también a quien gestiona, o habría que anotar cada listado dos veces.
 */
test('«gestionar» incluye «ver»', function () {
    $token = cuentaCon('admin_local');

    comoOtro($this, $token)->getJson('/api/clientes')->assertOk();
    comoOtro($this, $token)->postJson('/api/clientes', ['nombre' => 'Ana'])->assertCreated();
});

test('un módulo sin acceso da 403, no 404', function () {
    // El preset de Profesional no tiene caja ni inventario.
    $token = cuentaCon('profesional');

    comoOtro($this, $token)->getJson('/api/locales')
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'sin_permiso');
});

/*
 * 403 y no 404 a propósito: el recurso existe y es de su negocio, lo que falta
 * es permiso. El 404 se reserva para lo de otro tenant — mezclarlos haría
 * imposible distinguir «no tienes acceso» de «no existe», que es justo lo que
 * el panel necesita para decidir si enseña un aviso o una pantalla vacía.
 */
test('el código distingue esta pared de la de cobro', function () {
    $token = cuentaCon('profesional');

    comoOtro($this, $token)->getJson('/api/locales')
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'sin_permiso');

    // La de cobro usa otro código, y el frontend las pinta distinto.
    $this->tenant->update(['estado' => 'suspendida']);

    comoOtro($this, $token)->getJson('/api/locales')
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'suscripcion_vencida');
});

/*
 * Falla cerrado. Un permiso que se concede por omisión es el que nadie revisa.
 */
test('sin cuenta en el negocio no puede nada', function () {
    $huerfano = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Sin cuenta',
        'email' => 'huerfano@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
        'email_verified_at' => now(),
    ]);

    comoOtro($this, $huerfano->createToken('t')->plainTextToken)
        ->getJson('/api/clientes')
        ->assertStatus(403);
});

test('el administrador general pasa por todas las puertas', function () {
    foreach (['/api/clientes', '/api/servicios', '/api/locales', '/api/profesionales', '/api/configuracion'] as $url) {
        comoOtro($this, $this->token)->getJson($url)->assertOk();
    }
});

/*
 * Se pregunta por CAPACIDAD y nunca por rol: es lo que permite que el negocio
 * invente «Recepcionista» sin tocar un endpoint.
 */
test('un rol inventado por el negocio funciona igual que uno de sistema', function () {
    $rolId = comoOtro($this, $this->token)->postJson('/api/roles', [
        'nombre' => 'Recepcionista',
        'permisos' => ['clientes' => 'gestionar', 'citas' => 'gestionar'],
    ])->assertCreated()->json('data.id');

    $central = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Lucía',
        'email' => 'recepcion@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
        'email_verified_at' => now(),
    ]);

    $this->tenant->run(fn () => Usuario::create([
        'central_user_id' => $central->id,
        'rol_id' => $rolId,
    ]));

    $token = $central->createToken('t')->plainTextToken;

    comoOtro($this, $token)->postJson('/api/clientes', ['nombre' => 'Ana'])->assertCreated();
    // Servicios no está en su matriz.
    comoOtro($this, $token)->getJson('/api/servicios')->assertStatus(403);
});

/*
 * El escenario completo de la escalada, con el rol que de verdad la permitia.
 *
 * El administrador local tiene `empleados: gestionar`, asi que llega a
 * `/profesionales` legitimamente. Lo que no puede es usar ese camino para
 * fabricarse un segundo administrador general — que es lo que la propia
 * documentacion nombra al explicar por que roles y cuentas son solo del
 * general: «quien puede crear cuentas y repartir roles puede fabricarse un
 * segundo dueño».
 */
test('un administrador local no puede ascenderse por la puerta de profesionales', function () {
    $token = cuentaCon('admin_local');

    $rolGeneral = $this->tenant->run(fn () => Rol::where('clave', 'admin_general')->value('id'));

    // Llega al endpoint: tiene el permiso. Lo que se le niega es el rol.
    comoOtro($this, $token)->post('/api/profesionales', [
        'nombre' => 'Yo mismo',
        'tipo_pago' => 'comision',
        'comision_porcentaje' => 10,
        'usuario' => ['email' => 'ascendido@elrosal.pe', 'rol_id' => $rolGeneral],
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('usuario.rol_id');

    // Y sigue habiendo exactamente un administrador general.
    $this->tenant->run(function () use ($rolGeneral) {
        expect(Usuario::where('rol_id', $rolGeneral)->count())->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| El alcance por sedes
|--------------------------------------------------------------------------
*/

test('quien tiene sedes asignadas solo ve las suyas', function () {
    [$piura, $castilla] = $this->tenant->run(fn () => [
        Local::create(['nombre' => 'Piura Centro'])->id,
        Local::create(['nombre' => 'Castilla'])->id,
    ]);

    $token = cuentaCon('admin_local', locales: [$castilla]);

    comoOtro($this, $token)->getJson('/api/locales')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Castilla');

    /*
     * 404 y no 403: para esa persona esa sede no existe, igual que la de otro
     * negocio. Un 403 confirmaría que está ahí, que es lo que el alcance viene
     * a ocultar.
     */
    comoOtro($this, $token)->getJson("/api/locales/{$piura}")->assertNotFound();
    comoOtro($this, $token)->getJson("/api/locales/{$piura}/profesionales")->assertNotFound();

    comoOtro($this, $token)->getJson("/api/locales/{$castilla}")->assertOk();
});

test('sin sedes asignadas se ven todas', function () {
    $this->tenant->run(function () {
        Local::create(['nombre' => 'Piura Centro']);
        Local::create(['nombre' => 'Castilla']);
    });

    comoOtro($this, cuentaCon('admin_local'))->getJson('/api/locales')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

/*
|--------------------------------------------------------------------------
| GET /capacidades
|--------------------------------------------------------------------------
*/

/*
 * Lo manda el backend YA RESUELTO. Si el menú de Next dedujera los permisos por
 * su cuenta acabaría habiendo dos matrices, y la que manda es la de aquí.
 */
test('el panel pide sus capacidades resueltas', function () {
    $data = comoOtro($this, $this->token)->getJson('/api/capacidades')
        ->assertOk()
        ->json('data');

    expect(array_keys($data['permisos']))->toBe(RolesSistema::MODULOS)
        ->and($data['permisos']['facturacion'])->toBe('gestionar')
        ->and($data['solo_propios'])->toBeFalse()
        // `null` = todas. Una lista vacía significaría ninguna.
        ->and($data['locales'])->toBeNull();
});

test('el profesional viene con solo_propios y su matriz recortada', function () {
    $data = comoOtro($this, cuentaCon('profesional'))->getJson('/api/capacidades')
        ->assertOk()
        ->json('data');

    expect($data['solo_propios'])->toBeTrue()
        ->and($data['permisos']['clientes'])->toBe('ver')
        ->and($data['permisos']['caja'])->toBeNull()
        ->and($data['permisos']['facturacion'])->toBeNull();
});

test('las sedes asignadas viajan en las capacidades', function () {
    $castilla = $this->tenant->run(fn () => Local::create(['nombre' => 'Castilla'])->id);

    $data = comoOtro($this, cuentaCon('admin_local', locales: [$castilla]))
        ->getJson('/api/capacidades')
        ->assertOk()
        ->json('data');

    expect($data['locales'])->toBe([$castilla]);
});

test('sin sesión → 401', function () {
    $this->getJson('/api/capacidades')->assertStatus(401);
});
