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
     * Lectura: prod tiene texto doblemente encoded. La conexión utf8mb4 entrega
     * bytes que originalmente eran utf8 almacenados como cp1252 (que MySQL usa
     * internamente para `charset=latin1`, no como ISO-8859-1 puro).
     * Ver ADR-0011 + postscript Bloque 07c.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_encoding((string) $value, 'WINDOWS-1252', 'UTF-8');
    }

    /**
     * Escritura: utf8 entrante "á" (c3 a1) -> 4 bytes "Ã¡" (c3 83 c2 a1).
     * MySQL transcodifica de utf8mb4 connection a latin1 column (cp1252) ->
     * 2 bytes (c3 a1) almacenados. Mismo patrón que la app vieja escribe.
     * Ver ADR-0011 + postscript Bloque 07c.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_encoding((string) $value, 'UTF-8', 'WINDOWS-1252');
    }
}
