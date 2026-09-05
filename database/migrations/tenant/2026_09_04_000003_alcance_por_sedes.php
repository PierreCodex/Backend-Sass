<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// En qué sedes mira una cuenta cuando entra al panel.
//
// El alcance es de la PERSONA, no del rol. Dos recepcionistas con el mismo rol
// trabajan en sedes distintas; si viviera en el rol habría que crear
// «Recepcionista de Piura» y «Recepcionista de Castilla», y multiplicar los
// roles por sedes es justo lo que esto viene a evitar. Es como lo describe
// AgendaPro: «se LE puede asignar permisos sobre uno o varios locales».
//
// Con esto quedan tres ejes ortogonales:
//   `roles.permisos`      → QUÉ puede tocar        (política, se reutiliza)
//   `roles.solo_propios`  → SOBRE QUIÉN            (política, se reutiliza)
//   el alcance de abajo   → DÓNDE                  (asignación, es de cada uno)
//
// OJO, y está escrito en pendientes-contrato: hoy no se aplica ningún permiso.
// Esto guarda el dato —que es lo que no se puede hacer después sin re-migrar—
// pero el filtrado de cada listado llega con el middleware de capacidades. Un
// ajuste que no filtra es peor que no tenerlo: le promete al negocio algo que
// no cumple. Por eso el panel no debe ofrecerlo hasta entonces.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            /*
             * Por defecto TRUE, y existe para que «sin sedes asignadas» no
             * signifique dos cosas a la vez: «todas» y «nadie se lo ha
             * configurado todavía». Sin este booleano, una cuenta recién
             * creada tendría una puerta abierta por omisión y nadie sabría si
             * fue una decisión o un olvido.
             *
             * El administrador general lo lleva siempre en TRUE: no tiene
             * sentido pedirle que se asigne sus propias sedes.
             */
            $table->boolean('todos_los_locales')->default(true)->after('rol_id');
        });

        Schema::create('local_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_id')->constrained('locales')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['local_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_usuario');

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('todos_los_locales');
        });
    }
};
