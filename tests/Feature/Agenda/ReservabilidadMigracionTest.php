<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Local;
use App\Models\Plan;
use App\Models\Profesional;
use App\Models\Servicio;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;

/*
 * La migración de datos de la Story 1.3: desde G-3 una cita exige las filas de
 * `local_profesional` y `servicio_profesional`, y los negocios que ya existían
 * no las tenían. Se ejecuta su `up()` a mano sobre datos sembrados DESPUÉS de
 * provisionar, que es lo que ocurrirá al desplegar sobre un negocio vivo.
 */

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();
});

afterEach(fn () => limpiarBasesDeTenants());

function rellenarPivotes(object $test): void
{
    $migracion = require database_path('migrations/tenant/2026_09_28_000001_rellenar_pivotes_de_reservabilidad.php');

    $test->tenant->run(fn () => $migracion->up());
}

/** @return array<int, array{0: int, 1: int}> pares ordenados del pivote */
function pares(object $test, string $tabla, string $a, string $b): array
{
    return $test->tenant->run(fn () => DB::table($tabla)
        ->orderBy($a)->orderBy($b)
        ->get([$a, $b])
        ->map(fn ($f) => [(int) $f->{$a}, (int) $f->{$b}])
        ->all());
}

function servicioDePrueba(string $nombre): Servicio
{
    return Servicio::create([
        'nombre' => $nombre, 'color' => '#ff0000', 'tipo' => 'normal', 'precio' => 30, 'duracion_min' => 60,
    ]);
}

test('en una base recién provisionada no hace nada', function () {
    $antes = [
        pares($this, 'local_profesional', 'local_id', 'profesional_id'),
        pares($this, 'servicio_profesional', 'servicio_id', 'profesional_id'),
    ];

    rellenarPivotes($this);

    expect([
        pares($this, 'local_profesional', 'local_id', 'profesional_id'),
        pares($this, 'servicio_profesional', 'servicio_id', 'profesional_id'),
    ])->toBe($antes);
});

test('los pivotes vacíos se rellenan con todo lo que no está borrado', function () {
    [$norte, $sur, $rosa, $luis, $corte, $tinte] = $this->tenant->run(function () {
        // El provisioning puede dejar la ficha del dueño: se parte de cero para
        // que los pares esperados sean exactos.
        Profesional::query()->forceDelete();

        $borrada = Local::create(['nombre' => 'Cerrada']);
        $borrada->delete();
        $borrado = Profesional::create(['nombre' => 'Ex']);
        $borrado->delete();
        $servicioBorrado = servicioDePrueba('Retirado');
        $servicioBorrado->delete();

        return [
            Local::create(['nombre' => 'Norte'])->id,
            Local::create(['nombre' => 'Sur'])->id,
            Profesional::create(['nombre' => 'Rosa'])->id,
            Profesional::create(['nombre' => 'Luis'])->id,
            servicioDePrueba('Corte')->id,
            servicioDePrueba('Tinte')->id,
        ];
    });

    rellenarPivotes($this);

    // Cada profesional en cada sede viva, habilitado.
    expect(pares($this, 'local_profesional', 'local_id', 'profesional_id'))->toBe([
        [$norte, $rosa], [$norte, $luis], [$sur, $rosa], [$sur, $luis],
    ]);
    expect($this->tenant->run(fn () => DB::table('local_profesional')->where('habilitado', false)->count()))->toBe(0);

    // Cada servicio vivo con cada profesional vivo.
    expect(pares($this, 'servicio_profesional', 'servicio_id', 'profesional_id'))->toBe([
        [$corte, $rosa], [$corte, $luis], [$tinte, $rosa], [$tinte, $luis],
    ]);
});

test('una asignación deliberada no se ensancha', function () {
    [$norte, $rosa, $corte] = $this->tenant->run(function () {
        Profesional::query()->forceDelete();

        $norte = Local::create(['nombre' => 'Norte']);
        Local::create(['nombre' => 'Sur']);

        $rosa = Profesional::create(['nombre' => 'Rosa']);
        $luis = Profesional::create(['nombre' => 'Luis']);
        $corte = servicioDePrueba('Corte');
        $tinte = servicioDePrueba('Tinte');

        // Rosa solo en norte —y deshabilitada, que también es una decisión—;
        // el corte solo con Rosa y el tinte solo con Luis.
        $rosa->locales()->attach($norte->id, ['habilitado' => false]);
        $corte->profesionales()->attach($rosa->id);
        $tinte->profesionales()->attach($luis->id);

        return [$norte->id, $rosa->id, $corte->id];
    });

    $luis = $this->tenant->run(fn () => Profesional::where('nombre', 'Luis')->value('id'));
    $sur = $this->tenant->run(fn () => Local::where('nombre', 'Sur')->value('id'));

    rellenarPivotes($this);

    // Rosa sigue solo en norte y deshabilitada; Luis, que no tenía nada, entra
    // en las dos.
    expect(pares($this, 'local_profesional', 'local_id', 'profesional_id'))->toBe([
        [$norte, $rosa], [$norte, $luis], [$sur, $luis],
    ]);
    expect($this->tenant->run(fn () => (bool) DB::table('local_profesional')
        ->where('profesional_id', $rosa)->value('habilitado')))->toBeFalse();

    // El corte sigue solo con Rosa y el tinte solo con Luis.
    $tinte = $this->tenant->run(fn () => Servicio::where('nombre', 'Tinte')->value('id'));
    expect(pares($this, 'servicio_profesional', 'servicio_id', 'profesional_id'))->toBe([
        [$corte, $rosa], [$tinte, $luis],
    ]);
});

/*
 * Cada servicio ya tiene a alguien, pero Ana no presta nada: antes de G-3 se le
 * agendaba cualquier cosa, así que tras desplegar tiene que poder seguir
 * haciéndolo. Y Luis, que ya tenía el suyo, no se ensancha.
 */
test('un profesional sin ningún servicio pasa a prestarlos todos', function () {
    [$rosa, $luis, $ana, $corte, $tinte] = $this->tenant->run(function () {
        Profesional::query()->forceDelete();

        $rosa = Profesional::create(['nombre' => 'Rosa']);
        $luis = Profesional::create(['nombre' => 'Luis']);
        $ana = Profesional::create(['nombre' => 'Ana']);
        $corte = servicioDePrueba('Corte');
        $tinte = servicioDePrueba('Tinte');
        $retirado = servicioDePrueba('Retirado');
        $retirado->delete();

        $corte->profesionales()->attach($rosa->id);
        $tinte->profesionales()->attach($luis->id);

        return [$rosa->id, $luis->id, $ana->id, $corte->id, $tinte->id];
    });

    rellenarPivotes($this);

    expect(pares($this, 'servicio_profesional', 'servicio_id', 'profesional_id'))->toBe([
        [$corte, $rosa], [$corte, $ana], [$tinte, $luis], [$tinte, $ana],
    ]);
});

/*
 * Los tres conjuntos «vacíos» se calculan antes de insertar: el servicio vacío
 * no puede «llenar» al profesional vacío y dejarlo con un solo servicio.
 */
test('el relleno de un servicio vacío no le quita el resto al profesional vacío', function () {
    [$rosa, $ana, $corte, $tinte] = $this->tenant->run(function () {
        Profesional::query()->forceDelete();

        $rosa = Profesional::create(['nombre' => 'Rosa']);
        $ana = Profesional::create(['nombre' => 'Ana']);
        $corte = servicioDePrueba('Corte');
        $tinte = servicioDePrueba('Tinte');

        $corte->profesionales()->attach($rosa->id);

        return [$rosa->id, $ana->id, $corte->id, $tinte->id];
    });

    rellenarPivotes($this);

    // El tinte (vacío) va a las dos; Ana (vacía) presta también el corte.
    expect(pares($this, 'servicio_profesional', 'servicio_id', 'profesional_id'))->toBe([
        [$corte, $rosa], [$corte, $ana], [$tinte, $rosa], [$tinte, $ana],
    ]);
});

/*
 * Una fila que solo apunta a algo borrado no es una asignación. Cada conjunto
 * se prueba por separado: Luis (vivo, con su tinte y su sede) hace que solo el
 * relleno del SERVICIO le dé el corte, y que solo el de la PROFESIONAL le dé a
 * Rosa el tinte.
 */
test('un pivote que solo apunta a registros borrados cuenta como vacío', function () {
    $ids = $this->tenant->run(function () {
        Profesional::query()->forceDelete();

        $norte = Local::create(['nombre' => 'Norte']);
        $cerrada = Local::create(['nombre' => 'Cerrada']);
        $rosa = Profesional::create(['nombre' => 'Rosa']);
        $luis = Profesional::create(['nombre' => 'Luis']);
        $ex = Profesional::create(['nombre' => 'Ex']);
        $corte = servicioDePrueba('Corte');
        $tinte = servicioDePrueba('Tinte');
        $retirado = servicioDePrueba('Retirado');

        // Rosa: solo una sede cerrada y solo un servicio retirado.
        $rosa->locales()->attach($cerrada->id, ['habilitado' => true]);
        $rosa->servicios()->attach($retirado->id);
        // El corte: solo con alguien que se fue.
        $corte->profesionales()->attach($ex->id);
        // Luis: asignación deliberada y viva.
        $luis->locales()->attach($norte->id, ['habilitado' => true]);
        $luis->servicios()->attach($tinte->id);

        $cerrada->delete();
        $retirado->delete();
        $ex->delete();

        return compact('norte', 'cerrada', 'rosa', 'luis', 'ex', 'corte', 'tinte', 'retirado');
    });
    ['norte' => $norte, 'cerrada' => $cerrada, 'rosa' => $rosa, 'luis' => $luis, 'ex' => $ex,
        'corte' => $corte, 'tinte' => $tinte, 'retirado' => $retirado] = array_map(fn ($m) => $m->id, $ids);

    rellenarPivotes($this);

    // Sin sedes vivas → Rosa entra en norte. La fila a la cerrada se queda.
    expect(pares($this, 'local_profesional', 'local_id', 'profesional_id'))->toBe([
        [$norte, $rosa], [$norte, $luis], [$cerrada, $rosa],
    ]);

    // Servicio sin profesionales vivos → el corte a Rosa y a Luis.
    // Profesional sin servicios vivos → Rosa presta corte y tinte.
    expect(pares($this, 'servicio_profesional', 'servicio_id', 'profesional_id'))->toBe([
        [$corte, $rosa], [$corte, $luis], [$corte, $ex],
        [$tinte, $rosa], [$tinte, $luis],
        [$retirado, $rosa],
    ]);
});

test('dos pasadas dan lo mismo que una', function () {
    $this->tenant->run(function () {
        Local::create(['nombre' => 'Norte']);
        Profesional::create(['nombre' => 'Rosa']);
        servicioDePrueba('Corte');
    });

    rellenarPivotes($this);
    $una = [
        pares($this, 'local_profesional', 'local_id', 'profesional_id'),
        pares($this, 'servicio_profesional', 'servicio_id', 'profesional_id'),
    ];

    rellenarPivotes($this);

    expect([
        pares($this, 'local_profesional', 'local_id', 'profesional_id'),
        pares($this, 'servicio_profesional', 'servicio_id', 'profesional_id'),
    ])->toBe($una)
        ->and($una[0])->not->toBe([])
        ->and($una[1])->not->toBe([]);
});
