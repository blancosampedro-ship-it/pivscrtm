<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de instalación de un PIV.
 * `instalador_id` es FK lógica a `u1.user_id`.
 */
class InstaladorPiv extends Model
{
    use HasFactory;

    protected $table = 'instalador_piv';

    protected $primaryKey = 'instalador_piv_id';

    public $timestamps = false;

    protected $fillable = ['instalador_piv_id', 'piv_id', 'instalador_id'];

    protected $casts = [
        'piv_id' => 'integer',
        'instalador_id' => 'integer',
    ];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function instalador(): BelongsTo
    {
        return $this->belongsTo(U1::class, 'instalador_id', 'user_id');
    }
}
