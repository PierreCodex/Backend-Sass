<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Support\Rango;
use App\Support\RolesSistema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `clave` y `sistema` NO aparecen aqui a proposito, ni siquiera como campos
 * prohibidos: al leerse solo `validated()`, lo que no esta en las reglas no
 * llega al modelo. Aceptarlos dejaria a cualquiera fabricarse un rol con
 * `clave=dueno`, que es justo el que ninguna barandilla deja tocar.
 */
class RolRequest extends FormRequest
{
    /**
     * El rango se mira ANTES de validar.
     *
     * El controlador lo comprueba igual para las cinco acciones, pero un
     * FormRequest valida primero: sin esto, quien no es dueño recibiría el 422
     * del nombre repetido —y con él, la confirmación de que ese rol existe—
     * antes del 403.
     *
     * Aborta en vez de devolver `false`: el 403 de Laravel para un `authorize()`
     * negativo sale pelado, sin el `codigo` que el panel usa para distinguir la
     * falta de permiso de la pared de cobro. Aquí estaba escrita la regla por
     * segunda vez y por eso divergió — la de al lado sí llevaba `codigo` y
     * nadie lo notó, porque esta salta primero.
     */
    public function authorize(): bool
    {
        Rango::soloElAdminGeneral($this->user());

        return true;
    }

    public function rules(): array
    {
        $id = $this->route('rol')?->id;

        return [
            'nombre' => [
                'required', 'string', 'max:100',
                // UNIQUE simple: la tabla vive en la BD del negocio, asi que
                // «unico por tenant» sale solo.
                Rule::unique('roles', 'nombre')->ignore($id),
            ],

            'permisos' => [
                /*
                 * `bail` primero: sin el, el closure corre aunque `array` haya
                 * fallado y `array_keys("texto")` es un TypeError — un 500 en
                 * vez del 422 que ya estaba listo. Misma leccion que el
                 * telefono de clientes.
                 */
                'bail',
                'required', 'array',
                function (string $atributo, mixed $valor, callable $fallar): void {
                    $desconocidos = array_diff(array_keys($valor), RolesSistema::MODULOS);

                    if ($desconocidos !== []) {
                        $fallar('Estos módulos no existen: '.implode(', ', $desconocidos).'.');
                    }
                },
            ],

            // Dos niveles, no verbos CRUD. `null` es «sin acceso» y es un
            // valor legitimo, no un campo vacio.
            'permisos.*' => ['nullable', Rule::in(['ver', 'gestionar'])],

            'solo_propios' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya tienes un rol con ese nombre.',
            'permisos.*.in' => 'El nivel de acceso solo puede ser «ver» o «gestionar».',
        ];
    }
}
