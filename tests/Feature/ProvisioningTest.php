<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->planPrueba = Plan::where('slug', 'prueba')->firstOrFail();
});

afterEach(fn () => limpiarBasesDeTenants());

function crearTenantRegistrado($plan, string $rango = 'independiente'): Tenant
{
    // Lo mismo que hará POST /register: tenant SIN nombre ni slug (los fija
    // el onboarding), estado 'registrada', BD sin provisionar.
    //
    // `independiente` por defecto porque es el caso en que el provisioning le
    // crea al dueño su ficha de profesional, y casi todos los tests la dan por
    // hecha. Los que prueban lo contrario pasan otro rango.
    return Tenant::create([
        'plan_id' => $plan->id,
        'rango_profesionales' => $rango,
    ])->refresh(); // carga los defaults que pone MySQL (estado, db_provisionada)
}

function crearDueno(Tenant $tenant): User
{
    return User::create([
        'tenant_id' => $tenant->id,
        'nombre' => 'María',
        'apellido' => 'Quispe',
        'email' => 'maria+'.$tenant->id.'@correo.pe',
        'password' => 'secreta123',
        'rol' => 'admin_general',
        'telefono' => '+51987654321',
    ]);
}

test('crear un tenant NO crea su base de datos (lazy provisioning)', function () {
    $tenant = crearTenantRegistrado($this->planPrueba);

    expect($tenant->id)->toHaveLength(8)
        ->and($tenant->slug)->toBeNull()
        ->and($tenant->estado)->toBe('registrada')
        ->and($tenant->db_provisionada)->toBeFalse();

    $manager = $tenant->database()->manager();

    expect($tenant->database()->getName())->toBe('tenant_'.$tenant->id)
        ->and($manager->databaseExists('tenant_'.$tenant->id))->toBeFalse();
});

test('el job de provisioning crea la BD, migra todas las tablas y deja al dueño en profesionales', function () {
    $tenant = crearTenantRegistrado($this->planPrueba);
    $dueno = crearDueno($tenant);

    (new ProvisionTenantDatabase($tenant))->handle();

    $manager = $tenant->database()->manager();
    expect($manager->databaseExists('tenant_'.$tenant->id))->toBeTrue();

    $tenant->run(function () use ($dueno) {
        foreach ([
            'roles', 'usuarios', 'profesionales', 'clientes', 'locales',
            'local_profesional', 'local_usuario',
            'categoria_servicios', 'servicios', 'servicio_imagenes',
            'servicio_profesional', 'productos', 'citas', 'cita_servicio',
            'cita_producto', 'cita_pagos', 'grupos', 'grupo_local',
            'grupo_profesional', 'grupo_servicio', 'caja_cierres',
            'caja_movimientos', 'inventario_movimientos', 'plantilla_whatsapps',
        ] as $tabla) {
            expect(Schema::hasTable($tabla))->toBeTrue("Falta la tabla {$tabla}");
        }

        $cuenta = DB::table('usuarios')->where('central_user_id', $dueno->id)->first();
        expect($cuenta)->not->toBeNull();

        $perfil = DB::table('profesionales')->where('usuario_id', $cuenta->id)->first();

        expect($perfil)->not->toBeNull()
            ->and($perfil->nombre)->toBe('María Quispe')
            ->and((bool) $perfil->atiende)->toBeTrue();
    });

    $tenant->refresh();

    expect($tenant->db_provisionada)->toBeTrue()
        ->and($tenant->estado)->toBe('prueba')
        ->and($tenant->suscripcion_vence_el->toDateString())
        ->toBe(now()->addDays(7)->toDateString());
});

test('re-lanzar el job de provisioning es idempotente', function () {
    $tenant = crearTenantRegistrado($this->planPrueba);
    $dueno = crearDueno($tenant);

    (new ProvisionTenantDatabase($tenant))->handle();
    (new ProvisionTenantDatabase($tenant))->handle();

    $tenant->run(function () use ($dueno) {
        expect(DB::table('usuarios')->where('central_user_id', $dueno->id)->count())->toBe(1)
            ->and(DB::table('profesionales')->count())->toBe(1)
            ->and(DB::table('roles')->count())->toBe(4);
    });
});

test('aislación básica: los datos del tenant A no existen en el tenant B', function () {
    $tenantA = crearTenantRegistrado($this->planPrueba);
    $tenantB = crearTenantRegistrado($this->planPrueba);

    (new ProvisionTenantDatabase($tenantA))->handle();
    (new ProvisionTenantDatabase($tenantB))->handle();

    $tenantA->run(function () {
        DB::table('clientes')->insert([
            'nombre' => 'Cliente del tenant A',
            'telefono' => '+51999888777',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $clientesEnA = $tenantA->run(fn () => DB::table('clientes')->count());
    $clientesEnB = $tenantB->run(fn () => DB::table('clientes')->count());

    expect($clientesEnA)->toBe(1)
        ->and($clientesEnB)->toBe(0);
});

test('el provisioning siembra los cuatro roles de sistema y le da el de general al titular', function () {
    $tenant = crearTenantRegistrado($this->planPrueba);
    $dueno = crearDueno($tenant);

    (new ProvisionTenantDatabase($tenant))->handle();

    $tenant->run(function () use ($dueno) {
        $roles = DB::table('roles')->orderBy('id')->get()->keyBy('clave');

        expect($roles->keys()->all())->toBe(['admin_general', 'admin_local', 'profesional', 'recepcion']);

        // Los cuatro son de sistema: no se borran nunca (siempre tiene que haber
        // dónde meter a un barbero). Solo el de dueño tampoco se edita.
        $roles->each(fn ($rol) => expect((bool) $rol->sistema)->toBeTrue());

        // NULL mientras el negocio no los toque: es lo que permite mejorarles
        // los presets en una versión futura sin pisar al que personalizó.
        $roles->each(fn ($rol) => expect($rol->editado_at)->toBeNull());

        // La facturación no se delega ni a la mano derecha del dueño.
        $admin = json_decode($roles['admin_local']->permisos, true);
        expect($admin['facturacion'])->toBeNull()
            ->and($admin['caja'])->toBe('gestionar');

        // El profesional ve SUS citas, no las de sus compañeros.
        expect((bool) $roles['profesional']->solo_propios)->toBeTrue()
            ->and((bool) $roles['admin_local']->solo_propios)->toBeFalse();

        // El rol cuelga de la CUENTA, no de la ficha de profesional: un
        // barbero sin acceso al panel no lleva rol y no le hace falta.
        $cuenta = DB::table('usuarios')->where('central_user_id', $dueno->id)->first();
        expect($cuenta->rol_id)->toBe((int) $roles['admin_general']->id);
    });
});

test('un rol en uso no se puede borrar', function () {
    $tenant = crearTenantRegistrado($this->planPrueba);
    crearDueno($tenant);

    (new ProvisionTenantDatabase($tenant))->handle();

    // La barandilla vive en la BD (restrictOnDelete), no solo en el service:
    // borrar el rol de alguien lo dejaría sin permisos de golpe. Lo usa la
    // CUENTA del dueño, que es donde vive ahora `rol_id`.
    $tenant->run(function () {
        expect(fn () => DB::table('roles')->where('clave', 'admin_general')->delete())
            ->toThrow(QueryException::class);
    });
});

/*
 * Story 1.5: Recepción, tal como la fija la matriz A-1 (§2.3) y el PRD FR-64.
 */
test('el rol de recepción trae lo que necesita y nada de la empresa', function () {
    $tenant = crearTenantRegistrado($this->planPrueba);
    crearDueno($tenant);

    (new ProvisionTenantDatabase($tenant))->handle();

    $tenant->run(function () {
        $rol = DB::table('roles')->where('clave', 'recepcion')->first();
        $permisos = json_decode($rol->permisos, true);

        expect($rol->nombre)->toBe('Recepción')
            ->and((bool) $rol->sistema)->toBeTrue()
            // Agenda la de todos en su sede, no solo lo suyo.
            ->and((bool) $rol->solo_propios)->toBeFalse()
            ->and($permisos['citas'])->toBe('gestionar')
            ->and($permisos['clientes'])->toBe('gestionar')
            // Decidido el 2026-10-05: gestionar, no ver.
            ->and($permisos['pagos'])->toBe('gestionar')
            // Ve el equipo y el catálogo para agendar, pero no los cambia.
            ->and($permisos['empleados'])->toBe('ver')
            ->and($permisos['servicios'])->toBe('ver')
            // Nada de configuración ni facturación.
            ->and($permisos['configuracion'] ?? null)->toBeNull()
            ->and($permisos['facturacion'] ?? null)->toBeNull()
            ->and($permisos['locales'] ?? null)->toBeNull()
            ->and($permisos['reportes'] ?? null)->toBeNull();

        // «Administrador de sede» en pantalla; la clave interna no cambia.
        expect(DB::table('roles')->where('clave', 'admin_local')->value('nombre'))
            ->toBe('Administrador de sede');
    });
});
