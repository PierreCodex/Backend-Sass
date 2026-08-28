<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UsuarioResource;
use App\Mail\VerificarCorreoMail;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class RegistroController extends Controller
{
    /**
     * Solo dueños se registran (§2.9). En UNA transacción: tenants
     * (estado 'registrada', sin nombre ni slug — los fija el onboarding,
     * plan de prueba del seeder) + users dueño. NO abre sesión ni devuelve
     * token, y NO crea ninguna BD de tenant (regla 2: lazy provisioning).
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $planPrueba = Plan::where('slug', 'prueba')->firstOrFail();

        $user = DB::transaction(function () use ($datos, $planPrueba) {
            $tenant = Tenant::create([
                'plan_id' => $planPrueba->id,
                'business_category_id' => $datos['tipo_negocio_id'],
                'rango_profesionales' => $datos['rango_profesionales'],
                'telefono' => $datos['telefono'],
            ]);

            return User::create([
                'tenant_id' => $tenant->id,
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'email' => $datos['email'],
                'telefono' => $datos['telefono'],
                'password' => $datos['password'],
                'rol' => 'dueno',
            ]);
        });

        Mail::to($user->email)->send(new VerificarCorreoMail($user));

        return UsuarioResource::make($user->load('tenant'))
            ->response()
            ->setStatusCode(201);
    }
}
