<?php

declare(strict_types=1);

namespace App\Http\Requests\Clientes;

use App\Models\Cliente;
use Illuminate\Foundation\Http\FormRequest;

class ClienteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        /*
         * La ficha dice que los opcionales viajan como null y no como "",
         * pero eso lo garantiza el formulario de HOY. Un cliente de la API
         * (o el propio formulario mañana) puede mandar cadena vacía, y
         * `nullable` no la considera nula: acabaría guardando un email "" que
         * luego rompe el `unique`.
         */
        foreach (['apellido', 'telefono', 'email', 'documento', 'fecha_nacimiento', 'notas'] as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }
    }

    public function rules(): array
    {
        $id = $this->route('cliente')?->id;

        return [
            // Texto libre, sin formato impuesto: la app real tiene nombres en
            // mayúsculas, minúsculas y de prueba. No se normaliza nada.
            'nombre' => ['required', 'string', 'max:150'],
            'apellido' => ['nullable', 'string', 'max:150'],

            /*
             * Igual de libre: conviven "9768657567", "+51 981 912 809" y
             * hasta "999". Lo que SÍ se exige es que dos fichas no compartan
             * el mismo número una vez normalizado — la reserva pública busca
             * por teléfono, y dos fichas de la misma persona parten su
             * historial en dos.
             */
            'telefono' => [
                'nullable', 'string', 'max:30',
                /*
                 * Regla propia y no `Rule::unique`: la columna que hay que
                 * mirar es `telefono_normalizado`, pero el formulario manda
                 * `telefono`. Un `unique` normal compararía el texto crudo y
                 * dejaría pasar "904 169 872" contra un "904169872" ya
                 * guardado. Así el error sigue cayendo en el campo `telefono`,
                 * que es donde el formulario lo pinta.
                 */
                function (string $attributo, ?string $valor, callable $fallar) use ($id): void {
                    $normalizado = Cliente::normalizarTelefono($valor);

                    if ($normalizado === null) {
                        return;
                    }

                    $existe = Cliente::where('telefono_normalizado', $normalizado)
                        ->when($id !== null, fn ($q) => $q->whereKeyNot($id))
                        ->exists();

                    if ($existe) {
                        $fallar('Ya existe un cliente con ese teléfono.');
                    }
                },
            ],

            'email' => ['nullable', 'email', 'max:150'],
            'documento' => ['nullable', 'string', 'max:30'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'Escribe un correo válido.',
            'fecha_nacimiento.before' => 'La fecha de nacimiento no puede ser hoy ni futura.',
        ];
    }
}
