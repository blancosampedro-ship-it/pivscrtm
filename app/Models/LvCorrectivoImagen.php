<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvCorrectivoImagenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto asociada al cierre de un correctivo (ADR-0006).
 *
 * NO es la tabla legacy `piv_imagen` (esa es por panel). Esta es por cierre
 * concreto. Sin FK física a correctivo (regla coexistencia ADR-0002), la
 * integridad la valida la app.
 */
class LvCorrectivoImagen extends Model
{
    /** @use HasFactory<LvCorrectivoImagenFactory> */
    use HasFactory;

    protected $table = 'lv_correctivo_imagen';

    protected $fillable = ['correctivo_id', 'url', 'posicion'];

    protected $casts = [
        'correctivo_id' => 'integer',
        'posicion' => 'integer',
    ];

    public function correctivo(): BelongsTo
    {
        return $this->belongsTo(Correctivo::class, 'correctivo_id', 'correctivo_id');
    }
}
