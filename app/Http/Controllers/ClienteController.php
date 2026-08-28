<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Clientes\ClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Models\Cliente;
use App\Services\ClienteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClienteController extends Controller
{
    public function __construct(private ClienteService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $clientes = Cliente::query()
            ->withCount('citas')
            ->withMax('citas', 'starts_at')
            ->when($request->string('search')->trim()->value(), function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('apellido', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('telefono', 'like', "%{$search}%")
                        /*
                         * Y tambien por el normalizado: quien busca "904169872"
                         * tiene que encontrar al que se guardo como
                         * "904 169 872". Es el mismo motivo por el que existe
                         * la columna.
                         */
                        ->when(
                            Cliente::normalizarTelefono($search),
                            fn ($q, string $digitos) => $q->orWhere('telefono_normalizado', 'like', "%{$digitos}%"),
                        );
                });
            })
            ->orderBy('nombre')
            ->paginate($request->integer('per_page', 10));

        return ClienteResource::collection($clientes);
    }

    public function show(Cliente $cliente): ClienteResource
    {
        return ClienteResource::make(
            $cliente->loadCount('citas')->loadMax('citas', 'starts_at'),
        );
    }

    public function store(ClienteRequest $request): JsonResponse
    {
        return ClienteResource::make($this->service->crear($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(ClienteRequest $request, Cliente $cliente): ClienteResource
    {
        return ClienteResource::make($this->service->actualizar($cliente, $request->validated()));
    }

    public function destroy(Cliente $cliente): JsonResponse
    {
        $this->service->eliminar($cliente);

        return response()->json(status: 204);
    }
}
