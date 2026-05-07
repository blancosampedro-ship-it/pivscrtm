<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvDerivacionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LvDerivacion extends Model
{
    /** @use HasFactory<LvDerivacionFactory> */
    use HasFactory;

    protected $table = 'lv_derivacion';

    public const CAUSA_SIN_TENSION = 'sin_tension';

    public const CAUSA_PANEL_OFFLINE = 'panel_offline';

    public const CAUSA_INCIDENCIA_SOFTWARE = 'incidencia_software';

    public const CAUSA_VANDALISMO = 'vandalismo';

    public const CAUSA_PANEL_INACCESIBLE = 'panel_inaccesible';

    public const CAUSA_MATERIAL = 'material_no_disponible';

    public const CAUSA_AUTORIZACION = 'requiere_autorizacion';

    public const CAUSA_TERCERO = 'requiere_apoyo_tercero';

    public const CAUSA_OTROS = 'otros';

    public const ACTOR_CLEAR_CHANNEL = 'clear_channel';

    public const ACTOR_INDUSTRIAL = 'industrial';

    public const ACTOR_CRTM = 'crtm';

    public const ACTOR_AYUNTAMIENTO = 'ayuntamiento';

    public const ACTOR_OPERADOR_SIM = 'operador_sim';

    public const ACTOR_PROVEEDOR = 'proveedor';

    public const ACTOR_INTERNO_WINFIN = 'interno_winfin';

    public const ACTOR_OTROS = 'otros';

    public const STATUS_PENDIENTE_TERCERO = 'pendiente_tercero';

    public const STATUS_EN_CURSO = 'en_curso';

    public const STATUS_RESUELTO_EXTERNO = 'resuelto_externo';

    public const STATUS_DEVUELTO_A_RUTA = 'devuelto_a_ruta';

    public const STATUS_CANCELADA = 'cancelada';

    public const CAUSAS = [
        self::CAUSA_SIN_TENSION,
        self::CAUSA_PANEL_OFFLINE,
        self::CAUSA_INCIDENCIA_SOFTWARE,
        self::CAUSA_VANDALISMO,
        self::CAUSA_PANEL_INACCESIBLE,
        self::CAUSA_MATERIAL,
        self::CAUSA_AUTORIZACION,
        self::CAUSA_TERCERO,
        self::CAUSA_OTROS,
    ];

    public const ACTORES = [
        self::ACTOR_CLEAR_CHANNEL,
        self::ACTOR_INDUSTRIAL,
        self::ACTOR_CRTM,
        self::ACTOR_AYUNTAMIENTO,
        self::ACTOR_OPERADOR_SIM,
        self::ACTOR_PROVEEDOR,
        self::ACTOR_INTERNO_WINFIN,
        self::ACTOR_OTROS,
    ];

    public const STATUSES_ABIERTAS = [self::STATUS_PENDIENTE_TERCERO, self::STATUS_EN_CURSO];

    public const STATUSES_CERRADAS = [self::STATUS_RESUELTO_EXTERNO, self::STATUS_DEVUELTO_A_RUTA, self::STATUS_CANCELADA];

    protected $fillable = [
        'lv_ruta_dia_item_id',
        'tipo_causa',
        'causa_otros_texto',
        'actor_responsable',
        'actor_notas',
        'notas_derivacion',
        'fecha_derivacion',
        'derivado_por_user_id',
        'status',
        'fecha_resolucion',
        'resuelto_notas',
        'resuelto_por_user_id',
    ];

    protected $casts = [
        'lv_ruta_dia_item_id' => 'integer',
        'fecha_derivacion' => 'datetime',
        'derivado_por_user_id' => 'integer',
        'fecha_resolucion' => 'datetime',
        'resuelto_por_user_id' => 'integer',
    ];

    protected static function newFactory(): LvDerivacionFactory
    {
        return LvDerivacionFactory::new();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LvRutaDiaItem::class, 'lv_ruta_dia_item_id');
    }

    public function derivadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'derivado_por_user_id');
    }

    public function resueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por_user_id');
    }

    public function scopeAbiertas(Builder $query): void
    {
        $query->whereIn('status', self::STATUSES_ABIERTAS);
    }

    public function scopeCerradas(Builder $query): void
    {
        $query->whereIn('status', self::STATUSES_CERRADAS);
    }

    public function scopePorActor(Builder $query, string $actor): void
    {
        $query->where('actor_responsable', $actor);
    }

    public function isAbierta(): bool
    {
        return in_array($this->status, self::STATUSES_ABIERTAS, true);
    }

    public function isCerrada(): bool
    {
        return in_array($this->status, self::STATUSES_CERRADAS, true);
    }

    public function requiereCausaOtrosTexto(): bool
    {
        return $this->tipo_causa === self::CAUSA_OTROS;
    }
}
