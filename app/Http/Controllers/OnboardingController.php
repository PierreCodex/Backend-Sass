<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Onboarding\OnboardingNombreRequest;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->onboarding->estado($request->user()->tenant)]);
    }

    /**
     * Paso 1: fija nombre + slug definitivo inmutable y "enciende" la
     * tienda (GET /publico/{slug} deja de dar 404). Repetirlo → 422.
     */
    public function nombre(OnboardingNombreRequest $request): JsonResponse
    {
        $tenant = $this->onboarding->fijarNombre(
            $request->user()->tenant,
            $request->validated()['nombre'],
        );

        return response()->json(['data' => [
            'nombre' => $tenant->nombre,
            'slug' => $tenant->slug,
        ]]);
    }

    /**
     * Solo las claves marcables desde el cliente (hoy: sitio_publico).
     * Los demás pasos los marca el backend como efecto lateral de su
     * endpoint — el checklist no puede "hacerse trampas".
     */
    public function marcarPaso(Request $request, string $clave): JsonResponse
    {
        if (! in_array($clave, OnboardingService::MARCABLES_POR_CLIENTE, true)) {
            return response()->json([
                'message' => 'Este paso se completa automáticamente desde su pantalla.',
                'errors' => ['clave' => ['Este paso se completa automáticamente desde su pantalla.']],
            ], 422);
        }

        $tenant = $request->user()->tenant;
        $this->onboarding->marcar($tenant, $clave);

        return response()->json(['data' => $this->onboarding->estado($tenant->refresh())]);
    }
}
