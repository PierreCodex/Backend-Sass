<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Las claves de los tres roles de sistema pasan a llamarse como se llaman en
// pantalla: `dueno` → `admin_general`, `admin` → `admin_local`.
//
// El motivo no es cosmético. «Administrador general» y «Administrador» se
// diferenciaban en UNA fila —facturación— y eso no es un rol distinto, es el
// mismo con un permiso menos. Lo que de verdad los separa es el ALCANCE: uno
// manda en la empresa, el otro en su sede. Al cambiar el concepto, la clave
// vieja dejó de describirlo.
//
// El `nombre` visible solo se toca en los roles que el negocio NO ha
// personalizado (`editado_at` NULL). A quien renombró su rol no se le deshace
// la decisión a su espalda — que es exactamente para lo que existe esa columna.
return new class extends Migration
{
    private const CLAVES = [
        'dueno' => 'admin_general',
        'admin' => 'admin_local',
    ];

    private const NOMBRES = [
        'admin_general' => 'Administrador general',
        'admin_local' => 'Administrador local',
    ];

    public function up(): void
    {
        foreach (self::CLAVES as $vieja => $nueva) {
            DB::table('roles')->where('clave', $vieja)->update(['clave' => $nueva]);
        }

        foreach (self::NOMBRES as $clave => $nombre) {
            DB::table('roles')
                ->where('clave', $clave)
                ->whereNull('editado_at')
                ->update(['nombre' => $nombre]);
        }

        /*
         * Y el administrador local pierde Configuración: ahí viven el nombre
         * del negocio, el slug, la marca y el horario base — cosas de la
         * empresa, no de un local.
         *
         * Solo si nadie lo ha tocado, por lo mismo de arriba.
         */
        $rol = DB::table('roles')->where('clave', 'admin_local')->whereNull('editado_at')->first();

        if ($rol !== null) {
            $permisos = json_decode($rol->permisos, true) ?: [];
            unset($permisos['configuracion']);

            DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($permisos)]);
        }
    }

    public function down(): void
    {
        foreach (self::CLAVES as $vieja => $nueva) {
            DB::table('roles')->where('clave', $nueva)->update(['clave' => $vieja]);
        }

        DB::table('roles')->where('clave', 'dueno')->whereNull('editado_at')->update(['nombre' => 'Dueño']);
        DB::table('roles')->where('clave', 'admin')->whereNull('editado_at')->update(['nombre' => 'Administrador']);
    }
};
