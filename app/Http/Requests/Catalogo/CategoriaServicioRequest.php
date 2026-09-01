<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sirve para crear y para editar (`POST` con `_method=PUT`, porque sube
 * archivo y va en multipart).
 *
 * NO acepta `activo`: la ficha dice que la categoria no se puede desactivar.
 * La columna existe en la migracion y ahi se queda —quitarla obligaria a
 * re-migrar todos los tenants por nada—, pero ni entra ni sale.
 */
class CategoriaServicioRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('categoria')?->id;

        return [
            // `unique` a secas: dentro de la BD del tenant ya significa
            // "unico por negocio" — la base ES el negocio.
            'nombre' => [
                'required', 'string', 'max:100',
                Rule::unique('categoria_servicios', 'nombre')->ignore($id),
            ],
            'descripcion' => ['nullable', 'string', 'max:255'],
            /*
             * La columna es `char(7)` y la BD corre en modo estricto: un
             * `max:20` dejaba pasar `#5D87FF80` (hex con alfa) o
             * `rebeccapurple` y MySQL respondia "Data too long" — un 500
             * donde tocaba un 422.
             */
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'imagen' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],

            /*
             * No mandar `imagen` significa "dejala como esta" — si no, cada
             * edicion borraria la foto. Por eso quitarla necesita un campo
             * propio: la ausencia ya tiene otro significado.
             */
            'imagen_eliminar' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya existe una categoría con ese nombre.',
            'color.regex' => 'El color debe ser un hexadecimal como #4F46E5.',
            'imagen.max' => 'La imagen no debe superar los 2 MB.',
            'imagen.image' => 'El archivo debe ser una imagen.',
        ];
    }
}
