<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvRutaDiaItemFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LvRutaDiaItem extends Model
{
    /** @use HasFactory<LvRutaDiaItemFactory> */
    use HasFactory;

    protected $table = 'lv_ruta_dia_item';

    public const TIPO_CORRECTIVO = 'correctivo';

    public const TIPO_PREVENTIVO = 'preventivo';

    public const TIPO_CARRY_OVER = 'carry_over';

    public const STATUS_PENDIENTE = 'pendiente';

    public const STATUS_EN_PROGRESO = 'en_progreso';

    public const STATUS_CERRADO = 'cerrado';

    public const STATUS_NO_RESUELTO = 'no_resuelto';

    protected $fillable = [
        'ruta_dia_id',
        'orden',
        'tipo_item',
        'lv_averia_icca_id',
        'lv_revision_pendiente_id',
        'status',
        'causa_no_resolucion',
        'notas_tecnico',
        'cerrado_at',
    ];

    protected $casts = [
        'ruta_dia_id' => 'integer',
        'orden' => 'integer',
        'lv_averia_icca_id' => 'integer',
        'lv_revision_pendiente_id' => 'integer',
        'cerrado_at' => 'datetime',
    ];

    protected static function newFactory(): LvRutaDiaItemFactory
    {
        return LvRutaDiaItemFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $hasAveria = $item->lv_averia_icca_id !== null;
            $hasRevision = $item->lv_revision_pendiente_id !== null;

            if ($hasAveria === $hasRevision) {
                throw new DomainException('lv_ruta_dia_item: exactamente uno de lv_averia_icca_id o lv_revision_pendiente_id debe estar set, no ambos ni ninguno.');
            }
        });
    }

    public function rutaDia(): BelongsTo
    {
        return $this->belongsTo(LvRutaDia::class, 'ruta_dia_id');
    }

    public function averiaIcca(): BelongsTo
    {
        return $this->belongsTo(LvAveriaIcca::class, 'lv_averia_icca_id');
    }

    public function revisionPendiente(): BelongsTo
    {
        return $this->belongsTo(LvRevisionPendiente::class, 'lv_revision_pendiente_id');
    }
}
