<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Roles del negocio. Vive en la BD del TENANT, no en la central: un rol que
// inventa el dueño es dato de su negocio. Como todo el staff tiene fila en
// `profesionales`, la asignación se resuelve entera dentro de esta base, con
// foreign key de verdad (nada de referencias entre bases sin integridad).
//
// `users.rol` sigue en la central para lo único que es central: distinguir al
// dueño, que es quien maneja facturación.
//
// El esquema se escribe COMPLETO ahora aunque sus endpoints lleguen en el
// Sprint 2 (regla del CLAUDE.md): añadir columnas después obliga a re-migrar
// la base de cada tenant, una por una.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique(); // "Recepcionista", "Barbero con inventario"

            // Solo los de sistema la tienen: dueno|admin|profesional. Es lo que
            // permite al backend reconocerlos aunque el negocio los renombre.
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

        Schema::table('profesionales', function (Blueprint $table) {
            // restrictOnDelete: un rol en uso NO se borra. Primero se reasigna
            // a esas personas. La barandilla vive en la BD, no solo en el service.
            $table->foreignId('rol_id')
                ->nullable()
                ->after('central_user_id')
                ->constrained('roles')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('profesionales', function (Blueprint $table) {
            $table->dropForeign(['rol_id']);
            $table->dropColumn('rol_id');
        });

        Schema::dropIfExists('roles');
    }
};
