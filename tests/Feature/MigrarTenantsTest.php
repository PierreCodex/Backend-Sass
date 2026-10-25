<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->plan = Plan::where('slug', 'prueba')->firstOrFail();
});

afterEach(fn () => limpiarBasesDeTenants());

test('migrar tenants salta los que se registraron y no verificaron el correo', function () {
    // Uno con BD y otro a medio registrar. El segundo es el caso NORMAL:
    // siempre habra gente que se registra y no verifica.
    $conBd = crearTenantRegistrado($this->plan);
    crearDueno($conBd);
    (new ProvisionTenantDatabase($conBd))->handle();

    $sinBd = crearTenantRegistrado($this->plan);

    expect($sinBd->db_provisionada)->toBeFalse();

    /*
     * `tenants:migrate` de stancl aborta en el primero sin base y deja sin
     * migrar a TODOS los que vienen detras, en silencio. Este no.
     */
    $this->artisan('tenants:migrar-provisionados')
        ->expectsOutputToContain('se registraron y aun no verifican')
        ->assertSuccessful();

    expect($conBd->run(fn () => Schema::hasTable('roles')))->toBeTrue();
});

/*
 * La regresión del 2026-09-04, y la lección que la produjo.
 *
 * La separación de usuarios y profesionales se hizo EDITANDO las migraciones
 * que ya habían corrido. Parecía gratis —cero tenants en producción— pero
 * Laravel identifica las migraciones por nombre de archivo: las viejas seguían
 * registradas, el archivo nuevo salía pendiente, y su `Schema::create('roles')`
 * chocaba contra la tabla que ya existía. El tenant se quedaba a medias.
 *
 * Este test reconstruye a mano el estado ANTERIOR —con datos dentro— y
 * comprueba que la migración entra y no se lleva nada por delante. Es el caso
 * que en producción serían todos los negocios con clientes.
 */
test('un tenant provisionado ANTES de la separación se migra sin perder datos', function () {
    $tenant = crearTenantRegistrado($this->plan);
    $dueno = crearDueno($tenant);
    (new ProvisionTenantDatabase($tenant))->handle();

    $rolDueno = $tenant->run(fn () => DB::table('roles')->where('clave', 'admin_general')->value('id'));

    // Rebobinar al esquema viejo, con su fila de profesional como la tenía.
    $tenant->run(function () use ($dueno, $rolDueno) {
        Schema::table('profesionales', function ($table) {
            $table->dropForeign(['usuario_id']);
            $table->dropUnique(['usuario_id']);
            $table->dropColumn('usuario_id');
            // CON su unique, como lo tenia: es otra de las cosas que la
            // migracion tiene que saber quitar.
            $table->unsignedBigInteger('central_user_id')->nullable()->unique()->after('id');
            // CON su foreign key, como la tenía de verdad: es lo que la
            // migración tiene que saber quitar.
            $table->foreignId('rol_id')->nullable()->after('central_user_id')
                ->constrained('roles')->restrictOnDelete();
        });

        DB::table('profesionales')->update([
            'central_user_id' => $dueno->id,
            'rol_id' => $rolDueno,
            'nombre' => 'María Quispe',
        ]);

        /*
         * `local_usuario` cuelga de `usuarios` con foreign key, asi que hay que
         * tirarla primero. Este rebobinado modela un estado historico, y por
         * eso hay que mantenerlo cada vez que llega una migracion que toque
         * estas tablas — es el precio de tener un test que prueba de verdad la
         * ruta de actualizacion y no solo la instalacion limpia.
         */
        Schema::dropIfExists('local_usuario');
        Schema::dropIfExists('usuarios');

        DB::table('migrations')->whereIn('migration', [
            '2026_09_04_000001_separar_usuarios_de_profesionales',
            '2026_09_04_000003_alcance_por_sedes',
        ])->delete();
    });

    $this->artisan('tenants:migrar-provisionados')->assertSuccessful();

    $tenant->run(function () use ($dueno, $rolDueno) {
        // Los roles sembrados siguen ahí: la migración no recrea esa tabla.
        expect(DB::table('roles')->count())->toBe(4);

        // Y su gente conserva el vínculo con sus permisos.
        $cuenta = DB::table('usuarios')->where('central_user_id', $dueno->id)->first();

        expect($cuenta)->not->toBeNull()
            ->and($cuenta->rol_id)->toBe($rolDueno);

        $profesional = DB::table('profesionales')->first();

        expect($profesional->usuario_id)->toBe((int) $cuenta->id)
            ->and($profesional->nombre)->toBe('María Quispe');

        // Y las columnas viejas se fueron.
        expect(Schema::hasColumn('profesionales', 'central_user_id'))->toBeFalse()
            ->and(Schema::hasColumn('profesionales', 'rol_id'))->toBeFalse();

        // Las dos migraciones entraron, no solo la primera.
        expect(Schema::hasTable('local_usuario'))->toBeTrue()
            ->and(Schema::hasColumn('usuarios', 'todos_los_locales'))->toBeTrue();
    });
});

/*
 * El renombrado de claves, sobre un negocio que ya estaba en marcha.
 *
 * `dueno` → `admin_general` y `admin` → `admin_local`. Lo que hay que
 * comprobar no es que las claves cambien —eso es un UPDATE— sino que la gente
 * conserve su rol: las cuentas apuntan a `roles.id`, no a la clave, así que
 * renombrarla no puede dejar a nadie sin permisos.
 *
 * Y que el `nombre` visible SOLO se toque en los roles que el negocio no
 * personalizó: quien renombró el suyo no debe encontrárselo cambiado.
 */
test('renombrar las claves de rol conserva a la gente y respeta lo personalizado', function () {
    $tenant = crearTenantRegistrado($this->plan);
    $dueno = crearDueno($tenant);
    (new ProvisionTenantDatabase($tenant))->handle();

    // Rebobinar a las claves viejas, con un rol de sistema ya personalizado.
    $idsAntes = $tenant->run(function () {
        DB::table('roles')->where('clave', 'admin_general')->update(['clave' => 'dueno', 'nombre' => 'Dueño']);
        DB::table('roles')->where('clave', 'admin_local')->update([
            'clave' => 'admin',
            'nombre' => 'La encargada',   // el negocio lo renombró
            'editado_at' => now(),
        ]);

        DB::table('migrations')
            ->where('migration', '2026_09_04_000002_renombrar_claves_de_roles')
            ->delete();

        return DB::table('roles')->pluck('id', 'clave')->all();
    });

    $this->artisan('tenants:migrar-provisionados')->assertSuccessful();

    $tenant->run(function () use ($dueno, $idsAntes) {
        $roles = DB::table('roles')->get()->keyBy('clave');

        // Las claves nuevas, sobre las MISMAS filas: los ids no se mueven.
        expect($roles->keys()->sort()->values()->all())
            ->toBe(['admin_general', 'admin_local', 'profesional', 'recepcion'])
            ->and($roles['admin_general']->id)->toBe($idsAntes['dueno'])
            ->and($roles['admin_local']->id)->toBe($idsAntes['admin']);

        // Y su gente sigue con el rol que tenía.
        $cuenta = DB::table('usuarios')->where('central_user_id', $dueno->id)->first();
        expect($cuenta->rol_id)->toBe((int) $roles['admin_general']->id);

        // El nombre se actualiza en el que nadie tocó...
        expect($roles['admin_general']->nombre)->toBe('Administrador general');

        // ...y se respeta en el que el negocio personalizó.
        expect($roles['admin_local']->nombre)->toBe('La encargada');
    });
});

test('un tenant que revienta no deja sin migrar a los que vienen detrás', function () {
    /*
     * El roto va PRIMERO: es el orden lo que prueba que la tanda continúa. El
     * comando recorre los tenants en el orden en que se registraron.
     */
    $roto = crearTenantRegistrado($this->plan);
    crearDueno($roto);
    (new ProvisionTenantDatabase($roto))->handle();

    $sano = crearTenantRegistrado($this->plan);
    crearDueno($sano);
    (new ProvisionTenantDatabase($sano))->handle();

    /*
     * Al roto le borramos la FILA de `migrations` pero le dejamos la tabla: la
     * migración se cree pendiente, intenta el CREATE TABLE y choca con que ya
     * existe. Es el caso del docblock del comando —una migración que se pelea
     * con el estado de ESE negocio— y falla lanzando, no devolviendo un código:
     * la consola de Laravel corre con `setCatchExceptions(false)`, así que la
     * excepción sube por `Artisan::call`. Sin el try/catch se llevaba por
     * delante la tanda entera.
     */
    $roto->run(function () {
        DB::table('migrations')->where('migration', 'like', '%plantilla_whatsapps%')->delete();
    });

    // Al sano le dejamos una migración pendiente de verdad, esta sí sana.
    $sano->run(function () {
        Schema::drop('plantilla_whatsapps');
        DB::table('migrations')->where('migration', 'like', '%plantilla_whatsapps%')->delete();
    });

    $this->artisan('tenants:migrar-provisionados')->assertFailed();

    // Lo que importa: el que venía detrás del fallo SÍ se migró.
    expect($sano->run(fn () => Schema::hasTable('plantilla_whatsapps')))->toBeTrue();
});

/*
 * Story 1.6 sobre un negocio que ya estaba en marcha: el módulo `pagos` llega
 * a los dos administradores. Pero al de sede solo si el negocio no lo había
 * personalizado: a quien tocó su rol no se le cambia a su espalda.
 */
test('el módulo pagos llega a los administradores y respeta el rol personalizado', function () {
    $sinTocar = crearTenantRegistrado($this->plan);
    crearDueno($sinTocar);
    (new ProvisionTenantDatabase($sinTocar))->handle();

    $tocado = crearTenantRegistrado($this->plan);
    crearDueno($tocado);
    (new ProvisionTenantDatabase($tocado))->handle();

    // Rebobinar a antes de la 1.6: nadie tenía `pagos`.
    $rebobinar = function () {
        DB::table('roles')->get()->each(function ($rol) {
            $permisos = json_decode($rol->permisos, true);
            unset($permisos['pagos']);
            DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($permisos)]);
        });
        DB::table('migrations')->where('migration', '2026_10_19_000001_modulo_pagos_en_roles_de_sistema')->delete();
    };
    $sinTocar->run($rebobinar);
    $tocado->run(function () use ($rebobinar) {
        $rebobinar();
        DB::table('roles')->where('clave', 'admin_local')->update(['editado_at' => now()]);
    });

    $this->artisan('tenants:migrar-provisionados')->assertSuccessful();

    $pagos = fn ($tenant, string $clave) => $tenant->run(
        fn () => json_decode(DB::table('roles')->where('clave', $clave)->value('permisos'), true)['pagos'] ?? null
    );

    expect($pagos($sinTocar, 'admin_general'))->toBe('gestionar')
        ->and($pagos($sinTocar, 'admin_local'))->toBe('gestionar')
        ->and($pagos($sinTocar, 'profesional'))->toBeNull()
        // El general lo recibe siempre: su rol no se edita.
        ->and($pagos($tocado, 'admin_general'))->toBe('gestionar')
        // El de sede personalizado se queda como el negocio lo dejó.
        ->and($pagos($tocado, 'admin_local'))->toBeNull();
});

/*
 * Story 1.5 sobre un negocio que ya estaba en marcha: aparece Recepción sin
 * tocar los roles propios. El caso delicado es el negocio que ya se había
 * creado a mano un rol «Recepción»: `roles.nombre` es único, así que el de
 * sistema toma otro nombre y el suyo queda intacto.
 */
test('el rol de recepción llega a un negocio existente sin tocar sus roles propios', function () {
    $tenant = crearTenantRegistrado($this->plan);
    crearDueno($tenant);
    (new ProvisionTenantDatabase($tenant))->handle();

    $propio = $tenant->run(function () {
        // Rebobinar a antes de la 1.5.
        DB::table('roles')->where('clave', 'recepcion')->delete();
        DB::table('roles')->where('clave', 'admin_local')->update(['nombre' => 'Administrador local']);
        DB::table('migrations')->where('migration', '2026_10_19_000002_rol_de_sistema_recepcion')->delete();

        // El que el negocio se había inventado.
        return DB::table('roles')->insertGetId([
            'nombre' => 'Recepción',
            'clave' => null,
            'sistema' => false,
            'solo_propios' => false,
            'permisos' => json_encode(['citas' => 'ver']),
            'editado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->artisan('tenants:migrar-provisionados')->assertSuccessful();

    $tenant->run(function () use ($propio) {
        $sistema = DB::table('roles')->where('clave', 'recepcion')->first();
        $suyo = DB::table('roles')->find($propio);

        expect($sistema)->not->toBeNull()
            ->and((bool) $sistema->sistema)->toBeTrue()
            ->and($sistema->nombre)->toBe('Recepción (sistema)')
            ->and(json_decode($sistema->permisos, true)['pagos'])->toBe('gestionar');

        // El suyo, tal cual.
        expect($suyo->nombre)->toBe('Recepción')
            ->and($suyo->clave)->toBeNull()
            ->and((bool) $suyo->sistema)->toBeFalse()
            ->and(json_decode($suyo->permisos, true))->toBe(['citas' => 'ver']);

        // Y el administrador de sede, renombrado porque nadie lo tocó.
        expect(DB::table('roles')->where('clave', 'admin_local')->value('nombre'))
            ->toBe('Administrador de sede');
    });

    // Relanzar no duplica: idempotente.
    $tenant->run(fn () => DB::table('migrations')->where('migration', '2026_10_19_000002_rol_de_sistema_recepcion')->delete());
    $this->artisan('tenants:migrar-provisionados')->assertSuccessful();
    expect($tenant->run(fn () => DB::table('roles')->where('clave', 'recepcion')->count()))->toBe(1);
});
