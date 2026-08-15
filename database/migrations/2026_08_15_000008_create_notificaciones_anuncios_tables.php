<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('platform_admin_id')->nullable()
                ->constrained('platform_admins')->cascadeOnDelete();
            $table->string('tipo', 100);
            $table->string('titulo');
            $table->text('mensaje');
            $table->string('url')->nullable();
            $table->timestamp('leida_el')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'leida_el']);
        });

        Schema::create('anuncios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->string('titulo', 200);
            $table->text('contenido');
            $table->enum('audiencia', ['todos', 'duenos', 'profesionales'])->default('todos');
            $table->timestamp('publicado_el')->nullable();
            $table->timestamp('expira_el')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anuncios');
        Schema::dropIfExists('notificaciones');
    }
};
