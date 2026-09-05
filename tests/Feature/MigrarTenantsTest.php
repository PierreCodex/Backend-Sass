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

        Schema::dropIfExists('usuarios');

        DB::table('migrations')
            ->where('migration', '2026_09_04_000001_separar_usuarios_de_profesionales')
            ->delete();
    });

    $this->artisan('tenants:migrar-provisionados')->assertSuccessful();

    $tenant->run(function () use ($dueno, $rolDueno) {
        // Los roles sembrados siguen ahí: la migración no recrea esa tabla.
        expect(DB::table('roles')->count())->toBe(3);

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
            ->toBe(['admin_general', 'admin_local', 'profesional'])
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
