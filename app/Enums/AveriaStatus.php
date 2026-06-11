<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados de `averia.status` — capa de PRESENTACIÓN (Fase 1, 2026-06).
 *
 * Diccionario código→etiqueta+color. NO escribe ni migra datos: solo traduce el
 * número crudo a algo legible para el admin. Ver docs/prompts/14-estados-legibles-spec.md.
 *
 * Datos reales en prod (2026-06-11): solo existen 1 (2 filas), 2 (67.145), 4 (129).
 * Los códigos 3 y 5 se reservan en el catálogo pero no aparecen todavía.
 *
 * Fase 2 (NO implementada aquí): subclasificar el status=4 por sus notas
 * (SIN TENSIÓN → Bloqueada por tercero; DESMONTADO/RETIRADA → Retirada). Esa
 * normalización debe aplicarse SOLO sobre status=4 — nunca sobre las cerradas (2),
 * donde esos textos aparecen ~12.000 veces en incidencias ya resueltas.
 */
enum AveriaStatus: int
{
    case Abierta = 1;
    case Resuelta = 2;
    case EnCurso = 3;
    case Bloqueada = 4;
    case Retirada = 5;

    public function label(): string
    {
        return match ($this) {
            self::Abierta => 'Abierta',
            self::Resuelta => 'Resuelta',
            self::EnCurso => 'En curso',
            self::Bloqueada => 'Bloqueada / Otro',
            self::Retirada => 'Retirada / No procede',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Abierta => 'danger',
            self::Resuelta => 'success',
            self::EnCurso => 'info',
            self::Bloqueada => 'warning',
            self::Retirada => 'gray',
        };
    }

    /** ¿Cuenta como "pendiente"? Pendientes = 1 Abierta, 3 En curso, 4 Bloqueada. */
    public function isPendiente(): bool
    {
        return in_array($this, [self::Abierta, self::EnCurso, self::Bloqueada], true);
    }

    /** Códigos considerados pendientes (1, 3, 4). Excluye 2 Resuelta y 5 Retirada. */
    public static function pendientes(): array
    {
        return array_values(array_map(
            fn (self $s): int => $s->value,
            array_filter(self::cases(), fn (self $s): bool => $s->isPendiente()),
        ));
    }

    /**
     * Etiqueta legible tolerante a códigos fuera de catálogo: si aparece un valor
     * nuevo (p. ej. 6) no rompe la tabla — lo muestra como "Estado N".
     */
    public static function labelFor(int|string|null $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        return self::tryFrom((int) $value)?->label() ?? 'Estado '.(int) $value;
    }

    /** Color tolerante: códigos desconocidos / vacíos → gris (neutro). */
    public static function colorFor(int|string|null $value): string
    {
        if (! is_numeric($value)) {
            return 'gray';
        }

        return self::tryFrom((int) $value)?->color() ?? 'gray';
    }
}
