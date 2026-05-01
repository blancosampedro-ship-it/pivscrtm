<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de archivado para una fila `piv` (ADR-0012).
 *
 * Inserta una fila aquí = panel ocultado del admin Filament por defecto.
 * Borra una fila aquí = panel restaurado (visible de nuevo).
 *
 * Sin FK física a `piv` (regla ADR-0002). La integridad la valida la app —
 * en práctica, `uniq_piv_archived` evita duplicados.
 */
class LvPivArchived extends Model
{
    use HasFactory;

    protected $table = 'lv_piv_archived';

    protected $fillable = [
        'piv_id',
        'archived_at',
        'archived_by_user_id',
        'reason',
    ];

    protected $casts = [
        'piv_id' => 'integer',
        'archived_at' => 'datetime',
        'archived_by_user_id' => 'integer',
    ];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id', 'id');
    }
}
