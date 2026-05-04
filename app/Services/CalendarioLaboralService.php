<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Calendario laboral Winfin: horarios oficina/campo y festivos Madrid.
 *
 * Service puro, stateless y sin BD. Lo consumiran los bloques posteriores de
 * planificacion mensual, decisiones del dia y calendario operacional.
 */
final class CalendarioLaboralService
{
    public const TZ = 'Europe/Madrid';

    /**
     * Slots horarios por dia de semana (Carbon::MONDAY=1 .. SUNDAY=7).
     * Cada slot usa formato ['HH:MM', 'HH:MM']. Dia sin slots = no laborable.
     *
     * Total semanal: 8+8+9+9+6 = 40h.
     */
    public const HORARIOS = [
        CarbonInterface::MONDAY => [['07:00', '15:00']],
        CarbonInterface::TUESDAY => [['07:00', '15:00']],
        CarbonInterface::WEDNESDAY => [['08:00', '14:00'], ['15:00', '18:00']],
        CarbonInterface::THURSDAY => [['08:00', '14:00'], ['15:00', '18:00']],
        CarbonInterface::FRIDAY => [['08:00', '14:00']],
        CarbonInterface::SATURDAY => [],
        CarbonInterface::SUNDAY => [],
    ];

    /**
     * Festivos Madrid (nacionales + Comunidad). Anios 2026 y 2027 hardcoded.
     * Festivos locales por municipio no incluidos por decision conservadora.
     *
     * @var array<int, list<string>>
     */
    public const FESTIVOS = [
        2026 => [
            '2026-01-01', // Anio Nuevo
            '2026-01-06', // Reyes
            '2026-04-02', // Jueves Santo
            '2026-04-03', // Viernes Santo
            '2026-05-01', // Dia del Trabajador
            '2026-05-02', // Comunidad de Madrid
            '2026-08-15', // Asuncion
            '2026-10-12', // Hispanidad
            '2026-11-01', // Todos los Santos
            '2026-12-06', // Constitucion
            '2026-12-08', // Inmaculada
            '2026-12-25', // Navidad
        ],
        2027 => [
            '2027-01-01', // Anio Nuevo
            '2027-01-06', // Reyes
            '2027-03-25', // Jueves Santo
            '2027-03-26', // Viernes Santo
            '2027-05-01', // Dia del Trabajador
            '2027-05-02', // Comunidad de Madrid
            '2027-08-15', // Asuncion
            '2027-10-12', // Hispanidad
            '2027-11-01', // Todos los Santos
            '2027-12-06', // Constitucion
            '2027-12-08', // Inmaculada
            '2027-12-25', // Navidad
        ],
    ];

    public function isFestivo(CarbonInterface $date): bool
    {
        $normalizedDate = $this->normalize($date);
        $year = $normalizedDate->year;
        $isoDate = $normalizedDate->format('Y-m-d');

        return isset(self::FESTIVOS[$year]) && in_array($isoDate, self::FESTIVOS[$year], true);
    }

    public function isLaborable(CarbonInterface $date): bool
    {
        $normalizedDate = $this->normalize($date);
        $slots = self::HORARIOS[$normalizedDate->dayOfWeekIso] ?? [];

        return $slots !== [] && ! $this->isFestivo($normalizedDate);
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    public function slotsLaborables(CarbonInterface $date): array
    {
        if (! $this->isLaborable($date)) {
            return [];
        }

        $normalizedDate = $this->normalize($date);
        $slots = self::HORARIOS[$normalizedDate->dayOfWeekIso] ?? [];

        return array_map(
            static fn (array $slot): array => ['start' => $slot[0], 'end' => $slot[1]],
            $slots,
        );
    }

    public function horasLaborables(CarbonInterface $date): float
    {
        $total = 0.0;

        foreach ($this->slotsLaborables($date) as $slot) {
            [$startHour, $startMinute] = explode(':', $slot['start']);
            [$endHour, $endMinute] = explode(':', $slot['end']);

            $start = ((int) $startHour * 60) + (int) $startMinute;
            $end = ((int) $endHour * 60) + (int) $endMinute;

            $total += ($end - $start) / 60.0;
        }

        return $total;
    }

    /**
     * Devuelve la misma fecha si ya es laborable, o el proximo dia habil.
     */
    public function proximoDiaLaborable(CarbonInterface $date): CarbonImmutable
    {
        $normalizedDate = $this->normalize($date);

        for ($i = 0; $i < 14; $i++) {
            if ($this->isLaborable($normalizedDate)) {
                return $normalizedDate;
            }

            $normalizedDate = $normalizedDate->addDay();
        }

        throw new RuntimeException(
            sprintf(
                'No se encontro dia laborable en 14 dias desde %s. Festivos mal configurados?',
                $date->format('Y-m-d'),
            ),
        );
    }

    /**
     * Cuenta dias laborables entre $start y $end inclusivos.
     */
    public function diasLaborablesEntre(CarbonInterface $start, CarbonInterface $end): int
    {
        $normalizedStart = $this->normalize($start);
        $normalizedEnd = $this->normalize($end);

        if ($normalizedEnd->lt($normalizedStart)) {
            return 0;
        }

        $count = 0;

        for ($date = $normalizedStart; $date->lte($normalizedEnd); $date = $date->addDay()) {
            if ($this->isLaborable($date)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Suma horas laborables entre $start y $end inclusivos.
     */
    public function horasLaborablesEntre(CarbonInterface $start, CarbonInterface $end): float
    {
        $normalizedStart = $this->normalize($start);
        $normalizedEnd = $this->normalize($end);

        if ($normalizedEnd->lt($normalizedStart)) {
            return 0.0;
        }

        $total = 0.0;

        for ($date = $normalizedStart; $date->lte($normalizedEnd); $date = $date->addDay()) {
            $total += $this->horasLaborables($date);
        }

        return $total;
    }

    /**
     * Normaliza a fecha local Madrid para no mezclar hora/tz con calendario laboral.
     */
    private function normalize(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone(self::TZ)->startOfDay();
    }
}
