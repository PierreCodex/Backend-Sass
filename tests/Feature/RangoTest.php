<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Local;
use App\Models\Plan;
use App\Models\Rol;
use App\Models\User;
use App\Models\Usuario;
use App\Services\UsuarioService;
use App\Support\Capacidades;
use App\Support\Rango;
use App\Support\RolesSistema;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Nadie concede lo que no tiene (Story 1.4, G-4), probado en la regla
|--------------------------------------------------------------------------
|
| Hoy solo el general invita, así que por HTTP las ramas «rol con más
| permisos» y «alcance mayor» no se alcanzan: el 403 de invitar salta antes.
| Se prueban aquí, directamente sobre `Rango`, para que estén fijadas el día
| que la 1.8 abra la asignación de alcance o que invitar deje de ser solo del
| general. Y el service se prueba sin pasar por HTTP: si alguien quitara sus
| llamadas a `Rango`, los Form Requests ya no bastarían para notarlo.
*/

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->titular = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    tenancy()->initialize($this->tenant);

    Notification::fake();
});

afterEach(fn () => limpiarBasesDeTenants());

/**
 * Una cuenta nueva del negocio con esa matriz; devuelve su usuario central.
 *
 * @param  array<string, string>  $permisos
 * @param  list<int>|null  $locales  null = todas las sedes
 */
function rangoCuentaCon(array $permisos, bool $soloPropios = false, ?array $locales = null, string $rolCentral = 'profesional'): User
{
    $central = User::create([
        'tenant_id' => test()->tenant->id,
        'nombre' => 'Actor',
        'email' => uniqid().'@elrosal.pe',
        'password' => 'secreta123',
        'rol' => $rolCentral,
    ]);

    $cuenta = Usuario::create([
        'central_user_id' => $central->id,
        'rol_id' => rangoRolPropio($permisos, $soloPropios)->id,
        'todos_los_locales' => $locales === null,
    ]);

    if ($locales !== null) {
        $cuenta->locales()->sync($locales);
    }

    return $central;
}

/**
 * Las capacidades de una cuenta nueva con esa matriz.
 *
 * @param  array<string, string>  $permisos
 * @param  list<int>|null  $locales
 */
function rangoActorCon(array $permisos, bool $soloPropios = false, ?array $locales = null): Capacidades
{
    return Capacidades::deUsuarioCentral(rangoCuentaCon($permisos, $soloPropios, $locales)->id);
}

/** @param  array<string, string>  $permisos */
function rangoRolPropio(array $permisos, bool $soloPropios = false): Rol
{
    return Rol::create([
        'nombre' => 'Rol '.uniqid(),
        'clave' => null,
        'sistema' => false,
        'solo_propios' => $soloPropios,
        'permisos' => $permisos,
    ]);
}

/** El 403 que devuelve la regla, o null si deja pasar. */
function rangoRechazo(callable $regla): ?array
{
    try {
        $regla();
    } catch (HttpResponseException $e) {
        $respuesta = $e->getResponse();

        return ['status' => $respuesta->getStatusCode()] + $respuesta->getData(true);
    }

    return null;
}

function rangoGeneral(): Capacidades
{
    return Capacidades::deUsuarioCentral(test()->titular->id);
}

/*
| El service: todos los caminos pasan por aquí
*/

test('UsuarioService::crear con un actor que no es el general → 403 y no escribe nada', function () {
    $sede = rangoCuentaCon(
        Rol::where('clave', 'admin_local')->firstOrFail()->permisosCompletos(),
        rolCentral: 'admin_local',
    );
    $cuentas = Usuario::count();

    expect(rangoRechazo(fn () => app(UsuarioService::class)->crear([
        'nombre' => 'Colada',
        'email' => 'colada@elrosal.pe',
        'rol_id' => Rol::where('clave', 'profesional')->value('id'),
    ], $sede->id)))->toMatchArray(['status' => 403, 'codigo' => 'sin_permiso']);

    expect(User::where('email', 'colada@elrosal.pe')->withTrashed()->count())->toBe(0)
        ->and(Usuario::count())->toBe($cuentas);

    Notification::assertNothingSent();
});

test('UsuarioService::actualizar con un actor que no es el general → 403 y no cambia nada', function () {
    $sede = rangoCuentaCon(
        Rol::where('clave', 'admin_local')->firstOrFail()->permisosCompletos(),
        rolCentral: 'admin_local',
    );
    $objetivo = rangoCuentaCon(['citas' => 'ver']);
    $cuenta = Usuario::where('central_user_id', $objetivo->id)->firstOrFail();
    $rolAntes = $cuenta->rol_id;

    expect(rangoRechazo(fn () => app(UsuarioService::class)->actualizar($cuenta, [
        'nombre' => 'Cambiado',
        'email' => $objetivo->email,
        'rol_id' => Rol::where('clave', 'admin_local')->value('id'),
    ], $sede->id)))->toMatchArray(['status' => 403, 'codigo' => 'sin_permiso']);

    expect($cuenta->fresh()->rol_id)->toBe($rolAntes)
        ->and($objetivo->fresh()->nombre)->toBe('Actor');
});

test('UsuarioService con un id que no es nadie → 403', function () {
    expect(rangoRechazo(fn () => app(UsuarioService::class)->crear([
        'nombre' => 'Nadie',
        'email' => 'nadie@elrosal.pe',
        'rol_id' => Rol::where('clave', 'profesional')->value('id'),
    ], 999999)))->toMatchArray(['status' => 403, 'codigo' => 'sin_permiso']);

    expect(User::where('email', 'nadie@elrosal.pe')->withTrashed()->count())->toBe(0);
});

/*
| Sin cuenta en el negocio: falla cerrado
*/

test('sin cuenta en el negocio no concede nada, ni rol ni alcance', function () {
    $sinCuenta = Capacidades::deUsuarioCentral(999999);

    expect(rangoRechazo(fn () => Rango::rolContenido($sinCuenta, rangoRolPropio([]))))
        ->toMatchArray(['status' => 403, 'codigo' => 'sin_permiso'])
        ->and(rangoRechazo(fn () => Rango::alcanceContenido($sinCuenta, null)))
        ->toMatchArray(['status' => 403, 'codigo' => 'sin_permiso'])
        ->and(rangoRechazo(fn () => Rango::alcanceContenido($sinCuenta, [])))
        ->toMatchArray(['status' => 403, 'codigo' => 'sin_permiso']);
});

/*
| Permisos ⊆
*/

test('un rol con un módulo que el actor no tiene → 403', function () {
    $actor = rangoActorCon(['citas' => 'gestionar', 'clientes' => 'gestionar']);

    expect(rangoRechazo(fn () => Rango::rolContenido($actor, rangoRolPropio(['citas' => 'ver', 'configuracion' => 'ver']))))
        ->toMatchArray([
            'status' => 403,
            'codigo' => 'sin_permiso',
            'message' => 'No puedes asignar un rol con permisos que tú no tienes.',
        ]);
});

test('un rol con más nivel en un módulo que el actor solo ve → 403', function () {
    $actor = rangoActorCon(['citas' => 'gestionar', 'reportes' => 'ver']);

    expect(rangoRechazo(fn () => Rango::rolContenido($actor, rangoRolPropio(['reportes' => 'gestionar']))))
        ->toMatchArray(['status' => 403, 'codigo' => 'sin_permiso']);
});

test('si el actor solo ve lo suyo, el rol que da también → 403 si no', function () {
    $actor = rangoActorCon(['citas' => 'gestionar'], soloPropios: true);

    expect(rangoRechazo(fn () => Rango::rolContenido($actor, rangoRolPropio(['citas' => 'ver'], soloPropios: false))))
        ->toMatchArray(['status' => 403, 'codigo' => 'sin_permiso']);

    expect(rangoRechazo(fn () => Rango::rolContenido($actor, rangoRolPropio(['citas' => 'ver'], soloPropios: true))))
        ->toBeNull();
});

test('un rol igual o menor que el del actor se permite', function () {
    $permisos = ['citas' => 'gestionar', 'clientes' => 'ver', 'reportes' => 'ver'];
    $actor = rangoActorCon($permisos);

    expect(rangoRechazo(fn () => Rango::rolContenido($actor, rangoRolPropio($permisos))))->toBeNull()
        ->and(rangoRechazo(fn () => Rango::rolContenido($actor, rangoRolPropio(['citas' => 'ver']))))->toBeNull()
        // Un actor sin solo_propios puede dar un rol que sí lo tiene: es menos.
        ->and(rangoRechazo(fn () => Rango::rolContenido($actor, rangoRolPropio(['citas' => 'ver'], soloPropios: true))))->toBeNull();
});

/* Sin excepción en la regla: lo tiene todo, así que cumple por sí solo. */
test('el administrador general da cualquier rol', function () {
    $todo = rangoRolPropio(array_fill_keys(RolesSistema::MODULOS, 'gestionar'));

    expect(rangoRechazo(fn () => Rango::rolContenido(rangoGeneral(), $todo)))->toBeNull();
});

/*
| Alcance contenido
*/

test('un actor con alcance acotado no da todas las sedes ni una sede ajena', function () {
    $norte = Local::create(['nombre' => 'Norte'])->id;
    $sur = Local::create(['nombre' => 'Sur'])->id;

    $actor = rangoActorCon(['citas' => 'gestionar'], locales: [$norte]);

    expect(rangoRechazo(fn () => Rango::alcanceContenido($actor, null)))->toMatchArray([
        'status' => 403,
        'codigo' => 'sin_permiso',
        'message' => 'No puedes dar acceso a todas las sedes: tú no lo tienes.',
    ]);

    expect(rangoRechazo(fn () => Rango::alcanceContenido($actor, [$norte, $sur])))->toMatchArray([
        'status' => 403,
        'codigo' => 'sin_permiso',
        'message' => 'No puedes dar acceso a una sede que no es tuya.',
    ]);

    // Lo suyo, o menos, sí; también con el id como cadena de un `<select>`.
    expect(rangoRechazo(fn () => Rango::alcanceContenido($actor, [$norte])))->toBeNull()
        ->and(rangoRechazo(fn () => Rango::alcanceContenido($actor, [(string) $norte])))->toBeNull()
        ->and(rangoRechazo(fn () => Rango::alcanceContenido($actor, [])))->toBeNull();
});

test('con todas las sedes, cualquier alcance cabe', function () {
    $norte = Local::create(['nombre' => 'Norte'])->id;

    $actor = rangoActorCon(['citas' => 'gestionar']);

    expect(rangoRechazo(fn () => Rango::alcanceContenido($actor, null)))->toBeNull()
        ->and(rangoRechazo(fn () => Rango::alcanceContenido($actor, [$norte])))->toBeNull()
        ->and(rangoRechazo(fn () => Rango::alcanceContenido(rangoGeneral(), null)))->toBeNull();
});
