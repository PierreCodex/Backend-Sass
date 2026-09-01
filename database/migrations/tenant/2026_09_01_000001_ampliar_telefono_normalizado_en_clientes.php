<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `telefono_normalizado` pasa de VARCHAR(20) a VARCHAR(30).
 *
 * `telefono` se valida con `max:30` y su columna es VARCHAR(30), pero la
 * normalizada nacio con 20. Normalizar solo quita lo que no sea digito y el
 * prefijo `51` cuando el numero tiene exactamente 11: un valor largo —un
 * numero pegado con su anexo, por ejemplo— sobrevive entero y con 21 digitos
 * ya no cabia. Con la BD en modo estricto eso es "Data too long", o sea un 500
 * al guardar un cliente.
 *
 * Las dos columnas tienen que medir lo mismo: mientras `telefono` acepte 30,
 * la derivada no puede aceptar menos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            // El UNIQUE sobrevive al MODIFY: MySQL solo cambia el tipo.
            $table->string('telefono_normalizado', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('telefono_normalizado', 20)->nullable()->change();
        });
    }
};
