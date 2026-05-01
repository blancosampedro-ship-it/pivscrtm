<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Panel PIV físico instalado en marquesina. 575 filas en prod.
 *
 * Schema verificado contra INFORMATION_SCHEMA 2026-04-30. NO tiene lat/lng
 * (geocoding pendiente — Bloque 02f). Hasta 3 operadores responsables.
 * `municipio` es varchar con id de Modulo tipo=5 (ver ADR-0007).
 */
class Piv extends Model
{
    use HasFactory;

    protected $table = 'piv';

    protected $primaryKey = 'piv_id';

    public $timestamps = false;

    protected $fillable = [
        'piv_id', 'parada_cod', 'cc_cod', 'fecha_instalacion',
        'n_serie_piv', 'n_serie_sim', 'n_serie_mgp',
        'tipo_piv', 'tipo_marquesina', 'tipo_alimentacion',
        'industria_id', 'concesionaria_id',
        'direccion', 'municipio',
        'status', 'operador_id', 'operador_id_2', 'operador_id_3',
        'prevision', 'observaciones', 'mantenimiento', 'status2',
    ];

    protected $casts = [
        'fecha_instalacion' => 'date',
        'industria_id' => 'integer',
        'concesionaria_id' => 'integer',
        'status' => 'integer',
        'status2' => 'integer',
        'operador_id' => 'integer',
        'operador_id_2' => 'integer',
        'operador_id_3' => 'integer',
        'direccion' => Latin1String::class,
        'observaciones' => Latin1String::class,
        'prevision' => Latin1String::class,
    ];

    public function operadorPrincipal(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_id', 'operador_id');
    }

    public function operadorSecundario(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_id_2', 'operador_id');
    }

    public function operadorTerciario(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_id_3', 'operador_id');
    }

    public function industria(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'industria_id', 'modulo_id');
    }

    /**
     * Relación lógica a `modulo` con tipo=5 (municipios). Ver ADR-0007.
     *
     * `piv.municipio` es varchar pero almacena `modulo_id` numérico como string.
     * El centinela `"0"` significa "sin municipio asignado" — devuelve null
     * porque modulo_id=0 no existe en la tabla.
     *
     * El nombre `municipioModulo` evita colisión con la columna `municipio`.
     */
    public function municipioModulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'municipio', 'modulo_id');
    }

    public function averias(): HasMany
    {
        return $this->hasMany(Averia::class, 'piv_id', 'piv_id');
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(PivImagen::class, 'piv_id', 'piv_id')->orderBy('posicion');
    }

    public function instalaciones(): HasMany
    {
        return $this->hasMany(InstaladorPiv::class, 'piv_id', 'piv_id');
    }

    public function desinstalaciones(): HasMany
    {
        return $this->hasMany(DesinstaladoPiv::class, 'piv_id', 'piv_id');
    }

    public function reinstalaciones(): HasMany
    {
        return $this->hasMany(ReinstaladoPiv::class, 'piv_id', 'piv_id');
    }

    /**
     * Scope: paneles donde el operador dado es principal, secundario o terciario.
     * Util para Bloque 12 (PWA operador) y Bloque 07 (filtros admin).
     */
    public function scopeForOperador(Builder $q, int $operadorId): Builder
    {
        return $q->where(function ($w) use ($operadorId) {
            $w->where('operador_id', $operadorId)
                ->orWhere('operador_id_2', $operadorId)
                ->orWhere('operador_id_3', $operadorId);
        });
    }

    /**
     * URL completa de la primera imagen del panel para mostrar como thumbnail.
     *
     * Imagenes legacy viven en winfin.es/images/piv/<filename>. Bloque 07d
     * confirmo que son publicas (no requieren auth). Cuando migremos los
     * archivos al storage local de Laravel (post-cutover), cambiar el prefijo
     * a Storage::url().
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        $first = $this->imagenes->first();
        if (! $first) {
            return null;
        }

        return 'https://www.winfin.es/images/piv/'.$first->url;
    }
}
