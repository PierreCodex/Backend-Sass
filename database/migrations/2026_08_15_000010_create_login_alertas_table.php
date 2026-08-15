<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_alertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('platform_admin_id')->nullable()
                ->constrained('platform_admins')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('token', 100)->nullable()->unique();
            $table->boolean('resuelta')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_alertas');
    }
};
