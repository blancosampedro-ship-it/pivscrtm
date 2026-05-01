<?php

declare(strict_types=1);

use App\Auth\LegacyHashGuard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Limpiar el RateLimiter entre tests para evitar bleed.
    foreach ([
        'legacy-login:127.0.0.1|info@winfin.es|admin',
        'legacy-login:127.0.0.1|tec@winfin.es|tecnico',
        'legacy-login:127.0.0.1|op@winfin.es|operador',
        'legacy-login:127.0.0.1|x@x.x|admin',
    ] as $key) {
        RateLimiter::clear($key);
    }
});

function makeRequest(string $ip = '127.0.0.1'): Request
{
    $r = Request::create('/admin/login', 'POST');
    $r->server->set('REMOTE_ADDR', $ip);

    return $r;
}

function guard(): LegacyHashGuard
{
    return app(LegacyHashGuard::class);
}

function seedU1(int $userId, string $email, string $plainPassword): void
{
    DB::table('u1')->insert([
        'user_id' => $userId,
        'username' => 'admin'.$userId,
        'email' => $email,
        'password' => sha1($plainPassword),
    ]);
}

function seedTecnico(int $tecnicoId, string $email, string $plainPassword, ?string $nombre = null): void
{
    DB::table('tecnico')->insert([
        'tecnico_id' => $tecnicoId,
        'usuario' => 'usr_tec_'.$tecnicoId,
        'email' => $email,
        'clave' => sha1($plainPassword),
        'nombre_completo' => $nombre ?? 'Tecnico Test '.$tecnicoId,
    ]);
}

function seedOperador(int $operadorId, string $email, string $plainPassword, ?string $razonSocial = null): void
{
    DB::table('operador')->insert([
        'operador_id' => $operadorId,
        'usuario' => 'usr_op_'.$operadorId,
        'email' => $email,
        'clave' => sha1($plainPassword),
        'razon_social' => $razonSocial ?? 'Operador Test '.$operadorId,
    ]);
}

// ---------- Tests obligatorios DoD Bloque 06 ----------

it('legacy_login_rehashes_to_bcrypt', function () {
    seedU1(userId: 100, email: 'admin100@winfin.local', plainPassword: 'secret-pwd');
    expect(User::where('legacy_kind', 'admin')->where('legacy_id', 100)->count())->toBe(0);

    $ok = guard()->attempt('admin100@winfin.local', 'secret-pwd', 'admin', makeRequest());
    expect($ok)->toBeTrue();

    $u = User::where('legacy_kind', 'admin')->where('legacy_id', 100)->firstOrFail();
    expect(str_starts_with($u->password, '$2y$'))->toBeTrue();
    expect($u->legacy_password_sha1)->toBeNull();
    expect($u->lv_password_migrated_at)->not->toBeNull();

    Auth::logout();
    expect(guard()->attempt('admin100@winfin.local', 'secret-pwd', 'admin', makeRequest()))->toBeTrue();
});

it('legacy_login_uses_hash_equals', function () {
    seedU1(userId: 101, email: 'admin101@winfin.local', plainPassword: 'pwd-X');

    expect(guard()->attempt('admin101@winfin.local', 'pwd-X', 'admin', makeRequest()))->toBeTrue();

    // Cambiar el hash a uppercase en BD (algunas tablas legacy lo guardan así).
    DB::table('u1')->where('user_id', 101)->update([
        'password' => strtoupper(sha1('pwd-X')),
    ]);
    User::where('legacy_kind', 'admin')->where('legacy_id', 101)->delete();

    expect(guard()->attempt('admin101@winfin.local', 'pwd-X', 'admin', makeRequest()))
        ->toBeTrue('uppercase SHA1 debe seguir matcheando via strtolower()');
});

it('bcrypt_fail_falls_back_to_legacy_lookup', function () {
    seedU1(userId: 102, email: 'admin102@winfin.local', plainPassword: 'NEW-pwd');
    User::create([
        'legacy_kind' => 'admin',
        'legacy_id' => 102,
        'email' => 'admin102@winfin.local',
        'name' => 'admin102',
        'password' => Hash::make('OLD-pwd'),
        'legacy_password_sha1' => null,
        'lv_password_migrated_at' => now()->subDay(),
    ]);

    $ok = guard()->attempt('admin102@winfin.local', 'NEW-pwd', 'admin', makeRequest());
    expect($ok)->toBeTrue();

    $u = User::where('legacy_kind', 'admin')->where('legacy_id', 102)->firstOrFail();
    expect(Hash::check('NEW-pwd', $u->password))->toBeTrue('bcrypt actualizado al password nuevo');
});

it('wrong_password_never_creates_lv_user_row', function () {
    seedU1(userId: 103, email: 'admin103@winfin.local', plainPassword: 'good-pwd');
    expect(User::count())->toBe(0);

    $ok = guard()->attempt('admin103@winfin.local', 'WRONG-pwd', 'admin', makeRequest());
    expect($ok)->toBeFalse();
    expect(User::count())->toBe(0);
});

it('lookup_canonical_by_legacy_kind_legacy_id', function () {
    seedU1(userId: 104, email: 'old@winfin.local', plainPassword: 'pwd');
    expect(guard()->attempt('old@winfin.local', 'pwd', 'admin', makeRequest()))->toBeTrue();

    $rowId = User::where('legacy_kind', 'admin')->where('legacy_id', 104)->value('id');
    expect($rowId)->not->toBeNull();

    DB::table('u1')->where('user_id', 104)->update(['email' => 'new@winfin.local']);

    // Borrar bcrypt para forzar el camino SHA1+updateOrCreate.
    User::where('id', $rowId)->update(['password' => null]);
    Auth::logout();

    expect(guard()->attempt('new@winfin.local', 'pwd', 'admin', makeRequest()))->toBeTrue();

    expect(User::where('legacy_kind', 'admin')->where('legacy_id', 104)->count())->toBe(1);
    expect(User::find($rowId)->email)->toBe('new@winfin.local');
});

it('login_throttles_after_5_failures', function () {
    seedU1(userId: 105, email: 'x@x.x', plainPassword: 'right');

    for ($i = 0; $i < LegacyHashGuard::MAX_ATTEMPTS; $i++) {
        expect(guard()->attempt('x@x.x', 'wrong', 'admin', makeRequest()))->toBeFalse();
    }

    expect(fn () => guard()->attempt('x@x.x', 'right', 'admin', makeRequest()))
        ->toThrow(ValidationException::class);
});

it('successful_login_clears_rate_limit', function () {
    seedU1(userId: 106, email: 'admin106@winfin.local', plainPassword: 'good');

    for ($i = 0; $i < 4; $i++) {
        guard()->attempt('admin106@winfin.local', 'wrong', 'admin', makeRequest());
    }

    expect(RateLimiter::attempts('legacy-login:127.0.0.1|admin106@winfin.local|admin'))->toBe(4);

    expect(guard()->attempt('admin106@winfin.local', 'good', 'admin', makeRequest()))->toBeTrue();

    expect(RateLimiter::attempts('legacy-login:127.0.0.1|admin106@winfin.local|admin'))->toBe(0);
});

// ---------- Tests obligatorios adicionales (ADR-0008) ----------

it('legacy_login_uses_correct_password_column for u1.password', function () {
    seedU1(userId: 200, email: 'a@a.a', plainPassword: 'ppp');
    expect(guard()->attempt('a@a.a', 'ppp', 'admin', makeRequest()))->toBeTrue();
});

it('legacy_login_uses_correct_password_column for tecnico.clave', function () {
    seedTecnico(tecnicoId: 200, email: 't@t.t', plainPassword: 'qqq');
    expect(guard()->attempt('t@t.t', 'qqq', 'tecnico', makeRequest()))->toBeTrue();

    $u = User::where('legacy_kind', 'tecnico')->where('legacy_id', 200)->firstOrFail();
    expect($u->name)->toBe('Tecnico Test 200');
});

it('legacy_login_uses_correct_password_column for operador.clave', function () {
    seedOperador(operadorId: 200, email: 'o@o.o', plainPassword: 'rrr');
    expect(guard()->attempt('o@o.o', 'rrr', 'operador', makeRequest()))->toBeTrue();

    $u = User::where('legacy_kind', 'operador')->where('legacy_id', 200)->firstOrFail();
    expect($u->name)->toBe('Operador Test 200');
});

it('u1_user_id_pk_works_with_lv_users_lookup', function () {
    seedU1(userId: 999, email: 'admin999@winfin.local', plainPassword: 'pwd');
    expect(guard()->attempt('admin999@winfin.local', 'pwd', 'admin', makeRequest()))->toBeTrue();

    expect(User::where('legacy_kind', 'admin')->where('legacy_id', 999)->exists())->toBeTrue();
});

it('email_change_in_legacy_after_first_login_does_not_create_new_lv_user_row', function () {
    seedU1(userId: 300, email: 'orig@a.a', plainPassword: 'pp');
    guard()->attempt('orig@a.a', 'pp', 'admin', makeRequest());
    $idBefore = User::where('legacy_kind', 'admin')->where('legacy_id', 300)->value('id');

    DB::table('u1')->where('user_id', 300)->update(['email' => 'changed@a.a']);
    User::where('id', $idBefore)->update(['password' => null]);
    Auth::logout();

    guard()->attempt('changed@a.a', 'pp', 'admin', makeRequest());

    expect(User::where('legacy_kind', 'admin')->where('legacy_id', 300)->count())->toBe(1);
    expect(User::find($idBefore)->email)->toBe('changed@a.a');
});
