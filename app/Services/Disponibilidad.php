<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cita;
use App\Models\Profesional;
use App\Models\Tenant;
use App\Support\Jornada;

/**
 * Cuándo puede empezar una cita. Un solo sitio, para el panel y para la tienda.
 *
 * En el backend anterior este cálculo vivía solo en la reserva pública, así que
 * desde el panel se podían crear citas solapadas: dos respuestas distintas a la
 * misma pregunta, y la que mandaba dependía de por dónde entrara la petición.
 *
 * La especificación ejecutable es `features/calendario/disponibilidad.ts`, que
 * hoy alimenta el selector del panel. Esto la reproduce entera **a propósito**,
 * incluidos los bordes: mientras las dos existan tienen que dar exactamente lo
 * mismo, o el formulario ofrecerá horas que el backend rechaza con un 422.
 *
 * Que la lista exista es lo que hace que agendar fuera de horario no sea
 * alcanzable, en vez de ser algo que haya que validar después.
 */
class Disponibilidad
{
    /** Cuando aún no hay nada configurado, la rejilla va de 15 en 15. */
    public const PASO_POR_DEFECTO = 15;

    public function __construct(private Tenant $negocio) {}

    /**
     * La jornada de un profesional en una fecha, con su precedencia de cinco
     * niveles (contrato §4):
     *
     * 1. Excepción no disponible → no atiende, aunque sea día laborable.
     * 2. Excepción disponible → **reemplaza** el horario de ese día (medio
     *    turno, refuerzo, cubrir a un compañero).
     * 3. Horario propio con el día activo → ese horario, con sus breaks.
     * 4. Horario propio con el día apagado → no laborable.
     * 5. Sin horario propio → **rige el del negocio**. Un profesional recién
     *    creado atiende; no se queda sin agenda esperando a que alguien le
     *    rellene siete días.
     *
     * @param  string  $fecha  `YYYY-MM-DD`
     */
    public function jornada(Profesional $profesional, string $fecha): Jornada
    {
        /*
         * Se lee la COLUMNA, no lo que emite el Resource.
         *
         * `ProfesionalResource` rellena siempre los siete días para que el
         * formulario tenga siete tarjetas que pintar, y los que faltan salen
         * con `activo: false`. Resolver la jornada sobre eso convertiría «no
         * tiene horario propio» en «tiene horario y no trabaja ningún día», que
         * es justo el nivel 5 al revés: el profesional nuevo se quedaría sin
         * agenda para siempre.
         */
        $horario = (array) ($profesional->horario ?? []);

        $excepcion = $this->excepcionDe($horario, $fecha);

        if ($excepcion !== null && ! ($excepcion['disponible'] ?? false)) {
            return Jornada::libre(Jornada::EXCEPCION_INACTIVA, $excepcion['nota'] ?? 'No disponible');
        }

        if ($excepcion !== null) {
            return Jornada::trabajando(
                Jornada::EXCEPCION,
                $excepcion['desde'] ?? ConfiguracionService::HORARIO_APERTURA,
                $excepcion['hasta'] ?? ConfiguracionService::HORARIO_CIERRE,
                // Una excepción NO arrastra los breaks del día habitual: es un
                // turno distinto, y sus descansos habrían sido otros.
                [],
                $excepcion['nota'] ?? null,
            );
        }

        $dias = (array) ($horario['dias'] ?? []);
        $delDia = $this->diaDe($dias, $fecha);

        if ($dias !== [] && ($delDia['activo'] ?? false)) {
            return Jornada::trabajando(
                Jornada::PERSONALIZADO,
                $delDia['desde'] ?? ConfiguracionService::HORARIO_APERTURA,
                $delDia['hasta'] ?? ConfiguracionService::HORARIO_CIERRE,
                $this->breaksDe($delDia),
            );
        }

        if ($dias !== []) {
            return Jornada::libre(Jornada::NO_LABORABLE);
        }

        return Jornada::trabajando(Jornada::NEGOCIO, $this->apertura(), $this->cierre());
    }

    /**
     * Las horas a las que cabe ENTERA una cita de `$duracionMin`.
     *
     * @param  string  $fecha  `YYYY-MM-DD`
     * @param  int|null  $exceptoCita  al editar, su propia cita no se estorba:
     *                                 sin esto la hora que ya tiene saldría
     *                                 ocupada y no podría volver a elegirla
     * @return list<string> horas «HH:MM», en orden
     */
    public function huecos(Profesional $profesional, string $fecha, int $duracionMin, ?int $exceptoCita = null): array
    {
        if ($duracionMin < 1) {
            return [];
        }

        $jornada = $this->jornada($profesional, $fecha);

        if (! $jornada->trabaja) {
            return [];
        }

        $apertura = self::aMinutos($jornada->desde);
        $cierre = self::aMinutos($jornada->hasta);
        $paso = $this->paso($duracionMin);

        $bloqueados = $this->bloqueados($jornada, $profesional, $fecha, $exceptoCita);

        /*
         * Los candidatos son la rejilla MÁS los bordes: el instante en que
         * termina cada cita y cada break.
         *
         * Sin los bordes, una duración que no case con el paso deja huecos
         * muertos — una cita de 50 min desde las 09:00 termina a las 09:50 y la
         * rejilla no volvería a ofrecer nada hasta las 10:00, con el
         * profesional libre y el hueco invisible.
         */
        $candidatos = [];

        for ($inicio = $apertura; $inicio + $duracionMin <= $cierre; $inicio += $paso) {
            $candidatos[$inicio] = true;
        }

        foreach ($bloqueados as [$ini, $fin]) {
            if ($fin >= $apertura && $fin + $duracionMin <= $cierre) {
                $candidatos[$fin] = true;
            }
        }

        $libres = [];

        foreach (array_keys($candidatos) as $inicio) {
            if (! $this->pisaAlgo($inicio, $inicio + $duracionMin, $bloqueados)) {
                $libres[] = $inicio;
            }
        }

        sort($libres);

        return array_map(self::aHora(...), $libres);
    }

    /**
     * Breaks y citas ya tomadas, en minutos desde medianoche.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function bloqueados(Jornada $jornada, Profesional $profesional, string $fecha, ?int $exceptoCita): array
    {
        $bloqueados = [];

        foreach ($jornada->breaks as $descanso) {
            $bloqueados[] = [self::aMinutos($descanso['desde']), self::aMinutos($descanso['hasta'])];
        }

        $citas = Cita::query()
            ->where('profesional_id', $profesional->id)
            ->whereDate('starts_at', $fecha)
            /*
             * Solo las canceladas liberan su hueco. Una `no_asistio` ocupó ese
             * rato igual que cualquier otra: el profesional estuvo esperando, y
             * reescribir el pasado para que parezca libre falsearía tanto la
             * agenda como los reportes que salgan de ella.
             */
            ->where('estado', '!=', 'cancelada')
            ->when($exceptoCita !== null, fn ($q) => $q->whereKeyNot($exceptoCita))
            ->get(['starts_at', 'ends_at']);

        foreach ($citas as $cita) {
            $bloqueados[] = [
                (int) $cita->starts_at->format('H') * 60 + (int) $cita->starts_at->format('i'),
                (int) $cita->ends_at->format('H') * 60 + (int) $cita->ends_at->format('i'),
            ];
        }

        return $bloqueados;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $bloqueados
     */
    private function pisaAlgo(int $inicio, int $fin, array $bloqueados): bool
    {
        foreach ($bloqueados as [$ini, $finBloque]) {
            // Intervalos semiabiertos [a,b): terminar justo cuando otro empieza
            // NO es pisarlo, y es el caso normal de dos citas seguidas.
            if ($inicio < $finBloque && $ini < $fin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cada cuántos minutos se ofrece un inicio. Lo decide el dueño en
     * Configuración: encadenar con la duración del servicio (agenda compacta) o
     * una rejilla fija (más flexible, pero fragmenta).
     */
    private function paso(int $duracionMin): int
    {
        $agenda = (array) ($this->configuracion()['agenda'] ?? []);
        $modo = $agenda['modo_intervalo'] ?? ConfiguracionService::MODO_INTERVALO;

        if ($modo === 'duracion_servicio') {
            return max(1, $duracionMin);
        }

        return max(1, (int) ($agenda['intervalo_min'] ?? ConfiguracionService::INTERVALO_MIN));
    }

    /**
     * @param  array<string, mixed>  $horario
     * @return array<string, mixed>|null
     */
    private function excepcionDe(array $horario, string $fecha): ?array
    {
        foreach ((array) ($horario['excepciones'] ?? []) as $excepcion) {
            if (($excepcion['fecha'] ?? null) === $fecha) {
                return (array) $excepcion;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $dias
     * @return array<string, mixed>
     */
    private function diaDe(array $dias, string $fecha): array
    {
        // ISO-8601: 1 = lunes, 7 = domingo. El horario del profesional se
        // guarda así, y `date('N')` habla el mismo idioma.
        $iso = (int) date('N', strtotime($fecha));

        foreach ($dias as $dia) {
            if ((int) ($dia['dia'] ?? 0) === $iso) {
                return (array) $dia;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $dia
     * @return list<array{desde: string, hasta: string}>
     */
    private function breaksDe(array $dia): array
    {
        $breaks = [];

        foreach ((array) ($dia['breaks'] ?? []) as $descanso) {
            if (isset($descanso['desde'], $descanso['hasta'])) {
                $breaks[] = ['desde' => $descanso['desde'], 'hasta' => $descanso['hasta']];
            }
        }

        return $breaks;
    }

    /**
     * @return array<string, mixed>
     */
    private function configuracion(): array
    {
        return (array) ($this->negocio->configuracion ?? []);
    }

    private function apertura(): string
    {
        return $this->configuracion()['horario']['apertura'] ?? ConfiguracionService::HORARIO_APERTURA;
    }

    private function cierre(): string
    {
        return $this->configuracion()['horario']['cierre'] ?? ConfiguracionService::HORARIO_CIERRE;
    }

    public static function aMinutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    public static function aHora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}
