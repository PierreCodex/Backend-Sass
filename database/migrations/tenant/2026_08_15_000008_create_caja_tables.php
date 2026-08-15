<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Una sesión por fecha (UNIQUE). Dos montos: monto_apertura y
// monto_cierre_real (NULL = la caja sigue abierta) — el contrato emite
// monto_inicial/monto_final. `esperado` y `diferencia` se guardan al cerrar
// como auditoría y NO se emiten (§2.7).
// caja_movimientos.caja_cierre_id nullable: los movimientos automáticos
// (p. ej. pago QR verificado con caja cerrada) nacen huérfanos y
// POST /caja/abrir los ADOPTA en la sesión nueva (§6.2.3) — la regla
// "exige sesión abierta" aplica solo a los manuales.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_cierres', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->decimal('monto_apertura', 12, 2)->default(0);
            $table->decimal('monto_cierre_esperado', 12, 2)->default(0);
            $table->decimal('monto_cierre_real', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();
            $table->unsignedBigInteger('registrado_por_user_id'); // → central.users.id (sin FK)
            $table->string('nota')->nullable();
            $table->timestamp('cerrada_el')->nullable();
            $table->timestamps();
        });

        Schema::create('caja_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_cierre_id')->nullable()
                ->constrained('caja_cierres')->nullOnDelete();
            $table->foreignId('cita_id')->nullable()->constrained('citas')->nullOnDelete();
            $table->enum('tipo', ['ingreso', 'egreso']);
            $table->string('concepto', 150);
            $table->decimal('monto', 12, 2);
            $table->enum('metodo', ['efectivo', 'tarjeta', 'yape', 'plin', 'transferencia', 'otro'])
                ->default('efectivo');
            $table->unsignedBigInteger('registrado_por_user_id'); // → central.users.id (sin FK)
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_movimientos');
        Schema::dropIfExists('caja_cierres');
    }
};
