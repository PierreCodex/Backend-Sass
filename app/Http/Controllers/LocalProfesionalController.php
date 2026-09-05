<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Locales\LocalProfesionalRequest;
use App\Http\Resources\LocalProfesionalResource;
use App\Models\Local;
use App\Models\Profesional;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Quién atiende en cada sede, y cómo.
 *
 * Sin `store` ni `destroy` a propósito: el `PUT` hace `syncWithoutDetaching`,
 * así que el mismo endpoint asigna por primera vez y edita. Para sacar a
 * alguien de una sede se apaga `habilitado` — borrar la fila se llevaría de
 * paso su nombre público y su perfil allí, que el negocio escribió a mano.
 */
class LocalProfesionalController extends Controller
{
    /**
     * UNA FILA POR CADA profesional del negocio, tenga o no asignación.
     *
     * Es lo que convierte la pantalla en una sola tabla con interruptores, en
     * vez de dos listas y un botón de añadir. Quien no trabaja aquí vuelve con
     * `habilitado: false` y el resto en null.
     */
    public function index(Local $local): AnonymousResourceCollection
    {
        $profesionales = Profesional::query()
            /*
             * `atiende` lo pide la ficha; `activo` lo añado porque una tabla de
             * «quién atiende en esta sede» no debería ofrecer a alguien dado de
             * baja. Anotado como divergencia.
             */
            ->where('atiende', true)
            ->where('activo', true)
            // Solo la fila pivote de ESTE local, si la hay.
            ->with(['locales' => fn ($q) => $q->whereKey($local->id)])
            ->orderBy('nombre')
            ->get();

        /*
         * El Resource lee `$this->pivot`, que Eloquent solo rellena cuando el
         * modelo viene de una relación muchos-a-muchos. Aquí la consulta sale
         * de `profesionales`, así que se le pone a mano — y queda en null para
         * quien todavía no trabaja en esta sede, que es el caso normal.
         */
        $profesionales->each(fn (Profesional $p) => $p->pivot = $p->locales->first()?->pivot);

        return LocalProfesionalResource::collection($profesionales);
    }

    public function update(
        LocalProfesionalRequest $request,
        Local $local,
        Profesional $profesional,
    ): LocalProfesionalResource {
        $local->profesionales()->syncWithoutDetaching([
            $profesional->id => $this->fila($request->validated()),
        ]);

        $profesional->pivot = $local->profesionales()
            ->whereKey($profesional->id)
            ->first()
            ?->pivot;

        return LocalProfesionalResource::make($profesional);
    }

    /**
     * Solo lo que llega.
     *
     * Misma regla que en Configuración: «clave ausente» significa «no lo
     * toques», nunca «ponlo a null». Importa aquí porque el interruptor de
     * Habilitado guarda al momento y manda una petición con ese campo casi
     * solo: si el resto se interpretara como vacío, encender a alguien le
     * borraría su nombre público y su perfil en esa sede.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function fila(array $datos): array
    {
        $fila = [];

        if (array_key_exists('habilitado', $datos)) {
            $fila['habilitado'] = (bool) $datos['habilitado'];
        }

        foreach (['nombre_publico', 'perfil'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $fila[$campo] = $datos[$campo];
            }
        }

        if (array_key_exists('horario', $datos)) {
            $fila['horario_apertura'] = $datos['horario']['apertura'] ?? null;
            $fila['horario_cierre'] = $datos['horario']['cierre'] ?? null;
        }

        return $fila;
    }
}
