<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Los clientes NO tienen cuenta ni login (decisión cerrada §2.2): la ficha
// se crea o encuentra por TELÉFONO (firstOrCreate) y la gestión de su cita
// va por el `codigo` enviado por WhatsApp. central_user_id queda vestigial
// (siempre NULL en v1); se mantiene solo por compatibilidad con el SQL de
// referencia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('central_user_id')->nullable();
            $table->string('nombre', 150);
            $table->string('apellido', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('documento', 30)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('telefono');
            $table->index('documento');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
