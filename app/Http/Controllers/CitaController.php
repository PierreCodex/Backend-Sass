<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Citas\CitaRequest;
use App\Http\Resources\CitaResource;
use App\Models\Cita;
use App\Models\Cliente;
use App\Models\Local;
use App\Models\Producto;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Services\ConfiguracionService;
use App\Services\CitaService;
use App\Support\Capacidades;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class CitaController extends Controller
{
    private const RELACIONES = ['cliente', 'profesional', 'servicios', 'productos'];

    public function __construct(private CitaService $service) {}

    public function opciones(Request $request): JsonResponse
    {
        $capacidades = Capacidades::de($request->user());
        $locales = $capacidades->locales();
        $porPagina = min(max($request->integer('per_page', 20), 1), 50);

        $profesionales = Profesional::query()
            ->where('activo', true)
            ->when($capacidades->soloPropios(), fn ($q) => $q->whereKey($capacidades->profesional()?->id ?? 0))
            ->whereHas('locales', function ($q) use ($locales) {
                $q->where('locales.activo', true)
                    ->where('local_profesional.habilitado', true)
                    ->when($locales !== null, fn ($q) => $q->whereIn('locales.id', $locales));
            })
            ->with(['servicios' => fn ($q) => $q->where('servicios.activo', true)->select('servicios.id', 'servicios.nombre')])
            ->orderBy('nombre')
            ->paginate($porPagina, ['*'], 'profesionales_page');

        $servicios = Servicio::query()->where('servicios.activo', true)
            ->whereHas('profesionales', function ($q) use ($locales, $capacidades) {
                $q->where('profesionales.activo', true)
                    ->when($capacidades->soloPropios(), fn ($q) => $q->whereKey($capacidades->profesional()?->id ?? 0))
                    ->whereHas('locales', function ($q) use ($locales) {
                        $q->where('locales.activo', true)
                            ->where('local_profesional.habilitado', true)
                            ->when($locales !== null, fn ($q) => $q->whereIn('locales.id', $locales));
                    });
            })
            ->orderBy('nombre')
            ->paginate($porPagina, ['servicios.id', 'servicios.nombre', 'servicios.duracion_min', 'servicios.precio'], 'servicios_page');

        $localesQuery = Local::query()->where('activo', true)->orderBy('nombre');
        if ($locales !== null) {
            $localesQuery->whereIn('id', $locales);
        }

        $tenant = $request->user()->tenant;
        $agenda = (array) (($tenant->configuracion ?? [])['agenda'] ?? []);
        $data = [
            'profesionales' => $this->pagina($profesionales, fn (Profesional $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'color' => null,
                'horario' => $p->horario['dias'] ?? [],
                'excepciones' => $p->horario['excepciones'] ?? [],
                'servicios' => $p->servicios->map(fn (Servicio $s) => ['id' => $s->id, 'nombre' => $s->nombre])->values()->all(),
            ]),
            'servicios' => $this->pagina($servicios, fn (Servicio $s) => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                'duracion_min' => $s->duracion_min,
                'precio' => $s->precio,
            ]),
            'locales' => $this->pagina($localesQuery->paginate($porPagina, ['id', 'nombre'], 'locales_page'), fn (Local $l) => [
                'id' => $l->id,
                'nombre' => $l->nombre,
            ]),
            'agenda' => [
                'modo_intervalo' => $agenda['modo_intervalo'] ?? ConfiguracionService::MODO_INTERVALO,
                'intervalo_min' => (int) ($agenda['intervalo_min'] ?? ConfiguracionService::INTERVALO_MIN),
                'zona_horaria' => $tenant->zona_horaria,
            ],
        ];

        if ($capacidades->puede('inventario')) {
            $productos = Producto::query()->where('activo', true)->orderBy('nombre')
                ->paginate($porPagina, ['id', 'nombre', 'precio as precio_venta', 'stock'], 'productos_page');
            $data['productos'] = $this->pagina($productos, fn (Producto $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'precio_venta' => (float) $p->precio_venta,
                'stock' => $p->stock,
            ]);
        }

        if ($capacidades->puede('clientes')) {
            $clientes = Cliente::query()
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->string('search')->trim()->value();
                    $q->where(fn ($q) => $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('apellido', 'like', "%{$search}%")
                        ->orWhere('telefono', 'like', "%{$search}%"));
                })
                ->orderBy('nombre')->paginate($porPagina, ['id', 'nombre', 'apellido', 'telefono'], 'clientes_page');
            $data['clientes'] = $this->pagina($clientes, fn (Cliente $c) => [
                'id' => $c->id,
                'nombre' => trim($c->nombre.' '.$c->apellido),
                'telefono' => $c->telefono,
            ]);
        }

        return response()->json(['data' => $data]);
    }

    private function pagina(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => collect($paginator->items())->map($map)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

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
