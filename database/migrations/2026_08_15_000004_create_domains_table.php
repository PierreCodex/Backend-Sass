<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabla de stancl/tenancy. Apunta al `id` inmutable del tenant, NO al slug:
// cuando el onboarding fija (o algún día cambia) el slug, se reescribe la
// fila de domains sin tocar infraestructura.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('tenant_id', 63);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
