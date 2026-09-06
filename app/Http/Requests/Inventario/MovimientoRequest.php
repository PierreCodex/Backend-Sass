<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventario;

use App\Models\InventarioMovimiento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Una entrada o una salida de stock.
 *
 * `venta` y `ajuste` existen en el ENUM y NO se aceptan aquí (§2.8): la venta
 * la generará la cita con productos, y dejar que se registre a mano permitiría
 * descontar stock sin cita que lo respalde.
 */
class MovimientoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(InventarioMovimiento::TIPOS_MANUALES)],
            'cantidad' => ['required', 'integer', 'min:1'],

            // 150 y no 255: es el ancho de la columna. La ficha dice 255, pero
            // ante duda mandan las migraciones — y un `max` más largo que la
            // columna cambia un 422 legible por un 500 de MySQL.
            'motivo' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cantidad.min' => 'La cantidad tiene que ser al menos 1.',
        ];
    }
}
