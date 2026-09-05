<?php

declare(strict_types=1);

namespace App\Http\Requests\Locales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una sede. Multipart, porque sube banner y logo.
 *
 * `es_principal` NO se acepta: lo decide el backend. El primer local lo crea el
 * provisioning y el negocio no elige cual es el principal desde el formulario —
 * si pudiera, se quedaria sin ninguno con dos peticiones.
 */
class LocalRequest extends FormRequest
{
    /** En multipart un campo vaciado llega como "" y `nullable` no lo ve nulo. */
    private const VACIABLES = [
        'direccion', 'descripcion_publica', 'telefono', 'email',
        'latitud', 'longitud', 'horario_desde', 'horario_hasta',
    ];

    protected function prepareForValidation(): void
    {
        foreach (self::VACIABLES as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'descripcion_publica' => ['nullable', 'string', 'max:2000'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],

            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],

            // Hex de 6: la columna es `char(7)` y con MySQL estricto cualquier
            // otra cosa seria un 500 (misma leccion que servicios y categorias).
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],

            /*
             * Un solo rango, no por dia: a diferencia del profesional, el local
             * no aporta restriccion por dia de la semana (ficha § El horario del
             * local es un rango simple).
             */
            'horario_desde' => ['nullable', 'date_format:H:i'],
            'horario_hasta' => ['nullable', 'date_format:H:i', 'after:horario_desde'],

            'banner' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:4096'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],

            // La ausencia del archivo ya significa «dejalo como esta».
            'banner_eliminar' => ['nullable', 'boolean'],
            'logo_eliminar' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            /*
             * En los datos reales hay un local con «21:00 – 16:07»: la hora de
             * fin antes que la de inicio. La ficha lo señala y pide validarlo —
             * es un error de captura, no un horario nocturno, y de ahi saldrian
             * huecos imposibles cuando el Sprint 4 calcule disponibilidad.
             */
            'horario_hasta.after' => 'La hora de cierre debe ser posterior a la de apertura.',
            'color.regex' => 'El color debe ser un hexadecimal de 6 dígitos, como #4f46e5.',
        ];
    }
}
