<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Asignación técnico↔avería. 66.404 filas en prod.
 *
 * `tipo`:
 *   1 = correctivo (avería real reportada por operador) → cierre en `correctivo`.
 *   2 = revisión mensual rutinaria → cierre en `revision`.
 *
 * NO tiene `piv_id` directo: se llega al PIV vía `averia.piv_id`. Schema
 * legacy verificado 2026-04-30. Ver accessor `getPivAttribute()`.
 */
class Asignacion extends Model
{
    use HasFactory;

    public const TIPO_CORRECTIVO = 1;

    public const TIPO_REVISION = 2;

    protected $table = 'asignacion';

    protected $primaryKey = 'asignacion_id';

    public $timestamps = false;

    protected $fillable = [
        'asignacion_id', 'tecnico_id', 'fecha', 'hora_inicial', 'hora_final',
        'tipo', 'averia_id', 'status',
    ];

    protected $casts = [
        'fecha' => 'date',
        'hora_inicial' => 'integer',
        'hora_final' => 'integer',
        'tipo' => 'integer',
        'tecnico_id' => 'integer',
        'averia_id' => 'integer',
        'status' => 'integer',
    ];

    public function averia(): BelongsTo
    {
        return $this->belongsTo(Averia::class, 'averia_id', 'averia_id');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }

    public function correctivo(): HasOne
    {
        return $this->hasOne(Correctivo::class, 'asignacion_id', 'asignacion_id');
    }

    public function revision(): HasOne
    {
        return $this->hasOne(Revision::class, 'asignacion_id', 'asignacion_id');
    }

    /**
     * El PIV de esta asignación (vía averia.piv_id).
     * Asignacion no tiene `piv_id` directo — schema legacy verificado 2026-04-30.
     */
    public function getPivAttribute(): ?Piv
    {
        return $this->averia?->piv;
    }
}
