<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DECISIÓN (tarea 4 del Sprint 0.B): el tenant SE DERIVA DEL TOKEN.
 * El header X-Tenant del BFF queda como pista redundante: si llega y no
 * coincide con el tenant del dueño del token → 404 (nunca 403 — regla 4:
 * no se revela que el recurso exista). Jamás se usa el header para elegir
 * el tenant.
 */
class ValidarTenantDelToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Tenant');

        if ($header !== null && $header !== $request->user()->tenant_id) {
            abort(404);
        }

        return $next($request);
    }
}
