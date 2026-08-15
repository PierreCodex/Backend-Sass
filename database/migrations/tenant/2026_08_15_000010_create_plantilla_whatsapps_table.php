<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Divergencias aplicadas (§1.8): +nombre; `mensaje` se emite como
// `contenido` en el Resource. `evento` es string (no ENUM) validado en el
// Form Request contra las 9 claves EXACTAS del contrato: confirmacion,
// recordatorio, cancelacion, finalizado, bienvenida, pago_linea,
// redes_sociales, cumpleanos, personalizado. UNIQUE(evento): el store hace
// updateOrCreate — una plantilla por evento.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantilla_whatsapps', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('evento', 50)->unique();
            $table->text('mensaje');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_whatsapps');
    }
};
