<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Services\AsignacionCierreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function asignacionRevisionPendienteHookAsignacion(?int $asignacionId = null): Asignacion
{
    $piv = Piv::factory()->create();
    $tecnico = Tecnico::factory()->create();
    $averia = Averia::factory()->for($piv, 'piv')->create();

    return Asignacion::factory()
        ->for($averia, 'averia')
        ->for($tecnico, 'tecnico')
        ->create([
            'asignacion_id' => $asignacionId,
            'tipo' => Asignacion::TIPO_REVISION,
            'status' => 1,
        ]);
}

it('hook cerrar asignacion con lv_revision_pendiente asociada marca completada', function (): void {
    $asignacion = asignacionRevisionPendienteHookAsignacion(99101);
    $piv = $asignacion->averia->piv;

    $revisionPendiente = LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $piv->piv_id,
        'asignacion_id' => $asignacion->asignacion_id,
    ]);

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'fecha' => '2026-05-04',
        'aspecto' => 'OK',
        'funcionamiento' => 'OK',
    ]);

    expect($revisionPendiente->fresh()->status)->toBe(LvRevisionPendiente::STATUS_COMPLETADA);
});

it('hook cerrar asignacion sin lv_revision_pendiente no rompe', function (): void {
    $asignacion = asignacionRevisionPendienteHookAsignacion(99102);

    expect(fn () => app(AsignacionCierreService::class)->cerrar($asignacion, [
        'fecha' => '2026-05-04',
        'aspecto' => 'OK',
    ]))->not->toThrow(Throwable::class);

    expect($asignacion->fresh()->status)->toBe(2);
});

it('hook no machaca updated_at si revision pendiente ya estaba completada', function (): void {
    $asignacion = asignacionRevisionPendienteHookAsignacion(99103);
    $piv = $asignacion->averia->piv;
    $originalUpdatedAt = Carbon::parse('2026-05-04 10:00:00');

    $revisionPendiente = LvRevisionPendiente::factory()->completada()->create([
        'piv_id' => $piv->piv_id,
        'asignacion_id' => $asignacion->asignacion_id,
        'updated_at' => $originalUpdatedAt,
    ]);

    Carbon::setTestNow('2026-05-04 11:00:00');

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'fecha' => '2026-05-04',
        'aspecto' => 'OK',
    ]);

    Carbon::setTestNow();

    expect($revisionPendiente->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});
