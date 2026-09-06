<?php

declare(strict_types=1);

namespace App\Support;

/**
 * La jornada de UN profesional en UNA fecha, ya resuelta.
 *
 * Guarda además de dónde salió (`origen`), y no por curiosidad: la pantalla
 * dice cosas distintas según el caso —«no atiende este día» no es lo mismo que
 * la nota de un permiso médico—, y sin el origen habría que deducirlo mirando
 * los mismos datos otra vez, en el cliente, con otro criterio.
 */
final class Jornada
{
    /** Su propio horario para ese día de la semana. */
    public const PERSONALIZADO = 'personalizado';

    /** Una excepción con horario, que reemplaza al habitual. */
    public const EXCEPCION = 'excepcion';

    /** Una excepción que lo deja fuera: permiso, vacaciones. */
    public const EXCEPCION_INACTIVA = 'excepcion_inactiva';

    /** Tiene horario propio y ese día no lo trabaja. */
    public const NO_LABORABLE = 'no_laborable';

    /** No tiene horario propio: rige el del negocio. */
    public const NEGOCIO = 'negocio';

    /**
     * @param  list<array{desde: string, hasta: string}>  $breaks
     */
    public function __construct(
        public readonly bool $trabaja,
        public readonly ?string $desde,
        public readonly ?string $hasta,
        public readonly array $breaks,
        public readonly ?string $nota,
        public readonly string $origen,
    ) {}

    public static function libre(string $origen, ?string $nota = null): self
    {
        return new self(false, null, null, [], $nota, $origen);
    }

    /**
     * @param  list<array{desde: string, hasta: string}>  $breaks
     */
    public static function trabajando(string $origen, string $desde, string $hasta, array $breaks = [], ?string $nota = null): self
    {
        return new self(true, $desde, $hasta, $breaks, $nota, $origen);
    }

    /** ¿Atiende a esa hora («HH:MM»)? Ni fuera del turno ni dentro de un break. */
    public function atiendeA(string $hora): bool
    {
        if (! $this->trabaja || $hora < $this->desde || $hora >= $this->hasta) {
            return false;
        }

        foreach ($this->breaks as $descanso) {
            if ($hora >= $descanso['desde'] && $hora < $descanso['hasta']) {
                return false;
            }
        }

        return true;
    }
}
