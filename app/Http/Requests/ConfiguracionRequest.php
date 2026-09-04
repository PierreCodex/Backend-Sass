<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Acepta el objeto entero o solo un trozo.
 *
 * Todas las reglas van con `sometimes` porque la pantalla se parte en cuatro
 * secciones de Administración (Negocio, Agenda, Marca, Sitio público) y cada
 * una guarda lo suyo. Un objeto completo es un caso particular de esto, así
 * que un cliente que mande los 19 campos sigue funcionando igual.
 *
 * `slug` no aparece a propósito: lo fija el paso 1 del onboarding y después es
 * inmutable. Al leerse solo `validated()`, mandarlo no hace nada.
 */
class ConfiguracionRequest extends FormRequest
{
    /** Los que en multipart llegan como "" cuando el usuario los deja vacíos. */
    private const VACIABLES = [
        'descripcion', 'email', 'telefono', 'whatsapp', 'direccion',
        'informacion_adicional', 'latitud', 'longitud',
        'horario_apertura', 'horario_cierre', 'terminos_servicio',
    ];

    protected function prepareForValidation(): void
    {
        /*
         * En multipart no viaja `null`: un campo vaciado llega como cadena
         * vacía, y `nullable` no la considera nula. Sin esto se guardaría un
         * email "" que luego rompe cualquier envío, y una latitud "" que
         * revienta el DECIMAL.
         */
        foreach (self::VACIABLES as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            // Presente = obligatorio. Se puede no mandarlo (otra sección), pero
            // no se puede mandar vacío: es el nombre que ve el cliente.
            'nombre' => ['sometimes', 'required', 'string', 'max:150'],

            'descripcion' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:30'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],
            'direccion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'informacion_adicional' => ['sometimes', 'nullable', 'string', 'max:255'],

            'latitud' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],

            /*
             * `timezone` y no `max:80`: cada timestamp de negocio se interpreta
             * en esta zona (regla 6 del CLAUDE.md). Un texto libre deja meter
             * "Lima" o "GMT-5", que no son zonas IANA, y a partir de ahí todas
             * las horas de la agenda salen mal sin que nadie sepa por qué.
             */
            'zona_horaria' => ['sometimes', 'nullable', 'timezone'],

            'horario_apertura' => ['sometimes', 'nullable', 'date_format:H:i'],
            'horario_cierre' => ['sometimes', 'nullable', 'date_format:H:i'],

            /*
             * Hex de 6, no `max:20`: la columna es `char(7)` y con MySQL en
             * modo estricto cualquier otra cosa es un 500. Misma lección que
             * el `color` de categorías y servicios (revisión del Sprint 1).
             */
            'color_primario' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_secundario' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],

            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'cover' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:4096'],

            // La ausencia del archivo significa «déjalo como está», así que
            // quitarlo necesita bandera propia (lección del Sprint 1).
            'logo_eliminar' => ['sometimes', 'boolean'],
            'cover_eliminar' => ['sometimes', 'boolean'],

            'sitio_publico_activo' => ['sometimes', 'boolean'],
            'mostrar_en_marketplace' => ['sometimes', 'boolean'],
            'terminos_servicio' => ['sometimes', 'nullable', 'string', 'max:10000'],

            'agenda' => ['sometimes', 'array'],
            'agenda.modo_intervalo' => ['sometimes', 'in:duracion_servicio,fijo'],

            /*
             * Solo tiene sentido con la rejilla fija; con `duracion_servicio`
             * el paso lo pone el servicio. Se exige cuando hace falta en vez de
             * guardar un número que no se mira.
             */
            'agenda.intervalo_min' => [
                'exclude_unless:agenda.modo_intervalo,fijo',
                'required', 'integer', 'between:5,120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'zona_horaria.timezone' => 'Esa zona horaria no existe. Usa una de la lista (por ejemplo, America/Lima).',
            'color_primario.regex' => 'El color debe ser un hexadecimal de 6 dígitos, como #4f46e5.',
            'color_secundario.regex' => 'El color debe ser un hexadecimal de 6 dígitos, como #4f46e5.',
            'agenda.intervalo_min.required' => 'Con la rejilla fija hay que decir cada cuántos minutos se ofrece un turno.',
        ];
    }
}
