<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `documento` del titular (contrato § Usuario, ficha `vistas/perfil.md`).
 *
 * Identifica a la persona al conciliar pagos por Yape/Plin, donde el
 * comprobante llega con el DNI de quien transfirió. Opcional a propósito: el
 * registro no lo pide y un extranjero con carné no tiene DNI.
 *
 * No lleva UNIQUE: dos hermanos pueden compartir el mismo negocio y aquí lo
 * que identifica es el email, que sí es único global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('documento', 20)->nullable()->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('documento');
        });
    }
};
