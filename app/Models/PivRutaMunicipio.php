<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PivRutaMunicipioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PivRutaMunicipio extends Model
{
    /** @use HasFactory<PivRutaMunicipioFactory> */
    use HasFactory;

    protected $table = 'lv_piv_ruta_municipio';

    protected $fillable = [
        'ruta_id',
        'municipio_modulo_id',
        'km_desde_ciempozuelos',
    ];

    protected $casts = [
        'ruta_id' => 'integer',
        'municipio_modulo_id' => 'integer',
        'km_desde_ciempozuelos' => 'integer',
    ];

    protected static function newFactory(): PivRutaMunicipioFactory
    {
        return PivRutaMunicipioFactory::new();
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(PivRuta::class, 'ruta_id');
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'municipio_modulo_id', 'modulo_id');
    }
}
