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
            'color' => ['nullable', 'string', 'max:20'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'imagen' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya existe una categoría con ese nombre.',
            'imagen.max' => 'La imagen no debe superar los 2 MB.',
            'imagen.image' => 'El archivo debe ser una imagen.',
        ];
    }
}
