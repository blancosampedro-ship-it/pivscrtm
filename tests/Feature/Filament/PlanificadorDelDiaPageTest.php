<?php

declare(strict_types=1);

use App\Filament\Pages\PlanificadorDelDia;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin puede acceder a planificador-dia', function (): void {
    $this->get('/admin/planificador-dia')->assertOk();
});

it('non-admin no puede acceder', function (): void {
    $this->actingAs(User::factory()->tecnico()->create());

    $this->get('/admin/planificador-dia')->assertForbidden();
});

it('mount inicializa con today y computa resultado', function (): void {
    $today = CarbonImmutable::parse('2026-05-06 10:00:00', 'Europe/Madrid');
    CarbonImmutable::setTestNow($today);

    try {
        Livewire::test(PlanificadorDelDia::class)
            ->assertSet('data.fecha', '2026-05-06')
            ->assertSeeText('Total items hoy');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('cambio de fecha re-computa resultado', function (): void {
    Livewire::test(PlanificadorDelDia::class)
        ->set('data.fecha', '2026-05-10')
        ->assertSet('resultado.fecha', '2026-05-10');
});

it('slug explicito planificador-dia', function (): void {
    expect(PlanificadorDelDia::getSlug())->toBe('planificador-dia');
});

it('navegacion grupo Planificacion', function (): void {
    expect(PlanificadorDelDia::getNavigationGroup())->toBe('Planificación');
    expect(PlanificadorDelDia::getNavigationLabel())->toBe('Planificador del día');
});
