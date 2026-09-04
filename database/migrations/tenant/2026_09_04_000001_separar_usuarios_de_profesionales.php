<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Separa quién ENTRA al panel de quién PRESTA los servicios (2026-09-04).
//
// Antes todo el staff tenía fila en `profesionales`, y el síntoma era que para
// dar de alta a una recepcionista había que declarar cómo se le paga: entraba
// con un 50% de comisión sobre servicios que no presta.
//
// **Va HACIA ADELANTE, sin tocar las migraciones anteriores.** El primer
// intento las editó en el sitio —parecía gratis, con cero tenants en
// producción— y rompió a los que ya existían: Laravel identifica las
// migraciones por nombre de archivo, así que las viejas seguían registradas,
// el archivo nuevo salía pendiente y su `Schema::create('roles')` chocaba
// contra la tabla que ya estaba. Una vez que una migración ha corrido en algún
// sitio, es historia: se le añade encima, no se reescribe.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();

            // → central.users.id, SIN foreign key: MySQL no las permite entre
            // bases. La integridad la garantiza la aplicación.
            $table->unsignedBigInteger('central_user_id')->unique();

            // Aquí SÍ hay foreign key de verdad, y es todo el motivo de que
            // esta tabla viva en la base del tenant y no en la central.
            $table->foreignId('rol_id')->constrained('roles')->restrictOnDelete();

            $table->timestamps();
        });

        Schema::table('profesionales', function (Blueprint $table) {
            // NULL = no entra al sistema. El UNIQUE admite varios NULL en
            // MySQL, así que un negocio puede tener a todo su equipo sin
            // cuentas. `nullOnDelete`: quitarle el acceso a alguien no se
            // lleva su ficha, sus citas ni sus comisiones.
            $table->foreignId('usuario_id')->nullable()->unique()->after('id')
                ->constrained('usuarios')->nullOnDelete();
        });

        /*
         * El traspaso de los que ya existían. Cada profesional con cuenta pasa
         * a tener su fila en `usuarios` con el MISMO rol, y queda apuntando a
         * ella. Sin esto, un negocio en marcha perdería el vínculo entre su
         * gente y sus permisos — y el dueño, su acceso a facturación.
         *
         * En un tenant recién creado no hay nada que traspasar y este bucle no
         * hace nada.
         */
        foreach (DB::table('profesionales')->orderBy('id')->get() as $profesional) {
            $usuarioId = DB::table('usuarios')->insertGetId([
                'central_user_id' => $profesional->central_user_id,
                'rol_id' => $profesional->rol_id,
                'created_at' => $profesional->created_at,
                'updated_at' => now(),
            ]);

            DB::table('profesionales')
                ->where('id', $profesional->id)
                ->update(['usuario_id' => $usuarioId]);
        }

        Schema::table('profesionales', function (Blueprint $table) {
            $table->dropForeign(['rol_id']);
            $table->dropUnique(['central_user_id']);
            $table->dropColumn(['central_user_id', 'rol_id']);
        });
    }

    public function down(): void
    {
        Schema::table('profesionales', function (Blueprint $table) {
            $table->unsignedBigInteger('central_user_id')->nullable()->after('id');
            $table->foreignId('rol_id')->nullable()->after('central_user_id')
                ->constrained('roles')->restrictOnDelete();
        });

        // Devuelve a su ficha lo que se llevó la cuenta. Quien no tenía
        // profesional se pierde: en el modelo viejo no había dónde ponerlo, y
        // esa es justamente la razón de haberlo separado.
        foreach (DB::table('profesionales')->whereNotNull('usuario_id')->get() as $profesional) {
            $usuario = DB::table('usuarios')->find($profesional->usuario_id);

            if ($usuario === null) {
                continue;
            }

            DB::table('profesionales')->where('id', $profesional->id)->update([
                'central_user_id' => $usuario->central_user_id,
                'rol_id' => $usuario->rol_id,
            ]);
        }

        Schema::table('profesionales', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->dropUnique(['usuario_id']);
            $table->dropColumn('usuario_id');
        });

        Schema::dropIfExists('usuarios');
    }
};
