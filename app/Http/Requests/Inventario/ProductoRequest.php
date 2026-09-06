<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventario;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear y editar un producto. JSON plano: este módulo no sube archivos.
 *
 * El payload habla en `precio_venta`/`precio_compra` y la tabla en
 * `precio`/`costo`; aquí se validan con los nombres del contrato y el service
 * los traduce, para que el 422 caiga en el campo que el formulario pintó.
 */
class ProductoRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('producto')?->id;

        return [
            'nombre' => [
                'required', 'string', 'max:150',
                $id === null
                    // Al CREAR los borrados no estorban: chocar con uno es
                    // legítimo y el service lo restaura, como en servicios.
                    ? Rule::unique('productos', 'nombre')->whereNull('deleted_at')
                    // Al EDITAR sí cuentan: el UNIQUE de MySQL no distingue el
                    // soft delete, e ignorarlos aquí cambia un 422 por un 500.
                    : Rule::unique('productos', 'nombre')->ignore($id),
            ],
            'descripcion' => ['nullable', 'string', 'max:255'],

            /*
             * `stock` SOLO al crear, y no por gusto: es la regla del módulo.
             * El stock se mueve con movimientos, que dejan rastro de quién y
             * por qué; dejar que un PUT lo reescriba sería un cambio de
             * inventario sin autor ni motivo. El formulario de edición ya pinta
             * el campo deshabilitado, pero eso es interfaz — quien llame al API
             * directamente encuentra la misma puerta cerrada, y de la única
             * forma que cierra de verdad: la clave no existe en `validated()`,
             * así que no hay nada que ignorar más adelante.
             */
            ...$id === null ? ['stock' => ['required', 'integer', 'min:0']] : [],

            // Ausente = el default 5 de la tabla, que es lo que promete el
            // contrato. El formulario lo manda siempre.
            'stock_minimo' => ['nullable', 'integer', 'min:0'],

            'precio_compra' => ['required', 'numeric', 'min:0'],
            'precio_venta' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya tienes un producto con ese nombre.',
        ];
    }
}
