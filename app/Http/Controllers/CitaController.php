<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Citas\CitaRequest;
use App\Http\Resources\CitaResource;
use App\Models\Cita;
use App\Models\Profesional;
use App\Services\CitaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CitaController extends Controller
{
    private const RELACIONES = ['cliente', 'profesional', 'servicios', 'productos'];

    public function __construct(private CitaService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $citas = Cita::query()
            ->with(self::RELACIONES)
            ->tap(fn (Builder $q) => $this->acotar($q, $request))
            ->when($request->filled('fecha'), fn ($q) => $q->whereDate('starts_at', $request->string('fecha')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when(
                $request->string('search')->trim()->value(),
                // Por el CLIENTE: es como se busca una cita cuando alguien
                // llama preguntando por la suya.
                fn ($q, string $search) => $q->whereHas(
                    'cliente',
                    fn ($c) => $c->where('nombre', 'like', "%{$search}%")
                        ->orWhere('apellido', 'like', "%{$search}%")
                        ->orWhere('telefono', 'like', "%{$search}%")
                ),
            )
            ->orderBy('starts_at')
            ->paginate($this->porPagina($request));

        return CitaResource::collection($citas);
    }

    public function show(Request $request, Cita $cita): CitaResource
    {
        $this->exigirVisibilidad($request, $cita);

        return CitaResource::make($cita->load(self::RELACIONES));
    }

    public function store(CitaRequest $request): JsonResponse
    {
        $cita = $this->service->crear(
            $request->user()->tenant,
            $request->validated(),
            $request->user()->id,
        );

        return CitaResource::make($cita)->response()->setStatusCode(201);
    }

    public function update(CitaRequest $request, Cita $cita): CitaResource
    {
        $this->exigirVisibilidad($request, $cita);

        return CitaResource::make($this->service->actualizar(
            $request->user()->tenant,
            $cita,
            $request->validated(),
            $request->user()->id,
        ));
    }

    public function destroy(Request $request, Cita $cita): JsonResponse
    {
        $this->exigirVisibilidad($request, $cita);

        $this->service->eliminar($cita, $request->user()->id);

        return response()->json(status: 204);
    }

    /**
     * Recorta el listado a lo que esa persona puede ver: su sede y, si su rol
     * lo dice, solo sus propias citas.
     *
     * Los dos ejes son distintos y se aplican juntos: el alcance es DÓNDE
     * manda —vive en su cuenta— y `solo_propios` es SOBRE QUIÉN, que vive en su
     * rol. Una recepcionista de la sede norte ve todas las citas de esa sede;
     * un barbero con `solo_propios` ve solo las suyas, esté donde esté.
     */
    private function acotar(Builder $consulta, Request $request): void
    {
        $sedes = $this->alcanceDeSedes($request);

        if ($sedes !== null) {
            $consulta->whereIn('local_id', $sedes);
        }

        if ($this->soloLasSuyas($request)) {
            // `?->id ?? 0` si no tiene ficha de profesional: ningún id es 0,
            // así que no ve ninguna cita. Quien no atiende no tiene citas
            // propias, y devolverle la agenda entera sería justo lo contrario
            // de lo que su rol dice.
            $consulta->where('profesional_id', $this->fichaDe($request)?->id ?? 0);
        }
    }

    /**
     * 404 y no 403 sobre una cita que no le toca: para esa persona esa cita no
     * existe. Es el mismo criterio que el alcance por sedes, y por el mismo
     * motivo — un 403 confirmaría que está ahí.
     */
    private function exigirVisibilidad(Request $request, Cita $cita): void
    {
        if ($cita->local_id !== null) {
            $this->exigirAlcance($request, $cita->local_id);
        }

        if ($this->soloLasSuyas($request) && $cita->profesional_id !== $this->fichaDe($request)?->id) {
            abort(404);
        }
    }

    private function soloLasSuyas(Request $request): bool
    {
        return $request->attributes->get('capacidades')?->soloPropios() ?? false;
    }

    /**
     * Su ficha de profesional, si la tiene.
     *
     * La resuelve `Capacidades` y no este controlador: es la MISMA pregunta que
     * se hace el service al escribir, y dos resoluciones distintas de lo mismo
     * son las que se acaban separando. Además ya viene cargada del middleware,
     * así que esto no cuesta una consulta.
     */
    private function fichaDe(Request $request): ?Profesional
    {
        return $request->attributes->get('capacidades')?->profesional();
    }
}
