<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Inventario\MovimientoRequest;
use App\Http\Requests\Inventario\ProductoRequest;
use App\Http\Resources\ProductoResource;
use App\Models\Producto;
use App\Services\InventarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventarioController extends Controller
{
    public function __construct(private InventarioService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $productos = Producto::query()
            ->when(
                $request->string('search')->trim()->value(),
                // Nombre Y descripción, como fija la tabla de endpoints del
                // contrato. La ficha decía solo nombre; el contrato manda.
                fn ($q, string $search) => $q->where(
                    fn ($q) => $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('descripcion', 'like', "%{$search}%")
                ),
            )
            ->orderBy('nombre')
            ->paginate($this->porPagina($request));

        return ProductoResource::collection($productos);
    }

    public function show(Producto $producto): ProductoResource
    {
        return ProductoResource::make($producto);
    }

    public function store(ProductoRequest $request): JsonResponse
    {
        $producto = $this->service->crear($request->validated(), $request->user()->id);

        return ProductoResource::make($producto)->response()->setStatusCode(201);
    }

    public function update(ProductoRequest $request, Producto $producto): ProductoResource
    {
        return ProductoResource::make($this->service->actualizar($producto, $request->validated()));
    }

    public function destroy(Producto $producto): JsonResponse
    {
        $this->service->eliminar($producto);

        return response()->json(status: 204);
    }

    /**
     * Devuelve el PRODUCTO, no el movimiento: la tabla necesita repintar el
     * stock de esa fila y con el movimiento tendría que pedir el listado
     * entero para saber cómo quedó.
     */
    public function movimiento(MovimientoRequest $request, Producto $producto): ProductoResource
    {
        return ProductoResource::make(
            $this->service->movimiento($producto, $request->validated(), $request->user()->id),
        );
    }
}
