<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// `users.rol` pasa de dueno|admin|profesional a
// admin_general|admin_local|profesional.
//
// El rol central sirve para UNA cosa: saber quien responde por la cuenta y
// paga. Se renombra igual que el del negocio para no tener dos vocabularios
// para lo mismo — que era lo que iba a producir errores mas adelante.
//
// El ENUM se amplia, se traducen las filas y recien entonces se recorta: MySQL
// no deja renombrar un valor de ENUM en un solo paso sin dejar filas invalidas
// por el camino.
return new class extends Migration
{
    private const ANTES = "ENUM('dueno','admin','profesional') NOT NULL DEFAULT 'profesional'";

    private const AMBOS = "ENUM('dueno','admin','profesional','admin_general','admin_local') NOT NULL DEFAULT 'profesional'";

    private const DESPUES = "ENUM('admin_general','admin_local','profesional') NOT NULL DEFAULT 'profesional'";

    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY rol '.self::AMBOS);

        DB::table('users')->where('rol', 'dueno')->update(['rol' => 'admin_general']);
        DB::table('users')->where('rol', 'admin')->update(['rol' => 'admin_local']);

        DB::statement('ALTER TABLE users MODIFY rol '.self::DESPUES);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users MODIFY rol '.self::AMBOS);

        DB::table('users')->where('rol', 'admin_general')->update(['rol' => 'dueno']);
        DB::table('users')->where('rol', 'admin_local')->update(['rol' => 'admin']);

        DB::statement('ALTER TABLE users MODIFY rol '.self::ANTES);
    }
};
