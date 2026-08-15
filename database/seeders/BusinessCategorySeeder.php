<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BusinessCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Taxonomía amplia para el select "Tipo de negocio" del registro
 * (CLAUDE.md § Referencia de mercado: belleza, salud, fitness, veterinaria,
 * clases). "Otro" va al final: dispara tenants.categoria_otro_detalle.
 */
class BusinessCategorySeeder extends Seeder
{
    private const CATEGORIAS = [
        // Belleza
        'Barbería',
        'Salón de belleza',
        'Peluquería',
        'Spa',
        'Manicure y pedicure',
        'Estética y cosmetología',
        'Depilación',
        'Maquillaje',
        'Tatuajes y piercing',
        // Salud
        'Clínica dental',
        'Consultorio médico',
        'Psicología',
        'Fisioterapia',
        'Nutrición',
        'Podología',
        'Óptica',
        // Fitness
        'Gimnasio',
        'Entrenador personal',
        'Yoga y pilates',
        // Veterinaria
        'Veterinaria',
        'Peluquería canina',
        // Clases
        'Clases particulares',
        'Academia o instituto',
        'Escuela de música',
        'Escuela de baile',
        // Escape
        'Otro',
    ];

    public function run(): void
    {
        foreach (self::CATEGORIAS as $nombre) {
            BusinessCategory::updateOrCreate(
                ['slug' => Str::slug($nombre)],
                ['nombre' => $nombre, 'activo' => true],
            );
        }
    }
}
