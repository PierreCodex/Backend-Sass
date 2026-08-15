<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Centrales: soporte ve todo sin abrir N bases de tenants.
// Diverge del SQL de referencia (discrepancias §1.7): +columna `respuesta`
// (la única respuesta visible al negocio); `soporte_acciones` queda como
// bitácora interna del panel de soporte.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soporte_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 63)->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('soporte_admin_id')->nullable()
                ->constrained('platform_admins')->nullOnDelete();
            $table->string('asunto', 200);
            $table->text('descripcion')->nullable();
            $table->text('respuesta')->nullable();
            $table->enum('prioridad', ['baja', 'media', 'alta', 'critica'])->default('media');
            $table->enum('estado', ['abierto', 'en_proceso', 'resuelto', 'cerrado'])->default('abierto');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'estado']);
        });

        Schema::create('soporte_acciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->nullable()
                ->constrained('soporte_tickets')->nullOnDelete();
            $table->string('tenant_id', 63)->nullable();
            $table->foreignId('soporte_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->string('accion', 100);
            $table->text('detalle')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soporte_acciones');
        Schema::dropIfExists('soporte_tickets');
    }
};
