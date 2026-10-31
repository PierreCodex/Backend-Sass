<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Story 1.5 en los negocios que ya estaban en marcha. Los nuevos lo reciben al
// provisionar desde `RolesSistema::presets()`.
//
// 1. Se añade el rol de sistema «Recepción» (clave `recepcion`). Sin tocar los
//    roles propios del negocio: si ya tenía uno llamado «Recepción» —es lo que
//    la mayoría habría creado a mano—, ese se queda como está y el de sistema
//    toma otro nombre, porque `roles.nombre` es único.
// 2. «Administrador local» pasa a llamarse «Administrador de sede» en pantalla.
//    La clave `admin_local` no cambia, y el nombre solo se toca si el negocio
//    no lo personalizó (`editado_at` NULL), igual que en la migración del
//    2026-09-04, y si ese nombre está libre.
//
// Ningún choque de nombres puede hacerla fallar: si fallara en un negocio,
// `tenants:migrar-provisionados` la reintentaría en él cada vez sin registrarla
// nunca, y ese negocio no recibiría ninguna migración posterior.
return new class extends Migration
{
    /*
     * Los valores van fijados aquí y no se leen de `RolesSistema::presets()`:
     * una migración inserta lo que era cierto el día que se escribió. Si el
     * preset cambia mañana, los negocios que se migren después no deben
     * recibir otra cosa, ni romperse si la clave desaparece.
     */
    private const NOMBRE = 'Recepción';

    private const PERMISOS = [
        'dashboard' => 'ver',
        'citas' => 'gestionar',
        'calendario' => 'gestionar',
        'clientes' => 'gestionar',
        'servicios' => 'ver',
        'inventario' => 'ver',
        'caja' => 'gestionar',
        'pagos' => 'gestionar',
        'empleados' => 'ver',
    ];

    public function up(): void
    {
        /*
         * En un negocio recién creado esta migración corre ANTES de que el
         * provisioning siembre los roles. Si insertara aquí, Recepción tomaría
         * el id 1, delante del administrador general. Sin roles sembrados no
         * hay nada que migrar: el provisioning lo crea todo en orden.
         */
        if (! DB::table('roles')->where('clave', 'admin_general')->exists()) {
            return;
        }

        DB::transaction(function () {
            if (! DB::table('roles')->where('clave', 'recepcion')->exists()) {
                DB::table('roles')->insert([
                    'nombre' => $this->nombreLibre(),
                    'clave' => 'recepcion',
                    'sistema' => true,
                    'solo_propios' => false,
                    'permisos' => json_encode(self::PERMISOS),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->renombrarAdminLocal('Administrador de sede');
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Solo si nadie lo usa: la FK de `usuarios.rol_id` es restrictOnDelete.
            $rol = DB::table('roles')->where('clave', 'recepcion')->first();

            if ($rol !== null && ! DB::table('usuarios')->where('rol_id', $rol->id)->exists()) {
                DB::table('roles')->where('id', $rol->id)->delete();
            }

            $this->renombrarAdminLocal('Administrador local');
        });
    }

    /*
     * «Recepción», o «Recepción (sistema)», o «Recepción (sistema 2)»… el
     * primero que el negocio no use. La comparación es la de la columna, que
     * no distingue mayúsculas ni tildes: es la misma que aplica el índice
     * único, así que lo que aquí está libre lo está también para él.
     */
    private function nombreLibre(): string
    {
        $candidatos = (function () {
            yield self::NOMBRE;
            yield self::NOMBRE.' (sistema)';

            for ($n = 2; ; $n++) {
                yield self::NOMBRE." (sistema {$n})";
            }
        })();

        foreach ($candidatos as $nombre) {
            if (! DB::table('roles')->where('nombre', $nombre)->exists()) {
                return $nombre;
            }
        }
    }

    /*
     * Solo si el negocio no lo personalizó y si el nombre está libre. Si otro
     * rol ya se llama así, el de sistema conserva el que tenía: un nombre de
     * pantalla no vale una migración bloqueada.
     */
    private function renombrarAdminLocal(string $nombre): void
    {
        $ocupado = DB::table('roles')
            ->where('nombre', $nombre)
            ->where(fn ($q) => $q->whereNull('clave')->orWhere('clave', '!=', 'admin_local'))
            ->exists();

        if ($ocupado) {
            return;
        }

        DB::table('roles')
            ->where('clave', 'admin_local')
            ->whereNull('editado_at')
            ->update(['nombre' => $nombre]);
    }
};
