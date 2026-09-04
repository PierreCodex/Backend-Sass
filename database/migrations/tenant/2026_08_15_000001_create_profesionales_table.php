<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Quien PRESTA los servicios. Independiente de quien entra al panel
// (`usuarios`): un barbero puede no tener cuenta, y una recepcionista tiene
// cuenta y no está aquí.
//
// `usuario_id` es la unión opcional entre ambos mundos, y es `nullOnDelete` a
// propósito: quitarle el acceso a alguien no puede llevarse por delante su
// ficha, sus citas ni sus comisiones.
//
// `atiende` significa UNA sola cosa: si aparece en la tienda pública. No decide
// el cupo del plan (eso es tener fila activa aquí) ni si es staff (eso es tener
// fila en `usuarios`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profesionales', function (Blueprint $table) {
            $table->id();

            // NULL = no entra al sistema. El UNIQUE admite varios NULL en
            // MySQL, así que un negocio puede tener a todo su equipo sin
            // cuentas si así lo quiere.
            $table->foreignId('usuario_id')->nullable()->unique()
                ->constrained('usuarios')->nullOnDelete();

            $table->string('nombre', 150); // el que ven los clientes en la tienda
            $table->string('cargo', 100)->nullable();
            $table->string('foto')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->enum('tipo_pago', ['comision', 'sueldo', 'ambos'])->default('comision');
            $table->decimal('comision_pct', 5, 2)->default(50);
            $table->decimal('sueldo_monto', 10, 2)->nullable();
            $table->enum('sueldo_periodo', ['semanal', 'quincenal', 'mensual'])->nullable();
            $table->json('horario')->nullable(); // {dias: [...], excepciones: [...]}
            $table->boolean('atiende')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profesionales');
    }
};
