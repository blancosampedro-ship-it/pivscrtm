<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de reinstalación de un PIV. Mismo schema que desinstalado_piv.
 */
class ReinstaladoPiv extends Model
{
    use HasFactory;

    protected $table = 'reinstalado_piv';

    protected $primaryKey = 'reinstalado_piv_id';

    public $timestamps = false;

    protected $fillable = ['reinstalado_piv_id', 'piv_id', 'observaciones', 'pos'];

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
