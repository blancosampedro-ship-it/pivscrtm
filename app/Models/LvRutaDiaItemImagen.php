<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvRutaDiaItemImagenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LvRutaDiaItemImagen extends Model
{
    /** @use HasFactory<LvRutaDiaItemImagenFactory> */
    use HasFactory;

    protected $table = 'lv_ruta_dia_item_imagen';

    protected $fillable = ['ruta_dia_item_id', 'url', 'posicion'];

    protected $casts = [
        'ruta_dia_item_id' => 'integer',
        'posicion' => 'integer',
    ];

    protected static function newFactory(): LvRutaDiaItemImagenFactory
    {
        return LvRutaDiaItemImagenFactory::new();
    }

    public function rutaDiaItem(): BelongsTo
    {
        return $this->belongsTo(LvRutaDiaItem::class, 'ruta_dia_item_id');
    }
}
