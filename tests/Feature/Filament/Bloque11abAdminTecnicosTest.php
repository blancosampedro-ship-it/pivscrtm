<?php

declare(strict_types=1);

use App\Filament\Resources\TecnicoResource;
use App\Filament\Resources\TecnicoResource\Pages;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('tecnico_resource_visible_in_sidebar_under_personas', function (): void {
    expect(TecnicoResource::shouldRegisterNavigation())->toBeTrue();
    expect(TecnicoResource::getNavigationGroup())->toBe('Personas');
});

it('admin_can_create_tecnico_with_initial_password_hashed_as_sha1', function (): void {
    Livewire::test(Pages\CreateTecnico::class)
        ->fillForm([
            'nombre_completo' => 'Juan Pérez Test',
            'usuario' => 'jperez_test',
            'email' => 'jperez.test@winfin.local',
            'dni' => '12345678A',
            'password_plain' => 'SECRET-test-pass-1',
            'status' => true,
        ])
        ->call('create')
        ->assertHasNoErrors();

    $created = Tecnico::where('email', 'jperez.test@winfin.local')->first();
    expect($created)->not->toBeNull();
    expect($created->clave)->toBe(sha1('SECRET-test-pass-1'));
    expect((int) $created->status)->toBe(1);
});

it('created_tecnico_can_login_via_legacy_hash_guard_end_to_end', function (): void {
    Livewire::test(Pages\CreateTecnico::class)
        ->fillForm([
            'nombre_completo' => 'Test E2E',
            'usuario' => 'test_e2e',
            'email' => 'test.e2e@winfin.local',
            'password_plain' => 'mySecretPass-e2e!',
            'status' => true,
        ])
        ->call('create')
        ->assertHasNoErrors();

    auth()->logout();

    $response = Volt::test('tecnico.login')
        ->set('email', 'test.e2e@winfin.local')
        ->set('password', 'mySecretPass-e2e!')
        ->call('login');

    $response->assertHasNoErrors();
    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->isTecnico())->toBeTrue();
});

it('admin_editing_tecnico_without_password_change_preserves_clave', function (): void {
    $tecnico = Tecnico::factory()->create([
        'tecnico_id' => 88001,
        'clave' => sha1('original-pass'),
    ]);

    Livewire::test(Pages\EditTecnico::class, ['record' => $tecnico->getRouteKey()])
        ->fillForm([
            'nombre_completo' => 'Nombre Actualizado',
            'password_plain' => '',
        ])
        ->call('save')
        ->assertHasNoErrors();

    $tecnico->refresh();
    expect($tecnico->clave)->toBe(sha1('original-pass'));
    expect($tecnico->nombre_completo)->toBe('Nombre Actualizado');
});

it('admin_editing_tecnico_with_password_change_updates_clave_to_new_sha1', function (): void {
    $tecnico = Tecnico::factory()->create([
        'tecnico_id' => 88002,
        'clave' => sha1('original-pass'),
    ]);

    Livewire::test(Pages\EditTecnico::class, ['record' => $tecnico->getRouteKey()])
        ->fillForm([
            'password_plain' => 'new-rotated-pass-123',
        ])
        ->call('save')
        ->assertHasNoErrors();

    $tecnico->refresh();
    expect($tecnico->clave)->toBe(sha1('new-rotated-pass-123'));
    expect($tecnico->clave)->not->toBe(sha1('original-pass'));
});

it('admin_can_deactivate_tecnico', function (): void {
    $tecnico = Tecnico::factory()->create([
        'tecnico_id' => 88003,
        'status' => 1,
    ]);

    Livewire::test(Pages\ListTecnicos::class)
        ->callTableAction('deactivate', $tecnico);

    $tecnico->refresh();
    expect((int) $tecnico->status)->toBe(0);
});

it('admin_can_reactivate_inactive_tecnico', function (): void {
    $tecnico = Tecnico::factory()->create([
        'tecnico_id' => 88004,
        'status' => 0,
    ]);

    Livewire::test(Pages\ListTecnicos::class)
        ->callTableAction('activate', $tecnico);

    $tecnico->refresh();
    expect((int) $tecnico->status)->toBe(1);
});
