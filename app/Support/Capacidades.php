<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Profesional;
use App\Models\User;
use App\Models\Usuario;

/**
 * Qué puede hacer, sobre quién y dónde, la persona que está pidiendo.
 *
 * Resuelve UNA vez por petición y responde a las tres preguntas por separado,
 * que es como estan modeladas:
 *
 *   `puede()`        → QUÉ toca        (`roles.permisos`, dos niveles)
 *   `soloPropios()`  → SOBRE QUIÉN     (`roles.solo_propios`)
 *   `profesional()`  → QUIÉN SOY YO    (su ficha de `profesionales`, o null)
 *   `locales()`      → DÓNDE           (el alcance de su cuenta)
 *
 * Se pregunta siempre por CAPACIDAD y nunca por rol. Es lo que permite que el
 * dueño invente «Recepcionista» o «Barbero con inventario» sin tocar un solo
 * endpoint: cambia de dónde sale la respuesta, no quién la hace.
 *
 * **Falla cerrado.** Sin cuenta en el negocio o sin rol, no puede nada. Un
 * permiso que se concede por omisión es el que nadie revisa.
 */
class Capacidades
{
    /** @var array<string, string|null> */
    private array $permisos;

    private bool $soloPropios;

    /** @var list<int>|null null = todas las sedes */
    private ?array $locales;

    private function __construct(private ?Usuario $cuenta)
    {
        $rol = $cuenta?->rol;

        $this->permisos = $rol?->permisosCompletos() ?? [];
        $this->soloPropios = (bool) $rol?->solo_propios;

        $this->locales = $cuenta === null || $cuenta->todos_los_locales
            ? null
            : $cuenta->locales->pluck('id')->all();
    }

    /**
     * La cuenta de ESTE negocio que corresponde al usuario central.
     *
     * Requiere tenancy inicializada: `usuarios` vive en la base del negocio.
     */
    public static function de(User $central): self
    {
        return self::deUsuarioCentral($central->id);
    }

    /**
     * Lo mismo, desde el id central a secas.
     *
     * Existe porque los services reciben ese id y no el modelo, y traer el
     * `User` solo para leerle el id sería una consulta a la central por cada
     * cita que se guarda. `de()` delega aquí: una sola resolución, dos puertas.
     */
    public static function deUsuarioCentral(int $centralUserId): self
    {
        return new self(
            Usuario::with(['rol', 'locales', 'profesional'])
                ->where('central_user_id', $centralUserId)
                ->first(),
        );
    }

    /**
     * `gestionar` incluye `ver`.
     *
     * Es lo que evita anotar cada listado dos veces: pedir `ver` en un index
     * deja pasar también a quien gestiona, que es lo que espera cualquiera.
     */
    public function puede(string $modulo, string $nivel = 'ver'): bool
    {
        $tiene = $this->permisos[$modulo] ?? null;

        if ($tiene === null) {
            return false;
        }

        return $nivel === 'ver' || $tiene === 'gestionar';
    }

    /** Ve lo suyo y no lo de sus compañeros. */
    public function soloPropios(): bool
    {
        return $this->soloPropios;
    }

    /**
     * Su ficha de profesional, o `null` si no presta servicios.
     *
     * El ÚNICO sitio que responde «quién soy yo como profesional». Cuenta y
     * ficha son cosas distintas desde que se separaron —quien entra al panel no
     * siempre atiende— y la unión es `profesionales.usuario_id`. Vivía resuelto
     * a mano en `CitaController`, y una segunda copia en el service habría sido
     * la tercera versión de la misma pregunta: justo el error que costó tres
     * escaladas en septiembre.
     *
     * `null` también cuando no hay cuenta en el negocio. Ojo: eso NO hace que
     * la regla de escritura falle cerrada. Sin cuenta no hay rol, así que
     * `soloPropios()` es `false` y `CitaService` ni siquiera llega a mirar la
     * ficha. Hoy no se alcanza porque `puede:` ya exige una cuenta con permiso;
     * cualquier camino que llegue al service sin cuenta (la reserva pública de
     * la Épica 6) tiene que cerrarlo antes.
     */
    public function profesional(): ?Profesional
    {
        return $this->cuenta?->profesional;
    }

    /**
     * Las sedes que alcanza, o `null` si son todas.
     *
     * `null` y no la lista completa a propósito: así quien consulta distingue
     * «sin restricción» de «restringido a estas», y una lista vacía significa
     * de verdad ninguna en vez de confundirse con «todas».
     *
     * @return list<int>|null
     */
    public function locales(): ?array
    {
        return $this->locales;
    }

    /** @return array<string, string|null> */
    public function permisos(): array
    {
        return $this->permisos;
    }
}
