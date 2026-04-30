<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast para resolver mojibake en columnas de tablas legacy.
 *
 * Las tablas legacy almacenan texto con charset físico latin1, pero la
 * conexión MySQL de Laravel usa utf8mb4. Resultado: bytes latin1 leídos como
 * UTF-8 producen mojibake al leer (p. ej. "Móstoles" se ve como "Móstoles"),
 * y al escribir UTF-8 directamente se rompen los datos para la app vieja.
 *
 * Este cast revierte la lectura y vuelve a escribir en latin1-bytes vía
 * `mb_convert_encoding`, manteniendo coexistencia con la app vieja.
 *
 * Aplicación: cualquier columna varchar de tabla legacy que pueda contener
 * caracteres no-ASCII (nombres, direcciones, notas, observaciones).
 *
 * Ver ARCHITECTURE §11 y ADR-0002 (database coexistence).
 */
class Latin1String implements CastsAttributes
{
    /**
     * Lectura: bytes guardados como latin1 que MySQL devolvió tratándolos
     * como utf8 → reinterpretar como latin1 y reconvertir a utf8 limpio.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_encoding((string) $value, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Escritura: nuestro string utf8 limpio → bytes latin1 reinterpretables
     * por MySQL como utf8 sin romper a la app vieja.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_encoding((string) $value, 'ISO-8859-1', 'UTF-8');
    }
}
