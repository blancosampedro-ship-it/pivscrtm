<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvRutaDiaFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LvRutaDia extends Model
{
    /** @use HasFactory<LvRutaDiaFactory> */
    use HasFactory;

    protected $table = 'lv_ruta_dia';

    public const STATUS_PLANIFICADA = 'planificada';

    public const STATUS_EN_PROGRESO = 'en_progreso';

    public const STATUS_COMPLETADA = 'completada';

    public const STATUS_CANCELADA = 'cancelada';

    public const STATUSES = [
        self::STATUS_PLANIFICADA,
        self::STATUS_EN_PROGRESO,
        self::STATUS_COMPLETADA,
        self::STATUS_CANCELADA,
    ];

    public const STATUSES_EDITABLES = [
        self::STATUS_PLANIFICADA,
        self::STATUS_EN_PROGRESO,
    ];

    protected $fillable = [
        'tecnico_id',
        'fecha',
        'status',
        'notas_admin',
        'created_by_user_id',
    ];

    protected $casts = [
        'tecnico_id' => 'integer',
        'fecha' => 'date',
        'created_by_user_id' => 'integer',
    ];

    protected static function newFactory(): LvRutaDiaFactory
    {
        return LvRutaDiaFactory::new();
    }

    public function items(): HasMany
    {
        return $this->hasMany(LvRutaDiaItem::class, 'ruta_dia_id')->orderBy('orden');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::STATUSES_EDITABLES, true);
    }

    public function scopeDelDia(Builder $query, DateTimeInterface $fecha): void
    {
        $query->whereDate('fecha', $fecha->format('Y-m-d'));
    }
}
