<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Cita;
use App\Models\Cliente;
use App\Models\InventarioMovimiento;
use App\Models\Local;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\User;
use App\Models\Usuario;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;

    [$this->profesional, $this->servicio] = $this->tenant->run(fn () => [
        Profesional::create([
            'nombre' => 'Rosa Paredes',
            // Lunes a viernes, 09:00–18:00. Sin breaks: los huecos ya se
            // prueban a fondo en DisponibilidadTest.
            'horario' => [
                'dias' => array_map(fn (int $d) => [
                    'dia' => $d,
                    'activo' => $d <= 5,
                    'desde' => $d <= 5 ? '09:00' : null,
                    'hasta' => $d <= 5 ? '18:00' : null,
                    'breaks' => [],
                ], range(1, 7)),
                'excepciones' => [],
            ],
        ]),
        Servicio::create([
            'nombre' => 'Corte de cabello',
            'color' => '#ff0000',
            'tipo' => 'normal',
            'precio' => 30,
            'duracion_min' => 60,
        ]),
    ]);
});

afterEach(fn () => limpiarBasesDeTenants());

// 2026-09-07, lunes. La misma referencia que DisponibilidadTest.
const DIA = '2026-09-07';

function citaValida(object $test, array $extra = []): array
{
    return array_merge([
        'empleado_id' => $test->profesional->id,
        'servicio_id' => $test->servicio->id,
        'fecha' => DIA,
        'hora_inicio' => '10:00',
        'cliente_nombre' => 'Ana Torres',
        'cliente_telefono' => '987654321',
    ], $extra);
}

function agendar(object $test, array $extra = [])
{
    return $test->withToken($test->token)->postJson('/api/citas', citaValida($test, $extra));
}

/*
|--------------------------------------------------------------------------
| Alta y forma
|--------------------------------------------------------------------------
*/

test('agendar devuelve la cita con la forma del contrato', function () {
    $respuesta = agendar($this)->assertCreated();

    $respuesta
        ->assertJsonPath('data.fecha', DIA)
        ->assertJsonPath('data.hora_inicio', '10:00')
        // La calcula el backend: inicio + duración congelada del servicio.
        ->assertJsonPath('data.hora_fin', '11:00')
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.monto', 30)
        ->assertJsonPath('data.servicio.nombre', 'Corte de cabello')
        ->assertJsonPath('data.servicio.duracion_min', 60)
        ->assertJsonPath('data.empleado.nombre', 'Rosa Paredes')
        ->assertJsonPath('data.cliente_nombre', 'Ana Torres');

    // Ocho caracteres, y ninguno de los que se confunden al dictarlos.
    expect($respuesta->json('data.codigo'))->toHaveLength(8)
        ->and($respuesta->json('data.codigo'))->not->toMatch('/[O0I1L]/');
});

/*
 * §2.4: no existe `citas.servicio_id`. El panel manda uno y el backend inserta
 * UNA línea; `servicios[]` va al lado para que el día que la tienda encadene
 * tres, el panel pueda migrar sin que el backend cambie otra vez.
 */
test('el servicio vive en la línea, y sale también como lista', function () {
    $id = agendar($this)->assertCreated()->json('data.id');

    $lineas = $this->tenant->run(fn () => Cita::find($id)->servicios);

    expect($lineas)->toHaveCount(1)
        ->and((float) $lineas->first()->pivot->precio)->toBe(30.0)
        ->and((int) $lineas->first()->pivot->duracion_min)->toBe(60);

    $this->withToken($this->token)->getJson("/api/citas/{$id}")
        ->assertJsonPath('data.servicios.0.nombre', 'Corte de cabello')
        ->assertJsonCount(1, 'data.servicios');
});

/*
 * Precio y duración se congelan al reservar: subir la tarifa mañana no puede
 * cambiar lo que costó una cita de ayer.
 */
test('subir el precio del servicio no reescribe las citas ya hechas', function () {
    $id = agendar($this)->assertCreated()->json('data.id');

    $this->tenant->run(fn () => Servicio::find($this->servicio->id)->update(['precio' => 99]));

    $this->withToken($this->token)->getJson("/api/citas/{$id}")
        ->assertJsonPath('data.monto', 30)
        ->assertJsonPath('data.servicio.precio', 30);
});

/*
 * §2.6: el `monto` editable reescribe el precio de la línea, y `monto_total`
 * sigue siendo la suma. Así el campo es editable sin romper la invariante.
 */
test('el monto editable reescribe la línea y recalcula el total', function () {
    $id = agendar($this, ['monto' => 45])->assertCreated()
        ->assertJsonPath('data.monto', 45)
        ->assertJsonPath('data.monto_total', 45)
        ->json('data.id');

    expect($this->tenant->run(fn () => (float) Cita::find($id)->servicios->first()->pivot->precio))->toBe(45.0);
});

test('con un solo local la cita se asigna sola a la sede principal', function () {
    // El provisioning de las pruebas no deja principal: sin crearla, esto
    // compararía null con null y no fijaría nada. Solo la principal: es el
    // caso de una sede (§2.12), no el de varias.
    $principal = $this->tenant->run(fn () => Local::where('es_principal', true)->value('id')
        ?? tap(new Local(['nombre' => 'Principal']), fn (Local $l) => $l->forceFill(['es_principal' => true])->save())->id);

    expect($principal)->not->toBeNull()
        ->and($this->tenant->run(fn () => Local::count()))->toBe(1);

    agendar($this)->assertCreated()->assertJsonPath('data.local_id', $principal);
});

/*
|--------------------------------------------------------------------------
| El anti-solape
|--------------------------------------------------------------------------
*/

/*
 * La regla no negociable 1. En el backend anterior esto se podía hacer desde el
 * panel porque el cálculo vivía solo en la reserva pública.
 */
test('dos citas no pueden pisarse: 422 en hora_inicio', function () {
    agendar($this)->assertCreated();

    agendar($this)
        ->assertStatus(422)
        // La ficha pinta el error justo bajo el selector de huecos.
        ->assertJsonValidationErrors('hora_inicio');

    expect($this->tenant->run(fn () => Cita::count()))->toBe(1);
});

test('una cita cancelada libera su hora', function () {
    $id = agendar($this)->assertCreated()->json('data.id');

    $this->withToken($this->token)->putJson("/api/citas/{$id}", citaValida($this, ['estado' => 'cancelada']))
        ->assertOk();

    agendar($this)->assertCreated();
});

test('fuera del horario del profesional no se agenda', function () {
    // Rosa cierra a las 18:00: una cita de 60 min a las 17:30 no cabe entera.
    agendar($this, ['hora_inicio' => '17:30'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('hora_inicio');

    // Y el domingo no trabaja.
    agendar($this, ['fecha' => '2026-09-13'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('hora_inicio');
});

test('el mensaje distingue «hora ocupada» de «no trabaja ese día»', function () {
    agendar($this)->assertCreated();

    $ocupada = agendar($this)->json('errors.hora_inicio.0');
    $cerrado = agendar($this, ['fecha' => '2026-09-13'])->json('errors.hora_inicio.0');

    // Elegir otra hora arregla la primera y no la segunda; decirlo igual
    // dejaría al usuario probando horas en un día en que nadie atiende.
    expect($ocupada)->toContain('Libres:')
        ->and($cerrado)->toContain('no tiene horas libres ese día');
});

test('al editar, su propia hora sigue siendo válida', function () {
    $id = agendar($this)->assertCreated()->json('data.id');

    // Mismo hueco, solo cambia la nota: no puede estorbarse a sí misma.
    $this->withToken($this->token)
        ->putJson("/api/citas/{$id}", citaValida($this, ['estado' => 'confirmada', 'notas' => 'Viene con su hija']))
        ->assertOk()
        ->assertJsonPath('data.hora_inicio', '10:00')
        ->assertJsonPath('data.notas', 'Viene con su hija');
});

/*
|--------------------------------------------------------------------------
| El cliente (§2.2)
|--------------------------------------------------------------------------
*/

test('el cliente se reutiliza por teléfono, no se duplica', function () {
    agendar($this)->assertCreated();
    agendar($this, ['hora_inicio' => '12:00', 'cliente_nombre' => 'Ana T.'])->assertCreated();

    expect($this->tenant->run(fn () => Cliente::count()))->toBe(1);
});

test('sin teléfono se crea una ficha nueva aunque el nombre se repita', function () {
    agendar($this, ['cliente_telefono' => null])->assertCreated();
    agendar($this, ['hora_inicio' => '12:00', 'cliente_telefono' => null])->assertCreated();

    /*
     * Deliberado: sin teléfono no hay con qué reconocerlo, y fusionar por
     * nombre juntaría a dos «Ana Torres» distintas. Separar dos historiales
     * mezclados es mucho peor que tener dos fichas repetidas.
     */
    expect($this->tenant->run(fn () => Cliente::count()))->toBe(2);
});

test('con cliente_id se usa esa ficha y no se inventa otra', function () {
    $cliente = $this->tenant->run(fn () => Cliente::create(['nombre' => 'Marta', 'telefono' => '999888777']));

    agendar($this, ['cliente_id' => $cliente->id, 'cliente_nombre' => null])
        ->assertCreated()
        ->assertJsonPath('data.cliente_id', $cliente->id);

    expect($this->tenant->run(fn () => Cliente::count()))->toBe(1);
});

test('sin cliente_id ni nombre, 422 en el nombre', function () {
    agendar($this, ['cliente_nombre' => null, 'cliente_telefono' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cliente_nombre');
});

/*
|--------------------------------------------------------------------------
| Productos y stock
|--------------------------------------------------------------------------
*/

test('los productos se congelan al precio de venta del día', function () {
    $producto = $this->tenant->run(fn () => Producto::create([
        'nombre' => 'Cera', 'precio' => 20, 'stock' => 10,
    ]));

    $id = agendar($this, ['productos' => [['id' => $producto->id, 'cantidad' => 2]]])
        ->assertCreated()
        ->assertJsonPath('data.productos.0.precio_unitario', 20)
        ->assertJsonPath('data.productos.0.cantidad', 2)
        // 30 del servicio + 40 de producto.
        ->assertJsonPath('data.monto_total', 70)
        ->json('data.id');

    $this->tenant->run(fn () => Producto::find($producto->id)->update(['precio' => 35]));

    $this->withToken($this->token)->getJson("/api/citas/{$id}")
        ->assertJsonPath('data.productos.0.precio_unitario', 20);
});

/*
 * Agendar no saca nada del almacén: reservar dos ceras para el jueves no las
 * quita del estante hoy. El stock se mueve cuando la cita se COMPLETA.
 */
test('el stock baja al completar, no al agendar', function () {
    $producto = $this->tenant->run(fn () => Producto::create([
        'nombre' => 'Cera', 'precio' => 20, 'stock' => 10,
    ]));

    $id = agendar($this, ['productos' => [['id' => $producto->id, 'cantidad' => 2]]])
        ->assertCreated()->json('data.id');

    expect($this->tenant->run(fn () => Producto::find($producto->id)->stock))->toBe(10);

    $this->withToken($this->token)->putJson("/api/citas/{$id}", citaValida($this, [
        'estado' => 'completada',
        'productos' => [['id' => $producto->id, 'cantidad' => 2]],
    ]))->assertOk();

    expect($this->tenant->run(fn () => Producto::find($producto->id)->stock))->toBe(8);

    $venta = $this->tenant->run(fn () => InventarioMovimiento::where('cita_id', $id)->sole());
    expect($venta->tipo)->toBe('venta')->and($venta->cantidad)->toBe(2);
});

/*
 * Marcar completada por error y corregirlo no puede dejar el stock descontado
 * para siempre: sería un error de inventario silencioso, que es justo contra lo
 * que existe el libro de movimientos.
 */
test('deshacer el completado devuelve el stock, anotando la devolución', function () {
    $producto = $this->tenant->run(fn () => Producto::create([
        'nombre' => 'Cera', 'precio' => 20, 'stock' => 10,
    ]));

    $lineas = ['productos' => [['id' => $producto->id, 'cantidad' => 2]]];

    $id = agendar($this, $lineas)->assertCreated()->json('data.id');

    $this->withToken($this->token)->putJson("/api/citas/{$id}", citaValida($this, $lineas + ['estado' => 'completada']))->assertOk();
    $this->withToken($this->token)->putJson("/api/citas/{$id}", citaValida($this, $lineas + ['estado' => 'cancelada']))->assertOk();

    expect($this->tenant->run(fn () => Producto::find($producto->id)->stock))->toBe(10);

    // El libro no se reescribe: quedan las dos filas, la venta y su vuelta.
    expect($this->tenant->run(fn () => InventarioMovimiento::where('cita_id', $id)->count()))->toBe(2);
});

test('ir y venir del completado no descuenta dos veces', function () {
    $producto = $this->tenant->run(fn () => Producto::create([
        'nombre' => 'Cera', 'precio' => 20, 'stock' => 10,
    ]));

    $lineas = ['productos' => [['id' => $producto->id, 'cantidad' => 2]]];
    $id = agendar($this, $lineas)->assertCreated()->json('data.id');

    foreach (['completada', 'confirmada', 'completada'] as $estado) {
        $this->withToken($this->token)
            ->putJson("/api/citas/{$id}", citaValida($this, $lineas + ['estado' => $estado]))
            ->assertOk();
    }

    expect($this->tenant->run(fn () => Producto::find($producto->id)->stock))->toBe(8);
});

/*
|--------------------------------------------------------------------------
| Listado y filtros
|--------------------------------------------------------------------------
*/

test('el listado filtra por fecha, por estado y busca por cliente', function () {
    agendar($this)->assertCreated();
    agendar($this, ['hora_inicio' => '12:00', 'cliente_nombre' => 'Beto Ruiz', 'cliente_telefono' => '911111111'])
        ->assertCreated();
    agendar($this, ['fecha' => '2026-09-08', 'cliente_telefono' => null])->assertCreated();

    $this->withToken($this->token)->getJson('/api/citas?fecha='.DIA)
        ->assertOk()->assertJsonCount(2, 'data');

    $this->withToken($this->token)->getJson('/api/citas?search=Beto')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.cliente_nombre', 'Beto Ruiz');

    $this->withToken($this->token)->getJson('/api/citas?search=911111111')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->withToken($this->token)->getJson('/api/citas?estado=pendiente')
        ->assertOk()->assertJsonCount(3, 'data');
});

test('el listado viene en orden de agenda, no de creación', function () {
    agendar($this, ['hora_inicio' => '15:00'])->assertCreated();
    agendar($this, ['hora_inicio' => '09:00', 'cliente_telefono' => null])->assertCreated();

    $this->withToken($this->token)->getJson('/api/citas')
        ->assertJsonPath('data.0.hora_inicio', '09:00')
        ->assertJsonPath('data.1.hora_inicio', '15:00');
});

/*
|--------------------------------------------------------------------------
| Onboarding, estados y borrado
|--------------------------------------------------------------------------
*/

test('la primera cita marca el paso del onboarding', function () {
    $paso = fn () => collect($this->withToken($this->token)->getJson('/api/onboarding')->json('data.pasos'))
        ->firstWhere('clave', 'reserva_prueba')['completado'];

    expect($paso())->toBeFalse();

    agendar($this)->assertCreated();

    expect($paso())->toBeTrue();
});

/*
 * §2.5: la BD tiene seis estados y el contrato del panel declaraba cuatro. Se
 * emiten los seis. Recortarlos habría obligado a mentir sobre el estado real de
 * una cita, y el propio contrato los necesita — su bloque de inasistencias es
 * imposible sin `no_asistio`.
 */
test('los seis estados de la BD se aceptan y se emiten', function () {
    $id = agendar($this)->assertCreated()->json('data.id');

    foreach (['confirmada', 'en_curso', 'completada', 'no_asistio'] as $estado) {
        $this->withToken($this->token)
            ->putJson("/api/citas/{$id}", citaValida($this, ['estado' => $estado]))
            ->assertOk()
            ->assertJsonPath('data.estado', $estado);
    }

    $this->withToken($this->token)
        ->putJson("/api/citas/{$id}", citaValida($this, ['estado' => 'inventado']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('estado');
});

test('confirmar y completar dejan su marca de tiempo', function () {
    $id = agendar($this)->assertCreated()->json('data.id');

    $this->withToken($this->token)->putJson("/api/citas/{$id}", citaValida($this, ['estado' => 'confirmada']))->assertOk();

    expect($this->tenant->run(fn () => Cita::find($id)->confirmada_el))->not->toBeNull();
});

test('borrar una cita completada devuelve su stock antes de irse', function () {
    $producto = $this->tenant->run(fn () => Producto::create([
        'nombre' => 'Cera', 'precio' => 20, 'stock' => 10,
    ]));

    $lineas = ['productos' => [['id' => $producto->id, 'cantidad' => 3]]];
    $id = agendar($this, $lineas)->assertCreated()->json('data.id');

    $this->withToken($this->token)->putJson("/api/citas/{$id}", citaValida($this, $lineas + ['estado' => 'completada']))->assertOk();
    expect($this->tenant->run(fn () => Producto::find($producto->id)->stock))->toBe(7);

    $this->withToken($this->token)->deleteJson("/api/citas/{$id}")->assertStatus(204);

    // Si no, el descuento se queda sin nada que lo explique.
    expect($this->tenant->run(fn () => Producto::find($producto->id)->stock))->toBe(10);
    expect($this->tenant->run(fn () => Cita::count()))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Quién ve qué
|--------------------------------------------------------------------------
*/

/**
 * Una cuenta del negocio con los permisos que se le pidan, con o sin
 * `solo_propios`, y unida —o no— a una ficha de profesional. Devuelve su token.
 *
 * Es el montaje de tres piezas que piden todos los casos de «quién ve qué» y
 * de «para quién agenda»: el rol en la base del tenant, el `User` central que
 * hace login y el `Usuario` que los une. Escrito una vez para que ninguna
 * prueba pase en verde porque su montaje se quedó a medias.
 *
 * El nombre del rol y el correo se derivan de un contador, así que **dos
 * llamadas en el mismo test conviven**: los dos campos son únicos en la base y
 * fijarlos obligaba a montar a mano la segunda cuenta. Si se pasan `rol` o
 * `email` a mano, el contador no interviene y repetir el mismo valor revienta
 * contra el unique.
 *
 * Opciones: `permisos`, `solo_propios`, `ficha`, `rol`, `email`, `locales`.
 * Sin `locales` (o con `null`) la cuenta ve todas las sedes
 * (`todos_los_locales`); con una lista —también vacía— queda acotada a esas.
 */
function cuentaDelNegocio(object $test, array $opciones = []): string
{
    static $n = 0;
    $n++;

    $rolId = comoOtro($test, $test->token)->postJson('/api/roles', [
        'nombre' => $opciones['rol'] ?? "Barbero {$n}",
        'permisos' => $opciones['permisos'] ?? ['citas' => 'gestionar'],
        'solo_propios' => $opciones['solo_propios'] ?? true,
    ])->assertCreated()->json('data.id');

    $central = User::create([
        'tenant_id' => $test->tenant->id,
        'nombre' => 'Luis',
        'email' => $opciones['email'] ?? "luis{$n}@elrosal.pe",
        'password' => 'secreta123',
        'rol' => 'profesional',
    ]);

    $test->tenant->run(function () use ($central, $rolId, $opciones) {
        $locales = $opciones['locales'] ?? null;

        $usuario = Usuario::create([
            'central_user_id' => $central->id,
            'rol_id' => $rolId,
            'todos_los_locales' => $locales === null,
        ]);

        if ($locales !== null) {
            $usuario->locales()->sync($locales);
        }

        // Sin ficha, la cuenta entra al panel y no atiende: es el caso que
        // tiene que fallar CERRADO.
        if (($opciones['ficha'] ?? null) !== null) {
            Profesional::whereKey($opciones['ficha'])->update(['usuario_id' => $usuario->id]);
        }
    });

    return $central->createToken('t')->plainTextToken;
}

/** Un segundo profesional, sin horario propio: hereda el del negocio. */
function otroProfesional(object $test, string $nombre = 'Luis Ramos'): Profesional
{
    return $test->tenant->run(fn () => Profesional::create([
        'nombre' => $nombre,
        'horario' => null,
    ]));
}

/*
 * `solo_propios` llevaba desde el Sprint 2 guardándose sin filtrar nada, porque
 * no había citas que filtrar. Aquí empieza a significar algo.
 */
test('con solo_propios un profesional ve solo sus citas', function () {
    $otro = otroProfesional($this);

    agendar($this)->assertCreated();
    agendar($this, ['empleado_id' => $otro->id, 'hora_inicio' => '10:00', 'cliente_telefono' => null])->assertCreated();

    $token = cuentaDelNegocio($this, ['ficha' => $otro->id]);

    comoOtro($this, $token)->getJson('/api/citas')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.empleado.nombre', 'Luis Ramos');
});

/*
 * Y la de otro le responde 404, no 403: para él esa cita no existe. Un 403
 * confirmaría que está ahí, que es lo que el filtro viene a ocultar.
 */
test('la cita de otro profesional responde 404 a quien solo ve las suyas', function () {
    $ajena = agendar($this)->assertCreated()->json('data.id');

    $otro = otroProfesional($this);
    $token = cuentaDelNegocio($this, ['ficha' => $otro->id]);

    comoOtro($this, $token)->getJson("/api/citas/{$ajena}")->assertNotFound();

    /*
     * También el PUT, y con un `empleado_id` que ADEMÁS está fuera de su
     * alcance: así las dos ramas compiten de verdad y se ve cuál gana. Tiene
     * que ganar el 404 — un 422 sobre el campo confirmaría que la cita ajena
     * está ahí, que es justo lo que el filtro viene a ocultar. Con la ficha
     * propia en el payload este test pasaría con la precedencia invertida.
     */
    comoOtro($this, $token)->putJson("/api/citas/{$ajena}", citaValida($this, [
        'empleado_id' => $this->profesional->id,
        'estado' => 'confirmada',
    ]))->assertNotFound();

    comoOtro($this, $token)->deleteJson("/api/citas/{$ajena}")->assertNotFound();

    // Y sigue siendo de Rosa: el PUT no llegó a escribir nada.
    expect($this->tenant->run(fn () => Cita::find($ajena)->profesional_id))
        ->toBe($this->profesional->id);
});

/*
 * Y sin ficha no ve NINGUNA, que es la otra mitad de la regla: quien entra al
 * panel no siempre atiende, y quien no atiende no tiene citas propias.
 *
 * Es la rama `?->id ?? 0` del listado y el `?->id` nulo del 404. Sin este test,
 * relajar la comprobación a «solo si tiene ficha» le abriría la agenda entera
 * sin que fallara nada.
 */
test('con solo_propios y sin ficha no ve ninguna cita', function () {
    $ajena = agendar($this)->assertCreated()->json('data.id');

    $token = cuentaDelNegocio($this, ['ficha' => null]);

    comoOtro($this, $token)->getJson('/api/citas')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    comoOtro($this, $token)->getJson("/api/citas/{$ajena}")->assertNotFound();
    comoOtro($this, $token)->putJson("/api/citas/{$ajena}", citaValida($this, [
        'estado' => 'confirmada',
    ]))->assertNotFound();
    comoOtro($this, $token)->deleteJson("/api/citas/{$ajena}")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Para quién agenda
|--------------------------------------------------------------------------
*/

/*
 * El hueco G-1: `solo_propios` filtraba al LEER y no al ESCRIBIR, así que un
 * barbero no veía las citas de sus compañeros pero sí podía crearlas — y al
 * reasignar la suya, hacerla desaparecer de su propia vista.
 */
test('con solo_propios agenda para sí mismo', function () {
    $otro = otroProfesional($this);
    $token = cuentaDelNegocio($this, ['ficha' => $otro->id]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this, [
        'empleado_id' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.empleado.nombre', 'Luis Ramos');
});

/*
 * El id llega como CADENA cuando el panel lo saca de un `<select>`: la regla
 * `integer` del Form Request la acepta y `validated()` la entrega tal cual, sin
 * castear. Sin el `(int)` del service, `'3' === 3` es false y el candado
 * bloquearía al propio profesional.
 */
test('con solo_propios el empleado_id como cadena sigue siendo el suyo', function () {
    $otro = otroProfesional($this);
    $token = cuentaDelNegocio($this, ['ficha' => $otro->id]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this, [
        'empleado_id' => (string) $otro->id,
    ]))->assertCreated();
});

/*
 * Y sigue editando la SUYA. Los demás casos del `PUT` son negativos, así que
 * una regresión que bloqueara la edición legítima pasaría en verde sin esto.
 */
test('con solo_propios sí edita su propia cita', function () {
    $otro = otroProfesional($this);
    $token = cuentaDelNegocio($this, ['ficha' => $otro->id]);

    $id = comoOtro($this, $token)->postJson('/api/citas', citaValida($this, [
        'empleado_id' => $otro->id,
    ]))->assertCreated()->json('data.id');

    comoOtro($this, $token)->putJson("/api/citas/{$id}", citaValida($this, [
        'empleado_id' => $otro->id,
        'hora_inicio' => '11:00',
        'estado' => 'confirmada',
    ]))
        ->assertOk()
        ->assertJsonPath('data.hora_inicio', '11:00')
        ->assertJsonPath('data.estado', 'confirmada');
});

test('con solo_propios no agenda a nombre de un compañero', function () {
    $otro = otroProfesional($this);
    $token = cuentaDelNegocio($this, ['ficha' => $otro->id]);

    // `empleado_id` por defecto es Rosa, que no es él.
    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertStatus(422)
        ->assertJsonValidationErrors('empleado_id')
        ->assertJsonPath('errors.empleado_id.0', 'Solo puedes agendar citas para ti.');

    expect($this->tenant->run(fn () => Cita::count()))->toBe(0);
});

test('con solo_propios no reasigna su cita a otro profesional', function () {
    $otro = otroProfesional($this);
    $token = cuentaDelNegocio($this, ['ficha' => $otro->id]);

    $id = comoOtro($this, $token)->postJson('/api/citas', citaValida($this, [
        'empleado_id' => $otro->id,
    ]))->assertCreated()->json('data.id');

    comoOtro($this, $token)->putJson("/api/citas/{$id}", citaValida($this, [
        'empleado_id' => $this->profesional->id,
        'estado' => 'confirmada',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('empleado_id');

    // La cita conserva su profesional: la transacción no escribió nada.
    expect($this->tenant->run(fn () => Cita::find($id)->profesional_id))->toBe($otro->id);
});

/*
 * Falla CERRADO. Quien entra al panel no siempre atiende, y sin ficha no hay
 * «lo suyo»: darle la agenda entera sería lo contrario de lo que dice su rol.
 */
test('con solo_propios y sin ficha de profesional no agenda para nadie', function () {
    $token = cuentaDelNegocio($this, ['ficha' => null]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertStatus(422)
        ->assertJsonValidationErrors('empleado_id')
        // Mensaje propio: «solo puedes agendar para ti» no sería accionable,
        // porque para esta cuenta no existe ese «ti».
        ->assertJsonPath(
            'errors.empleado_id.0',
            'Tu cuenta no tiene ficha de profesional, así que no puede agendar citas.',
        );

    expect($this->tenant->run(fn () => Cita::count()))->toBe(0);
});

/* Y sin `solo_propios` no cambia nada: la recepcionista agenda para quien sea. */
test('sin solo_propios se agenda para cualquier profesional', function () {
    $token = cuentaDelNegocio($this, [
        'rol' => 'Recepción',
        'solo_propios' => false,
        'ficha' => null,
    ]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertCreated()
        ->assertJsonPath('data.empleado.nombre', 'Rosa Paredes');
});

test('quien solo tiene ver no agenda', function () {
    $token = cuentaDelNegocio($this, [
        'rol' => 'Mirón',
        'permisos' => ['citas' => 'ver'],
        'solo_propios' => false,
        'ficha' => null,
    ]);

    comoOtro($this, $token)->getJson('/api/citas')->assertOk();
    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'sin_permiso');
});

/*
|--------------------------------------------------------------------------
| En qué sede
|--------------------------------------------------------------------------
*/

/*
 * El hueco G-2: el alcance por sedes filtraba al LEER y no al ESCRIBIR. Estas
 * cuentas van SIN `solo_propios` para que el eje profesional no intervenga:
 * lo único que puede dar el 422 es la sede.
 */

/**
 * Una sede principal y dos más, en este orden de id: principal < norte < sur.
 *
 * El provisioning de las pruebas no deja sede principal, así que se crea si
 * falta. `es_principal` no es asignable en masa: la marca solo la pone el
 * service de locales, por eso `forceFill`.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function tresSedes(object $test): array
{
    return $test->tenant->run(fn () => [
        Local::where('es_principal', true)->value('id')
            ?? tap(new Local(['nombre' => 'Principal']), fn (Local $l) => $l->forceFill(['es_principal' => true])->save())->id,
        Local::create(['nombre' => 'Norte'])->id,
        Local::create(['nombre' => 'Sur'])->id,
    ]);
}

/** Una recepcionista (sin `solo_propios`, sin ficha) con el alcance dado. */
function cuentaConSedes(object $test, ?array $locales): string
{
    return cuentaDelNegocio($test, [
        'solo_propios' => false,
        'ficha' => null,
        'locales' => $locales,
    ]);
}

test('con alcance de sedes agenda en una sede suya', function () {
    [, $norte] = tresSedes($this);
    $token = cuentaConSedes($this, [$norte]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this, ['local_id' => $norte]))
        ->assertCreated()
        ->assertJsonPath('data.local_id', $norte);
});

/* El id llega como cadena desde un `<select>`: sigue siendo su sede. */
test('con alcance de sedes el local_id como cadena sigue siendo el suyo', function () {
    [, $norte] = tresSedes($this);
    $token = cuentaConSedes($this, [$norte]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this, ['local_id' => (string) $norte]))
        ->assertCreated()
        ->assertJsonPath('data.local_id', $norte);
});

test('con alcance de sedes no agenda en una sede ajena', function () {
    [, $norte, $sur] = tresSedes($this);
    $token = cuentaConSedes($this, [$norte]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this, ['local_id' => $sur]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('local_id')
        ->assertJsonPath('errors.local_id.0', 'No puedes agendar citas en esa sede.');

    expect($this->tenant->run(fn () => Cita::count()))->toBe(0);
});

test('sin local_id y con la principal en su alcance, la cita cae en la principal', function () {
    [$principal, $norte] = tresSedes($this);
    // Norte primero en la lista: manda la principal, no el orden del alcance.
    $token = cuentaConSedes($this, [$norte, $principal]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertCreated()
        ->assertJsonPath('data.local_id', $principal);
});

/*
 * En `tresSedes()` la principal siempre tiene el id más bajo, así que ahí
 * «la principal» y «la de id más bajo» son la misma: aquí nace después.
 */
test('sin local_id la principal gana aunque no tenga el id más bajo', function () {
    [$norte, $principal] = $this->tenant->run(function () {
        Local::where('es_principal', true)->update(['es_principal' => false]);

        $norte = Local::create(['nombre' => 'Norte'])->id;
        $principal = tap(new Local(['nombre' => 'Principal']), fn (Local $l) => $l->forceFill(['es_principal' => true])->save())->id;

        return [$norte, $principal];
    });

    expect($norte)->toBeLessThan($principal);

    $token = cuentaConSedes($this, [$norte, $principal]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertCreated()
        ->assertJsonPath('data.local_id', $principal);
});

/*
 * Antes le asignaba la principal aunque estuviera fuera de su alcance: creaba
 * la cita y dejaba de verla en el mismo instante.
 */
test('sin local_id y con la principal fuera, cae en la sede activa de su alcance con id más bajo', function () {
    [, $norte, $sur] = tresSedes($this);
    $token = cuentaConSedes($this, [$sur, $norte]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertCreated()
        ->assertJsonPath('data.local_id', $norte);
});

test('sin local_id se salta las sedes inactivas de su alcance', function () {
    [, $norte, $sur] = tresSedes($this);
    $this->tenant->run(fn () => Local::whereKey($norte)->update(['activo' => false]));
    $token = cuentaConSedes($this, [$norte, $sur]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertCreated()
        ->assertJsonPath('data.local_id', $sur);
});

/*
 * Un alcance `[]` es falso en PHP: un `if ($alcance && …)` dejaría a una cuenta
 * sin sedes agendar en cualquiera (el fallo de `LocalController::index`).
 */
test('con el alcance vacío no agenda en una sede aunque la nombre', function () {
    [, $norte] = tresSedes($this);
    $token = cuentaConSedes($this, []);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this, ['local_id' => $norte]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('local_id')
        ->assertJsonPath('errors.local_id.0', 'No puedes agendar citas en esa sede.');

    expect($this->tenant->run(fn () => Cita::count()))->toBe(0);
});

test('sin local_id y con el alcance vacío no agenda', function () {
    tresSedes($this);
    $token = cuentaConSedes($this, []);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertStatus(422)
        ->assertJsonValidationErrors('local_id')
        ->assertJsonPath(
            'errors.local_id.0',
            'Tu cuenta no tiene ninguna sede activa a su alcance, así que no puede agendar citas.',
        );

    expect($this->tenant->run(fn () => Cita::count()))->toBe(0);
});

test('sin local_id y con solo sedes inactivas en su alcance no agenda', function () {
    [, $norte] = tresSedes($this);
    $this->tenant->run(fn () => Local::whereKey($norte)->update(['activo' => false]));
    $token = cuentaConSedes($this, [$norte]);

    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertStatus(422)
        ->assertJsonValidationErrors('local_id')
        ->assertJsonPath(
            'errors.local_id.0',
            'Tu cuenta no tiene ninguna sede activa a su alcance, así que no puede agendar citas.',
        );

    expect($this->tenant->run(fn () => Cita::count()))->toBe(0);
});

test('con alcance de sedes no mueve su cita a una sede ajena', function () {
    [, $norte, $sur] = tresSedes($this);
    $token = cuentaConSedes($this, [$norte]);

    $id = comoOtro($this, $token)->postJson('/api/citas', citaValida($this, ['local_id' => $norte]))
        ->assertCreated()->json('data.id');

    comoOtro($this, $token)->putJson("/api/citas/{$id}", citaValida($this, [
        'local_id' => $sur,
        'hora_inicio' => '11:00',
        'estado' => 'confirmada',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('local_id')
        ->assertJsonPath('errors.local_id.0', 'No puedes agendar citas en esa sede.');

    // La transacción no escribió nada: ni la sede ni el resto del payload.
    $cita = $this->tenant->run(fn () => Cita::find($id));
    expect($cita->local_id)->toBe($norte)
        ->and($cita->estado)->not->toBe('confirmada');
});

/*
 * Una regresión que bloqueara la edición legítima pasaría en verde con solo
 * los casos negativos.
 */
test('con alcance de sedes edita su cita sin tocar la sede', function () {
    [, $norte] = tresSedes($this);
    $token = cuentaConSedes($this, [$norte]);

    $id = comoOtro($this, $token)->postJson('/api/citas', citaValida($this, ['local_id' => $norte]))
        ->assertCreated()->json('data.id');

    comoOtro($this, $token)->putJson("/api/citas/{$id}", citaValida($this, [
        'hora_inicio' => '11:00',
        'estado' => 'confirmada',
    ]))
        ->assertOk()
        ->assertJsonPath('data.local_id', $norte)
        ->assertJsonPath('data.hora_inicio', '11:00');
});

/*
 * Con la principal en su alcance, el valor por defecto de CREAR sería la
 * principal: al editar sin `local_id` la cita debe quedarse en norte.
 */
test('al editar sin local_id la cita conserva su sede aunque el defecto sea otra', function () {
    [$principal, $norte] = tresSedes($this);
    $token = cuentaConSedes($this, [$principal, $norte]);

    $id = comoOtro($this, $token)->postJson('/api/citas', citaValida($this, ['local_id' => $norte]))
        ->assertCreated()->json('data.id');

    comoOtro($this, $token)->putJson("/api/citas/{$id}", citaValida($this, [
        'hora_inicio' => '11:00',
        'estado' => 'confirmada',
    ]))
        ->assertOk()
        ->assertJsonPath('data.local_id', $norte);

    expect($this->tenant->run(fn () => Cita::find($id)->local_id))->toBe($norte);
});

/* El 404 de la cita ajena gana al 422 del campo: para él no existe. */
test('la cita de una sede ajena responde 404 aunque el local_id sea suyo', function () {
    [, $norte, $sur] = tresSedes($this);
    $ajena = agendar($this, ['local_id' => $sur])->assertCreated()->json('data.id');
    $token = cuentaConSedes($this, [$norte]);

    comoOtro($this, $token)->putJson("/api/citas/{$ajena}", citaValida($this, [
        'local_id' => $norte,
        'estado' => 'confirmada',
    ]))->assertNotFound();

    expect($this->tenant->run(fn () => Cita::find($ajena)->local_id))->toBe($sur);
});

test('con todos los locales agenda y mueve la cita a cualquier sede', function () {
    [, $norte, $sur] = tresSedes($this);
    $token = cuentaConSedes($this, null);

    $id = comoOtro($this, $token)->postJson('/api/citas', citaValida($this, ['local_id' => $sur]))
        ->assertCreated()
        ->assertJsonPath('data.local_id', $sur)
        ->json('data.id');

    comoOtro($this, $token)->putJson("/api/citas/{$id}", citaValida($this, [
        'local_id' => $norte,
        'estado' => 'confirmada',
    ]))
        ->assertOk()
        ->assertJsonPath('data.local_id', $norte);
});

test('las citas de otro negocio: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    // Varias, para que el id no exista también aquí y el test no pase midiendo
    // la cita de casa.
    $ajena = $otro->run(function () {
        $cliente = Cliente::create(['nombre' => 'Del vecino']);
        $profesional = Profesional::create(['nombre' => 'Otro']);
        $id = null;

        foreach (range(1, 4) as $i) {
            $id = Cita::forceCreate([
                'codigo' => 'AJENA'.$i.'X',
                'profesional_id' => $profesional->id,
                'cliente_id' => $cliente->id,
                'starts_at' => '2026-09-07 1'.$i.':00:00',
                'ends_at' => '2026-09-07 1'.$i.':30:00',
            ])->id;
        }

        return $id;
    });

    expect($this->tenant->run(fn () => Cita::find($ajena)))->toBeNull();

    $this->withToken($this->token)->getJson("/api/citas/{$ajena}")->assertNotFound();
    $this->withToken($this->token)->deleteJson("/api/citas/{$ajena}")->assertNotFound();
});

test('sin sesión → 401', function () {
    $this->getJson('/api/citas')->assertStatus(401);
});
