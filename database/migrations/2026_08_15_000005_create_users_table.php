<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Diverge del SQL de referencia — decisiones cerradas (discrepancias §2.9/§2.10):
//  - email ÚNICO GLOBAL (no compuesto por tenant): identifica el login.
//  - NO existe la columna `usuario` ni su unique compuesto.
//  - NO existe el rol `cliente`: los clientes finales no tienen cuenta.
//  - `apellido` nuevo: el registro pide nombre y apellido por separado
//    (Usuario.name del contrato se emite como nombre + apellido).
//  - password_reset_tokens con PK simple por email (vuelve a ser único
//    global). El reset de platform_admins queda fuera de v1 (panel de
//    plataforma, otra app).
// Sin tabla `sessions`: la API usa tokens Bearer (SESSION_DRIVER=file).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 63);
            $table->string('nombre', 150);
            $table->string('apellido', 150)->nullable();
            $table->string('email', 150)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable(); // requisito p/ provisionar
            $table->string('password');
            $table->enum('rol', ['dueno', 'admin', 'profesional'])->default('profesional');
            $table->string('foto')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 150)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
