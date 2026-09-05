<?php

declare(strict_types=1);

namespace App\Http\Requests\Grupos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrupoRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('grupo')?->id;

        return [
            'nombre' => [
                'required', 'string', 'max:100',
                // UNIQUE simple: la tabla vive en la BD del negocio.
                Rule::unique('grupos', 'nombre')->ignore($id),
            ],

            /*
             * Las tres listas van con `sync()`: lo que no llega, se desasigna.
             * Un array vacío deja el grupo sin nada de esa categoría — que es
             * distinto de no mandar la clave, que lo deja como estaba.
             *
             * La pertenencia al negocio sale GRATIS: cada tenant tiene su base,
             * así que un `exists` normal ya no puede alcanzar filas de otro. En
             * el Laravel viejo hacía falta `exists:locales,id,negocio_id,{id}`.
             */
            'locales' => ['nullable', 'array'],
            'locales.*' => ['integer', Rule::exists('locales', 'id')],

            'profesionales' => ['nullable', 'array'],
            'profesionales.*' => ['integer', Rule::exists('profesionales', 'id')],

            'servicios' => ['nullable', 'array'],
            'servicios.*' => ['integer', Rule::exists('servicios', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya tienes un grupo con ese nombre.',
            'locales.*.exists' => 'Uno de los locales no existe en tu negocio.',
            'profesionales.*.exists' => 'Uno de los profesionales no existe en tu negocio.',
            'servicios.*.exists' => 'Uno de los servicios no existe en tu negocio.',
        ];
    }
}
