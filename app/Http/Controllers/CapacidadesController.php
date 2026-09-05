<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Capacidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que puede hacer quien esta mirando, ya resuelto.
 *
 * Endpoint aparte y no dentro del `Usuario` de `/login` por una razon de
 * arquitectura: los permisos viven en la base del NEGOCIO, y `/login` y
 * `/user` se resuelven enteros en la central a proposito — conectar a la base
 * del tenant para cada uno tiene un coste y ademas falla con 503 mientras el
 * provisioning no ha terminado.
 *
 * Lo manda el backend YA RESUELTO, no la matriz cruda: si el menu de Next
 * dedujera los permisos por su cuenta acabaria habiendo dos matrices, y la que
 * manda es esta.
 *
 * Y conviene decirlo aunque sea obvio: **esconder una opcion del menu NO es
 * autorizacion**. El backend responde 403 igual. Esto sirve para no enseñar
 * puertas cerradas, no para cerrarlas.
 */
class CapacidadesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $capacidades = Capacidades::de($request->user());

        return response()->json(['data' => [
            // Los 14 modulos siempre, con `null` donde no hay acceso.
            'permisos' => $capacidades->permisos(),

            // Ve lo suyo y no lo de sus compañeros. No es un permiso: es sobre
            // QUIEN, no sobre QUE.
            'solo_propios' => $capacidades->soloPropios(),

            // `null` = todas las sedes. Una lista vacia significaria ninguna, y
            // por eso no se usa para «sin restriccion».
            'locales' => $capacidades->locales(),
        ]]);
    }
}
