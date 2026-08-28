<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Subida de imagenes del catalogo. Compartido: lo usaran Servicios,
 * Empleados y Locales.
 *
 * Disco PUBLICO a proposito. La regla 7 del CLAUDE.md (disco privado, URL
 * firmada) es para los comprobantes de pago, que nadie debe poder mirar. Una
 * foto de "Cortes de cabello" esta hecha para verse: acaba en la tienda
 * publica, donde el visitante no tiene sesion y una URL firmada la romperia.
 *
 * Lo demas de esa regla si se mantiene:
 *  - se re-encodifica con GD, asi que lo guardado es una imagen de verdad y
 *    no un archivo que solo se llamaba `.jpg` (`image` valida la cabecera,
 *    pero no impide que haya cosas pegadas detras)
 *  - el re-encode ademas tira los EXIF: las fotos de movil traen GPS, y no
 *    tiene ninguna gracia publicar la casa del dueno en su tienda
 *  - nombre UUID: el original lo escribe un desconocido y nunca toca el disco
 *
 * Nota de tenancy: `Storage::disk('public')` ya apunta a la carpeta de ESTE
 * negocio — el FilesystemTenancyBootstrapper sufija storage_path por tenant.
 * Aqui no hay que pasar el tenant a mano.
 */
class ImagenService
{
    public function guardar(UploadedFile $archivo, string $carpeta): string
    {
        $imagen = @imagecreatefromstring(file_get_contents($archivo->getRealPath()));

        if ($imagen === false) {
            // El validador `image` ya deberia haberlo parado; si llega aqui es
            // que el archivo miente sobre lo que es.
            throw new RuntimeException('El archivo no es una imagen valida.');
        }

        imagepalettetotruecolor($imagen);
        imagealphablending($imagen, true);
        imagesavealpha($imagen, true);

        $ruta = $carpeta.'/'.Str::uuid()->toString().'.webp';

        ob_start();
        imagewebp($imagen, null, 85);
        $binario = (string) ob_get_clean();

        imagedestroy($imagen);

        Storage::disk('public')->put($ruta, $binario);

        return $ruta;
    }

    /**
     * La URL publica de un archivo del tenant.
     *
     * NO se usa `Storage::disk('public')->url()`: eso genera
     * `/storage/{ruta}` sin segmento de tenant, y todos los negocios
     * compartirian el mismo espacio de URLs sobre una sola carpeta fisica.
     */
    public static function url(?string $ruta): ?string
    {
        if ($ruta === null) {
            return null;
        }

        return route('archivos.tenant', ['tenant' => tenant('id'), 'ruta' => $ruta]);
    }

    public function borrar(?string $ruta): void
    {
        if ($ruta !== null) {
            Storage::disk('public')->delete($ruta);
        }
    }
}
