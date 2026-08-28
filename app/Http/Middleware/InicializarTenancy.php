<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conecta la peticion a la BD del negocio.
 *
 * Corre DESPUES de `auth:sanctum`, no antes: el tenant se deriva del token
 * (decision del Sprint 0.B) y los tokens viven en la BD central, asi que no
 * hay tenant que resolver hasta que Sanctum ha identificado al usuario. Eso
 * matiza la regla del CLAUDE.md: el orden importaba cuando el tenant salia
 * del header, y el header dejo de mandar.
 *
 * A partir de aqui la conexion por defecto apunta a `tenant_{id}`, y
 * `storage/app/public` a la carpeta de ese negocio (bootstrappers de stancl).
 * Los modelos centrales se anclan con el trait CentralConnection.
 */
class InicializarTenancy
{
    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()->tenant;

        /*
         * Sin BD todavia: el correo esta verificado pero el job de
         * provisioning no ha terminado (o fallo). 503 y no 500 — es un
         * "vuelve en un momento", no un error del cliente. El panel puede
         * reintentar; el checklist de onboarding no depende de esto.
         */
        if ($tenant === null || ! $tenant->db_provisionada) {
            abort(503, 'Estamos preparando tu negocio. Vuelve a intentarlo en unos segundos.');
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }
}
