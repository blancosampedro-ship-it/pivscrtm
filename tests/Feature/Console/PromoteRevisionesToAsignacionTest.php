<?php

declare(strict_types=1);

use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('comando con date promueve para esa fecha', function (): void {
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
        'fecha_planificada' => '2026-05-15',
    ]);

    $this->artisan('lv:promote-revisiones-to-asignacion', ['--date' => '2026-05-15'])
        ->assertSuccessful();

    expect(LvRevisionPendiente::query()->firstOrFail()->fresh()->asignacion_id)->not->toBeNull();
});

it('comando con date invalida devuelve invalid', function (): void {
    $this->artisan('lv:promote-revisiones-to-asignacion', ['--date' => 'no-es-fecha'])
        ->assertFailed();
});

it('comando sin date usa today Europe Madrid', function (): void {
    $today = CarbonImmutable::parse('2026-05-15 10:00:00', 'Europe/Madrid');
    CarbonImmutable::setTestNow($today);
    try {
        LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
            'fecha_planificada' => $today->startOfDay(),
        ]);

        $this->artisan('lv:promote-revisiones-to-asignacion')->assertSuccessful();

        expect(LvRevisionPendiente::query()->firstOrFail()->fresh()->asignacion_id)->not->toBeNull();
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('cron daily registrado en schedule', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command);

    expect($events->contains(fn ($command): bool => is_string($command)
        && str_contains($command, 'lv:promote-revisiones-to-asignacion')))->toBeTrue();
});
