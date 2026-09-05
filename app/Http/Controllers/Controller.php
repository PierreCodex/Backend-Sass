<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Tamaño de página por defecto de todos los index. */
    protected const POR_PAGINA = 10;

    /**
     * Techo duro: nadie pide más de esto, venga lo que venga en la query.
     *
     * 200 y no 100 porque el helper `all()` del frontend —el que llena los
     * selects— pide justo `per_page: 200`. Lo que había que matar era el caso
     * SIN límite, no la diferencia entre 100 y 200: un techo bajo no protege
     * de nada extra y trunca esos selects en silencio, que es peor que un
     * error. Ojo: `all()` sigue siendo frágil por encima de 200 — eso se
     * arregla con un autocompletado paginado, no subiendo el número.
     */
    protected const POR_PAGINA_MAX = 200;

    /**
     * `per_page` acotado.
     *
     * Sin tope, `?per_page=-1` volcaba la tabla entera: `Builder::limit()`
     * ignora los valores negativos en silencio, así que la consulta salía SIN
     * `LIMIT` y un negocio con 50.000 clientes los serializaba todos —
     * subconsultas de `withCount` incluidas— en una sola respuesta. No hace
     * falta mala fe para dispararlo; basta un cliente de la API con un off-by-one.
     */
    /**
     * Gestionar roles y cuentas es solo del dueno.
     *
     * Quien puede crear cuentas y repartir roles puede fabricarse un segundo
     * dueno: se hace un rol con todo marcado y se lo asigna. Por eso el preset
     * de Administrador trae `empleados: gestionar` y aun asi esto se comprueba
     * aparte — dar de alta gente y decidir que puede hacer la gente son
     * permisos distintos.
     *
     * 403 y no 404: el recurso existe y es del negocio de quien pregunta; lo
     * que falta es rango. El 404 se reserva para lo que es de otro tenant.
     */
    protected function soloElDueno(Request $request): void
    {
        if ($request->user()->rol !== 'dueno') {
            abort(403, 'Solo el dueño del negocio puede hacer esto.');
        }
    }

    protected function porPagina(Request $request): int
    {
        $pedido = $request->integer('per_page', self::POR_PAGINA);

        return max(1, min($pedido, self::POR_PAGINA_MAX));
    }
}
