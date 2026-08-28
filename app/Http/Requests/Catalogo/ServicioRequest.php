<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogo;

use App\Models\Servicio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear y editar (POST con `_method=PUT`: multipart, porque sube imagenes).
 *
 * El `unique` de `nombre` ignora los borrados a proposito: si el negocio
 * borro "Corte a maquina" y lo vuelve a crear, el service RESTAURA la fila en
 * vez de insertar otra. Sin esta salvedad el dueno leeria "ya existe" mirando
 * una lista donde no esta.
 */
class ServicioRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        /*
         * El select manda "" para "Sin categoria" y el multipart lo entrega
         * como cadena vacia, que `nullable` no considera nula. Sin esto, un
         * servicio sin categoria da un 422 incomprensible.
         */
        foreach (['categoria_id', 'descripcion', 'max_sesiones'] as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }
    }

    public function rules(): array
    {
        $id = $this->route('servicio')?->id;

        return [
            'nombre' => [
                'required', 'string', 'max:150',
                Rule::unique('servicios', 'nombre')->ignore($id)->whereNull('deleted_at'),
            ],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'max:20'],
            'categoria_id' => ['nullable', 'integer', Rule::exists('categoria_servicios', 'id')],
            'tipo' => ['required', Rule::in(Servicio::TIPOS)],

            // Solo lo piden `sesiones` y `paquete`; en los demas ni se guarda.
            'max_sesiones' => [
                Rule::requiredIf(fn () => in_array($this->input('tipo'), Servicio::TIPOS_CON_SESIONES, true)),
                'nullable', 'integer', 'min:1',
            ],

            'precio' => ['required', 'numeric', 'min:0'],
            'duracion_min' => ['required', 'integer', 'min:1'],

            // La tabla lo pinta pero el formulario no lo trae: al crear entra
            // activo, y se apaga desde la tabla cuando exista ese control.
            'activo' => ['nullable', 'boolean'],
            'visible_publico' => ['nullable', 'boolean'],

            'imagen_principal' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],

            'galeria' => ['nullable', 'array', 'max:4'],
            'galeria.*' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],

            /*
             * IDs de `servicio_imagenes`, no URLs (divergencia con la ficha —
             * ver pendientes-contrato). Casar por URL se rompe en silencio si
             * cambia APP_URL o el disco, y lo que se pierde son fotos.
             */
            'galeria_conservar' => ['nullable', 'array'],
            'galeria_conservar.*' => ['integer'],

            'empleado_ids' => ['nullable', 'array'],
            'empleado_ids.*' => ['integer', Rule::exists('profesionales', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya existe un servicio con ese nombre.',
            'max_sesiones.required' => 'Indica cuántas sesiones incluye.',
            'galeria.max' => 'La galería admite hasta 4 imágenes.',
            'empleado_ids.*.exists' => 'Alguno de los profesionales seleccionados ya no existe.',
        ];
    }
}
