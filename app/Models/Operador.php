<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operador (cliente final). 41 filas en prod.
 *
 * Password legacy se llama `clave` (ADR-0008). $hidden para evitar leak
 * accidental en serialización JSON. La auth la hace LegacyHashGuard.
 *
 * Un operador puede ser principal, secundario o terciario en un PIV
 * (columnas piv.operador_id, _2, _3). Las relaciones aquí son las de
 * "operador principal"; las queries de scope a paneles del operador
 * (ver Bloque 12) deben hacer WHERE operador_id IN (op_id, _2, _3) —
 * usar `Piv::scopeForOperador()`.
 */
class Operador extends Model
{
    use HasFactory;

    protected $table = 'operador';

    protected $primaryKey = 'operador_id';

    public $timestamps = false;

    protected $fillable = [
        'operador_id', 'usuario', 'clave', 'email', 'domicilio', 'lineas',
        'responsable', 'razon_social', 'cif', 'status',
    ];

    protected $hidden = ['clave'];

    protected $casts = [
        'status' => 'integer',
        'razon_social' => Latin1String::class,
        'domicilio' => Latin1String::class,
        'responsable' => Latin1String::class,
    ];

    /**
     * Paneles donde este operador es el principal.
     * Para todos los paneles del operador (incluyendo _2 y _3) ver el scope
     * Piv::scopeForOperador().
     */
    public function paneles(): HasMany
    {
        return $this->hasMany(Piv::class, 'operador_id', 'operador_id');
    }

    public function averias(): HasMany
    {
        return $this->hasMany(Averia::class, 'operador_id', 'operador_id');
    }
}
