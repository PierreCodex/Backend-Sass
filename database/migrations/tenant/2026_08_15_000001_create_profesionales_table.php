<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Perfil laboral local. La identidad (email, password, rol) vive en la BD
// central: central_user_id la referencia SIN foreign key (MySQL no permite
// FK entre bases; la integridad es de la aplicación).
// Divergencias aplicadas: tipo_pago 'ambos' (no 'mixto', §1.9) y columna
// `atiende` — TODO el staff tiene fila aquí (dueño/admin incluidos, desde el
// provisioning); el flag controla agenda y tienda pública, no el cupo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profesionales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('central_user_id')->unique(); // → central.users.id (sin FK)
            $table->string('nombre', 150); // denormalizado p/ mostrar sin ir a central
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
