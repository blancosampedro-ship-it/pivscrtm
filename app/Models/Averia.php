<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Avería de un PIV. 66.392 filas en prod.
 *
 * Se crea por:
 * - Operador reportando una incidencia real (asignacion.tipo=1).
 * - App vieja generando avería stub para revisiones rutinarias mensuales
 *   (asignacion.tipo=2) — porque `asignacion` no tiene `piv_id` directo.
 *   Ver ADR-0004 y bug histórico REVISION MENSUAL.
 *
 * `notas` la rellena el operador al reportar; el técnico NO la sobreescribe.
 */
class Averia extends Model
{
    use HasFactory;

    protected $table = 'averia';

    protected $primaryKey = 'averia_id';

    public $timestamps = false;

    protected $fillable = ['averia_id', 'operador_id', 'piv_id', 'notas', 'fecha', 'status', 'tecnico_id'];

    protected $casts = [
        'fecha' => 'datetime',
        'piv_id' => 'integer',
        'operador_id' => 'integer',
        'tecnico_id' => 'integer',
        'status' => 'integer',
        'notas' => Latin1String::class,
    ];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_id', 'operador_id');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }

    public function asignacion(): HasOne
    {
        return $this->hasOne(Asignacion::class, 'averia_id', 'averia_id');
    }
}
