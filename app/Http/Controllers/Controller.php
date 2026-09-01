<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Tamaño de página por defecto de todos los index. */
    protected const POR_PAGINA = 10;

    /** Techo duro: nadie pide más de esto, venga lo que venga en la query. */
    protected const POR_PAGINA_MAX = 100;

    /**
     * `per_page` acotado.
     *
     * Sin tope, `?per_page=-1` volcaba la tabla entera: `Builder::limit()`
     * ignora los valores negativos en silencio, así que la consulta salía SIN
     * `LIMIT` y un negocio con 50.000 clientes los serializaba todos —
     * subconsultas de `withCount` incluidas— en una sola respuesta. No hace
     * falta mala fe para dispararlo; basta un cliente de la API con un off-by-one.
     */
    protected function porPagina(Request $request): int
    {
        $pedido = $request->integer('per_page', self::POR_PAGINA);

        return max(1, min($pedido, self::POR_PAGINA_MAX));
    }
}
