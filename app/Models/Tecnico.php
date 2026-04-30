<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Técnico de campo. 65 filas en prod, 3 activas.
 *
 * Datos RGPD sensibles (regla #3): dni, n_seguridad_social, ccc, telefono,
 * direccion, email, carnet_conducir. NUNCA exportar al cliente. Ver
 * TecnicoExportTransformer (Bloque 10) para filtrado en exports.
 *
 * Password legacy se llama `clave` (NO `password`) — ver ADR-0008. Está en
 * $hidden para que no se serialice por accidente. La validación SHA1 la
 * hace LegacyHashGuard (Bloque 06) leyendo `clave` directamente vía DB::table.
 */
class Tecnico extends Model
{
    use HasFactory;

    protected $table = 'tecnico';

    protected $primaryKey = 'tecnico_id';

    public $timestamps = false;

    protected $fillable = [
        'tecnico_id', 'usuario', 'clave', 'email', 'nombre_completo',
        'dni', 'carnet_conducir', 'direccion', 'ccc',
        'n_seguridad_social', 'telefono', 'status',
    ];

    protected $hidden = ['clave'];

    protected $casts = [
        'status' => 'integer',
        'nombre_completo' => Latin1String::class,
        'direccion' => Latin1String::class,
    ];

    public function asignaciones(): HasMany
    {
        return $this->hasMany(Asignacion::class, 'tecnico_id', 'tecnico_id');
    }

    public function averias(): HasMany
    {
        return $this->hasMany(Averia::class, 'tecnico_id', 'tecnico_id');
    }
}
