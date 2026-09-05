<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Support\RolesSistema;
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
 * Pasos: crear la BD (tenant_{id}) → migrar → sembrar los tres roles de
 * sistema → fila del dueño en `profesionales` (atiende=1, rol dueño) →
 * db_provisionada=1, estado='prueba', prueba de 7 días.
 */
class ProvisionTenantDatabase implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Tenant $tenant) {}

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

        $tenant->run(function () {
            foreach (RolesSistema::presets() as $rol) {
                DB::table('roles')->updateOrInsert(
                    ['clave' => $rol['clave']],
                    [
                        'nombre' => $rol['nombre'],
                        'sistema' => $rol['sistema'],
                        'solo_propios' => $rol['solo_propios'],
                        'permisos' => json_encode($rol['permisos']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });

        $dueno = $tenant->users()->where('rol', 'admin_general')->orderBy('id')->first();

        if ($dueno !== null) {
            $tenant->run(function () use ($dueno, $tenant) {
                $usuarioId = DB::table('usuarios')
                    ->where('central_user_id', $dueno->id)
                    ->value('id');

                if ($usuarioId === null) {
                    $usuarioId = DB::table('usuarios')->insertGetId([
                        'central_user_id' => $dueno->id,
                        'rol_id' => DB::table('roles')->where('clave', 'admin_general')->value('id'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                /*
                 * La ficha de PROFESIONAL solo se crea si en el registro
                 * respondió que trabaja solo.
                 *
                 * Quien dijo `independiente` es su propio profesional y no
                 * tendría a quién dar de alta; crearle la ficha le ahorra un
                 * paso y le deja la tienda pública utilizable desde el primer
                 * día. A los demás se la pide el checklist de onboarding
                 * («agrega tu primer profesional»), y crearla a ciegas sería
                 * suponer que el dueño atiende —falso en una clínica— y
                 * gastarle una plaza del plan sin que la pida.
                 */
                if ($tenant->rango_profesionales !== 'independiente') {
                    return;
                }

                $yaExiste = DB::table('profesionales')
                    ->where('usuario_id', $usuarioId)
                    ->exists();

                if (! $yaExiste) {
                    DB::table('profesionales')->insert([
                        'usuario_id' => $usuarioId,
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
