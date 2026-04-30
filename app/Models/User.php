<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuario unificado de la app nueva. Apunta a `lv_users`.
 *
 * Cada fila identifica unívocamente a un usuario legacy via (legacy_kind, legacy_id):
 *   - legacy_kind='admin'    -> u1.user_id
 *   - legacy_kind='tecnico'  -> tecnico.tecnico_id
 *   - legacy_kind='operador' -> operador.operador_id
 *
 * El email NO es único globalmente. Puede haber colisión cross-tabla (verificado
 * en Bloque 02: 1 caso real de info@winfin.es en tecnico Y operador). El guard
 * de auth resuelve por la ruta de login (/admin, /tecnico, /operador) — ver
 * ADR-0005 §3 y ADR-0008.
 *
 * Password puede ser NULL hasta el primer login post-migración (ADR-0003 lazy
 * SHA1->bcrypt). legacy_password_sha1 se popula al vuelo y se borra tras rehash.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'lv_users';

    protected $fillable = [
        'legacy_kind', 'legacy_id', 'email', 'name', 'password',
        'legacy_password_sha1', 'lv_password_migrated_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'legacy_password_sha1',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'lv_password_migrated_at' => 'datetime',
            'password' => 'hashed',
            'legacy_id' => 'integer',
        ];
    }

    // ----------------------------------------------------------------
    // Helpers de rol
    // ----------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->legacy_kind === 'admin';
    }

    public function isTecnico(): bool
    {
        return $this->legacy_kind === 'tecnico';
    }

    public function isOperador(): bool
    {
        return $this->legacy_kind === 'operador';
    }

    /**
     * Devuelve el modelo legacy correspondiente (U1, Tecnico, Operador).
     * Útil para acceder a campos que viven en la tabla origen.
     */
    public function legacyEntity(): ?Model
    {
        return match ($this->legacy_kind) {
            'admin' => U1::find($this->legacy_id),
            'tecnico' => Tecnico::find($this->legacy_id),
            'operador' => Operador::find($this->legacy_id),
            default => null,
        };
    }
}
