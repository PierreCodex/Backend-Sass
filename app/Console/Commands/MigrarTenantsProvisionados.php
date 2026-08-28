<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Migra SOLO los tenants que ya tienen base de datos.
 *
 * `tenants:migrate` de stancl recorre la tabla `tenants` entera y aborta con
 * TenantDatabaseDoesNotExistException en el primer negocio que se registro y
 * nunca verifico su correo: existe en la central, pero su base no, porque el
 * provisioning es perezoso (regla 2 del CLAUDE.md).
 *
 * Eso no es un caso raro — es lo normal: siempre habra gente a medio
 * registrar. Y el comando aborta, asi que los tenants que venian DESPUES del
 * primer fallo se quedan sin migrar en silencio. Este es el que hay que
 * llamar al desplegar.
 */
class MigrarTenantsProvisionados extends Command
{
    protected $signature = 'tenants:migrar-provisionados';

    protected $description = 'Migra los tenants con BD creada (los registrados sin verificar no tienen base todavia)';

    public function handle(): int
    {
        $tenants = Tenant::where('db_provisionada', true)->pluck('id');

        if ($tenants->isEmpty()) {
            $this->info('No hay tenants provisionados.');

            return self::SUCCESS;
        }

        $saltados = Tenant::where('db_provisionada', false)->count();

        if ($saltados > 0) {
            $this->line("Saltando {$saltados} negocio(s) sin BD: se registraron y aun no verifican el correo.");
        }

        /*
         * De uno en uno: si un tenant falla —una migracion que choca con
         * datos suyos, por ejemplo— los demas tienen que migrarse igual. El
         * comando de stancl aborta la tanda entera.
         */
        $fallos = [];

        foreach ($tenants as $id) {
            $codigo = Artisan::call('tenants:migrate', ['--tenants' => [$id]], $this->output);

            if ($codigo !== self::SUCCESS) {
                $fallos[] = $id;
            }
        }

        if ($fallos !== []) {
            $this->error('Fallaron: '.implode(', ', $fallos));

            return self::FAILURE;
        }

        $this->info("Migrados {$tenants->count()} negocio(s).");

        return self::SUCCESS;
    }
}
