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
 * Las rutas oficiales vienen del Excel WINFIN_Rutas_PIV_Madrid.xlsx.
 * Los paneles fuera del Excel quedan sin ruta y se gestionan ad-hoc.
 */
final class PivRuta extends Model
{
    /** @use HasFactory<PivRutaFactory> */
    use HasFactory;

    protected $table = 'lv_piv_ruta';

    public const COD_ROSA_NO = 'ROSA-NO';

    public const COD_ROSA_E = 'ROSA-E';

    public const COD_VERDE = 'VERDE';

    public const COD_AZUL = 'AZUL';

    public const COD_AMARILLO = 'AMARILLO';

    public const CODIGOS = [
        self::COD_ROSA_NO,
        self::COD_ROSA_E,
        self::COD_VERDE,
        self::COD_AZUL,
        self::COD_AMARILLO,
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
