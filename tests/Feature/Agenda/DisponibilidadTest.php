<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Cita;
use App\Models\Cliente;
use App\Models\Plan;
use App\Models\Profesional;
use App\Services\Disponibilidad;
use App\Support\Jornada;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();
});

afterEach(fn () => limpiarBasesDeTenants());

// 2026-09-07 es LUNES (ISO 1). Todas las fechas del fichero salen de aquí para
// que el día de la semana sea evidente al leer el test.
const LUNES = '2026-09-07';

const MARTES = '2026-09-08';

/**
 * Un profesional con el horario que se le pase, ya guardado en la BD del
 * negocio. `null` = sin horario propio, que es el caso del nivel 5.
 */
function profesionalCon(object $test, ?array $horario): Profesional
{
    return $test->tenant->run(fn () => Profesional::create([
        'nombre' => 'Lic. Rosa Paredes',
        'horario' => $horario,
    ]));
}

/** Días de la semana, con el mismo turno en todos los que se pidan. */
function dias(array $activos, string $desde = '09:00', string $hasta = '18:00', array $breaks = []): array
{
    return array_map(fn (int $dia) => [
        'dia' => $dia,
        'activo' => in_array($dia, $activos, true),
        'desde' => in_array($dia, $activos, true) ? $desde : null,
        'hasta' => in_array($dia, $activos, true) ? $hasta : null,
        'breaks' => in_array($dia, $activos, true) ? $breaks : [],
    ], range(1, 7));
}

/** Ocupa un tramo con una cita real, que es lo que el motor consulta. */
function ocuparCon(object $test, Profesional $profesional, string $fecha, string $desde, string $hasta, string $estado = 'confirmada'): Cita
{
    return $test->tenant->run(function () use ($profesional, $fecha, $desde, $hasta, $estado) {
        $cliente = Cliente::firstOrCreate(['nombre' => 'Cliente de prueba']);

        return Cita::forceCreate([
            'codigo' => substr(md5($fecha.$desde.$estado.uniqid()), 0, 8),
            'profesional_id' => $profesional->id,
            'cliente_id' => $cliente->id,
            'starts_at' => "{$fecha} {$desde}:00",
            'ends_at' => "{$fecha} {$hasta}:00",
            'estado' => $estado,
        ]);
    });
}

function huecosDe(object $test, Profesional $profesional, string $fecha, int $duracion, ?int $excepto = null): array
{
    return $test->tenant->run(
        fn () => (new Disponibilidad($test->tenant))->huecos($profesional, $fecha, $duracion, $excepto)
    );
}

function jornadaDe(object $test, Profesional $profesional, string $fecha): Jornada
{
    return $test->tenant->run(fn () => (new Disponibilidad($test->tenant))->jornada($profesional, $fecha));
}

/*
|--------------------------------------------------------------------------
| Los cinco niveles de precedencia (contrato §4)
|--------------------------------------------------------------------------
*/

/*
 * Caso 1 del plan. Es el que decide si un profesional recién creado sirve para
 * algo: sin esto nace sin agenda y nadie puede reservarle nada hasta que
 * alguien le rellene siete días a mano.
 */
test('sin horario propio rige el del negocio', function () {
    $profesional = profesionalCon($this, null);

    $jornada = jornadaDe($this, $profesional, LUNES);

    expect($jornada->origen)->toBe(Jornada::NEGOCIO)
        ->and($jornada->trabaja)->toBeTrue()
        // Los defaults de aplicación, porque este negocio no ha tocado su
        // horario todavía.
        ->and($jornada->desde)->toBe('09:00')
        ->and($jornada->hasta)->toBe('20:00');

    expect(huecosDe($this, $profesional, LUNES, 60))->not->toBeEmpty();
});

test('el horario del negocio manda sobre el default cuando está configurado', function () {
    $this->tenant->forceFill([
        'configuracion' => ['horario' => ['apertura' => '10:00', 'cierre' => '14:00']],
    ])->save();

    $profesional = profesionalCon($this, null);

    expect(huecosDe($this, $profesional, LUNES, 60))->toBe(['10:00', '11:00', '12:00', '13:00']);
});

// Caso 2: tiene horario propio y ese día no lo trabaja.
test('un día no laborable no ofrece nada', function () {
    $profesional = profesionalCon($this, ['dias' => dias([1]), 'excepciones' => []]);

    expect(jornadaDe($this, $profesional, MARTES)->origen)->toBe(Jornada::NO_LABORABLE);
    expect(huecosDe($this, $profesional, MARTES, 30))->toBe([]);
});

// Caso 3: la excepción gana al día laborable.
test('una excepción no disponible vacía el día, aunque sea laborable', function () {
    $profesional = profesionalCon($this, [
        'dias' => dias([1, 2, 3, 4, 5]),
        'excepciones' => [[
            'fecha' => LUNES,
            'disponible' => false,
            'desde' => null,
            'hasta' => null,
            'nota' => 'Permiso médico',
        ]],
    ]);

    $jornada = jornadaDe($this, $profesional, LUNES);

    expect($jornada->origen)->toBe(Jornada::EXCEPCION_INACTIVA)
        ->and($jornada->trabaja)->toBeFalse()
        // La nota viaja para que la pantalla diga POR QUÉ, en vez del genérico
        // «no atiende este día».
        ->and($jornada->nota)->toBe('Permiso médico');

    expect(huecosDe($this, $profesional, LUNES, 30))->toBe([]);
});

/*
 * Caso 4. REEMPLAZA, no se suma: es medio turno, un refuerzo o cubrir a un
 * compañero. Si se mezclara con el horario habitual, un «solo por la tarde»
 * seguiría ofreciendo la mañana.
 */
test('una excepción disponible reemplaza el horario de ese día', function () {
    $profesional = profesionalCon($this, [
        'dias' => dias([1, 2, 3, 4, 5], '09:00', '18:00'),
        'excepciones' => [[
            'fecha' => LUNES,
            'disponible' => true,
            'desde' => '14:00',
            'hasta' => '17:00',
            'nota' => 'Medio turno',
        ]],
    ]);

    expect(jornadaDe($this, $profesional, LUNES)->origen)->toBe(Jornada::EXCEPCION);

    expect(huecosDe($this, $profesional, LUNES, 60))->toBe(['14:00', '15:00', '16:00']);

    // Y el resto de la semana sigue con su horario de siempre.
    expect(huecosDe($this, $profesional, MARTES, 60))->toContain('09:00');
});

/*
|--------------------------------------------------------------------------
| Lo que ocupa la jornada
|--------------------------------------------------------------------------
*/

// Caso 5: el break parte el día en dos.
test('un descanso parte la jornada', function () {
    $profesional = profesionalCon($this, [
        'dias' => dias([1], '09:00', '15:00', [['desde' => '12:00', 'hasta' => '13:00']]),
        'excepciones' => [],
    ]);

    expect(huecosDe($this, $profesional, LUNES, 60))
        ->toBe(['09:00', '10:00', '11:00', '13:00', '14:00']);
});

// Caso 6: una cita bloquea su tramo.
test('una cita existente bloquea su tramo', function () {
    $profesional = profesionalCon($this, ['dias' => dias([1], '09:00', '13:00'), 'excepciones' => []]);

    ocuparCon($this, $profesional, LUNES, '10:00', '11:00');

    expect(huecosDe($this, $profesional, LUNES, 60))->toBe(['09:00', '11:00', '12:00']);
});

test('una cita cancelada libera su hueco; una de no asistió, no', function () {
    $profesional = profesionalCon($this, ['dias' => dias([1], '09:00', '12:00'), 'excepciones' => []]);

    ocuparCon($this, $profesional, LUNES, '10:00', '11:00', 'cancelada');

    expect(huecosDe($this, $profesional, LUNES, 60))->toBe(['09:00', '10:00', '11:00']);

    /*
     * `no_asistio` ocupó ese rato igual que cualquier otra: el profesional
     * estuvo esperando. Liberarlo reescribiría el pasado.
     */
    ocuparCon($this, $profesional, LUNES, '10:00', '11:00', 'no_asistio');

    expect(huecosDe($this, $profesional, LUNES, 60))->toBe(['09:00', '11:00']);
});

/*
 * Al editar, la hora que la cita ya tenía sigue siendo elegible: si no, el
 * formulario abriría con su propio hueco marcado como ocupado y no se podría
 * guardar sin moverla.
 */
test('al editar, su propia cita no se estorba', function () {
    $profesional = profesionalCon($this, ['dias' => dias([1], '09:00', '12:00'), 'excepciones' => []]);

    $cita = ocuparCon($this, $profesional, LUNES, '10:00', '11:00');

    expect(huecosDe($this, $profesional, LUNES, 60))->toBe(['09:00', '11:00']);
    expect(huecosDe($this, $profesional, LUNES, 60, $cita->id))->toBe(['09:00', '10:00', '11:00']);
});

// Caso 7: el hueco tiene que caber ENTERO antes del cierre.
test('el hueco tiene que caber entero antes del cierre', function () {
    $profesional = profesionalCon($this, ['dias' => dias([1], '09:00', '10:00'), 'excepciones' => []]);

    expect(huecosDe($this, $profesional, LUNES, 60))->toBe(['09:00']);
    // 90 minutos no caben en una hora de jornada, aunque empiece a la apertura.
    expect(huecosDe($this, $profesional, LUNES, 90))->toBe([]);
});

test('dos citas seguidas no se pisan: terminar cuando otra empieza es válido', function () {
    $profesional = profesionalCon($this, ['dias' => dias([1], '09:00', '12:00'), 'excepciones' => []]);

    ocuparCon($this, $profesional, LUNES, '10:00', '11:00');

    // 09:00–10:00 acaba justo cuando la otra empieza: el intervalo es
    // semiabierto y eso NO es solape.
    expect(huecosDe($this, $profesional, LUNES, 60))->toContain('09:00');
});

/*
|--------------------------------------------------------------------------
| La rejilla y sus bordes
|--------------------------------------------------------------------------
*/

/*
 * Caso 8, el que más fácil se olvida. Sin los bordes, una cita de 50 min desde
 * las 09:00 termina a las 09:50 y la rejilla no volvería a ofrecer nada hasta
 * las 10:00: diez minutos con el profesional libre y el hueco invisible.
 */
test('tras una cita se ofrece el instante exacto, no el siguiente múltiplo', function () {
    $this->tenant->forceFill([
        'configuracion' => ['agenda' => ['modo_intervalo' => 'fijo', 'intervalo_min' => 30]],
    ])->save();

    $profesional = profesionalCon($this, ['dias' => dias([1], '09:00', '12:00'), 'excepciones' => []]);

    ocuparCon($this, $profesional, LUNES, '09:00', '09:50');

    // 09:50 es el borde: la rejilla de 30 habría saltado de 09:30 a 10:00.
    expect(huecosDe($this, $profesional, LUNES, 10))->toContain('09:50');
});

test('tras un descanso también se ofrece el instante exacto', function () {
    $profesional = profesionalCon($this, [
        'dias' => dias([1], '09:00', '12:00', [['desde' => '09:20', 'hasta' => '09:50']]),
        'excepciones' => [],
    ]);

    expect(huecosDe($this, $profesional, LUNES, 30))->toContain('09:50');
});

/*
 * El paso lo decide el dueño en Configuración. `duracion_servicio` encadena
 * (agenda compacta); `fijo` fragmenta pero deja elegir.
 */
test('el modo fijo usa su intervalo y el de duración encadena', function () {
    $profesional = profesionalCon($this, ['dias' => dias([1], '09:00', '11:00'), 'excepciones' => []]);

    /*
     * Por defecto, `duracion_servicio`: el paso es la duración, así que las
     * citas se encadenan. Solo caben dos — 10:30 + 45 se pasaría de las 11:00,
     * y el hueco tiene que caber entero.
     */
    expect(huecosDe($this, $profesional, LUNES, 45))->toBe(['09:00', '09:45']);

    $this->tenant->forceFill([
        'configuracion' => ['agenda' => ['modo_intervalo' => 'fijo', 'intervalo_min' => 15]],
    ])->save();

    expect(huecosDe($this, $profesional, LUNES, 45))
        ->toBe(['09:00', '09:15', '09:30', '09:45', '10:00', '10:15']);
});

test('una duración absurda no devuelve nada en vez de reventar', function () {
    $profesional = profesionalCon($this, ['dias' => dias([1]), 'excepciones' => []]);

    expect(huecosDe($this, $profesional, LUNES, 0))->toBe([])
        ->and(huecosDe($this, $profesional, LUNES, -30))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| El caso dorado
|--------------------------------------------------------------------------
*/

/*
 * El ejemplo verificado de `vistas/citas.md`: Lic. Rosa Paredes, 09:00–18:00,
 * break de 13:00 a 14:00, con dos citas.
 *
 * Es el test que de verdad importa, porque compara contra números que ya
 * existían antes de escribir este motor. Los otros comprueban que hace lo que
 * yo creo; este, que hace lo que la especificación dice.
 *
 * Las dos citas están reconstruidas desde los dos listados de la ficha: son las
 * únicas que producen a la vez las 27 opciones de 15 min y las 9 de 45 con
 * 10:15 y 15:30 entre ellas.
 */
describe('el caso dorado de la ficha de citas', function () {
    beforeEach(function () {
        $this->rosa = profesionalCon($this, [
            'dias' => dias([1, 2, 3, 4, 5], '09:00', '18:00', [['desde' => '13:00', 'hasta' => '14:00']]),
            'excepciones' => [],
        ]);

        ocuparCon($this, $this->rosa, LUNES, '10:00', '10:15');
        ocuparCon($this, $this->rosa, LUNES, '14:30', '15:30');
    });

    test('Hemograma de 15 min: 27 opciones, de 09:00 a 17:45', function () {
        $huecos = huecosDe($this, $this->rosa, LUNES, 15);

        expect($huecos)->toHaveCount(27)
            ->and($huecos[0])->toBe('09:00')
            ->and(end($huecos))->toBe('17:45')
            // Ni el break ni las citas asoman por ninguna parte.
            ->and($huecos)->not->toContain('13:00')
            ->and($huecos)->not->toContain('10:00')
            ->and($huecos)->not->toContain('14:30');
    });

    test('Limpieza dental de 45 min: las 9 opciones exactas, bordes incluidos', function () {
        expect(huecosDe($this, $this->rosa, LUNES, 45))->toBe([
            '09:00',
            // Borde: justo cuando Rosa se libera de la primera cita. La rejilla
            // de 45 iba de 09:45 a 10:30 y no lo habría ofrecido.
            '10:15',
            '10:30',
            '11:15',
            '12:00',
            // Borde: sale de la cita de la tarde.
            '15:30',
            '15:45',
            '16:30',
            '17:15',
        ]);
    });
});
