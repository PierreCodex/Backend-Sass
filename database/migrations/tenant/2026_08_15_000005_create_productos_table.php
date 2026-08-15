<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El API emite precio_venta/precio_compra: aquí son precio/costo y el
// Resource mapea (§1.10). stock_minimo default 5, como espera el contrato.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->unique();
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 10, 2)->default(0);
            $table->decimal('costo', 10, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->unsignedInteger('stock_minimo')->default(5);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
