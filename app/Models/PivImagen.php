<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Imagen asociada a un panel PIV. 1135 filas en prod (375 huérfanas).
 *
 * NO se reusa para fotos de cierre de correctivo — para eso ver
 * tabla `lv_correctivo_imagen` (Bloque 04, ADR-0006).
 */
class PivImagen extends Model
{
    use HasFactory;

    protected $table = 'piv_imagen';

    protected $primaryKey = 'piv_imagen_id';

    public $timestamps = false;

    protected $fillable = ['piv_imagen_id', 'piv_id', 'url', 'posicion'];

    protected $casts = [
        'piv_id' => 'integer',
        'posicion' => 'integer',
    ];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }
}
