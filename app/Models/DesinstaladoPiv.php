<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de desinstalación de un PIV. Sin `fecha`, sin `motivo` enum:
 * toda la información va en `observaciones` texto libre.
 */
class DesinstaladoPiv extends Model
{
    use HasFactory;

    protected $table = 'desinstalado_piv';

    protected $primaryKey = 'desinstalado_piv_id';

    public $timestamps = false;

    protected $fillable = ['desinstalado_piv_id', 'piv_id', 'observaciones', 'pos'];

    protected $casts = [
        'piv_id' => 'integer',
        'pos' => 'integer',
        'observaciones' => Latin1String::class,
    ];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }
}
