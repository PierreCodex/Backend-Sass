<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Crea (o actualiza) un administrador de plataforma para probar el acceso con
 * código por correo.
 *
 * **Solo en local**, por lo mismo que `tenant:cuenta-de-prueba`: un comando que
 * fabrica superadmins es un regalo para quien consiga ejecutar artisan en el
 * servidor. Allí se darán de alta por el camino que defina la Story 8.4.
 */
class CrearAdminPlataforma extends Command
{
    protected $signature = 'plataforma:crear-admin
        {email : El correo donde llegarán los códigos}
        {--nombre=Admin Plataforma}
        {--rol=superadmin : superadmin o soporte}
        {--password= : Si no se da, se genera una y se muestra una vez}';

    protected $description = 'Crea un administrador de plataforma para probar el acceso. Solo en local.';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Este comando solo corre en local.');

            return self::FAILURE;
        }

        $rol = (string) $this->option('rol');

        if (! in_array($rol, ['superadmin', 'soporte'], true)) {
            $this->error('El rol tiene que ser superadmin o soporte.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(14, symbols: false));

        $admin = PlatformAdmin::withTrashed()->updateOrCreate(
            ['email' => strtolower((string) $this->argument('email'))],
            [
                'nombre' => (string) $this->option('nombre'),
                'password' => $password,
                'rol' => $rol,
                'activo' => true,
            ],
        );
        $admin->restore();

        $this->info('Administrador de plataforma listo.');
        $this->line("  correo:     {$admin->email}");
        $this->line("  contraseña: {$password}");
        $this->line("  rol:        {$admin->rol}");

        return self::SUCCESS;
    }
}
