<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PivZonaMunicipioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot zona <-> municipio. Modelo standalone (no via belongsToMany)
 * porque no podemos definir relación bidireccional con `modulo`
 * (legacy, sin FK física).
 */
class PivZonaMunicipio extends Model
{
    /** @use HasFactory<PivZonaMunicipioFactory> */
    use HasFactory;

    protected $table = 'lv_piv_zona_municipio';

    protected $fillable = [
        'zona_id',
        'municipio_modulo_id',
    ];

    protected $casts = [
        'zona_id' => 'integer',
        'municipio_modulo_id' => 'integer',
    ];

    public function zona(): BelongsTo
    {
        return $this->belongsTo(PivZona::class, 'zona_id');
    }

    /**
     * Resolver al modelo Modulo (legacy) por `municipio_modulo_id`.
     */
    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'municipio_modulo_id', 'modulo_id');
    }
}
