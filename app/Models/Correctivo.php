<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cierre de una asignación tipo=1 (correctivo). 65.901 filas en prod.
 *
 * Field mapping del formulario nuevo a columnas legacy reales (ADR-0006):
 *   Diagnóstico       → `diagnostico`
 *   Acción / Recambio → `recambios`
 *   Estado final      → `estado_final`
 *   Tiempo (horas)    → `tiempo`
 *   Foto              → `lv_correctivo_imagen.url` (tabla nueva, Bloque 04)
 *
 * NO existen columnas `accion` ni `imagen` en esta tabla.
 */
class Correctivo extends Model
{
    use HasFactory;

    protected $table = 'correctivo';

    protected $primaryKey = 'correctivo_id';

    public $timestamps = false;

    protected $fillable = [
        'correctivo_id', 'tecnico_id', 'asignacion_id',
        'tiempo', 'contrato',
        'facturar_horas', 'facturar_desplazamiento', 'facturar_recambios',
        'recambios', 'diagnostico', 'estado_final',
    ];

    protected $casts = [
        'contrato' => 'boolean',
        'facturar_horas' => 'boolean',
        'facturar_desplazamiento' => 'boolean',
        'facturar_recambios' => 'boolean',
        'tecnico_id' => 'integer',
        'asignacion_id' => 'integer',
        'recambios' => Latin1String::class,
        'diagnostico' => Latin1String::class,
        'estado_final' => Latin1String::class,
    ];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id', 'asignacion_id');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }

    /**
     * Fotos asociadas al cierre del correctivo (tabla nueva lv_correctivo_imagen,
     * ADR-0006). Sin FK física — relación lógica por correctivo_id.
     */
    public function imagenes(): HasMany
    {
        return $this->hasMany(LvCorrectivoImagen::class, 'correctivo_id', 'correctivo_id')
            ->orderBy('posicion');
    }
}
