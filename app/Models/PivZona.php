<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PivZonaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zona operativa para clusterizar paneles por proximidad geográfica.
 *
 * Cada panel pertenece a una zona via su municipio (piv.municipio ->
 * modulo_id -> lv_piv_zona_municipio.municipio_modulo_id -> zona_id).
 * Sin FK física a modulo (ADR-0002 coexistencia).
 *
 * El admin define las zonas una vez y asigna los 102 municipios reales.
 * 12b.3+ usará esta agrupación para clusterizar la planificación diaria.
 */
class PivZona extends Model
{
    /** @use HasFactory<PivZonaFactory> */
    use HasFactory;

    protected $table = 'lv_piv_zona';

    protected $fillable = [
        'nombre',
        'color_hint',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Asignaciones de municipios a esta zona (pivot).
     */
    public function municipios(): HasMany
    {
        return $this->hasMany(PivZonaMunicipio::class, 'zona_id');
    }
}
