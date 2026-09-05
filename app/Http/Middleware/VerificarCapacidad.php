<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Capacidades;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `puede:clientes,gestionar` — la puerta de cada módulo del panel.
 *
 * Pregunta por CAPACIDAD, nunca por rol. Es lo que permite que el negocio
 * invente sus propios roles sin que haya que tocar un endpoint: cambia de dónde
 * sale la respuesta, no quién la hace.
 *
 * Va DESPUÉS de `tenancy.init`, porque los permisos viven en la base del
 * negocio. Y se resuelve una vez por petición: se guarda en el request para que
 * el controlador pueda leer `solo_propios` y el alcance de sedes sin volver a
 * consultar.
 */
class VerificarCapacidad
{
    public function handle(Request $request, Closure $next, string $modulo, string $nivel = 'ver'): Response
    {
        $capacidades = Capacidades::de($request->user());

        // Para que los controladores no repitan la consulta.
        $request->attributes->set('capacidades', $capacidades);

        if (! $capacidades->puede($modulo, $nivel)) {
            /*
             * 403 y no 404: el recurso existe y es de su negocio; lo que falta
             * es permiso. El 404 se reserva para lo que es de otro tenant, y
             * mezclarlos haría imposible distinguir «no tienes acceso» de «no
             * existe» — que es justo lo que el panel necesita para decidir si
             * enseña un aviso o una pantalla vacía.
             *
             * `codigo` para que el frontend lo distinga de la pared de cobro
             * (`suscripcion_vencida`), que se parece pero se arregla de otra
             * forma.
             */
            return response()->json([
                'message' => 'No tienes permiso para hacer esto.',
                'codigo' => 'sin_permiso',
            ], 403);
        }

        return $next($request);
    }
}
