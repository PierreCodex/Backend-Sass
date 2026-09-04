<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConfiguracionRequest;
use App\Http\Resources\ConfiguracionResource;
use App\Models\Tenant;
use App\Services\ConfiguracionService;
use Illuminate\Http\Request;

class ConfiguracionController extends Controller
{
    public function __construct(private ConfiguracionService $service) {}

    public function show(Request $request): ConfiguracionResource
    {
        return ConfiguracionResource::make($this->negocio($request))
            /*
             * La lista de zonas viaja con el negocio, fuera de `data`: la
             * pantalla necesita el valor y las opciones para pintar un select,
             * y un endpoint aparte serian dos peticiones para un campo. Mismo
             * criterio que `modulos` en el listado de roles.
             *
             * El PUT no la repite: quien esta guardando ya la tiene.
             */
            ->additional(['zonas_horarias' => ConfiguracionService::zonasHorarias()]);
    }

    public function update(ConfiguracionRequest $request): ConfiguracionResource
    {
        return ConfiguracionResource::make($this->service->actualizar(
            $this->negocio($request),
            $request->safe()->except(['logo', 'cover']),
            [
                'logo' => $request->file('logo'),
                'cover' => $request->file('cover'),
            ],
        ));
    }

    /**
     * El negocio SIEMPRE sale del token, nunca de nada que venga en la
     * petición.
     *
     * Es la prueba de aislación de este módulo, y es distinta a la de los
     * demás: aquí no hay `{id}` en la ruta ni base de datos separada que haga
     * de red. La fila de otro tenant está en la MISMA tabla, a un id de
     * distancia — si el tenant saliera del payload o de la cabecera, escribir
     * sobre el negocio del vecino sería un renglón.
     */
    private function negocio(Request $request): Tenant
    {
        return $request->user()->tenant;
    }
}
