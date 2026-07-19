<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catálogos compartidos del cierre de campo (M1). Mismos valores que el flujo
 * legacy (resources/views/livewire/tecnico/cierre.blade.php); centralizados
 * aquí para el wizard de ruta del día. El componente legacy se migrará a esta
 * clase cuando se toque por otra razón (evitar churn en flujo estable).
 */
final class CierreCatalogo
{
    public const RECAMBIOS_DISPONIBLES = [
        'Cable',
        'Conector',
        'Fuente alimentación',
        'Batería',
        'Pantalla',
        'Tarjeta SIM',
        'GPS',
        'Marquesina',
    ];

    /** @var array<int, array{value: string, label: string}> */
    public const TIEMPOS_DISPONIBLES = [
        ['value' => '5', 'label' => '5 min'],
        ['value' => '15', 'label' => '15 min'],
        ['value' => '30', 'label' => '30 min'],
        ['value' => '60', 'label' => '1 h'],
        ['value' => '90', 'label' => '1h 30'],
        ['value' => '120', 'label' => '2 h'],
    ];

    /** @return list<string> */
    public static function tiempoValues(): array
    {
        return array_column(self::TIEMPOS_DISPONIBLES, 'value');
    }

    /**
     * Minutos (string del dropdown) → horas para `correctivo.tiempo` legacy.
     * Mismo formato que mapTiempoToHoras() del cierre legacy: "0.5", "1", "1.5"
     * (número recortado, como string).
     */
    public static function tiempoToHoras(string $minutos): string
    {
        $mins = (int) $minutos;
        if ($mins === 0) {
            return '';
        }

        return rtrim(rtrim(number_format($mins / 60, 2, '.', ''), '0'), '.');
    }
}
