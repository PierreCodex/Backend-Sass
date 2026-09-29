<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\ServicioImagen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioService
{
    private const CARPETA = 'servicios';

    /** orden 0 = principal; 1..4 = galería. */
    private const ORDEN_PRINCIPAL = 0;

    /** Tope TOTAL de la galería, no por petición. */
    private const MAX_GALERIA = 4;

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

                /*
                 * Restaurar la fila es un truco para no chocar con el UNIQUE,
                 * pero para el dueño esto es un ALTA: rellenó un formulario en
                 * blanco. Devolverle la fila tal cual hacía que el servicio
                 * "nuevo" naciera con las fotos del viejo, con sus
                 * profesionales y desactivado si así lo había dejado.
                 *
                 * Se limpia lo que el formulario de alta no puede expresar: no
                 * manda `galeria_conservar` ni `empleado_ids` vacíos ni los
                 * flags, así que la ausencia no puede significar "conserva lo
                 * de antes". Un `empleado_ids` vacío acaba asignando a todos,
                 * como cualquier alta (abajo).
                 */
                $this->vaciarImagenes($borrado);

                $datos += [
                    'activo' => true,
                    'visible_publico' => true,
                    'empleado_ids' => [],
                ];

                $servicio = $this->rellenar($borrado, $datos);
            } else {
                $servicio = $this->rellenar(new Servicio, $datos);
            }

            /*
             * Lo nuevo nace ASIGNADO (Story 1.3): desde G-3 solo se agenda un
             * servicio con quien lo presta. El formulario del panel manda
             * siempre `empleado_ids`, `[]` si no se eligió a nadie, y un
             * servicio que naciera sin nadie no se podría agendar. En el ALTA,
             * ausente o vacío = lo prestan todos; el negocio recorta después.
             * Al editar, `[]` sigue significando «desasignar a todos».
             */
            if (($datos['empleado_ids'] ?? []) === []) {
                $datos['empleado_ids'] = Profesional::pluck('id')->all();
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
     * Las imágenes se quedan en disco mientras el servicio siga borrado. Si el
     * dueño vuelve a darlo de alta con el mismo nombre, `crear()` restaura la
     * fila pero limpia esas imágenes: para él es un alta, no una restauración.
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
        /*
         * Galería. `galeria_conservar` llega SOLO al editar: si no viene, no
         * se borra nada. Que el formulario no mande el campo no puede
         * significar "bórralo todo" — es el mismo error que reenviar la
         * imagen en multipart, pero destruyendo más.
         */
        $vaciar = filter_var($datos['galeria_vaciar'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $podar = $vaciar || array_key_exists('galeria_conservar', $datos);
        $conservar = $vaciar ? [] : ($datos['galeria_conservar'] ?? []);

        $nuevas = $archivos['galeria'] ?? [];

        /*
         * El tope de la galería es TOTAL, no por petición. El `max:4` del Form
         * Request solo cuenta los archivos que vienen en ESTA llamada: un
         * servicio con 4 fotos que reenviaba sus 4 ids en `galeria_conservar` y
         * subía 4 más acababa con 8, contra lo que promete el propio mensaje de
         * error. Se comprueba lo que va a QUEDAR: las que sobreviven a la poda
         * más las nuevas.
         *
         * Va antes de tocar disco para que un 422 no deje archivos sueltos: la
         * transacción revierte la base, pero no los ficheros ya escritos.
         */
        if ($nuevas !== []) {
            $sobreviven = $servicio->imagenes()
                ->where('orden', '>', self::ORDEN_PRINCIPAL)
                ->when($podar, fn ($q) => $q->whereIn('id', $conservar))
                ->count();

            if ($sobreviven + count($nuevas) > self::MAX_GALERIA) {
                throw ValidationException::withMessages([
                    'galeria' => 'La galería admite hasta '.self::MAX_GALERIA.' imágenes.',
                ]);
            }
        }

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

        if ($podar) {
            $servicio->imagenes()
                ->where('orden', '>', self::ORDEN_PRINCIPAL)
                ->whereNotIn('id', $conservar)
                ->get()
                ->each(function (ServicioImagen $img) {
                    $this->imagenes->borrar($img->ruta);
                    $img->delete();
                });
        }

        foreach ($nuevas as $archivo) {
            $siguiente = (int) $servicio->imagenes()->max('orden') + 1;

            ServicioImagen::create([
                'servicio_id' => $servicio->id,
                'ruta' => $this->imagenes->guardar($archivo, self::CARPETA),
                'orden' => $siguiente,
            ]);
        }
    }

    private function vaciarImagenes(Servicio $servicio): void
    {
        $servicio->imagenes()->get()->each(function (ServicioImagen $img) {
            $this->imagenes->borrar($img->ruta);
            $img->delete();
        });
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
