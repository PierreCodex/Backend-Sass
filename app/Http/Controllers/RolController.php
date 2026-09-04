<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Roles\RolRequest;
use App\Http\Resources\RolResource;
use App\Models\Rol;
use App\Services\RolService;
use App\Support\RolesSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class RolController extends Controller
{
    public function __construct(private RolService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->soloElDueno($request);

        $roles = Rol::query()
            ->withCount('profesionales')
            ->when($request->string('search')->trim()->value(), function ($q, string $search) {
                $q->where('nombre', 'like', "%{$search}%");
            })
            /*
             * Los de sistema arriba y en su orden (dueño, administrador,
             * profesional), y los del negocio detrás por nombre. `sistema`
             * DESC no bastaba: entre los tres el orden lo decidiría el nombre,
             * y el negocio puede renombrarlos.
             */
            ->orderByRaw("FIELD(clave, 'profesional', 'admin', 'dueno') DESC")
            ->orderBy('nombre')
            ->paginate($this->porPagina($request));

        return RolResource::collection($roles)
            /*
             * La lista de módulos viaja con el listado: la pantalla necesita
             * las dos cosas para pintar la matriz y son una consulta y una
             * constante. Un endpoint aparte serían dos peticiones para armar
             * una sola tabla.
             */
            ->additional(['modulos' => RolesSistema::MODULOS]);
    }

    public function show(Request $request, Rol $rol): RolResource
    {
        $this->soloElDueno($request);

        return RolResource::make($rol->loadCount('profesionales'));
    }

    public function store(RolRequest $request): JsonResponse
    {
        $this->soloElDueno($request);

        return RolResource::make($this->service->crear($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(RolRequest $request, Rol $rol): RolResource
    {
        $this->soloElDueno($request);

        if (! $rol->editable()) {
            throw ValidationException::withMessages([
                'rol' => 'El rol del dueño no se puede editar.',
            ]);
        }

        return RolResource::make($this->service->actualizar($rol, $request->validated()));
    }

    public function destroy(Request $request, Rol $rol): JsonResponse
    {
        $this->soloElDueno($request);

        if (! $rol->borrable()) {
            throw ValidationException::withMessages([
                'rol' => 'Los roles del sistema no se pueden borrar. Si no lo usas, no se lo asignes a nadie.',
            ]);
        }

        $this->service->eliminar($rol);

        return response()->json(status: 204);
    }

    /**
     * Gestionar roles es solo del dueño.
     *
     * Si pudiera un administrador, se crearía un rol con todo marcado y se lo
     * asignaría: escalada de privilegios en dos clics. Por eso el preset de
     * Administrador trae `empleados: gestionar` pero esto se comprueba aparte
     * — dar de alta gente y decidir qué puede hacer la gente son dos permisos
     * distintos.
     *
     * 403 y no 404: el recurso existe y es del negocio de quien pregunta; lo
     * que falta es rango. El 404 se reserva para lo que es de otro tenant.
     */
    private function soloElDueno(Request $request): void
    {
        if ($request->user()->rol !== 'dueno') {
            abort(403, 'Solo el dueño del negocio puede gestionar los roles.');
        }
    }
}
