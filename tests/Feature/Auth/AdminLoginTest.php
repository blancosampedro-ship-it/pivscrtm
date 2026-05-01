<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('legacy-login:127.0.0.1|info@winfin.es|admin');
    RateLimiter::clear('legacy-login:127.0.0.1|admin2@winfin.local|admin');
});

it('admin_login_via_filament_uses_legacy_hash_guard', function () {
    DB::table('u1')->insert([
        'user_id' => 1,
        'username' => 'admin',
        'email' => 'info@winfin.es',
        'password' => sha1('test-pwd'),
    ]);

    Livewire::test(Login::class)
        ->set('data.email', 'info@winfin.es')
        ->set('data.password', 'test-pwd')
        ->call('authenticate');

    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->legacy_kind)->toBe('admin');
    expect(auth()->user()->legacy_id)->toBe(1);
});

it('admin_login_rejects_wrong_password', function () {
    DB::table('u1')->insert([
        'user_id' => 2,
        'username' => 'admin2',
        'email' => 'admin2@winfin.local',
        'password' => sha1('right'),
    ]);

    Livewire::test(Login::class)
        ->set('data.email', 'admin2@winfin.local')
        ->set('data.password', 'wrong')
        ->call('authenticate')
        ->assertHasErrors();

    expect(auth()->check())->toBeFalse();
    expect(User::count())->toBe(0);
});
