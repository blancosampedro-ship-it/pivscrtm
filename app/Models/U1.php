<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla legacy `u1` para administradores.
 *
 * Excepciones a las convenciones del proyecto (ver ADR-0008):
 * - PK es `user_id`, NO `u1_id`.
 * - Columna password se llama `password` (en `tecnico` y `operador` se llama `clave`).
 *
 * Schema verificado 2026-04-30: 1 fila en prod (1 admin).
 */
class U1 extends Model
{
    use HasFactory;

    protected $table = 'u1';

    protected $primaryKey = 'user_id';

    public $timestamps = false;

    protected $fillable = ['user_id', 'username', 'email', 'password'];

    protected $hidden = ['password'];
}
