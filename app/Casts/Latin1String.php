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
     * Lectura: prod tiene texto doblemente encoded (PHP 2014 escribió bytes
     * utf8 en columna latin1 sin transcoding; la conexión utf8mb4 los
     * retransforma a 4 bytes "Ã¡"). utf8_decode los devuelve a 2 bytes
     * válidos utf8 "á". Ver ADR-0011.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_encoding((string) $value, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * Escritura: utf8 entrante "á" (c3 a1) -> 4 bytes "Ã¡" (c3 83 c2 a1).
     * MySQL transcodifica de utf8mb4 connection a latin1 column -> 2 bytes
     * (c3 a1) almacenados. Mismo patrón que la app vieja escribe. ADR-0011.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_encoding((string) $value, 'UTF-8', 'ISO-8859-1');
    }
}
