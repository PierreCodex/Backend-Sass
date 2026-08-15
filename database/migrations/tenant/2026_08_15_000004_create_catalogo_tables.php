<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Divergencias aplicadas:
//  - categoria_servicios: +descripcion, +color, +imagen (§1.2)
//  - servicios: +color, +tipo, +max_sesiones (§1.1); visible_publico filtra
//    la tienda (§4)
//  - servicio_imagenes: orden=0 es la imagen principal, el resto galería
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categoria_servicios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->text('descripcion')->nullable();
            $table->char('color', 7)->nullable();
            $table->string('imagen')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('servicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_servicio_id')->nullable()
                ->constrained('categoria_servicios')->nullOnDelete();
            $table->string('nombre', 150)->unique();
            $table->text('descripcion')->nullable();
            $table->char('color', 7)->default('#4f46e5');
            $table->enum('tipo', ['normal', 'sesiones', 'clases', 'paquete'])->default('normal');
            $table->unsignedInteger('max_sesiones')->nullable(); // solo sesiones y paquete
            $table->decimal('precio', 10, 2)->default(0);
            $table->unsignedInteger('duracion_min')->default(30);
            $table->boolean('visible_publico')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('servicio_imagenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servicio_id')->constrained('servicios')->cascadeOnDelete();
            $table->string('ruta');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('servicio_profesional', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servicio_id')->constrained('servicios')->cascadeOnDelete();
            $table->foreignId('profesional_id')->constrained('profesionales')->cascadeOnDelete();
            $table->decimal('precio_override', 10, 2)->nullable(); // sin pantalla aún (§4)
            $table->timestamps();

            $table->unique(['servicio_id', 'profesional_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicio_profesional');
        Schema::dropIfExists('servicio_imagenes');
        Schema::dropIfExists('servicios');
        Schema::dropIfExists('categoria_servicios');
    }
};
