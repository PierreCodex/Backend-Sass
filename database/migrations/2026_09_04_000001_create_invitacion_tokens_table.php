<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabla propia para las invitaciones de empleado, con la misma forma que
// `password_reset_tokens`.
//
// Podrían compartir tabla —el broker es el mismo mecanismo— pero cada broker
// juzga la caducidad con SU configuración: la invitación dura 7 días y el reset
// 60 minutos. Compartiendo tabla, un enlace de reset de hace tres días se
// podría canjear por el endpoint de invitación y seguiría siendo válido. Con
// tablas separadas, cada token solo lo acepta el endpoint que lo emitió.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitacion_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitacion_tokens');
    }
};
