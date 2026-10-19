<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Services\AccesoPlataformaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /plataforma/acceso/codigo     { email, password }  → 202, manda el código
 * POST /plataforma/acceso/verificar  { email, codigo }    → { data: admin }
 *
 * Sin tenancy y sin sesión de negocio: el panel de plataforma no es de ningún
 * negocio (AD-3).
 */
class AccesoPlataformaController extends Controller
{
    public function __construct(private AccesoPlataformaService $service) {}

    public function codigo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->service->enviarCodigo($datos['email'], $datos['password']);

        return response()->json([
            'message' => 'Te enviamos un código a tu correo.',
            'minutos' => AccesoPlataformaService::MINUTOS,
        ], 202);
    }

    public function verificar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'codigo' => ['required', 'digits:6'],
        ]);

        $admin = $this->service->verificar($datos['email'], $datos['codigo']);

        return response()->json([
            'data' => [
                'nombre' => $admin->nombre,
                'email' => $admin->email,
                'rol' => $admin->rol,
            ],
        ]);
    }
}
