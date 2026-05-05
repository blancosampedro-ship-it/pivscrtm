<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvAveriaIccaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LvAveriaIcca extends Model
{
    /** @use HasFactory<LvAveriaIccaFactory> */
    use HasFactory;

    protected $table = 'lv_averia_icca';

    public const CAT_COMUNICACION = 'Problemas de comunicación';

    public const CAT_APAGADO = 'Panel apagado';

    public const CAT_TIEMPOS = 'Problema de tiempos';

    public const CAT_AUDIO = 'Problema de audio';

    public const CAT_OTRAS = 'Otras';

    public const CATEGORIAS_CONOCIDAS = [
        self::CAT_COMUNICACION,
        self::CAT_APAGADO,
        self::CAT_TIEMPOS,
        self::CAT_AUDIO,
    ];

    protected $fillable = [
        'sgip_id',
        'panel_id_sgip',
        'piv_id',
        'categoria',
        'descripcion',
        'notas',
        'estado_externo',
        'asignada_a',
        'activa',
        'fecha_import',
        'archivo_origen',
        'imported_by_user_id',
        'marked_inactive_at',
    ];

    protected $casts = [
        'piv_id' => 'integer',
        'activa' => 'boolean',
        'fecha_import' => 'datetime',
        'imported_by_user_id' => 'integer',
        'marked_inactive_at' => 'datetime',
    ];

    protected static function newFactory(): LvAveriaIccaFactory
    {
        return LvAveriaIccaFactory::new();
    }

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    public function scopeActivas(Builder $query): void
    {
        $query->where('activa', true);
    }

    public function scopeInactivas(Builder $query): void
    {
        $query->where('activa', false);
    }
}
