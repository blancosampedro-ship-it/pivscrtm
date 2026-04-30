<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates and reads from lv_users', function () {
    User::factory()->create([
        'legacy_kind' => 'admin',
        'legacy_id' => 1,
        'email' => 'admin@winfin.local',
        'name' => 'Admin Test',
    ]);
    $found = User::where('email', 'admin@winfin.local')->first();
    expect($found)->not->toBeNull();
    expect($found->legacy_kind)->toBe('admin');
});

it('hides password and legacy_password_sha1 from serialization', function () {
    $u = User::factory()->create();
    $arr = $u->toArray();
    expect($arr)->not->toHaveKey('password');
    expect($arr)->not->toHaveKey('legacy_password_sha1');
    expect($arr)->not->toHaveKey('remember_token');
});

it('exposes role helpers', function () {
    expect(User::factory()->admin()->make()->isAdmin())->toBeTrue();
    expect(User::factory()->tecnico()->make()->isTecnico())->toBeTrue();
    expect(User::factory()->operador()->make()->isOperador())->toBeTrue();
});

it('enforces unique (legacy_kind, legacy_id)', function () {
    User::factory()->create(['legacy_kind' => 'tecnico', 'legacy_id' => 5]);
    expect(fn () => User::factory()->create(['legacy_kind' => 'tecnico', 'legacy_id' => 5]))
        ->toThrow(QueryException::class);
});

it('allows same email across different legacy_kind (cross-tabla colision)', function () {
    User::factory()->create(['legacy_kind' => 'tecnico', 'legacy_id' => 10, 'email' => 'shared@winfin.local']);
    $op = User::factory()->create(['legacy_kind' => 'operador', 'legacy_id' => 20, 'email' => 'shared@winfin.local']);
    expect($op)->not->toBeNull();
});
