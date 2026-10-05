<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profesionales\ProfesionalRequest;
use App\Http\Resources\ProfesionalResource;
use App\Models\Profesional;
use App\Models\User;
use App\Models\Usuario;
use App\Services\ProfesionalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Quien presta los servicios. Ya no exige cuenta ni rol: eso es `/usuarios`.
 *
 * Tampoco exige ser administrador general para gestionarlo, al reves que roles y cuentas — dar
 * de alta a un barbero no reparte poder sobre el sistema, y el preset de
 * Administrador trae `empleados: gestionar` justo para esto.
 *
 * Lo que SÍ es del general es darle acceso al panel (`usuario` en el payload):
 * crear una cuenta reparte poder. Esa regla no vive aquí sino en
 * `UsuarioService` vía `Rango` (G-4), que es por donde pasan todos los caminos.
 */
class ProfesionalController extends Controller
{
    public function __construct(private ProfesionalService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $profesionales = Profesional::query()
            // Sin esto, una pagina de 10 serian 10 consultas mas (N+1).
            ->with('usuario.rol')
            ->when($request->string('search')->trim()->value(), function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('cargo', 'like', "%{$search}%")
                        /*
                         * Buscar por correo cruza a la otra base, asi que se
                         * resuelve en dos pasos: los ids centrales que casan,
                         * las cuentas del tenant que los llevan, y el whereIn.
                         */
                        ->orWhereIn('usuario_id', Usuario::whereIn(
                            'central_user_id',
                            User::where('email', 'like', "%{$search}%")->pluck('id'),
                        )->pluck('id'));
                });
            })
            ->orderBy('nombre')
            ->paginate($this->porPagina($request));

        foreach ($profesionales->getCollection() as $profesional) {
            $this->service->cargar($profesional);
        }

        return ProfesionalResource::collection($profesionales)
            // La tarjeta del cupo viaja con el listado: son dos consultas ya
            // hechas y ahorra una peticion para pintar la misma pantalla.
            ->additional(['resumen' => $this->service->resumen()]);
    }

    public function resumen(): JsonResponse
    {
        return response()->json(['data' => $this->service->resumen()]);
    }

    public function show(Profesional $profesional): ProfesionalResource
    {
        return ProfesionalResource::make($this->service->cargar($profesional));
    }

    public function store(ProfesionalRequest $request): JsonResponse
    {
        $profesional = $this->service->crear($request->validated(), [
            'foto' => $request->file('foto'),
        ], $request->user()->id);

        return ProfesionalResource::make($profesional)->response()->setStatusCode(201);
    }

    public function update(ProfesionalRequest $request, Profesional $profesional): ProfesionalResource
    {
        return ProfesionalResource::make($this->service->actualizar(
            $profesional,
            $request->validated(),
            ['foto' => $request->file('foto')],
            $request->user()->id,
        ));
    }

    /**
     * Da de baja la FICHA, no a la persona.
     *
     * Su cuenta del panel sobrevive: si ademas hay que quitarle el acceso, eso
     * se hace en `/usuarios`. Son dos decisiones distintas, y mezclarlas haria
     * que dejar de atender significara quedarse fuera del sistema.
     */
    public function destroy(Profesional $profesional): JsonResponse
    {
        $this->service->eliminar($profesional);

        return response()->json(status: 204);
    }
}
