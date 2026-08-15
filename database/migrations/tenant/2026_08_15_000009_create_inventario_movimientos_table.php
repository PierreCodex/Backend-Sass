<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El endpoint manual solo valida entrada|salida; 'venta' la genera la cita
// con productos y 'ajuste' queda reservado (§2.8).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->enum('tipo', ['entrada', 'salida', 'ajuste', 'venta']);
            $table->integer('cantidad');
            $table->string('motivo', 150)->nullable();
            $table->foreignId('cita_id')->nullable()->constrained('citas')->nullOnDelete();
            $table->unsignedBigInteger('registrado_por_user_id'); // → central.users.id (sin FK)
            $table->timestamps();

            $table->index(['producto_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_movimientos');
    }
};
