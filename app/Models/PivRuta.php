<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PivRutaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ruta operativa Winfin para planificación preventiva.
 *
 * Las 9 zonas oficiales (puntos cardinales) vienen del Excel "rutas e items.xlsx" (hoja "Rutas").
 * Ver PivRutaSeeder. Los paneles fuera del Excel quedan sin ruta y se gestionan ad-hoc.
 */
final class PivRuta extends Model
{
    /** @use HasFactory<PivRutaFactory> */
    use HasFactory;

    protected $table = 'lv_piv_ruta';

    public const COD_CENTRO = 'CENTRO';

    public const COD_NORTE = 'NORTE';

    public const COD_NORESTE = 'NORESTE';

    public const COD_ESTE = 'ESTE';

    public const COD_SURESTE = 'SURESTE';

    public const COD_SUR = 'SUR';

    public const COD_SUROESTE = 'SUROESTE';

    public const COD_OESTE = 'OESTE';

    public const COD_NOROESTE = 'NOROESTE';

    public const CODIGOS = [
        self::COD_CENTRO,
        self::COD_NORTE,
        self::COD_NORESTE,
        self::COD_ESTE,
        self::COD_SURESTE,
        self::COD_SUR,
        self::COD_SUROESTE,
        self::COD_OESTE,
        self::COD_NOROESTE,
    ];

    protected $fillable = [
        'codigo',
        'nombre',
        'zona_geografica',
        'color_hint',
        'km_medio',
        'sort_order',
    ];

    protected $casts = [
        'km_medio' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function newFactory(): PivRutaFactory
    {
        return PivRutaFactory::new();
    }

    public function municipios(): HasMany
    {
        return $this->hasMany(PivRutaMunicipio::class, 'ruta_id');
    }
}
