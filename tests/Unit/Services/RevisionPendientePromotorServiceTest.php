<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use App\Services\RevisionPendientePromotorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->svc = new RevisionPendientePromotorService;
});

it('promueve fila requiere_visita con fecha today y crea averia mas asignacion', function (): void {
    $today = CarbonImmutable::parse('2026-05-15', 'Europe/Madrid');
    $piv = Piv::factory()->create();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today,
    ]);

    $result = $this->svc->promoverDelDia($today);

    expect($result['promoted'])->toBe(1);
    expect(Averia::query()->count())->toBe(1);
    expect(Asignacion::query()->count())->toBe(1);

    $averia = Averia::query()->firstOrFail();
    expect($averia->piv_id)->toBe($piv->piv_id);
    expect($averia->notas)->toBe(RevisionPendientePromotorService::NOTAS_AVERIA_STUB);
    expect((int) $averia->status)->toBe(1);

    $asignacion = Asignacion::query()->firstOrFail();
    expect((int) $asignacion->tipo)->toBe(Asignacion::TIPO_REVISION);
    expect((int) $asignacion->status)->toBe(1);
    expect($asignacion->tecnico_id)->toBeNull();
    expect($asignacion->averia_id)->toBe($averia->averia_id);
    expect($asignacion->fecha->format('Y-m-d'))->toBe('2026-05-15');

    expect($row->fresh()->asignacion_id)->toBe($asignacion->asignacion_id);
});

it('es idempotente y re-run no crea segunda asignacion', function (): void {
    $today = CarbonImmutable::parse('2026-05-15', 'Europe/Madrid');
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today,
    ]);

    $this->svc->promoverDelDia($today);
    $firstAverias = Averia::query()->count();
    $firstAsignaciones = Asignacion::query()->count();

    $this->svc->promoverDelDia($today);

    expect(Averia::query()->count())->toBe($firstAverias);
    expect(Asignacion::query()->count())->toBe($firstAsignaciones);
});

it('ignora filas con status pendiente', function (): void {
    $today = CarbonImmutable::parse('2026-05-15', 'Europe/Madrid');
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'fecha_planificada' => $today,
    ]);

    $result = $this->svc->promoverDelDia($today);

    expect($result['promoted'])->toBe(0);
    expect(Averia::query()->count())->toBe(0);
    expect(Asignacion::query()->count())->toBe(0);
});

it('ignora fechas no coincidentes', function (): void {
    $today = CarbonImmutable::parse('2026-05-15', 'Europe/Madrid');
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today->addDay(),
    ]);

    $result = $this->svc->promoverDelDia($today);

    expect($result['promoted'])->toBe(0);
});

it('default sin date usa now Europe Madrid', function (): void {
    $today = CarbonImmutable::parse('2026-05-15 10:00:00', 'Europe/Madrid');
    CarbonImmutable::setTestNow($today);
    try {
        LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
            'fecha_planificada' => $today->startOfDay(),
        ]);

        $result = $this->svc->promoverDelDia();

        expect($result['promoted'])->toBe(1);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('ignora filas requiere_visita ya promocionadas', function (): void {
    $today = CarbonImmutable::parse('2026-05-15', 'Europe/Madrid');
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today,
        'asignacion_id' => 99999,
    ]);

    $result = $this->svc->promoverDelDia($today);

    expect($result['promoted'])->toBe(0);
    expect(Averia::query()->count())->toBe(0);
});
