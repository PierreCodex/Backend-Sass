<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Superadmin y soporte de la plataforma: guard propio, SIN tenant.
// Nunca se mezclan con `users` (no hay tenant_id NULL "mágico").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->enum('rol', ['superadmin', 'soporte'])->default('soporte');
            $table->boolean('activo')->default(true);
            $table->text('two_factor_secret')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
