<?php

declare(strict_types=1);

use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('comando con year y month genera filas para ese periodo', function (): void {
    Piv::factory()->count(3)->create();

    $this->artisan('lv:generate-revision-pendiente-monthly', [
        '--year' => 2026,
        '--month' => 5,
    ])->assertSuccessful();

    expect(LvRevisionPendiente::query()->delMes(2026, 5)->count())->toBe(3);
});

it('comando sin opciones usa now Europe Madrid', function (): void {
    Piv::factory()->count(2)->create();
    $now = now('Europe/Madrid');

    $this->artisan('lv:generate-revision-pendiente-monthly')->assertSuccessful();

    expect(LvRevisionPendiente::query()->delMes($now->year, $now->month)->count())->toBe(2);
});

it('comando con mes invalido devuelve invalid', function (): void {
    $this->artisan('lv:generate-revision-pendiente-monthly', [
        '--year' => 2026,
        '--month' => 13,
    ])->assertFailed();
});

it('cron mensual esta registrado en schedule', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command);

    expect($events->contains(
        fn ($command): bool => is_string($command) && str_contains($command, 'lv:generate-revision-pendiente-monthly')
    ))->toBeTrue();
});
