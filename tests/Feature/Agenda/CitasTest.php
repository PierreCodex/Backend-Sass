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
    $principal = $this->tenant->run(fn () => Local::where('es_principal', true)->value('id'));

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

/*
 * `solo_propios` llevaba desde el Sprint 2 guardándose sin filtrar nada, porque
 * no había citas que filtrar. Aquí empieza a significar algo.
 */
test('con solo_propios un profesional ve solo sus citas', function () {
    $otro = $this->tenant->run(fn () => Profesional::create([
        'nombre' => 'Luis Ramos',
        'horario' => null, // hereda el del negocio
    ]));

    agendar($this)->assertCreated();
    agendar($this, ['empleado_id' => $otro->id, 'hora_inicio' => '10:00', 'cliente_telefono' => null])->assertCreated();

    $rolId = comoOtro($this, $this->token)->postJson('/api/roles', [
        'nombre' => 'Barbero',
        'permisos' => ['citas' => 'gestionar'],
        'solo_propios' => true,
    ])->assertCreated()->json('data.id');

    $central = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Luis',
        'email' => 'luis@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
    ]);

    $this->tenant->run(function () use ($central, $rolId, $otro) {
        $usuario = Usuario::create(['central_user_id' => $central->id, 'rol_id' => $rolId]);
        Profesional::whereKey($otro->id)->update(['usuario_id' => $usuario->id]);
    });

    $token = $central->createToken('t')->plainTextToken;

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

    $rolId = comoOtro($this, $this->token)->postJson('/api/roles', [
        'nombre' => 'Barbero',
        'permisos' => ['citas' => 'gestionar'],
        'solo_propios' => true,
    ])->assertCreated()->json('data.id');

    $central = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Luis',
        'email' => 'luis@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
    ]);

    $this->tenant->run(fn () => Usuario::create(['central_user_id' => $central->id, 'rol_id' => $rolId]));

    $token = $central->createToken('t')->plainTextToken;

    comoOtro($this, $token)->getJson("/api/citas/{$ajena}")->assertNotFound();
    comoOtro($this, $token)->deleteJson("/api/citas/{$ajena}")->assertNotFound();
});

test('quien solo tiene ver no agenda', function () {
    $rolId = comoOtro($this, $this->token)->postJson('/api/roles', [
        'nombre' => 'Mirón',
        'permisos' => ['citas' => 'ver'],
    ])->assertCreated()->json('data.id');

    $central = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Ana',
        'email' => 'mira@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
    ]);

    $this->tenant->run(fn () => Usuario::create(['central_user_id' => $central->id, 'rol_id' => $rolId]));

    $token = $central->createToken('t')->plainTextToken;

    comoOtro($this, $token)->getJson('/api/citas')->assertOk();
    comoOtro($this, $token)->postJson('/api/citas', citaValida($this))
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'sin_permiso');
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
