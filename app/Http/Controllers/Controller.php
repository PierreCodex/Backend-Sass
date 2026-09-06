<?php

namespace App\Http\Controllers;

use App\Support\Rango;
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
     * Gestionar roles y cuentas es solo del administrador general.
     *
     * Quien puede crear cuentas y repartir roles puede fabricarse un segundo
     * administrador general: se hace un rol con todo marcado y se lo asigna.
     * Por eso el preset de Administrador local trae `empleados: gestionar` y
     * aun asi esto se comprueba aparte — dar de alta gente y decidir que puede
     * hacer la gente son permisos distintos.
     *
     * La regla en si vive en `Rango`, que es el unico sitio donde esta escrita:
     * el `authorize()` de `RolRequest` la necesita ANTES de validar y este
     * atajo la necesita dentro de la accion.
     */
    protected function soloElAdminGeneral(Request $request): void
    {
        Rango::soloElAdminGeneral($request->user());
    }

    /**
     * Las sedes que alcanza quien pide, o `null` si son todas.
     *
     * Sale del middleware `puede`, que ya la resolvio: repetir la consulta aqui
     * seria pedirle a la base lo mismo dos veces por peticion.
     *
     * @return list<int>|null
     */
    protected function alcanceDeSedes(Request $request): ?array
    {
        return $request->attributes->get('capacidades')?->locales();
    }

    /**
     * 404 si la sede queda fuera de su alcance.
     *
     * 404 y no 403 a proposito: para esa persona ese local no existe, igual que
     * no existe el de otro negocio. Un 403 confirmaria que la sede esta ahi,
     * que es justo lo que el alcance viene a ocultar.
     */
    protected function exigirAlcance(Request $request, int $localId): void
    {
        $alcance = $this->alcanceDeSedes($request);

        if ($alcance !== null && ! in_array($localId, $alcance, true)) {
            abort(404);
        }
    }

    protected function porPagina(Request $request): int
    {
        $pedido = $request->integer('per_page', self::POR_PAGINA);

        return max(1, min($pedido, self::POR_PAGINA_MAX));
    }
}
