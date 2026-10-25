<?php

use App\Support\RolesSistema;
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
//    2026-09-04.
return new class extends Migration
{
    private const NOMBRE_ALTERNATIVO = 'Recepción (sistema)';

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

        if (! DB::table('roles')->where('clave', 'recepcion')->exists()) {
            $preset = collect(RolesSistema::presets())->firstWhere('clave', 'recepcion');

            $nombre = DB::table('roles')->where('nombre', $preset['nombre'])->exists()
                ? self::NOMBRE_ALTERNATIVO
                : $preset['nombre'];

            DB::table('roles')->insert([
                'nombre' => $nombre,
                'clave' => 'recepcion',
                'sistema' => true,
                'solo_propios' => $preset['solo_propios'],
                'permisos' => json_encode($preset['permisos']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('roles')
            ->where('clave', 'admin_local')
            ->whereNull('editado_at')
            ->update(['nombre' => 'Administrador de sede']);
    }

    public function down(): void
    {
        // Solo si nadie lo usa: la FK de `usuarios.rol_id` es restrictOnDelete.
        $rol = DB::table('roles')->where('clave', 'recepcion')->first();

        if ($rol !== null && ! DB::table('usuarios')->where('rol_id', $rol->id)->exists()) {
            DB::table('roles')->where('id', $rol->id)->delete();
        }

        DB::table('roles')
            ->where('clave', 'admin_local')
            ->whereNull('editado_at')
            ->update(['nombre' => 'Administrador local']);
    }
};
