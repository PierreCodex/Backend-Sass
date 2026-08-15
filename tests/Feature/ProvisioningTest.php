<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->planPrueba = App\Models\Plan::where('slug', 'prueba')->firstOrFail();
});

afterEach(fn () => limpiarBasesDeTenants());

function crearTenantRegistrado($plan): Tenant
{
    // Lo mismo que hará POST /register: tenant SIN nombre ni slug (los fija
    // el onboarding), estado 'registrada', BD sin provisionar.
    return Tenant::create([
        'plan_id' => $plan->id,
        'rango_profesionales' => '3-5',
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
        'rol' => 'dueno',
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
            'profesionales', 'clientes', 'locales', 'local_profesional',
            'categoria_servicios', 'servicios', 'servicio_imagenes',
            'servicio_profesional', 'productos', 'citas', 'cita_servicio',
            'cita_producto', 'cita_pagos', 'grupos', 'grupo_local',
            'grupo_profesional', 'grupo_servicio', 'caja_cierres',
            'caja_movimientos', 'inventario_movimientos', 'plantilla_whatsapps',
        ] as $tabla) {
            expect(Schema::hasTable($tabla))->toBeTrue("Falta la tabla {$tabla}");
        }

        $perfil = DB::table('profesionales')->where('central_user_id', $dueno->id)->first();

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
        expect(DB::table('profesionales')->where('central_user_id', $dueno->id)->count())->toBe(1);
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
