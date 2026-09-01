<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\CategoriaServicioRequest;
use App\Http\Resources\CategoriaServicioResource;
use App\Models\CategoriaServicio;
use App\Services\CategoriaServicioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoriaServicioController extends Controller
{
    public function __construct(private CategoriaServicioService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $categorias = CategoriaServicio::query()
            ->withCount('servicios')
            // `search`, nunca `buscar` (contrato). Busca por nombre y descripcion.
            ->when($request->string('search')->trim()->value(), function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('descripcion', 'like', "%{$search}%");
                });
            })
            // Menor `orden`, mas arriba. El nombre desempata para que la lista
            // no baile entre peticiones cuando varias comparten orden.
            ->orderBy('orden')
            ->orderBy('nombre')
            ->paginate($this->porPagina($request));

        return CategoriaServicioResource::collection($categorias);
    }

    public function show(CategoriaServicio $categoria): CategoriaServicioResource
    {
        return CategoriaServicioResource::make($categoria->loadCount('servicios'));
    }

    public function store(CategoriaServicioRequest $request): JsonResponse
    {
        $categoria = $this->service->crear(
            $request->safe()->except('imagen'),
            $request->file('imagen'),
        );

        return CategoriaServicioResource::make($categoria)
            ->response()
            ->setStatusCode(201);
    }

    public function update(CategoriaServicioRequest $request, CategoriaServicio $categoria): CategoriaServicioResource
    {
        return CategoriaServicioResource::make(
            $this->service->actualizar($categoria, $request->safe()->except('imagen'), $request->file('imagen')),
        );
    }

    public function destroy(CategoriaServicio $categoria): JsonResponse
    {
        $this->service->eliminar($categoria);

        // 204 por contrato. El aviso de "N servicios quedaran sin categoria" lo
        // pinta el frontend con el `servicios_count` que ya tiene del listado.
        return response()->json(status: 204);
    }
}
