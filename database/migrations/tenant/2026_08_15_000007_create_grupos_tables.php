<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `descripcion` existe en la tabla pero el contrato de Grupos no la emite
// (discrepancias §2.12) — el Resource la omite.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('grupo_local', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos')->cascadeOnDelete();
            $table->foreignId('local_id')->constrained('locales')->cascadeOnDelete();

            $table->unique(['grupo_id', 'local_id']);
        });

        Schema::create('grupo_profesional', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos')->cascadeOnDelete();
            $table->foreignId('profesional_id')->constrained('profesionales')->cascadeOnDelete();

            $table->unique(['grupo_id', 'profesional_id']);
        });

        Schema::create('grupo_servicio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos')->cascadeOnDelete();
            $table->foreignId('servicio_id')->constrained('servicios')->cascadeOnDelete();

            $table->unique(['grupo_id', 'servicio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupo_servicio');
        Schema::dropIfExists('grupo_profesional');
        Schema::dropIfExists('grupo_local');
        Schema::dropIfExists('grupos');
    }
};
