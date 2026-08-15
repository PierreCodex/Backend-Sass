<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// El corazón del producto. Reglas que este esquema fija (discrepancias §2):
//  - starts_at/ends_at DATETIME en la zona horaria del tenant (§2.1), con
//    CHECK ends_at > starts_at.
//  - cliente_id NOT NULL: siempre hay cliente; el walk-in se registra vía
//    firstOrCreate por teléfono (§2.2).
//  - cita_servicio es la ÚNICA fuente de verdad de los servicios (§2.4):
//    no existe citas.servicio_id. Precio y duración se congelan al reservar.
//  - monto_total = suma de cita_servicio + cita_producto (§2.6).
//  - Anti-solape: índice (profesional_id, starts_at) + transacción con
//    SELECT ... FOR UPDATE en el service (MySQL no tiene EXCLUDE).
//  - Pagos QR (§6.1): en citas solo metodo_pago_eleccion y estado_pago
//    (materializado, lo escribe únicamente el service); los intentos viven
//    en cita_pagos (1-N: re-subida tras rechazo y seña sin migración).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $table) {
            $table->id();
            $table->char('codigo', 8)->unique(); // código público p/ el cliente (WhatsApp)
            $table->foreignId('local_id')->nullable()->constrained('locales')->nullOnDelete();
            $table->foreignId('profesional_id')->constrained('profesionales');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('estado', [
                'pendiente', 'confirmada', 'en_curso', 'completada', 'cancelada', 'no_asistio',
            ])->default('pendiente');
            $table->string('notas', 500)->nullable();
            $table->enum('fuente', ['publica', 'admin', 'whatsapp', 'api'])->default('publica');
            $table->decimal('monto_total', 10, 2)->default(0);
            $table->enum('metodo_pago_eleccion', ['ahora', 'local'])->nullable();
            $table->enum('estado_pago', [
                'pendiente', 'comprobante_subido', 'verificado', 'rechazado',
            ])->nullable();
            $table->string('cancelada_motivo')->nullable();
            $table->timestamp('cancelada_el')->nullable();
            $table->timestamp('confirmada_el')->nullable();
            $table->timestamp('completada_el')->nullable();
            $table->timestamps();

            $table->index(['profesional_id', 'starts_at']); // consulta de solapes
            $table->index(['cliente_id', 'starts_at']);     // historial del cliente
            $table->index(['estado', 'starts_at']);
        });

        DB::statement('ALTER TABLE citas ADD CONSTRAINT citas_horario_chk CHECK (ends_at > starts_at)');

        Schema::create('cita_servicio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cita_id')->constrained('citas')->cascadeOnDelete();
            $table->foreignId('servicio_id')->constrained('servicios');
            $table->unsignedInteger('cantidad')->default(1);
            $table->decimal('precio', 10, 2)->default(0); // congelado al reservar
            $table->unsignedInteger('duracion_min')->default(0);
            $table->timestamps();

            $table->unique(['cita_id', 'servicio_id']);
        });

        Schema::create('cita_producto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cita_id')->constrained('citas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->unsignedInteger('cantidad')->default(1);
            $table->decimal('precio', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('cita_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cita_id')->constrained('citas')->cascadeOnDelete();
            $table->decimal('monto', 10, 2); // v1: siempre = monto_total; con seña será parcial
            $table->string('comprobante'); // disco privado, servido por URL firmada (§6.3)
            $table->enum('estado', ['comprobante_subido', 'verificado', 'rechazado'])
                ->default('comprobante_subido');
            $table->unsignedBigInteger('verificado_por_user_id')->nullable(); // → central.users.id (sin FK)
            $table->timestamp('verificado_el')->nullable();
            $table->string('rechazo_motivo')->nullable();
            $table->timestamps();

            $table->index(['cita_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cita_pagos');
        Schema::dropIfExists('cita_producto');
        Schema::dropIfExists('cita_servicio');
        Schema::dropIfExists('citas');
    }
};
