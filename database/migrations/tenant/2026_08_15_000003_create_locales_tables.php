<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Divergencias aplicadas (discrepancias §1.3 y §1.4):
//  - locales: +descripcion_publica, +email, +color, +banner, +logo,
//    +es_principal (el principal no se borra; se edita desde Configuración).
//    `horario` JSON es LA FUENTE DE VERDAD (permite "sábado hasta la 1,
//    domingo cerrado"); el Resource deriva horario_desde/hasta planos.
//  - local_profesional: el pivote gana los 5 campos que exige su PUT
//    (habilitado, nombre_publico, perfil, horario_apertura/cierre).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('direccion')->nullable();
            $table->text('descripcion_publica')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->decimal('latitud', 10, 8)->nullable();
            $table->decimal('longitud', 11, 8)->nullable();
            $table->char('color', 7)->nullable();
            $table->string('banner')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('es_principal')->default(false);
            $table->json('horario')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('local_profesional', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_id')->constrained('locales')->cascadeOnDelete();
            $table->foreignId('profesional_id')->constrained('profesionales')->cascadeOnDelete();
            $table->boolean('habilitado')->default(true);
            $table->string('nombre_publico', 150)->nullable();
            $table->text('perfil')->nullable();
            $table->time('horario_apertura')->nullable();
            $table->time('horario_cierre')->nullable();
            $table->timestamps();

            $table->unique(['local_id', 'profesional_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_profesional');
        Schema::dropIfExists('locales');
    }
};
