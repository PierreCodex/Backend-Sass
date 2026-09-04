<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Rol;
use Illuminate\Validation\ValidationException;

class RolService
{
    public function crear(array $datos): Rol
    {
        $rol = Rol::create($this->normalizar($datos) + [
            // Ninguno de los dos se acepta del cliente. Un rol que inventa el
            // negocio nunca es de sistema y nunca tiene clave: la clave es lo
            // que el backend usa para reconocer a los tres suyos.
            'clave' => null,
            'sistema' => false,
        ]);

        return $rol->loadCount('profesionales');
    }

    public function actualizar(Rol $rol, array $datos): Rol
    {
        $rol->update($this->normalizar($datos));

        return $rol->loadCount('profesionales');
    }

    public function eliminar(Rol $rol): void
    {
        /*
         * El 422 amable ANTES de que hable la base. La FK es
         * `restrictOnDelete`, asi que el borrado fallaria igual — pero como un
         * 500 con un error de integridad, no como un mensaje que diga a cuanta
         * gente hay que reasignar primero.
         */
        $enUso = $rol->profesionales()->count();

        if ($enUso > 0) {
            throw ValidationException::withMessages([
                'rol' => "Este rol lo usan {$enUso} persona(s). Cámbiales el rol antes de borrarlo.",
            ]);
        }

        $rol->delete();
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizar(array $datos): array
    {
        return [
            'nombre' => $datos['nombre'],

            /*
             * Se guardan solo los modulos CON permiso, no los catorce con
             * nulls. Asi el JSON del negocio tiene la misma forma que el de
             * los presets, y un modulo nuevo no aparece «denegado
             * explicitamente» en roles creados antes de que existiera —
             * distincion que importa el dia que se decida el valor por defecto
             * de un modulo recien lanzado.
             */
            'permisos' => array_filter(
                $datos['permisos'],
                static fn ($nivel) => $nivel !== null,
            ),

            'solo_propios' => (bool) ($datos['solo_propios'] ?? false),

            /*
             * Marca que el negocio lo tocó. El script que añada modulos a los
             * presets solo pisa los que siguen en NULL: a quien personalizó su
             * rol no se le deshace la decision a su espalda.
             */
            'editado_at' => now(),
        ];
    }
}
