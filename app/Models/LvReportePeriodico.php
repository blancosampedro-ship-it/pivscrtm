<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LvReportePeriodicoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LvReportePeriodico extends Model
{
    /** @use HasFactory<LvReportePeriodicoFactory> */
    use HasFactory;

    protected $table = 'lv_reporte_periodico';

    public const TIPO_MENSUAL = 'mensual';

    public const TIPO_ANUAL = 'anual';

    public const TIPOS = [self::TIPO_MENSUAL, self::TIPO_ANUAL];

    protected $fillable = [
        'tipo',
        'anyo',
        'mes',
        'generated_at',
        'generated_by_user_id',
        'pdf_path',
        'xlsx_path',
        'metadata_json',
    ];

    protected $casts = [
        'anyo' => 'integer',
        'mes' => 'integer',
        'generated_at' => 'datetime',
        'generated_by_user_id' => 'integer',
        'metadata_json' => 'array',
    ];

    protected static function newFactory(): LvReportePeriodicoFactory
    {
        return LvReportePeriodicoFactory::new();
    }

    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function periodoLabel(): string
    {
        if ($this->tipo === self::TIPO_ANUAL) {
            return 'Año '.$this->anyo;
        }

        $date = CarbonImmutable::create($this->anyo, $this->mes ?? 1, 1, 0, 0, 0, 'Europe/Madrid')->locale('es');

        return ucfirst($date->translatedFormat('F Y'));
    }

    public function pdfFullPath(): string
    {
        return storage_path('app/'.$this->pdf_path);
    }

    public function xlsxFullPath(): string
    {
        return storage_path('app/'.$this->xlsx_path);
    }

    public function scopeMensuales(Builder $query): void
    {
        $query->where('tipo', self::TIPO_MENSUAL);
    }

    public function scopeAnuales(Builder $query): void
    {
        $query->where('tipo', self::TIPO_ANUAL);
    }

    public function scopeDelAnyo(Builder $query, int $year): void
    {
        $query->where('anyo', $year);
    }
}
