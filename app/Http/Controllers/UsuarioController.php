<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Usuarios\UsuarioRequest;
use App\Http\Resources\CuentaResource;
use App\Models\User;
use App\Models\Usuario;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Quién entra al panel. Distinto de `/profesionales`, que es quién presta los
 * servicios: una recepcionista vive aquí y no allí.
 *
 * Gestionar cuentas es solo del administrador general, igual que los roles y por el mismo
 * motivo — quien puede crear cuentas y repartir roles puede fabricarse un
 * segundo administrador general.
 */
class UsuarioController extends Controller
{
    public function __construct(private UsuarioService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->soloElAdminGeneral($request);

        $usuarios = Usuario::query()
            ->with(['rol', 'profesional'])
            ->when($request->string('search')->trim()->value(), function ($q, string $search) {
                /*
                 * El nombre y el email viven en la otra base, así que la
                 * búsqueda se resuelve en dos pasos: los ids centrales que
                 * casan, y luego el `whereIn`. No hay JOIN posible.
                 */
                $q->whereIn('central_user_id', User::where(function ($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('apellido', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->pluck('id'));
            })
            ->paginate($this->porPagina($request));

        $this->service->conCentrales($usuarios->getCollection());

        return CuentaResource::collection($usuarios);
    }

    public function show(Request $request, Usuario $usuario): CuentaResource
    {
        $this->soloElAdminGeneral($request);

        return CuentaResource::make($this->service->cargar($usuario));
    }

    public function store(UsuarioRequest $request): JsonResponse
    {
        $this->soloElAdminGeneral($request);

        // El candado de «un solo administrador general» vive en el service, que
        // es por donde pasan TODOS los caminos — este y el alta desde
        // /profesionales, que antes lo esquivaba.
        return CuentaResource::make($this->service->crear($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UsuarioRequest $request, Usuario $usuario): CuentaResource
    {
        $this->soloElAdminGeneral($request);

        return CuentaResource::make($this->service->actualizar($usuario, $request->validated()));
    }

    public function destroy(Request $request, Usuario $usuario): JsonResponse
    {
        $this->soloElAdminGeneral($request);

        /*
         * Dos barandillas. La primera: la cuenta del administrador general no se borra. Es la
         * que lleva facturación y la creó el registro; borrarla deja al negocio
         * sin nadie que pueda pagar y solo se arregla entrando a la base.
         */
        if ($usuario->rol?->esAdminGeneral()) {
            throw ValidationException::withMessages([
                'usuario' => 'Al administrador general no se le puede quitar el acceso.',
            ]);
        }

        // La segunda: nadie se borra a sí mismo. Se quedaría sin sesión a mitad
        // de la petición y sin forma de deshacerlo.
        if ($usuario->central_user_id === $request->user()->id) {
            throw ValidationException::withMessages([
                'usuario' => 'No puedes quitarte el acceso a ti mismo.',
            ]);
        }

        $this->service->eliminar($usuario);

        return response()->json(status: 204);
    }

    /**
     * Reenviar la invitación.
     *
     * Existe desde el primer día porque el alta depende de que un correo
     * llegue, y los correos se pierden: caducan los 7 días, caen en spam, el
     * empleado los borra. Sin este botón la única salida sería borrar la cuenta
     * y volverla a crear.
     */
    public function invitar(Request $request, Usuario $usuario): JsonResponse
    {
        $this->soloElAdminGeneral($request);

        $this->service->invitar($usuario);

        return response()->json(['message' => 'Invitación reenviada.']);
    }
}
