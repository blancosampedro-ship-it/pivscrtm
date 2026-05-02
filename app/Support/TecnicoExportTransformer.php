<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tecnico;

/**
 * Transformer RGPD-safe para datos de tecnico en exports.
 * Regla #3: los campos sensibles nunca viajan al operador-cliente.
 */
class TecnicoExportTransformer
{
    /**
     * Campos del tecnico que nunca pueden viajar a un export al operador-cliente.
     */
    public const BLACKLIST_FIELDS_FOR_OPERADOR = [
        'dni',
        'n_seguridad_social',
        'ccc',
        'telefono',
        'direccion',
        'email',
        'carnet_conducir',
    ];

    /** @return array<string, mixed> */
    public static function forAdmin(?Tecnico $tecnico): array
    {
        if ($tecnico === null) {
            return self::emptyShape();
        }

        return [
            'tecnico_id' => $tecnico->tecnico_id,
            'usuario' => $tecnico->usuario,
            'nombre_completo' => (string) $tecnico->nombre_completo,
            'email' => $tecnico->email,
            'telefono' => $tecnico->telefono,
            'dni' => $tecnico->dni,
            'n_seguridad_social' => $tecnico->n_seguridad_social,
            'ccc' => $tecnico->ccc,
            'direccion' => (string) $tecnico->direccion,
            'carnet_conducir' => $tecnico->carnet_conducir,
            'status' => $tecnico->status,
        ];
    }

    /** @return array<string, mixed> */
    public static function forOperador(?Tecnico $tecnico): array
    {
        if ($tecnico === null) {
            return ['tecnico_nombre' => null];
        }

        return [
            'tecnico_nombre' => (string) $tecnico->nombre_completo,
        ];
    }

    /** @return array<string, null> */
    private static function emptyShape(): array
    {
        return array_fill_keys([
            'tecnico_id',
            'usuario',
            'nombre_completo',
            'email',
            'telefono',
            'dni',
            'n_seguridad_social',
            'ccc',
            'direccion',
            'carnet_conducir',
            'status',
        ], null);
    }
}
