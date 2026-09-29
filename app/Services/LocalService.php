<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Local;
use App\Models\Profesional;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalService
{
    private const CARPETA = 'locales';

    public function __construct(private ImagenService $imagenes) {}

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{banner?: ?UploadedFile, logo?: ?UploadedFile}  $archivos
     */
    public function crear(array $datos, array $archivos): Local
    {
        return DB::transaction(function () use ($datos, $archivos) {
            $local = new Local;

            /*
             * El PRIMERO es el principal, y lo decide el backend. Si lo eligiera
             * el formulario, un negocio podria quedarse sin ninguno — y el
             * principal es el que la tienda publica usa por defecto y el unico
             * que no se puede borrar.
             *
             * Se decide ANTES de guardar y no despues: asignarlo luego dejaria
             * el atributo sin valor en el modelo que se devuelve, y la respuesta
             * saldria con `es_principal: null` en vez de `false`. El default de
             * la columna solo lo conoce la base, no el objeto que la escribio.
             */
            $local->es_principal = Local::where('es_principal', true)->doesntExist();

            $local = $this->rellenar($local, $datos, $archivos);

            /*
             * Lo nuevo nace ASIGNADO (Story 1.3): desde G-3 solo se agenda a
             * quien está habilitado en la sede de la cita. Una sede vacía
             * dejaría sin agenda al negocio de una sola sede —la primera pasa a
             * ser la de por defecto—, así que entran todos los profesionales
             * activos y el negocio recorta después.
             */
            $activos = Profesional::where('activo', true)->pluck('id');

            if ($activos->isNotEmpty()) {
                $local->profesionales()->syncWithoutDetaching(
                    $activos->mapWithKeys(fn ($id) => [$id => ['habilitado' => true]])->all(),
                );
            }

            return $local;
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{banner?: ?UploadedFile, logo?: ?UploadedFile}  $archivos
     */
    public function actualizar(Local $local, array $datos, array $archivos): Local
    {
        return DB::transaction(fn () => $this->rellenar($local, $datos, $archivos));
    }

    /**
     * El principal no se borra.
     *
     * Es la sede que la tienda publica toma por defecto y de la que cuelga el
     * negocio entero; borrarla deja al local sin cabeza y solo se arregla
     * entrando a la base. Si el negocio quiere cerrar esa sede, primero tiene
     * que haber otra — y eso es una decision suya, no un efecto lateral.
     */
    public function eliminar(Local $local): void
    {
        if ($local->es_principal) {
            throw ValidationException::withMessages([
                'local' => 'El local principal no se puede eliminar. Si vas a cerrarlo, primero convierte otro en principal.',
            ]);
        }

        DB::transaction(fn () => $local->delete());
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{banner?: ?UploadedFile, logo?: ?UploadedFile}  $archivos
     */
    private function rellenar(Local $local, array $datos, array $archivos): Local
    {
        $local->fill([
            'nombre' => $datos['nombre'],
            'direccion' => $datos['direccion'] ?? null,
            'descripcion_publica' => $datos['descripcion_publica'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'email' => $datos['email'] ?? null,
            'latitud' => $datos['latitud'] ?? null,
            'longitud' => $datos['longitud'] ?? null,
            'color' => $datos['color'] ?? null,

            /*
             * Se guarda como JSON aunque el contrato lo pida plano: el dia que
             * un negocio quiera «sabado hasta la 1, domingo cerrado» cabe sin
             * migrar la base de cada tenant.
             */
            'horario' => [
                'apertura' => $datos['horario_desde'] ?? null,
                'cierre' => $datos['horario_hasta'] ?? null,
            ],
        ]);

        $this->guardarImagenes($local, $datos, $archivos);

        $local->save();

        return $local;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{banner?: ?UploadedFile, logo?: ?UploadedFile}  $archivos
     */
    private function guardarImagenes(Local $local, array $datos, array $archivos): void
    {
        foreach (['banner', 'logo'] as $campo) {
            $archivo = $archivos[$campo] ?? null;

            if ($archivo !== null) {
                $anterior = $local->{$campo};
                $local->{$campo} = $this->imagenes->guardar($archivo, self::CARPETA);
                $this->imagenes->borrar($anterior);

                continue;
            }

            // Bandera explicita: no mandar el archivo ya significa «dejalo».
            if (filter_var($datos[$campo.'_eliminar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $this->imagenes->borrar($local->{$campo});
                $local->{$campo} = null;
            }
        }
    }
}
