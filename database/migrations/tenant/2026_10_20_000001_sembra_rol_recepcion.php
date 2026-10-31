<?php

declare(strict_types=1);

use App\Support\RolesSistema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Añade Recepción a negocios ya provisionados. No modifica roles propios.
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $preset = collect(RolesSistema::presets())
                ->firstWhere('clave', 'recepcion');

            if ($preset === null) {
                return;
            }

            if (! DB::table('roles')->where('clave', 'recepcion')->exists()) {
                $nombre = $preset['nombre'];
                if (DB::table('roles')->where('nombre', $nombre)->exists()) {
                    // `nombre` es único: conservar el rol propio y dar al
                    // preset un nombre visible alternativo.
                    $nombre = $nombre.' (sistema)';
                }

                DB::table('roles')->insert([
                    'nombre' => $nombre,
                    'clave' => $preset['clave'],
                    'sistema' => true,
                    'solo_propios' => false,
                    'permisos' => json_encode($preset['permisos']),
                    'editado_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Solo se actualiza el nombre del preset no personalizado.
            $nombreAdminSedeEnUso = DB::table('roles')
                ->where('nombre', 'Administrador de sede')
                ->where('clave', '!=', 'admin_local')
                ->exists();

            if (! $nombreAdminSedeEnUso) {
                DB::table('roles')
                ->where('clave', 'admin_local')
                ->where('sistema', true)
                ->whereNull('editado_at')
                ->update(['nombre' => 'Administrador de sede', 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('clave', 'recepcion')
            ->where('sistema', true)
            ->whereNull('editado_at')
            ->whereNotExists(fn ($query) => $query
                ->from('usuarios')
                ->whereColumn('usuarios.rol_id', 'roles.id'))
            ->delete();
    }
};
