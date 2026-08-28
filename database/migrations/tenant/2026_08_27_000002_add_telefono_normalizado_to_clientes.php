<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El telefono se guarda TAL CUAL lo escribio el usuario (la ficha lo exige:
 * en la app conviven "9768657567", "+51 981 912 809" y "904 169 872"), pero
 * la reserva publica busca al cliente POR TELEFONO — es la clave natural del
 * firstOrCreate, decidido en discrepancias.md §, que esta congelado.
 *
 * Las dos cosas juntas fabrican duplicados: "904169872" y "904 169 872" son
 * la misma persona y crearian dos fichas, cada una con medio historial.
 *
 * Por eso hay dos columnas: `telefono` para mostrar y `telefono_normalizado`
 * para casar. El UNIQUE es lo que ademas cierra la carrera: dos reservas
 * simultaneas del mismo cliente no pueden crear dos fichas.
 *
 * Se anade ahora y no en el Sprint 4 porque casi no hay tenants
 * provisionados: mas tarde seria un ALTER TABLE sobre la base de cada uno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('telefono_normalizado', 20)->nullable()->after('telefono');

            // NULL no colisiona consigo mismo en MySQL, asi que los clientes
            // sin telefono (los que da de alta el panel a mano) no estorban.
            $table->unique('telefono_normalizado');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropUnique(['telefono_normalizado']);
            $table->dropColumn('telefono_normalizado');
        });
    }
};
