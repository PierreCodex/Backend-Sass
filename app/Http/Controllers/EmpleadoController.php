<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Empleados\EmpleadoRequest;
use App\Http\Resources\EmpleadoResource;
use App\Models\Profesional;
use App\Models\Rol;
use App\Models\User;
use App\Services\EmpleadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class EmpleadoController extends Controller
{
    public function __construct(private EmpleadoService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $profesionales = Profesional::query()
            // El rol viaja en la respuesta: sin el `with`, una pagina de 10
            // empleados serian 10 consultas mas (N+1).
            ->with('rol')
            ->when($request->string('search')->trim()->value(), function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('cargo', 'like', "%{$search}%")
                        /*
                         * La ficha busca "por usuario", y el usuario ES el
                         * email (§1.9) — que vive en la otra base. No hay JOIN
                         * posible, así que se resuelve en dos pasos: los ids
                         * centrales que casan, y luego el `whereIn`.
                         */
                        ->orWhereIn('central_user_id', User::where('email', 'like', "%{$search}%")->pluck('id'));
                });
            })
            ->orderBy('nombre')
            ->paginate($this->porPagina($request));

        $this->service->conUsuarios($profesionales->getCollection());

        return EmpleadoResource::collection($profesionales)
            /*
             * La tarjeta del cupo viaja con el listado: la ficha ofrecía
             * ahorrarse la request de `/empleados/resumen` y es un cálculo de
             * dos consultas ya hechas. El endpoint suelto se mantiene igual,
             * porque el formulario lo consulta sin recargar la tabla.
             */
            ->additional(['resumen' => $this->service->resumen()]);
    }

    public function resumen(): JsonResponse
    {
        return response()->json(['data' => $this->service->resumen()]);
    }

    public function show(Profesional $empleado): EmpleadoResource
    {
        return EmpleadoResource::make($this->service->cargar($empleado));
    }

    public function store(EmpleadoRequest $request): JsonResponse
    {
        // Hay exactamente un dueño por negocio y lo crea el registro. Darle ese
        // rol a un alta nueva fabricaría un segundo superusuario.
        if (Rol::find($request->validated('rol_id'))?->esDueno()) {
            throw ValidationException::withMessages([
                'rol_id' => 'Ya hay un dueño en este negocio.',
            ]);
        }

        $empleado = $this->service->crear($request->validated(), [
            'foto' => $request->file('foto'),
        ]);

        return EmpleadoResource::make($empleado)->response()->setStatusCode(201);
    }

    public function update(EmpleadoRequest $request, Profesional $empleado): EmpleadoResource
    {
        $this->protegerAlDueno($empleado, (int) $request->validated('rol_id'));

        return EmpleadoResource::make($this->service->actualizar(
            $empleado,
            $request->validated(),
            ['foto' => $request->file('foto')],
        ));
    }

    public function destroy(Request $request, Profesional $empleado): JsonResponse
    {
        $usuario = User::find($empleado->central_user_id);

        /*
         * Dos barandillas. La primera: el dueño no se borra. Su fila es la que
         * lleva facturación y la creó el provisioning; borrarla deja al negocio
         * sin nadie que pueda pagar y solo se arregla entrando a la base.
         */
        if ($empleado->rol?->esDueno()) {
            throw ValidationException::withMessages([
                'empleado' => 'Al dueño del negocio no se le puede dar de baja.',
            ]);
        }

        // La segunda: nadie se borra a sí mismo. Se quedaría sin sesión a mitad
        // de la petición y sin forma de deshacerlo.
        if ($usuario !== null && $usuario->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'empleado' => 'No puedes darte de baja a ti mismo.',
            ]);
        }

        $this->service->eliminar($empleado);

        return response()->json(status: 204);
    }

    /**
     * El rol `dueno` no se reparte ni se quita.
     *
     * Hay exactamente uno por negocio: quitárselo lo deja sin acceso a
     * facturación, y dárselo a otro fabrica un segundo superusuario. Cambiar de
     * dueño es una operación de soporte, no un select del formulario.
     */
    private function protegerAlDueno(Profesional $empleado, int $rolNuevo): void
    {
        /*
         * Se mira el rol del NEGOCIO y no `users.rol`: desde que el rol lo
         * elige el dueño de entre los suyos, el central es un valor derivado.
         * Preguntarle a él sería preguntarle a la copia. Además ahorra una
         * consulta a la otra base.
         */
        $esDueno = (bool) $empleado->rol?->esDueno();
        $seraDueno = (bool) Rol::find($rolNuevo)?->esDueno();

        if ($esDueno && ! $seraDueno) {
            throw ValidationException::withMessages([
                'rol_id' => 'El dueño del negocio no puede cambiar de rol.',
            ]);
        }

        if (! $esDueno && $seraDueno) {
            throw ValidationException::withMessages([
                'rol_id' => 'Ya hay un dueño en este negocio.',
            ]);
        }
    }
}
