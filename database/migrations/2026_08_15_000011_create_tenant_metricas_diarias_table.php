<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El reemplazo del "SELECT COUNT(*) global": un job nocturno recorre los
// tenants activos y vuelca aquí sus números (regla 5 del CLAUDE.md —
// no hay JOINs cross-tenant).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_metricas_diarias', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 63);
            $table->date('fecha');
            $table->unsignedInteger('citas_creadas')->default(0);
            $table->unsignedInteger('citas_completadas')->default(0);
            $table->unsignedInteger('citas_canceladas')->default(0);
            $table->decimal('ingresos', 12, 2)->default(0);
            $table->unsignedInteger('clientes_nuevos')->default(0);
            $table->unsignedInteger('profesionales_activos')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_metricas_diarias');
    }
};
