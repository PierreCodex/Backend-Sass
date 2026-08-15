<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pagos de la SUSCRIPCIÓN del tenant a la plataforma (no confundir con la
// caja del negocio, que vive en su BD). Sin pasarela en v1: los registra
// soporte a mano tras activar un plan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 63);
            $table->foreignId('plan_id')->constrained('planes');
            $table->decimal('monto', 10, 2);
            $table->enum('periodo', ['mensual', 'anual'])->default('mensual');
            $table->date('fecha_pago');
            $table->string('metodo', 50)->nullable();
            $table->enum('estado', ['pagado', 'pendiente', 'vencido', 'reembolsado'])->default('pagado');
            $table->string('referencia_externa', 120)->nullable();
            $table->string('nota')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'fecha_pago']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
