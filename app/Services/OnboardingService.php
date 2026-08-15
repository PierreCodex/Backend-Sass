<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checklist de onboarding (vistas/onboarding.md). El estado vive en
 * tenants.onboarding_pasos (JSON, central). Marcar es idempotente y nunca se
 * desmarca. Los pasos 2-5 los marcan otros módulos como efecto lateral
 * (hooks de los sprints 1, 2, 4 y 5); desde el cliente solo es marcable
 * `sitio_publico`.
 */
class OnboardingService
{
    public const PASOS = [
        'nombre_negocio',
        'horario_local',
        'primer_profesional',
        'primer_servicio',
        'reserva_prueba',
        'sitio_publico',
    ];

    public const MARCABLES_POR_CLIENTE = ['sitio_publico'];

    /**
     * Subdominios que un slug jamás puede tomar (lista de
     * vistas/onboarding.md + rutas propias de la plataforma).
     */
    public const SLUGS_RESERVADOS = [
        'www', 'api', 'admin', 'app', 'mail', 'ftp',
        'panel', 'dashboard', 'soporte', 'ayuda', 'blog', 'docs',
        'login', 'registro', 'static', 'cdn', 'status', 'publico',
    ];

    /** @return array{completado: bool, pasos: list<array{clave: string, completado: bool}>} */
    public function estado(Tenant $tenant): array
    {
        $marcados = $tenant->onboarding_pasos ?? [];

        $pasos = array_map(fn (string $clave) => [
            'clave' => $clave,
            'completado' => (bool) ($marcados[$clave] ?? false),
        ], self::PASOS);

        return [
            'completado' => $tenant->onboarding_completado,
            'pasos' => $pasos,
        ];
    }

    public function marcar(Tenant $tenant, string $clave): void
    {
        if (! in_array($clave, self::PASOS, true)) {
            throw new \InvalidArgumentException("Paso de onboarding desconocido: {$clave}");
        }

        $marcados = $tenant->onboarding_pasos ?? [];

        if (($marcados[$clave] ?? false) === true) {
            return; // idempotente: nunca se re-marca ni se desmarca
        }

        $marcados[$clave] = true;

        $tenant->update([
            'onboarding_pasos' => $marcados,
            'onboarding_completado' => count(array_intersect_key(
                array_filter($marcados),
                array_flip(self::PASOS),
            )) === count(self::PASOS),
        ]);
    }

    /**
     * Paso 1: fija nombre y slug DEFINITIVO (inmutable — es el subdominio de
     * la tienda). Segundo intento → 422 errors.nombre.
     */
    public function fijarNombre(Tenant $tenant, string $nombre): Tenant
    {
        if ($tenant->slug !== null) {
            throw ValidationException::withMessages([
                'nombre' => 'El nombre ya está definido y el enlace de tu tienda no puede cambiar.',
            ]);
        }

        $tenant->update([
            'nombre' => $nombre,
            'slug' => $this->derivarSlug($nombre),
        ]);

        $this->marcar($tenant, 'nombre_negocio');

        return $tenant->refresh();
    }

    private function derivarSlug(string $nombre): string
    {
        $base = Str::slug($nombre);

        if ($base === '') {
            $base = 'negocio';
        }

        $slug = $base;
        $sufijo = 2;

        while (
            in_array($slug, self::SLUGS_RESERVADOS, true)
            || Tenant::withTrashed()->where('slug', $slug)->exists()
        ) {
            $slug = "{$base}-{$sufijo}";
            $sufijo++;
        }

        return $slug;
    }
}
