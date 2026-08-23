<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Perfil\ActualizarPerfilRequest;
use App\Http\Requests\Perfil\CambiarPasswordRequest;
use App\Http\Resources\UsuarioResource;
use App\Services\PerfilService;
use Illuminate\Http\JsonResponse;

/**
 * Mi perfil (`/configuracion/perfil`): la cuenta de la persona, no la del
 * negocio — eso vive en Configuración.
 */
class PerfilController extends Controller
{
    public function __construct(private readonly PerfilService $perfil) {}

    /**
     * PUT /user → { data: Usuario }
     */
    public function actualizar(ActualizarPerfilRequest $request): UsuarioResource
    {
        $user = $this->perfil->actualizar($request->user(), $request->validated());

        return UsuarioResource::make($user->load('tenant'));
    }

    /**
     * PUT /user/password → 200. Deja viva SOLO la sesión desde la que se cambia.
     */
    public function password(CambiarPasswordRequest $request): JsonResponse
    {
        $this->perfil->cambiarPassword(
            $request->user(),
            $request->validated()['password'],
            $request->user()->currentAccessToken()?->id,
        );

        return response()->json([
            'message' => 'Contraseña actualizada. Cerramos tus otras sesiones por seguridad.',
        ]);
    }
}
