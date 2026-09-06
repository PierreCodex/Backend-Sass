<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\InventarioMovimiento;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\User;
use App\Models\Usuario;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;
});

afterEach(fn () => limpiarBasesDeTenants());

function productoValido(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Enjuague bucal 500ml',
        'descripcion' => 'Sin alcohol, menta',
        'stock' => 24,
        'stock_minimo' => 5,
        'precio_compra' => 11,
        'precio_venta' => 18,
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| Listado y forma
|--------------------------------------------------------------------------
*/

/*
 * La tabla dice `precio`/`costo` y el contrato `precio_venta`/`precio_compra`
 * (§1.10). Que salgan traducidos es media razón de ser del Resource.
 */
test('el producto viaja con los nombres del contrato, no con los de la tabla', function () {
    $this->withToken($this->token)->postJson('/api/inventario', productoValido())
        ->assertCreated()
        ->assertJsonPath('data.precio_venta', 18)
        ->assertJsonPath('data.precio_compra', 11)
        ->assertJsonPath('data.stock', 24)
        ->assertJsonPath('data.activo', true)
        ->assertJsonMissingPath('data.precio')
        ->assertJsonMissingPath('data.costo');
});

/*
 * Números y no cadenas: `decimal` de MySQL vuelve como "18.00", y el formateador
 * del panel pinta «S/ 18.00.00». Le pasó a servicios.
 *
 * Con decimales a propósito: 18 redondo viaja como `18` y vuelve del JSON como
 * entero, así que un precio entero no distingue un float de una cadena mal
 * casteada. 18.50 sí.
 */
test('los precios salen como números y no como cadenas', function () {
    $data = $this->withToken($this->token)->postJson('/api/inventario', productoValido([
        'precio_venta' => 18.5,
        'precio_compra' => 11.25,
    ]))->json('data');

    expect($data['precio_venta'])->toBe(18.5)
        ->and($data['precio_compra'])->toBe(11.25)
        ->and($data['stock'])->toBeInt();
});

test('el listado pagina y viene ordenado por nombre', function () {
    foreach (['Cera dental', 'Aceite', 'Protector bucal'] as $nombre) {
        $this->withToken($this->token)->postJson('/api/inventario', productoValido(['nombre' => $nombre]))
            ->assertCreated();
    }

    $this->withToken($this->token)->getJson('/api/inventario?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.nombre', 'Aceite')
        ->assertJsonPath('meta.total', 3);
});

// El contrato lista «nombre, descripción» para este módulo; la ficha decía solo
// nombre. Manda el contrato.
test('search busca en el nombre y en la descripción', function () {
    $this->withToken($this->token)->postJson('/api/inventario', productoValido());
    $this->withToken($this->token)->postJson('/api/inventario', productoValido([
        'nombre' => 'Cera dental',
        'descripcion' => 'Caja de 12 unidades',
    ]));

    $this->withToken($this->token)->getJson('/api/inventario?search=menta')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Enjuague bucal 500ml');

    $this->withToken($this->token)->getJson('/api/inventario?search=cera')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Cera dental');
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

/*
 * El contrato promete 5 y la migración lo pone; el formulario lo manda siempre,
 * pero un cliente que lo omita no debería quedarse con un umbral de 0, que
 * significa «no avisar nunca».
 */
test('sin stock_minimo se queda en el 5 que promete el contrato', function () {
    $datos = productoValido();
    unset($datos['stock_minimo']);

    $this->withToken($this->token)->postJson('/api/inventario', $datos)
        ->assertCreated()
        ->assertJsonPath('data.stock_minimo', 5);
});

/*
 * El stock inicial también es un movimiento: si no, un producto que nace con 24
 * unidades tiene un saldo que ninguna fila explica, y el historial dirá que
 * aparecieron solas.
 */
test('el stock inicial queda anotado en el libro, con su autor', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido())
        ->assertCreated()->json('data.id');

    $movimiento = $this->tenant->run(fn () => InventarioMovimiento::where('producto_id', $id)->sole());

    expect($movimiento->tipo)->toBe('entrada')
        ->and($movimiento->cantidad)->toBe(24)
        ->and($movimiento->motivo)->toBe('Stock inicial')
        ->and($movimiento->registrado_por_user_id)->toBe($this->user->id);
});

test('un producto que nace sin stock no anota nada', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido(['stock' => 0]))
        ->assertCreated()->json('data.id');

    expect($this->tenant->run(fn () => InventarioMovimiento::where('producto_id', $id)->count()))->toBe(0);
});

test('un nombre repetido da 422 en su campo', function () {
    $this->withToken($this->token)->postJson('/api/inventario', productoValido())->assertCreated();

    $this->withToken($this->token)->postJson('/api/inventario', productoValido())
        ->assertStatus(422)
        ->assertJsonValidationErrors('nombre');
});

/*
|--------------------------------------------------------------------------
| Edición: el stock no se toca por aquí
|--------------------------------------------------------------------------
*/

/*
 * La regla del módulo. El stock se mueve con movimientos, que dejan quién y por
 * qué; dejar que un PUT lo reescriba sería un cambio de inventario sin autor.
 * El formulario ya pinta el campo deshabilitado, pero eso es interfaz.
 */
test('el update cambia precios pero NO el stock, aunque venga en el payload', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido())
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->putJson("/api/inventario/{$id}", productoValido([
        'precio_venta' => 25,
        'stock' => 9999,
    ]))
        ->assertOk()
        ->assertJsonPath('data.precio_venta', 25)
        ->assertJsonPath('data.stock', 24);
});

test('renombrar un producto con el nombre de otro da 422', function () {
    $this->withToken($this->token)->postJson('/api/inventario', productoValido())->assertCreated();

    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido(['nombre' => 'Cera dental']))
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)
        ->putJson("/api/inventario/{$id}", productoValido(['nombre' => 'Enjuague bucal 500ml']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('nombre');
});

/*
|--------------------------------------------------------------------------
| Movimientos
|--------------------------------------------------------------------------
*/

test('una salida descuenta y devuelve el producto ya recalculado', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido(['stock' => 10]))
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->postJson("/api/inventario/{$id}/movimiento", [
        'tipo' => 'salida',
        'cantidad' => 4,
        'motivo' => 'Venta en mostrador',
    ])
        ->assertOk()
        ->assertJsonPath('data.stock', 6)
        ->assertJsonPath('data.nombre', 'Enjuague bucal 500ml');
});

test('una entrada suma', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido(['stock' => 10]))
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->postJson("/api/inventario/{$id}/movimiento", [
        'tipo' => 'entrada',
        'cantidad' => 5,
    ])->assertOk()->assertJsonPath('data.stock', 15);
});

/*
 * La cantidad se guarda SIEMPRE positiva y el signo lo lleva `tipo`. Con signo,
 * «cantidad» significaría dos cosas según la fila y sumar la columna daría el
 * saldo por accidente.
 */
test('la cantidad se guarda positiva; el signo es del tipo', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido(['stock' => 10]))
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->postJson("/api/inventario/{$id}/movimiento", [
        'tipo' => 'salida',
        'cantidad' => 4,
    ])->assertOk();

    $salida = $this->tenant->run(
        fn () => InventarioMovimiento::where('producto_id', $id)->where('tipo', 'salida')->sole()
    );

    expect($salida->cantidad)->toBe(4)
        ->and($salida->registrado_por_user_id)->toBe($this->user->id);
});

/*
 * La app vieja restaba sin tope y el stock se iba a negativo. Un stock negativo
 * no es un dato: es un error de captura contado como inventario, y de ahí sale
 * a la tienda pública y a los reportes.
 */
test('una salida mayor que el stock da 422 en cantidad y no escribe nada', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido(['stock' => 3]))
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->postJson("/api/inventario/{$id}/movimiento", [
        'tipo' => 'salida',
        'cantidad' => 4,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cantidad');

    // Ni el saldo ni el libro: la transacción entera se deshace.
    $this->withToken($this->token)->getJson("/api/inventario/{$id}")->assertJsonPath('data.stock', 3);

    expect($this->tenant->run(fn () => InventarioMovimiento::where('producto_id', $id)->count()))->toBe(1);
});

// Dejarlo en cero justo es legítimo: lo que no puede es pasarse.
test('una salida que deja el stock en cero sí pasa', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido(['stock' => 3]))
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->postJson("/api/inventario/{$id}/movimiento", [
        'tipo' => 'salida',
        'cantidad' => 3,
    ])->assertOk()->assertJsonPath('data.stock', 0);
});

/*
 * `venta` y `ajuste` existen en el ENUM y no se aceptan por aquí (§2.8): la
 * venta la generará la cita con productos, y admitirla a mano permitiría
 * descontar stock sin cita que lo respalde.
 */
test('los tipos reservados del ENUM no se aceptan a mano', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido())
        ->assertCreated()->json('data.id');

    foreach (['venta', 'ajuste'] as $tipo) {
        $this->withToken($this->token)->postJson("/api/inventario/{$id}/movimiento", [
            'tipo' => $tipo,
            'cantidad' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('tipo');
    }
});

test('una cantidad de cero o negativa da 422', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido())
        ->assertCreated()->json('data.id');

    foreach ([0, -3] as $cantidad) {
        $this->withToken($this->token)->postJson("/api/inventario/{$id}/movimiento", [
            'tipo' => 'entrada',
            'cantidad' => $cantidad,
        ])->assertStatus(422)->assertJsonValidationErrors('cantidad');
    }
});

/*
|--------------------------------------------------------------------------
| Borrado
|--------------------------------------------------------------------------
*/

test('borrar es soft delete: 204 y desaparece del listado', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido())
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->deleteJson("/api/inventario/{$id}")->assertStatus(204);

    $this->withToken($this->token)->getJson('/api/inventario')->assertJsonCount(0, 'data');

    expect($this->tenant->run(fn () => Producto::withTrashed()->find($id)->trashed()))->toBeTrue();
});

/*
 * Restaurar la fila es el truco para no chocar con el UNIQUE, pero para el
 * negocio esto es un ALTA: rellenó un formulario en blanco. Misma decisión que
 * en servicios.
 */
test('recrear un producto borrado lo restaura, pero nace limpio', function () {
    $id = $this->withToken($this->token)->postJson('/api/inventario', productoValido(['stock' => 24]))
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->deleteJson("/api/inventario/{$id}")->assertStatus(204);

    $this->withToken($this->token)->postJson('/api/inventario', productoValido([
        'stock' => 2,
        'precio_venta' => 30,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.stock', 2)
        ->assertJsonPath('data.precio_venta', 30)
        ->assertJsonPath('data.activo', true);
});

/*
|--------------------------------------------------------------------------
| Quién puede, y aislación
|--------------------------------------------------------------------------
*/

test('quien solo tiene ver lee el inventario pero no lo mueve', function () {
    $rolId = comoOtro($this, $this->token)->postJson('/api/roles', [
        'nombre' => 'Recepcionista',
        'permisos' => ['inventario' => 'ver'],
    ])->assertCreated()->json('data.id');

    $id = comoOtro($this, $this->token)->postJson('/api/inventario', productoValido())
        ->assertCreated()->json('data.id');

    $central = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Lucía',
        'email' => 'recepcion@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
    ]);

    $this->tenant->run(fn () => Usuario::create([
        'central_user_id' => $central->id,
        'rol_id' => $rolId,
    ]));

    $token = $central->createToken('t')->plainTextToken;

    comoOtro($this, $token)->getJson('/api/inventario')->assertOk();

    // Mover stock es gestionar: es la escritura de verdad del módulo.
    comoOtro($this, $token)->postJson("/api/inventario/{$id}/movimiento", ['tipo' => 'entrada', 'cantidad' => 1])
        ->assertStatus(403)
        ->assertJsonPath('codigo', 'sin_permiso');

    comoOtro($this, $token)->postJson('/api/inventario', productoValido(['nombre' => 'Otro']))
        ->assertStatus(403);
});

test('los productos de otro negocio: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    /*
     * Varios, y a propósito: con uno solo el ajeno sale con id 1, que aquí
     * también existe, y el test pasaría midiendo el producto de casa. Nos ha
     * pasado tres veces.
     */
    $ajeno = $otro->run(function () {
        $id = null;

        foreach (['Uno', 'Dos', 'Tres', 'Cuatro'] as $nombre) {
            $id = Producto::create(['nombre' => $nombre, 'precio' => 1])->id;
        }

        return $id;
    });

    expect($this->tenant->run(fn () => Producto::find($ajeno)))->toBeNull();

    $this->withToken($this->token)->getJson("/api/inventario/{$ajeno}")->assertNotFound();
    $this->withToken($this->token)->putJson("/api/inventario/{$ajeno}", productoValido())->assertNotFound();
    $this->withToken($this->token)->deleteJson("/api/inventario/{$ajeno}")->assertNotFound();
    $this->withToken($this->token)
        ->postJson("/api/inventario/{$ajeno}/movimiento", ['tipo' => 'entrada', 'cantidad' => 1])
        ->assertNotFound();
});

test('sin sesión → 401', function () {
    $this->getJson('/api/inventario')->assertStatus(401);
});
