<?php

use App\Jobs\ProvisionTenantDatabase;
use App\Models\Plan;
use App\Models\Profesional;
use App\Models\Rol;
use App\Models\User;
use App\Models\Usuario;
use App\Notifications\InvitacionNotification;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    $this->tenant = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $this->user = crearDueno($this->tenant);
    (new ProvisionTenantDatabase($this->tenant))->handle();

    $this->token = $this->user->createToken('test')->plainTextToken;

    Notification::fake();
});

afterEach(fn () => limpiarBasesDeTenants());

function cuentaValida(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Lucía',
        'apellido' => 'Torres',
        'email' => 'recepcion@elrosal.pe',
        'telefono' => '+51987441220',
        'rol_id' => test()->tenant->run(fn () => Rol::where('clave', 'admin')->value('id')),
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| Alta e invitación
|--------------------------------------------------------------------------
*/

/*
 * Una recepcionista es una cuenta y NO es profesional: no presta servicios, no
 * cobra comisión y no debería tener que declarar cómo se le paga para existir.
 * Ese era el síntoma que hizo separar las dos cosas.
 */
test('crear una cuenta no crea ninguna ficha de profesional', function () {
    $data = $this->withToken($this->token)->postJson('/api/usuarios', cuentaValida())
        ->assertCreated()
        ->json('data');

    expect($data['email'])->toBe('recepcion@elrosal.pe')
        ->and($data['rol']['clave'])->toBe('admin')
        ->and($data['profesional'])->toBeNull();

    $this->tenant->run(function () {
        expect(Usuario::count())->toBe(2)        // el dueño y ella
            ->and(Profesional::count())->toBe(1); // solo el dueño
    });
});

/*
 * El jefe no debería conocer la contraseña de su empleado, y de paso es un
 * campo menos que inventar. Es como lo hace AgendaPro.
 */
test('el alta no pide contraseña: se manda una invitación', function () {
    $this->withToken($this->token)->postJson('/api/usuarios', cuentaValida())->assertCreated();

    $central = User::where('email', 'recepcion@elrosal.pe')->firstOrFail();

    Notification::assertSentTo($central, InvitacionNotification::class);

    // La cuenta existe pero todavía no se puede usar: sin contraseña conocida
    // y sin correo verificado.
    expect($central->email_verified_at)->toBeNull();
});

test('la invitación deja elegir contraseña, y con ella se entra', function () {
    $this->withToken($this->token)->postJson('/api/usuarios', cuentaValida())->assertCreated();

    $central = User::where('email', 'recepcion@elrosal.pe')->firstOrFail();
    $token = Password::broker('invitaciones')->createToken($central);

    $this->postJson('/api/invitacion/aceptar', [
        'token' => $token,
        'email' => 'recepcion@elrosal.pe',
        'password' => 'miclave123',
        'password_confirmation' => 'miclave123',
    ])->assertOk();

    /*
     * Y queda verificada de paso: llegar hasta aquí exige haber abierto un
     * enlace enviado a ese correo, que es exactamente lo que la verificación
     * demuestra. Pedirla después sería un segundo correo para probar lo mismo.
     */
    $this->postJson('/api/login', ['email' => 'recepcion@elrosal.pe', 'password' => 'miclave123'])
        ->assertOk()
        ->assertJsonPath('data.usuario.email', 'recepcion@elrosal.pe');
});

/*
 * El contenido del correo NO es decoración.
 *
 * Recibir sin haberlo pedido un enlace para «crear una contraseña» tiene
 * exactamente la forma de una estafa. Lo unico que lo desmiente es reconocer
 * quien te dio de alta y donde — y saber con que correo vas a entrar, que puede
 * no ser el que tu habrias elegido porque te lo puso otra persona.
 */
test('la invitación dice quién invita, desde qué negocio y con qué correo', function () {
    $this->tenant->update(['nombre' => 'Clínica El Rosal', 'color_primario' => '#0ea5e9']);

    $central = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Lucía',
        'email' => 'recepcion@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'admin',
    ]);

    $html = (new InvitacionNotification('token-de-prueba', $this->tenant, 'María Quispe'))
        ->toMail($central)
        ->render();

    expect($html)
        ->toContain('María Quispe')            // quién invita
        ->toContain('Clínica El Rosal')        // desde dónde
        ->toContain('recepcion@elrosal.pe')    // con qué usuario entra
        ->toContain('/invitacion?')            // y el enlace
        ->toContain('#0ea5e9')                 // con el color del negocio, no el nuestro
        // El enlace tambien en texto: hay clientes que no pintan el boton.
        ->toContain('Copia esta dirección');
});

/*
 * El nombre del negocio es NULL hasta el paso 1 del onboarding. Es raro pero
 * posible —un dueno que da de alta a alguien antes de ponerle nombre al local—
 * y el correo no puede quedarse con un hueco.
 */
test('la invitación se sostiene aunque el negocio aún no tenga nombre', function () {
    $central = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Lucía',
        'email' => 'recepcion@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'admin',
    ]);

    $html = (new InvitacionNotification('token', $this->tenant, null))->toMail($central)->render();

    expect($html)->toContain('tu negocio')
        ->and($html)->toContain('quien te dio de alta');
});

test('una invitación inventada da 422, no un 500', function () {
    $this->withToken($this->token)->postJson('/api/usuarios', cuentaValida())->assertCreated();

    $this->postJson('/api/invitacion/aceptar', [
        'token' => 'esto-no-es-un-token',
        'email' => 'recepcion@elrosal.pe',
        'password' => 'miclave123',
        'password_confirmation' => 'miclave123',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

/*
 * Existe desde el primer día porque el alta depende de que un correo llegue, y
 * los correos se pierden: caducan, caen en spam, el empleado los borra. Sin
 * esto la única salida sería borrar la cuenta y volverla a crear.
 */
test('la invitación se puede reenviar', function () {
    $id = $this->withToken($this->token)->postJson('/api/usuarios', cuentaValida())
        ->assertCreated()->json('data.id');

    $this->withToken($this->token)->postJson("/api/usuarios/{$id}/invitacion")->assertOk();

    $central = User::where('email', 'recepcion@elrosal.pe')->firstOrFail();

    Notification::assertSentToTimes($central, InvitacionNotification::class, 2);
});

test('el email ya usado en OTRO negocio da 422 y no deja fila huérfana', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());

    User::create([
        'tenant_id' => $otro->id,
        'nombre' => 'Ya', 'apellido' => 'Existe',
        'email' => 'recepcion@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'profesional',
    ]);

    $this->withToken($this->token)->postJson('/api/usuarios', cuentaValida())
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect(User::where('email', 'recepcion@elrosal.pe')->count())->toBe(1);
    $this->tenant->run(fn () => expect(Usuario::count())->toBe(1));
});

/*
|--------------------------------------------------------------------------
| Edición y baja
|--------------------------------------------------------------------------
*/

test('desactivar una cuenta le revoca los tokens y le cierra el login', function () {
    $id = $this->withToken($this->token)->postJson('/api/usuarios', cuentaValida())
        ->assertCreated()->json('data.id');

    $central = User::where('email', 'recepcion@elrosal.pe')->firstOrFail();
    $central->forceFill(['password' => 'miclave123', 'email_verified_at' => now()])->save();
    $central->createToken('suyo');

    $this->withToken($this->token)->putJson("/api/usuarios/{$id}", cuentaValida(['activo' => 0]))
        ->assertOk()
        ->assertJsonPath('data.activo', false);

    expect($central->fresh()->tokens()->count())->toBe(0);

    $this->postJson('/api/login', ['email' => 'recepcion@elrosal.pe', 'password' => 'miclave123'])
        ->assertStatus(422);
});

/*
 * La otra cara de la separación: quitarle el acceso a un barbero no puede
 * borrarle la ficha, ni sus citas, ni sus comisiones.
 */
test('quitar el acceso NO borra a su profesional', function () {
    $profesionalId = $this->withToken($this->token)->post('/api/profesionales', [
        'nombre' => 'Carmen',
        'tipo_pago' => 'comision',
        'comision_porcentaje' => 30,
        'usuario' => [
            'email' => 'carmen@elrosal.pe',
            'rol_id' => $this->tenant->run(fn () => Rol::where('clave', 'profesional')->value('id')),
        ],
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $cuentaId = $this->tenant->run(fn () => Profesional::find($profesionalId)->usuario_id);

    $this->withToken($this->token)->deleteJson("/api/usuarios/{$cuentaId}")->assertStatus(204);

    $this->tenant->run(function () use ($profesionalId) {
        $profesional = Profesional::find($profesionalId);

        expect($profesional)->not->toBeNull()
            ->and($profesional->nombre)->toBe('Carmen')
            // Se queda sin cuenta, no sin ficha.
            ->and($profesional->usuario_id)->toBeNull();
    });
});

test('al dueño no se le quita el acceso ni se le cambia el rol', function () {
    $id = $this->tenant->run(fn () => Usuario::first()->id);

    $this->withToken($this->token)->putJson("/api/usuarios/{$id}", cuentaValida([
        'email' => $this->user->email,
    ]))->assertStatus(422)->assertJsonValidationErrors('rol_id');

    $this->withToken($this->token)->deleteJson("/api/usuarios/{$id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('usuario');
});

test('nadie se asciende a dueño: ya hay uno', function () {
    $this->withToken($this->token)->postJson('/api/usuarios', cuentaValida([
        'rol_id' => $this->tenant->run(fn () => Rol::where('clave', 'dueno')->value('id')),
    ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.rol_id.0', 'Ya hay un dueño en este negocio.');
});

/*
|--------------------------------------------------------------------------
| Quién puede, y aislación
|--------------------------------------------------------------------------
*/

/*
 * Gestionar cuentas es solo del dueño, igual que los roles y por el mismo
 * motivo: quien puede crear cuentas y repartir roles puede fabricarse un
 * segundo dueño.
 */
test('quien no es dueño no gestiona cuentas', function () {
    $admin = User::create([
        'tenant_id' => $this->tenant->id,
        'nombre' => 'Lucía',
        'email' => 'lucia@elrosal.pe',
        'password' => 'secreta123',
        'rol' => 'admin',
    ]);
    $token = $admin->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/usuarios')->assertForbidden();
    $this->withToken($token)->postJson('/api/usuarios', cuentaValida())->assertForbidden();
});

test('las cuentas de otro negocio: 404, nunca 403', function () {
    $otro = crearTenantRegistrado(Plan::where('slug', 'prueba')->firstOrFail());
    $duenoAjeno = crearDueno($otro);
    (new ProvisionTenantDatabase($otro))->handle();

    // Una NUEVA en el otro negocio: el id 1 existe en las dos bases.
    $ajena = $otro->run(fn () => Usuario::create([
        'central_user_id' => $duenoAjeno->id + 1000,
        'rol_id' => Rol::where('clave', 'admin')->value('id'),
    ])->id);

    expect($this->tenant->run(fn () => Usuario::find($ajena)))->toBeNull();

    $this->withToken($this->token)->getJson("/api/usuarios/{$ajena}")->assertNotFound();
    $this->withToken($this->token)->deleteJson("/api/usuarios/{$ajena}")->assertNotFound();
});

test('search encuentra por nombre y por correo', function () {
    $this->withToken($this->token)->postJson('/api/usuarios', cuentaValida())->assertCreated();

    foreach (['Lucía', 'recepcion@'] as $busqueda) {
        $this->withToken($this->token)->getJson('/api/usuarios?search='.$busqueda)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
});

test('sin sesión → 401', function () {
    $this->getJson('/api/usuarios')->assertStatus(401);
});
