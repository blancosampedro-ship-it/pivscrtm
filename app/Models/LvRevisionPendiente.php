<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvRevisionPendienteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila por panel y mes. La crea el cron mensual y la decide admin en 12b.4.
 */
final class LvRevisionPendiente extends Model
{
    /** @use HasFactory<LvRevisionPendienteFactory> */
    use HasFactory;

    protected $table = 'lv_revision_pendiente';

    public const STATUS_PENDIENTE = 'pendiente';

    public const STATUS_VERIFICADA_REMOTO = 'verificada_remoto';

    public const STATUS_REQUIERE_VISITA = 'requiere_visita';

    public const STATUS_EXCEPCION = 'excepcion';

    public const STATUS_COMPLETADA = 'completada';

    public const STATUSES_INCOMPLETAS = [
        self::STATUS_PENDIENTE,
        self::STATUS_REQUIERE_VISITA,
        self::STATUS_EXCEPCION,
    ];

    public const STATUSES_SATISFECHAS = [
        self::STATUS_VERIFICADA_REMOTO,
        self::STATUS_COMPLETADA,
    ];

    protected $fillable = [
        'piv_id',
        'periodo_year',
        'periodo_month',
        'status',
        'fecha_planificada',
        'decision_user_id',
        'decision_at',
        'decision_notas',
        'carry_over_origen_id',
        'asignacion_id',
    ];

    protected $casts = [
        'piv_id' => 'integer',
        'periodo_year' => 'integer',
        'periodo_month' => 'integer',
        'fecha_planificada' => 'date',
        'decision_user_id' => 'integer',
        'decision_at' => 'datetime',
        'carry_over_origen_id' => 'integer',
        'asignacion_id' => 'integer',
    ];

    protected static function newFactory(): LvRevisionPendienteFactory
    {
        return LvRevisionPendienteFactory::new();
    }

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function decisionUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_user_id');
    }

    public function carryOverOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carry_over_origen_id');
    }

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id', 'asignacion_id');
    }

    public function scopeIncompletas(Builder $query): void
    {
        $query->whereIn('status', self::STATUSES_INCOMPLETAS);
    }

    public function scopeSatisfechas(Builder $query): void
    {
        $query->whereIn('status', self::STATUSES_SATISFECHAS);
    }

    public function scopeDelMes(Builder $query, int $year, int $month): void
    {
        $query->where('periodo_year', $year)->where('periodo_month', $month);
    }

    public function isCarryOver(): bool
    {
        return $this->carry_over_origen_id !== null;
    }
}
