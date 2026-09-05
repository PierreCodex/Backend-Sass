<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Rol;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Una cuenta con contraseña conocida para probar el panel de punta a punta.
 *
 * Existe porque el alta normal manda una invitación y la contraseña la elige el
 * empleado — que es lo correcto, y que hace imposible probar a mano lo que ve
 * una recepcionista sin pasar por el correo.
 *
 * **Solo en local.** Un comando que crea cuentas con contraseña conocida es un
 * regalo para cualquiera que consiga ejecutar artisan en el servidor, así que
 * se niega fuera de ese entorno en vez de confiar en que nadie lo llame.
 */
class CuentaDePrueba extends Command
{
    protected $signature = 'tenant:cuenta-de-prueba
        {tenant : El id del negocio (p. ej. 3brlcaps)}
        {--rol=profesional : La clave del rol: admin_local, profesional, o la de uno propio}
        {--email= : Por defecto, {rol}@prueba.local}
        {--password=secreta123}';

    protected $description = 'Crea una cuenta con contraseña conocida en un negocio, para probar el panel. Solo en local.';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Este comando solo corre en local.');

            return self::FAILURE;
        }

        $tenant = Tenant::find($this->argument('tenant'));

        if ($tenant === null || ! $tenant->db_provisionada) {
            $this->error('Ese negocio no existe o todavía no tiene base de datos.');

            return self::FAILURE;
        }

        $clave = (string) $this->option('rol');
        $email = (string) ($this->option('email') ?: $clave.'@prueba.local');
        $password = (string) $this->option('password');

        $rolId = $tenant->run(fn () => Rol::where('clave', $clave)->orWhere('nombre', $clave)->value('id'));

        if ($rolId === null) {
            $this->error("No hay ningún rol con clave o nombre «{$clave}» en ese negocio.");

            return self::FAILURE;
        }

        /*
         * El mismo invariante que en el alta normal: de aquí no sale un segundo
         * administrador general. Es un comando de pruebas, pero escribe en la
         * misma base — y un atajo de desarrollo que se salta una regla de
         * seguridad es exactamente como se cuelan.
         */
        if ($tenant->run(fn () => Rol::find($rolId)->esAdminGeneral())) {
            $this->error('El administrador general lo crea el registro. No se fabrica desde aquí.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Ya existe una cuenta con {$email}. El correo es único en toda la plataforma.");

            return self::FAILURE;
        }

        $central = DB::transaction(fn () => User::create([
            'tenant_id' => $tenant->id,
            'nombre' => 'Prueba',
            'apellido' => ucfirst($clave),
            'email' => $email,
            'password' => $password,
            'rol' => $clave === 'admin_local' ? 'admin_local' : 'profesional',
            // Verificada de entrada: aquí no hay invitación que aceptar.
            'email_verified_at' => now(),
        ]));

        $tenant->run(fn () => Usuario::create([
            'central_user_id' => $central->id,
            'rol_id' => $rolId,
        ]));

        $this->info('Cuenta lista.');
        $this->line("  correo:     {$email}");
        $this->line("  contraseña: {$password}");
        $this->line("  rol:        {$clave}");
        $this->line("  negocio:    {$tenant->id}");

        return self::SUCCESS;
    }
}
