<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /login → { data: { token, usuario } } — forma EXACTA que el BFF
     * ya tiene cableada. El email identifica a una sola persona (único
     * global, §2.10). 403 si el correo no está verificado.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $user = User::where('email', $datos['email'])->first();

        if ($user === null || ! Hash::check($datos['password'], $user->password) || ! $user->activo) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'message' => 'Verifica tu correo antes de iniciar sesión. Revisa tu bandeja de entrada.',
            ], 403);
        }

        /*
         * Ciclo de vida del negocio. `suspendida` NO se bloquea aquí a
         * propósito: el dueño entra y paga (el corte del resto del panel lo
         * hace el middleware `suscripcion.activa`). Los estados de purga sí
         * cierran la puerta: la BD del tenant está en camino de desaparecer.
         */
        if (in_array($user->tenant?->estado, ['purga_pendiente', 'eliminada'], true)) {
            return response()->json([
                'message' => 'Esta cuenta fue dada de baja. Escríbenos si necesitas recuperarla.',
                'codigo' => 'cuenta_dada_de_baja',
            ], 403);
        }

        $token = $user->createToken('panel')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'usuario' => UsuarioResource::make($user->load('tenant')),
            ],
        ]);
    }

    /**
     * Revoca SOLO el token en uso, no todos (contrato).
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function user(Request $request): UsuarioResource
    {
        return UsuarioResource::make($request->user()->load('tenant'));
    }
}
