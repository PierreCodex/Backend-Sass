<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Grupos\GrupoRequest;
use App\Http\Resources\GrupoResource;
use App\Models\Grupo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class GrupoController extends Controller
{
    private const RELACIONES = ['locales', 'profesionales', 'servicios'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $grupos = Grupo::query()
            // Sin esto, una página de 10 grupos serían 30 consultas más.
            ->with(self::RELACIONES)
            ->when($request->string('search')->trim()->value(), function ($q, string $search) {
                $q->where('nombre', 'like', "%{$search}%");
            })
            ->orderBy('nombre')
            ->paginate($this->porPagina($request));

        return GrupoResource::collection($grupos);
    }

    public function show(Grupo $grupo): GrupoResource
    {
        return GrupoResource::make($grupo->load(self::RELACIONES));
    }

    public function store(GrupoRequest $request): JsonResponse
    {
        $grupo = DB::transaction(function () use ($request) {
            $grupo = Grupo::create(['nombre' => $request->validated('nombre')]);

            return $this->sincronizar($grupo, $request->validated());
        });

        return GrupoResource::make($grupo)->response()->setStatusCode(201);
    }

    public function update(GrupoRequest $request, Grupo $grupo): GrupoResource
    {
        $grupo = DB::transaction(function () use ($request, $grupo) {
            $grupo->update(['nombre' => $request->validated('nombre')]);

            return $this->sincronizar($grupo, $request->validated());
        });

        return GrupoResource::make($grupo);
    }

    public function destroy(Grupo $grupo): JsonResponse
    {
        // Borrado real: nada del historial apunta a un grupo, y los pivotes se
        // van solos por `cascadeOnDelete`.
        $grupo->delete();

        return response()->json(status: 204);
    }

    /**
     * `sync()` en las tres listas: lo que no llega, se desasigna.
     *
     * Un array VACÍO deja el grupo sin nada de esa categoría — y eso es
     * distinto de no mandar la clave, que lo deja como estaba. La distinción
     * importa porque el formulario manda las tres siempre, pero un cliente de
     * la API puede querer tocar solo una.
     *
     * @param  array<string, mixed>  $datos
     */
    private function sincronizar(Grupo $grupo, array $datos): Grupo
    {
        foreach (self::RELACIONES as $relacion) {
            if (array_key_exists($relacion, $datos)) {
                $grupo->{$relacion}()->sync($datos[$relacion] ?? []);
            }
        }

        return $grupo->load(self::RELACIONES);
    }
}
