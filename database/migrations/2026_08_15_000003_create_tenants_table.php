<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Diverge del SQL de referencia — decisiones cerradas (CLAUDE.md):
//  - `id` y `slug` DESACOPLADOS: id aleatorio inmutable (nombra la BD
//    tenant_{id}); slug UNIQUE nullable que fija el paso 1 del onboarding.
//  - `nombre` nullable: el registro ya no lo pide.
//  - `rango_profesionales` nuevo: viene del registro.
//  - Pagos QR (§6.1 discrepancias): pagos_qr_activo, qr_imagen,
//    instrucciones_pago — centrales porque la tienda pública las necesita
//    sin tocar la BD del tenant.
//  - elegible_promo NO es columna: se deriva (estado === 'prueba').
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id', 63)->primary();
            $table->string('slug', 63)->nullable()->unique();
            $table->foreignId('plan_id')->constrained('planes');
            $table->foreignId('business_category_id')->nullable()
                ->constrained('business_categories')->nullOnDelete();
            $table->string('categoria_otro_detalle')->nullable();
            $table->string('rango_profesionales', 15)->nullable();

            // Identidad pública del negocio (NULL hasta el paso 1 del onboarding)
            $table->string('nombre', 150)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('direccion')->nullable();
            $table->decimal('latitud', 10, 8)->nullable();
            $table->decimal('longitud', 11, 8)->nullable();
            $table->string('zona_horaria', 64)->default('America/Lima');

            // Marca / sitio público
            $table->string('logo')->nullable();
            $table->string('cover')->nullable();
            $table->char('color_primario', 7)->default('#4f46e5');
            $table->char('color_secundario', 7)->default('#06b6d4');
            $table->boolean('sitio_publico_activo')->default(true);
            $table->boolean('mostrar_en_marketplace')->default(false);
            $table->text('terminos_servicio')->nullable();
            $table->text('mapa_embed')->nullable();

            // Ciclo de vida SaaS
            $table->enum('estado', [
                'registrada', 'prueba', 'activa', 'suspendida', 'purga_pendiente', 'eliminada',
            ])->default('registrada');
            $table->boolean('db_provisionada')->default(false);
            $table->boolean('onboarding_completado')->default(false);
            $table->json('onboarding_pasos')->nullable();
            $table->date('suscripcion_vence_el')->nullable();
            $table->timestamp('suspendida_el')->nullable();
            $table->timestamp('aviso_purga_enviado_el')->nullable();
            $table->date('purga_programada_el')->nullable();

            // Límites y consumo del plan
            $table->unsignedInteger('extra_profesionales')->default(0);
            $table->unsignedInteger('extra_whatsapp')->default(0);
            $table->unsignedInteger('whatsapp_mensajes_enviados_mes')->default(0);
            $table->date('whatsapp_mes_periodo')->nullable();

            // Pagos QR (Yape/Plin, sin pasarela)
            $table->boolean('pagos_qr_activo')->default(false);
            $table->string('qr_imagen')->nullable();
            $table->text('instrucciones_pago')->nullable();

            $table->json('configuracion')->nullable();
            $table->json('data')->nullable(); // requerido por stancl (VirtualColumn)

            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'suscripcion_vence_el']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
