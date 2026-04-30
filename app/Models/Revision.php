<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cierre de una asignación tipo=2 (revisión mensual rutinaria).
 *
 * Los checks (`aspecto`, `funcionamiento`, `actuacion`, `audio`,
 * `fecha_hora`, `precision_paso`) son varchar(100) texto libre — no enum
 * en BD. La nueva app los limita a OK/KO/N/A en formulario.
 *
 * `notas` NUNCA debe rellenarse automáticamente con "REVISION MENSUAL"
 * (regla red-line del DoD copilot-instructions). Si el técnico no escribe
 * nada, queda NULL.
 */
class Revision extends Model
{
    use HasFactory;

    protected $table = 'revision';

    protected $primaryKey = 'revision_id';

    public $timestamps = false;

    protected $fillable = [
        'revision_id', 'tecnico_id', 'asignacion_id', 'fecha', 'ruta',
        'aspecto', 'funcionamiento', 'actuacion', 'audio',
        'lineas', 'fecha_hora', 'precision_paso', 'notas',
    ];

    protected $casts = [
        'tecnico_id' => 'integer',
        'asignacion_id' => 'integer',
        'notas' => Latin1String::class,
    ];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id', 'asignacion_id');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }
}
