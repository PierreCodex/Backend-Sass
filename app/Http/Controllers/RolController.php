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

/**
 * **Escribir** roles es solo del administrador general. Leerlos, no.
 *
 * Si un administrador pudiera crearlos, se haría uno con todo marcado y se lo
 * asignaría: escalada de privilegios en dos clics. Pero LEERLOS tiene que poder
 * cualquiera que abra el formulario de una cuenta, o el select de rol se queda
 * vacío. Y la lista no es un secreto dentro del negocio: es su organigrama.
 *
 * Se deja abierta a cualquier usuario del tenant, y no a los dos administradores, para no
 * inventar una SEGUNDA tabla de permisos al lado de la que ya existe: la regla
 * de este proyecto es preguntar por capacidades y nunca por el rol. Mientras esa
 * resolución no exista —deuda anotada—, un único candado por propiedad es más
 * honesto que dos reglas paralelas que se separan.
 */
class RolController extends Controller
{
    public function __construct(private RolService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $roles = Rol::query()
            ->withCount('usuarios')
            ->when($request->string('search')->trim()->value(), function ($q, string $search) {
                $q->where('nombre', 'like', "%{$search}%");
            })
            /*
             * Los de sistema arriba y en su orden (general, local,
             * profesional), y los del negocio detrás por nombre. `sistema`
             * DESC no bastaba: entre los tres el orden lo decidiría el nombre,
             * y el negocio puede renombrarlos.
             */
            ->orderByRaw("FIELD(clave, 'profesional', 'admin_local', 'admin_general') DESC")
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

    public function show(Rol $rol): RolResource
    {
        return RolResource::make($rol->loadCount('usuarios'));
    }

    public function store(RolRequest $request): JsonResponse
    {
        $this->soloElAdminGeneral($request);

        return RolResource::make($this->service->crear($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(RolRequest $request, Rol $rol): RolResource
    {
        $this->soloElAdminGeneral($request);

        if (! $rol->editable()) {
            throw ValidationException::withMessages([
                'rol' => 'El rol de administrador general no se puede editar.',
            ]);
        }

        return RolResource::make($this->service->actualizar($rol, $request->validated()));
    }

    public function destroy(Request $request, Rol $rol): JsonResponse
    {
        $this->soloElAdminGeneral($request);

        if (! $rol->borrable()) {
            throw ValidationException::withMessages([
                'rol' => 'Los roles del sistema no se pueden borrar. Si no lo usas, no se lo asignes a nadie.',
            ]);
        }

        $this->service->eliminar($rol);

        return response()->json(status: 204);
    }
}
