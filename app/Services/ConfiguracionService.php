<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;

/**
 * Los datos del negocio viven en la BD CENTRAL (`tenants`), no en la del
 * tenant: la tienda pública los necesita sin abrir la base de cada negocio.
 *
 * Parte del trabajo es repartir un objeto plano entre columnas y el JSON
 * `configuracion`, y el resto es no romper nada al hacerlo.
 */
class ConfiguracionService
{
    /** Defaults de APLICACIÓN: el JSON puede estar vacío y la pantalla necesita algo (§1.6). */
    public const HORARIO_APERTURA = '09:00';

    public const HORARIO_CIERRE = '20:00';

    public const MODO_INTERVALO = 'duracion_servicio';

    public const INTERVALO_MIN = 15;

    /**
     * Las zonas horarias que el `PUT` acepta, para que el formulario sea un
     * select y no un campo de texto.
     *
     * Es EXACTAMENTE el mismo conjunto que valida la regla `timezone` de
     * Laravel (`timezone_identifiers_list()` con el grupo ALL). Que salgan de
     * la misma fuente es el punto: un select que ofrezca algo que el validador
     * rechaza es peor que no tener select.
     *
     * Sin etiquetas ni agrupación: los rótulos los pone el frontend
     * (convención del CLAUDE.md), y el desfase horario lo sabe calcular el
     * navegador con `Intl`.
     *
     * @return list<string>
     */
    public static function zonasHorarias(): array
    {
        return \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
    }

    /** Lo que sí tiene columna en `tenants`. */
    private const COLUMNAS = [
        'nombre', 'descripcion', 'email', 'telefono', 'whatsapp', 'direccion',
        'latitud', 'longitud', 'zona_horaria',
        'color_primario', 'color_secundario',
        'sitio_publico_activo', 'mostrar_en_marketplace', 'terminos_servicio',
    ];

    public function __construct(
        private ImagenService $imagenes,
        private OnboardingService $onboarding,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{logo?: ?UploadedFile, cover?: ?UploadedFile}  $archivos
     */
    public function actualizar(Tenant $tenant, array $datos, array $archivos): Tenant
    {
        /*
         * **Solo se toca lo que llega.** La pantalla está partida en cuatro
         * secciones y cada una manda lo suyo, así que un `fill()` a secas haría
         * que guardar el horario borrase el email y la dirección. En un PUT
         * parcial, «clave ausente» significa «no lo toques», nunca «ponlo a
         * null» — es la misma trampa que se llevó por delante el
         * `telefono_normalizado` de Clientes.
         *
         * Ojo con los booleanos: `false` es un valor que SÍ hay que escribir.
         * Por eso `array_key_exists` y no `isset` ni `?? null`.
         */
        foreach (self::COLUMNAS as $columna) {
            if (array_key_exists($columna, $datos)) {
                $tenant->{$columna} = $datos[$columna];
            }
        }

        $this->guardarImagenes($tenant, $datos, $archivos);

        $tenant->configuracion = $this->armarJson($tenant, $datos);

        $tenant->save();

        /*
         * Hook de onboarding: informar el horario del negocio marca el paso.
         * Va después del `save()` a propósito — si la escritura falla, el paso
         * no se da por hecho.
         */
        if ($this->traeHorario($datos)) {
            $this->onboarding->marcar($tenant, 'horario_local');
        }

        return $tenant;
    }

    /**
     * El JSON `configuracion`: lo que el contrato pide plano y la tabla no
     * tiene en columnas.
     *
     * Se MEZCLA sobre lo que ya había, por lo mismo que arriba: la sección de
     * Agenda no puede borrar el `informacion_adicional` que escribió la de
     * Negocio.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function armarJson(Tenant $tenant, array $datos): array
    {
        $json = $tenant->configuracion ?? [];

        if (array_key_exists('informacion_adicional', $datos)) {
            $json['informacion_adicional'] = $datos['informacion_adicional'];
        }

        foreach (['apertura' => 'horario_apertura', 'cierre' => 'horario_cierre'] as $clave => $campo) {
            if (array_key_exists($campo, $datos)) {
                $json['horario'][$clave] = $datos[$campo];
            }
        }

        if (array_key_exists('modo_intervalo', $datos['agenda'] ?? [])) {
            $json['agenda']['modo_intervalo'] = $datos['agenda']['modo_intervalo'];
        }

        if (array_key_exists('intervalo_min', $datos['agenda'] ?? [])) {
            $json['agenda']['intervalo_min'] = (int) $datos['agenda']['intervalo_min'];
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{logo?: ?UploadedFile, cover?: ?UploadedFile}  $archivos
     */
    private function guardarImagenes(Tenant $tenant, array $datos, array $archivos): void
    {
        foreach (['logo', 'cover'] as $campo) {
            $archivo = $archivos[$campo] ?? null;

            if ($archivo !== null) {
                $anterior = $tenant->{$campo};
                $tenant->{$campo} = $this->imagenes->guardar($archivo, 'negocio');
                $this->imagenes->borrar($anterior);

                continue;
            }

            // Bandera explícita: no mandar el archivo ya significa «déjalo».
            if (filter_var($datos[$campo.'_eliminar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $this->imagenes->borrar($tenant->{$campo});
                $tenant->{$campo} = null;
            }
        }
    }

    /** @param  array<string, mixed>  $datos */
    private function traeHorario(array $datos): bool
    {
        foreach (['horario_apertura', 'horario_cierre'] as $campo) {
            if (($datos[$campo] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
