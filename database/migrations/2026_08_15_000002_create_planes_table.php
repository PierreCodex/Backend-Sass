<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Diverge del SQL de referencia (discrepancias §1.5): se añaden los campos
// comerciales que exige el contrato (descripcion, promo, precios de extras,
// destacado) y los límites usan 999 como centinela de "ilimitado" — nunca
// NULL. Los flags permite_* del SQL viejo se absorben en `features` (JSON).
// Los nombres de columna siguen las claves que emite el API (max_sucursales,
// max_whatsapp_mes) para que el Resource no tenga que mapear.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50);
            $table->string('slug', 50)->unique();
            $table->text('descripcion')->nullable();
            $table->decimal('precio_mensual', 10, 2)->default(0);
            $table->decimal('precio_anual', 10, 2)->nullable();
            $table->decimal('precio_promo', 10, 2)->nullable();
            $table->unsignedInteger('promo_duracion_meses')->default(0);
            $table->boolean('promo_activa')->default(false);
            $table->unsignedInteger('max_profesionales')->default(999);
            $table->unsignedInteger('max_sucursales')->default(999);
            $table->unsignedInteger('max_whatsapp_mes')->default(0);
            $table->decimal('precio_profesional_extra', 10, 2)->default(0);
            $table->decimal('precio_whatsapp_extra', 10, 2)->default(0);
            $table->unsignedInteger('mensajes_whatsapp_extra')->default(50);
            $table->boolean('destacado')->default(false);
            $table->json('features')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planes');
    }
};
