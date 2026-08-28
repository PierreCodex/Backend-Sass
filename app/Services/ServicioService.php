<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Servicio;
use App\Models\ServicioImagen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ServicioService
{
    private const CARPETA = 'servicios';

    /** orden 0 = principal; 1..4 = galería. */
    private const ORDEN_PRINCIPAL = 0;

    public function __construct(private ImagenService $imagenes) {}

    /**
     * Crear, con una salvedad importante.
     *
     * `servicios.nombre` es UNIQUE y el índice NO distingue los borrados, así
     * que insertar un nombre que ya tuvo un servicio eliminado chocaría contra
     * la fila que sigue ahí. En vez de fallar, se RESTAURA esa fila con los
     * datos nuevos: es lo que el dueño quiere de verdad cuando vuelve a
     * ofrecer un servicio que había quitado.
     *
     * No reescribe el pasado: `cita_servicio` congela precio y duración al
     * reservar, así que las citas viejas conservan lo que costaron.
     */
    public function crear(array $datos, array $archivos): Servicio
    {
        return DB::transaction(function () use ($datos, $archivos) {
            $borrado = Servicio::onlyTrashed()->where('nombre', $datos['nombre'])->first();

            if ($borrado !== null) {
                $borrado->restore();
                $servicio = $this->rellenar($borrado, $datos);
            } else {
                $servicio = $this->rellenar(new Servicio, $datos);
            }

            $this->sincronizarProfesionales($servicio, $datos);
            $this->guardarImagenes($servicio, $archivos, $datos);

            return $this->cargar($servicio);
        });
    }

    public function actualizar(Servicio $servicio, array $datos, array $archivos): Servicio
    {
        return DB::transaction(function () use ($servicio, $datos, $archivos) {
            $this->rellenar($servicio, $datos);
            $this->sincronizarProfesionales($servicio, $datos);
            $this->guardarImagenes($servicio, $archivos, $datos);

            return $this->cargar($servicio);
        });
    }

    /**
     * Soft delete SIEMPRE, también con citas.
     *
     * La ficha preguntaba si con citas asociadas debía responder 409. No: el
     * dueño que deja de ofrecer un servicio tiene derecho a quitarlo de su
     * catálogo, y bloquearlo lo dejaría con una lista que no puede limpiar.
     * El historial queda intacto porque la fila no desaparece.
     *
     * Las imágenes tampoco se borran del disco: si mañana restaura el
     * servicio, vuelve entero.
     */
    public function eliminar(Servicio $servicio): void
    {
        $servicio->delete();
    }

    private function rellenar(Servicio $servicio, array $datos): Servicio
    {
        $servicio->fill([
            'categoria_servicio_id' => $datos['categoria_id'] ?? null,
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'color' => $datos['color'],
            'tipo' => $datos['tipo'],
            // Solo lo usan `sesiones` y `paquete`: si el tipo cambia a normal,
            // el valor viejo se limpia en vez de quedarse de fantasma.
            'max_sesiones' => in_array($datos['tipo'], Servicio::TIPOS_CON_SESIONES, true)
                ? ($datos['max_sesiones'] ?? null)
                : null,
            'precio' => $datos['precio'],
            'duracion_min' => $datos['duracion_min'],
        ]);

        foreach (['activo', 'visible_publico'] as $flag) {
            if (array_key_exists($flag, $datos)) {
                $servicio->{$flag} = (bool) $datos[$flag];
            }
        }

        $servicio->save();

        return $servicio;
    }

    private function sincronizarProfesionales(Servicio $servicio, array $datos): void
    {
        // Ausente = no se toca (el formulario podría no enviarlo);
        // presente y vacío = se desasignan todos.
        if (! array_key_exists('empleado_ids', $datos)) {
            return;
        }

        $servicio->profesionales()->sync($datos['empleado_ids'] ?? []);
    }

    /**
     * @param  array{imagen_principal?: ?UploadedFile, galeria?: array<int, UploadedFile>}  $archivos
     */
    private function guardarImagenes(Servicio $servicio, array $archivos, array $datos): void
    {
        $principal = $archivos['imagen_principal'] ?? null;

        if ($principal !== null) {
            // Una sola principal: la anterior se va del disco y de la tabla.
            $anterior = $servicio->imagenes()->where('orden', self::ORDEN_PRINCIPAL)->first();

            if ($anterior !== null) {
                $this->imagenes->borrar($anterior->ruta);
                $anterior->delete();
            }

            ServicioImagen::create([
                'servicio_id' => $servicio->id,
                'ruta' => $this->imagenes->guardar($principal, self::CARPETA),
                'orden' => self::ORDEN_PRINCIPAL,
            ]);
        } elseif (filter_var($datos['imagen_principal_eliminar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            // Bandera propia: no mandar el archivo ya significa "dejala".
            $servicio->imagenes()->where('orden', self::ORDEN_PRINCIPAL)->get()
                ->each(function (ServicioImagen $img) {
                    $this->imagenes->borrar($img->ruta);
                    $img->delete();
                });
        }

        /*
         * Galería. `galeria_conservar` llega SOLO al editar: si no viene, no
         * se borra nada. Que el formulario no mande el campo no puede
         * significar "bórralo todo" — es el mismo error que reenviar la
         * imagen en multipart, pero destruyendo más.
         */
        $vaciar = filter_var($datos['galeria_vaciar'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($vaciar || array_key_exists('galeria_conservar', $datos)) {
            $conservar = $vaciar ? [] : ($datos['galeria_conservar'] ?? []);

            $servicio->imagenes()
                ->where('orden', '>', self::ORDEN_PRINCIPAL)
                ->whereNotIn('id', $conservar)
                ->get()
                ->each(function (ServicioImagen $img) {
                    $this->imagenes->borrar($img->ruta);
                    $img->delete();
                });
        }

        foreach ($archivos['galeria'] ?? [] as $archivo) {
            $siguiente = (int) $servicio->imagenes()->max('orden') + 1;

            ServicioImagen::create([
                'servicio_id' => $servicio->id,
                'ruta' => $this->imagenes->guardar($archivo, self::CARPETA),
                'orden' => $siguiente,
            ]);
        }
    }

    private function cargar(Servicio $servicio): Servicio
    {
        /*
         * `refresh()` y no solo `load()`: los defaults de columnas que el
         * formulario no envía (`activo`, `visible_publico`) los pone MySQL, y
         * el modelo recién guardado los tiene en null hasta releerlo. Sin
         * esto, crear un servicio devuelve `activo: null` y la tabla lo pinta
         * como inactivo recién nacido.
         */
        return $servicio->refresh()->load(['categoria', 'imagenes', 'profesionales']);
    }
}
