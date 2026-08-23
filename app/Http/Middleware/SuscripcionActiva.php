<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de cobro: corta el panel cuando la suscripción venció.
 *
 * DECISIÓN de producto (2026-08-22): un negocio suspendido SÍ inicia sesión
 * y SÍ recibe token — si se le bloquea el login no puede pagar, y regularizar
 * acaba siendo una conversación de WhatsApp por cliente. Lo que se corta es
 * el resto del panel; Mi Plan y Soporte quedan fuera de este middleware (se
 * registran en `routes/api.php` antes de entrar aquí).
 *
 * Los estados de purga NO llegan a este punto: el login los rechaza, porque
 * ahí la BD del tenant está en camino de desaparecer.
 *
 * El 403 lleva `codigo` para que el frontend distinga esta pared de un
 * permiso insuficiente y pinte el aviso con el botón de pagar. La palabra
 * `vencida` es la del contrato (§1.6 de discrepancias mapea
 * suspendida/purga_pendiente → vencida); el estado interno no sale de aquí.
 */
class SuscripcionActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()->tenant;

        if ($tenant !== null && $tenant->estado === 'suspendida') {
            return response()->json([
                'message' => 'Tu suscripción venció. Renueva tu plan para volver a usar el panel.',
                'codigo' => 'suscripcion_vencida',
            ], 403);
        }

        return $next($request);
    }
}
