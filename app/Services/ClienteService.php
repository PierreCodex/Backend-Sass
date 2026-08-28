<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cliente;
use Illuminate\Support\Facades\DB;

class ClienteService
{
    /**
     * Alta desde el panel.
     *
     * Si el teléfono ya tuvo una ficha BORRADA, se restaura en vez de
     * insertar: el índice UNIQUE de `telefono_normalizado` no distingue los
     * borrados, así que un insert chocaría con un 500. Y restaurar es además
     * lo correcto — el cliente que vuelve es el mismo, con su historial.
     */
    public function crear(array $datos): Cliente
    {
        return DB::transaction(function () use ($datos) {
            $datos['telefono_normalizado'] = Cliente::normalizarTelefono($datos['telefono'] ?? null);

            $borrado = $datos['telefono_normalizado'] === null
                ? null
                : Cliente::onlyTrashed()
                    ->where('telefono_normalizado', $datos['telefono_normalizado'])
                    ->first();

            if ($borrado !== null) {
                $borrado->restore();
                $borrado->update($datos);

                return $this->cargar($borrado);
            }

            return $this->cargar(Cliente::create($datos));
        });
    }

    public function actualizar(Cliente $cliente, array $datos): Cliente
    {
        $datos['telefono_normalizado'] = Cliente::normalizarTelefono($datos['telefono'] ?? null);

        $cliente->update($datos);

        return $this->cargar($cliente);
    }

    /**
     * Soft delete: las citas lo referencian y borrarlo de verdad reescribiría
     * el historial. Desaparece del listado y del buscador, pero sus citas
     * pasadas siguen sabiendo de quién fueron.
     */
    public function eliminar(Cliente $cliente): void
    {
        $cliente->delete();
    }

    private function cargar(Cliente $cliente): Cliente
    {
        return $cliente->loadCount('citas')->loadMax('citas', 'starts_at');
    }
}
