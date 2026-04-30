<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo polimórfico legacy.
 *
 * Una sola tabla con 12 tipos distintos. Cada `tipo` representa una categoría
 * reutilizable referenciada por columnas de `piv`, `revision`, etc.
 *
 * Ver ADR-0007 para detalles. Schema verificado 2026-04-30.
 */
class Modulo extends Model
{
    use HasFactory;

    /** Tipos descubiertos en INFORMATION_SCHEMA prod (Bloque 02). */
    public const TIPO_INDUSTRIA = 1;

    public const TIPO_PANTALLA = 2;

    public const TIPO_MARQUESINA = 3;

    public const TIPO_ALIMENTACION = 4;

    public const TIPO_MUNICIPIO = 5;

    public const TIPO_ESTADO_PIV = 6;

    public const TIPO_CHECK_ASPECTO = 9;

    public const TIPO_CHECK_FUNCIONAMIENTO = 10;

    public const TIPO_CHECK_ACTUACION = 11;

    public const TIPO_CHECK_AUDIO = 12;

    public const TIPO_CHECK_FECHA_HORA = 13;

    public const TIPO_CHECK_PRECISION_PASO = 14;

    protected $table = 'modulo';

    protected $primaryKey = 'modulo_id';

    public $timestamps = false;

    protected $fillable = ['modulo_id', 'nombre', 'tipo'];

    protected $casts = [
        'tipo' => 'integer',
        'nombre' => Latin1String::class,
    ];

    public function scopeMunicipios(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_MUNICIPIO);
    }

    public function scopeIndustrias(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_INDUSTRIA);
    }

    public function scopeChecks(Builder $q): Builder
    {
        return $q->whereIn('tipo', [
            self::TIPO_CHECK_ASPECTO,
            self::TIPO_CHECK_FUNCIONAMIENTO,
            self::TIPO_CHECK_ACTUACION,
            self::TIPO_CHECK_AUDIO,
            self::TIPO_CHECK_FECHA_HORA,
            self::TIPO_CHECK_PRECISION_PASO,
        ]);
    }

    public function scopePantallas(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_PANTALLA);
    }

    public function scopeMarquesinas(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_MARQUESINA);
    }

    public function scopeAlimentaciones(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_ALIMENTACION);
    }
}
