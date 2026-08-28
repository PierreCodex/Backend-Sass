<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve las imágenes del catálogo de un negocio.
 *
 * No hay `public/storage`, y ponerlo no serviría: el
 * FilesystemTenancyBootstrapper deja los archivos en
 * `storage/tenant{id}/app/public/`, una carpeta por negocio, mientras que un
 * enlace simbólico solo puede apuntar a UNA. Con `Storage::url()` a secas
 * todos los tenants compartirían el espacio `/storage/...` y ganaría el que
 * estuviera detrás del enlace — no es un enlace que falta, es aislación rota.
 *
 * Por eso el tenant viaja en la ruta. No es un secreto (ya circula como
 * cabecera `X-Tenant`) y no autoriza nada: aquí solo elige de qué carpeta se
 * lee. La protección real es que los nombres son UUID.
 *
 * SIN sesión a propósito: la tienda pública (Sprint 5) muestra estas mismas
 * fotos a visitantes que no tienen cuenta.
 */
class ArchivoTenantController extends Controller
{
    public function __invoke(string $tenantId, string $ruta): StreamedResponse
    {
        /*
         * Traversal: `{ruta}` viene con `.*` para admitir la barra de
         * "categorias/x.webp", así que hay que mirarlo. Un `..` aquí leería
         * la carpeta de otro negocio, que es justo lo que este controlador
         * existe para impedir.
         */
        if (str_contains($ruta, '..')) {
            abort(404);
        }

        $tenant = Tenant::find($tenantId);

        if ($tenant === null || ! $tenant->db_provisionada) {
            abort(404);
        }

        return $tenant->run(function () use ($ruta): StreamedResponse {
            $disco = Storage::disk('public');

            if (! $disco->exists($ruta)) {
                abort(404);
            }

            return $disco->response($ruta, headers: [
                // Son inmutables: el nombre es un UUID y editar la imagen
                // crea otro archivo, nunca reescribe este.
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        });
    }
}
