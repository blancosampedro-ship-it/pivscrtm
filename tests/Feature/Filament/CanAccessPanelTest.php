<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('admin can access the admin panel', function () {
    $user = User::factory()->admin()->create();
    $panel = Filament::getPanel('admin');
    expect($user->canAccessPanel($panel))->toBeTrue();
});

it('tecnico cannot access the admin panel', function () {
    $user = User::factory()->tecnico()->create();
    $panel = Filament::getPanel('admin');
    expect($user->canAccessPanel($panel))->toBeFalse();
});

it('operador cannot access the admin panel', function () {
    $user = User::factory()->operador()->create();
    $panel = Filament::getPanel('admin');
    expect($user->canAccessPanel($panel))->toBeFalse();
});

it('non-admin authenticated user gets 403 visiting /admin', function () {
    $tecnico = User::factory()->tecnico()->create();
    $this->actingAs($tecnico)->get('/admin')->assertForbidden();
});

it('admin authenticated user gets through to /admin', function () {
    $admin = User::factory()->admin()->create();
    // Filament dashboard responde 200 o redirige a página interna; ambos OK.
    $response = $this->actingAs($admin)->get('/admin');
    expect($response->status())->toBeIn([200, 302]);
});
