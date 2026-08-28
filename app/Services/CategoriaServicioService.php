<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CategoriaServicio;
use Illuminate\Http\UploadedFile;

class CategoriaServicioService
{
    public function __construct(private ImagenService $imagenes) {}

    public function crear(array $datos, ?UploadedFile $imagen): CategoriaServicio
    {
        if ($imagen !== null) {
            $datos['imagen'] = $this->imagenes->guardar($imagen, 'categorias');
        }

        return CategoriaServicio::create($datos)->loadCount('servicios');
    }

    public function actualizar(CategoriaServicio $categoria, array $datos, ?UploadedFile $imagen): CategoriaServicio
    {
        /*
         * En multipart, NO mandar `imagen` significa "dejala como esta", no
         * "borrala": el formulario de edicion solo reenvia el archivo si el
         * usuario elige uno nuevo. Es el error clasico de este endpoint.
         */
        if ($imagen !== null) {
            $anterior = $categoria->imagen;
            $datos['imagen'] = $this->imagenes->guardar($imagen, 'categorias');
            $this->imagenes->borrar($anterior);
        }

        $categoria->update($datos);

        return $categoria->loadCount('servicios');
    }

    public function eliminar(CategoriaServicio $categoria): void
    {
        /*
         * Borrado REAL, sin soft delete: nada del historial apunta a una
         * categoria. Sus servicios quedan con `categoria_servicio_id` en NULL
         * por la FK (`nullOnDelete`) — no se borran.
         */
        $this->imagenes->borrar($categoria->imagen);

        $categoria->delete();
    }
}
