<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\ServicioRequest;
use App\Http\Resources\ServicioResource;
use App\Models\Servicio;
use App\Services\ServicioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

class ServicioController extends Controller
{
    private const RELACIONES = ['categoria', 'imagenes', 'profesionales'];

    public function __construct(private ServicioService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $servicios = Servicio::query()
            ->with(self::RELACIONES)
            // La ficha dice que filtra solo por nombre (a diferencia de
            // categorías, que también busca en la descripción).
            ->when($request->string('search')->trim()->value(), fn ($q, string $search) => $q->where('nombre', 'like', "%{$search}%"))
            ->when($request->filled('categoria_id'), fn ($q) => $q->where('categoria_servicio_id', $request->integer('categoria_id')))
            ->orderBy('nombre')
            ->paginate($request->integer('per_page', 10));

        return ServicioResource::collection($servicios);
    }

    public function show(Servicio $servicio): ServicioResource
    {
        return ServicioResource::make($servicio->load(self::RELACIONES));
    }

    public function store(ServicioRequest $request): JsonResponse
    {
        $servicio = $this->service->crear($request->validated(), $this->archivos($request));

        return ServicioResource::make($servicio)->response()->setStatusCode(201);
    }

    public function update(ServicioRequest $request, Servicio $servicio): ServicioResource
    {
        return ServicioResource::make(
            $this->service->actualizar($servicio, $request->validated(), $this->archivos($request)),
        );
    }

    public function destroy(Servicio $servicio): JsonResponse
    {
        $this->service->eliminar($servicio);

        // 204 aunque tenga citas: es soft delete, el historial queda intacto.
        return response()->json(status: 204);
    }

    /**
     * Los archivos van aparte de `validated()`: ahí llegan como UploadedFile
     * y no como datos que puedan acabar en un `fill()`.
     *
     * @return array{imagen_principal: ?UploadedFile, galeria: array}
     */
    private function archivos(ServicioRequest $request): array
    {
        return [
            'imagen_principal' => $request->file('imagen_principal'),
            'galeria' => $request->file('galeria', []),
        ];
    }
}
