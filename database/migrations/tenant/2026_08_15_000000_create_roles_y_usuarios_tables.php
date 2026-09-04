<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Quién ENTRA al panel. Va antes que `profesionales` porque quien presta los
// servicios es otra cosa y puede no tener cuenta.
//
// La separación (2026-09-04) corrige el modelo anterior, donde todo el staff
// tenía fila en `profesionales`: para dar de alta a una recepcionista había que
// declarar cómo se le paga —`tipo_pago` era obligatorio y `comision_pct` traía
// un 50 por defecto—, o sea comisión sobre servicios que no presta. Cuando el
// modelo obliga a rellenar campos sin sentido, el modelo está mal.
//
// Los roles viven aquí y no en la central por la misma razón que antes: un rol
// que inventa el dueño es dato de su negocio, y teniéndolos en la misma base
// que `usuarios` la asignación se resuelve con una foreign key de verdad.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique(); // "Recepcionista", "Barbero con inventario"

            // Solo los de sistema la tienen: dueno|admin|profesional. Es lo que
            // permite al backend reconocerlos aunque el negocio los renombre —
            // por eso el nombre visible puede cambiar y la clave no.
            $table->string('clave', 50)->nullable()->unique();

            // Se siembran al provisionar. No se borran nunca; solo el de `dueno`
            // tampoco se edita (si se pudiera, un dueño se dejaría a sí mismo
            // fuera de facturación y solo se arregla entrando a su base).
            $table->boolean('sistema')->default(false);

            // { "caja": "gestionar", "clientes": "ver", "reportes": null }
            // Dos niveles y no verbos CRUD: nadie en una barbería quiere "puede
            // crear clientes pero no borrarlos". Va en JSON porque añadir
            // módulos nuevos no debe tocar el esquema de N bases.
            $table->json('permisos');

            // Dimensión aparte del permiso: no es QUÉ toca, es SOBRE QUIÉN.
            // Un profesional que "ve citas" ve las suyas; un administrador, las
            // de todos. Es del rol, no de cada vista.
            $table->boolean('solo_propios')->default(false);

            // NULL mientras el negocio no lo haya tocado. Cuando se lance un
            // módulo nuevo y haya que añadirlo a los presets, el script solo
            // pisa los que siguen en NULL: a quien personalizó su rol no se le
            // deshace la decisión a su espalda.
            $table->timestamp('editado_at')->nullable();

            $table->timestamps();
        });

        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();

            // → central.users.id, SIN foreign key: MySQL no las permite entre
            // bases. La integridad la garantiza la aplicación, igual que en
            // `clientes`. Único: una cuenta central pertenece a un solo negocio.
            $table->unsignedBigInteger('central_user_id')->unique();

            // Aquí SÍ hay foreign key de verdad, que es todo el motivo de que
            // esta tabla viva en la base del tenant. `restrictOnDelete`: un rol
            // en uso no se borra, primero se reasigna a esas personas.
            $table->foreignId('rol_id')->constrained('roles')->restrictOnDelete();

            $table->timestamps();

            // Nada de `nombre`, `email` ni `activo`: eso es de `users` en la
            // central y duplicarlo es garantizar que un día no coincidan. Esta
            // tabla existe para una cosa — colgar el rol del negocio de una
            // cuenta — y no debe crecer más allá de eso.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('roles');
    }
};
