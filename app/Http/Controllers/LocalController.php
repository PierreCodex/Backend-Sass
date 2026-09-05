<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Locales\LocalRequest;
use App\Http\Resources\LocalResource;
use App\Models\Local;
use App\Services\LocalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocalController extends Controller
{
    public function __construct(private LocalService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $locales = Local::query()
            ->when($request->string('search')->trim()->value(), function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('direccion', 'like', "%{$search}%");
                });
            })
            // El principal primero: es la sede de la que cuelga el negocio.
            ->orderByDesc('es_principal')
            ->orderBy('nombre')
            ->paginate($this->porPagina($request));

        return LocalResource::collection($locales);
    }

    public function show(Local $local): LocalResource
    {
        return LocalResource::make($local);
    }

    public function store(LocalRequest $request): JsonResponse
    {
        $local = $this->service->crear($request->safe()->except(['banner', 'logo']), [
            'banner' => $request->file('banner'),
            'logo' => $request->file('logo'),
        ]);

        return LocalResource::make($local)->response()->setStatusCode(201);
    }

    public function update(LocalRequest $request, Local $local): LocalResource
    {
        return LocalResource::make($this->service->actualizar(
            $local,
            $request->safe()->except(['banner', 'logo']),
            ['banner' => $request->file('banner'), 'logo' => $request->file('logo')],
        ));
    }

    public function destroy(Local $local): JsonResponse
    {
        $this->service->eliminar($local);

        return response()->json(status: 204);
    }
}
