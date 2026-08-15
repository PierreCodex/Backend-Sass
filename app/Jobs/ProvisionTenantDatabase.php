<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Provisioning perezoso de la BD del tenant (regla 2 del CLAUDE.md).
 *
 * Se encola al VERIFICAR EL CORREO del dueño — nunca en el registro, sin
 * esperar al onboarding. Idempotente: re-lanzarlo no duplica nada.
 *
 * Pasos: crear la BD (tenant_{id}) → migrar → fila del dueño en
 * `profesionales` (atiende=1) → db_provisionada=1, estado='prueba',
 * prueba de 7 días.
 */
class ProvisionTenantDatabase implements ShouldQueue, ShouldBeUnique
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Tenant $tenant)
    {
    }

    public function uniqueId(): string
    {
        return $this->tenant->id;
    }

    public function handle(): void
    {
        $tenant = $this->tenant->refresh();

        if ($tenant->db_provisionada) {
            return;
        }

        $manager = $tenant->database()->manager();

        if (! $manager->databaseExists($tenant->database()->getName())) {
            $manager->createDatabase($tenant);
        }

        Artisan::call('tenants:migrate', ['--tenants' => [$tenant->id]]);

        $dueno = $tenant->users()->where('rol', 'dueno')->orderBy('id')->first();

        if ($dueno !== null) {
            $tenant->run(function () use ($dueno) {
                $yaExiste = DB::table('profesionales')
                    ->where('central_user_id', $dueno->id)
                    ->exists();

                if (! $yaExiste) {
                    DB::table('profesionales')->insert([
                        'central_user_id' => $dueno->id,
                        'nombre' => trim($dueno->nombre.' '.$dueno->apellido),
                        'telefono' => $dueno->telefono,
                        'atiende' => true,
                        'activo' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        }

        $tenant->update([
            'db_provisionada' => true,
            'estado' => 'prueba',
            'suscripcion_vence_el' => now()->addDays(7)->toDateString(),
        ]);
    }
}
