<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Guard custom que valida login contra los hashes legacy SHA1 (`u1.password`,
 * `tecnico.clave`, `operador.clave`) y rehashea lazy a bcrypt en la fila
 * `lv_users` correspondiente.
 *
 * Diseñado como servicio puro (no extiende SessionGuard) para poder reutilizarse
 * desde Filament (admin), Livewire/Volt (PWA tecnico) y Livewire/Volt (PWA operador).
 *
 * Algoritmo definitivo en ADR-0003 + ADR-0008. Field mapping por rol en ADR-0008.
 */
final class LegacyHashGuard
{
    /**
     * Mapeo (legacy_kind => meta de la tabla legacy correspondiente).
     * Ver ADR-0008 — los nombres reales en producción son distintos a los
     * asumidos en ADR-0003 antes de inspeccionar el schema vivo.
     */
    private const TABLE_META = [
        'admin' => [
            'table' => 'u1',
            'pk' => 'user_id',
            'password_col' => 'password',
            'name_cols' => ['username'],
        ],
        'tecnico' => [
            'table' => 'tecnico',
            'pk' => 'tecnico_id',
            'password_col' => 'clave',
            'name_cols' => ['nombre_completo', 'usuario'],
        ],
        'operador' => [
            'table' => 'operador',
            'pk' => 'operador_id',
            'password_col' => 'clave',
            'name_cols' => ['razon_social', 'responsable', 'usuario'],
        ],
    ];

    /** Máximo de intentos fallidos por (IP, email, rol) antes de bloquear. */
    public const MAX_ATTEMPTS = 5;

    /** Ventana del rate limit en segundos. */
    public const DECAY_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Intenta login. Devuelve true si autentica (y hace `Auth::login()`),
     * false si credenciales inválidas. Lanza ValidationException con código
     * de throttle si supera MAX_ATTEMPTS.
     */
    public function attempt(string $email, string $password, string $roleHint, Request $request): bool
    {
        if (! isset(self::TABLE_META[$roleHint])) {
            throw new \InvalidArgumentException("Rol desconocido: {$roleHint}");
        }

        $rlKey = $this->rateLimitKey($request->ip() ?? '0.0.0.0', $email, $roleHint);

        if ($this->rateLimiter->tooManyAttempts($rlKey, self::MAX_ATTEMPTS)) {
            $seconds = $this->rateLimiter->availableIn($rlKey);
            throw ValidationException::withMessages([
                'data.email' => trans('auth.throttle', ['seconds' => $seconds]),
            ]);
        }

        $meta = self::TABLE_META[$roleHint];

        // 0. Email fast-path (ADR-0010). Si lv_users tiene fila para este
        //    (email, legacy_kind) con bcrypt válido, autenticamos sin consultar
        //    la tabla legacy. Cubre el caso lv_users.email != legacy.email
        //    (p. ej. admin del Bloque 05) y ahorra un query en el happy path
        //    post-migración. NO escribe — la escritura sigue siendo updateOrCreate
        //    por (legacy_kind, legacy_id) en el flujo legacy de abajo.
        $fastPathUser = User::query()
            ->where('email', $email)
            ->where('legacy_kind', $roleHint)
            ->whereNotNull('password')
            ->first();

        if ($fastPathUser !== null && Hash::check($password, $fastPathUser->password)) {
            $this->rateLimiter->clear($rlKey);
            Auth::login($fastPathUser);

            return true;
        }

        // 1. Resolver legacy SIEMPRE primero. Fuente de verdad de la identidad.
        $legacy = $this->db->table($meta['table'])
            ->where('email', $email)
            ->first();

        if (! $legacy) {
            $this->rateLimiter->hit($rlKey, self::DECAY_SECONDS);

            return false;
        }

        $legacyId = (int) $legacy->{$meta['pk']};
        $legacyHash = $legacy->{$meta['password_col']} ?? '';

        // 2. Lookup canónico por (legacy_kind, legacy_id) — NUNCA por email.
        //    Si el email cambió en legacy entre logins, esta clave compuesta
        //    sigue resolviendo a la misma fila lv_users.
        $user = User::query()
            ->where('legacy_kind', $roleHint)
            ->where('legacy_id', $legacyId)
            ->first();

        // 3. Bcrypt OK -> happy path post-migración.
        if ($user !== null && $user->password !== null && Hash::check($password, $user->password)) {
            $this->rateLimiter->clear($rlKey);
            Auth::login($user);

            return true;
        }

        // 4. Bcrypt falló o todavía no había fila lv_users. Validar contra SHA1
        //    legacy timing-safe. Cubre tanto el primer login (lazy create) como
        //    el caso "el usuario cambió password en la app vieja después de migrar".
        if (! hash_equals(sha1($password), strtolower((string) $legacyHash))) {
            $this->rateLimiter->hit($rlKey, self::DECAY_SECONDS);

            return false;
        }

        // 5. SHA1 OK. Crear o actualizar lv_users con bcrypt fresco.
        //    updateOrCreate por (legacy_kind, legacy_id). Si el email cambió
        //    en legacy, la fila existente se actualiza con el email nuevo.
        $user = User::updateOrCreate(
            ['legacy_kind' => $roleHint, 'legacy_id' => $legacyId],
            [
                'email' => $legacy->email,
                'name' => $this->resolveName($legacy, $meta['name_cols']) ?? $legacy->email,
                'password' => $password, // cast 'hashed' aplica bcrypt
                'legacy_password_sha1' => null,
                'lv_password_migrated_at' => now(),
            ]
        );

        $this->rateLimiter->clear($rlKey);
        Auth::login($user);

        return true;
    }

    private function rateLimitKey(string $ip, string $email, string $roleHint): string
    {
        return 'legacy-login:'.$ip.'|'.strtolower($email).'|'.$roleHint;
    }

    /**
     * @param  array<int, string>  $cols
     */
    private function resolveName(object $legacy, array $cols): ?string
    {
        foreach ($cols as $col) {
            if (! empty($legacy->{$col} ?? null)) {
                return (string) $legacy->{$col};
            }
        }

        return null;
    }
}
