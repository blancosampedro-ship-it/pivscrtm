<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('schema tiene las columnas esperadas', function (): void {
    expect(Schema::hasTable('lv_revision_pendiente'))->toBeTrue();

    $columns = [
        'id',
        'piv_id',
        'periodo_year',
        'periodo_month',
        'status',
        'fecha_planificada',
        'decision_user_id',
        'decision_at',
        'decision_notas',
        'carry_over_origen_id',
        'asignacion_id',
        'created_at',
        'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('lv_revision_pendiente', $column))->toBeTrue();
    }
});

it('unique compuesto piv_id periodo_year periodo_month previene duplicados', function (): void {
    $piv = Piv::factory()->create();

    LvRevisionPendiente::factory()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 5,
    ]);

    expect(fn () => LvRevisionPendiente::factory()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 5,
    ]))->toThrow(QueryException::class);
});

it('statuses incompletas contiene exactamente pendiente requiere_visita excepcion', function (): void {
    expect(LvRevisionPendiente::STATUSES_INCOMPLETAS)->toBe([
        LvRevisionPendiente::STATUS_PENDIENTE,
        LvRevisionPendiente::STATUS_REQUIERE_VISITA,
        LvRevisionPendiente::STATUS_EXCEPCION,
    ]);
});

it('statuses satisfechas contiene exactamente verificada_remoto completada', function (): void {
    expect(LvRevisionPendiente::STATUSES_SATISFECHAS)->toBe([
        LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
        LvRevisionPendiente::STATUS_COMPLETADA,
    ]);
});

it('relaciones piv decisionUser carryOverOrigen asignacion existen', function (): void {
    $piv = Piv::factory()->create();
    $user = User::factory()->admin()->create();
    $asignacion = Asignacion::factory()->create();
    $origin = LvRevisionPendiente::factory()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $row = LvRevisionPendiente::factory()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 5,
        'decision_user_id' => $user->id,
        'carry_over_origen_id' => $origin->id,
        'asignacion_id' => $asignacion->asignacion_id,
    ]);

    expect($row->piv)->toBeInstanceOf(Piv::class);
    expect($row->decisionUser)->toBeInstanceOf(User::class);
    expect($row->carryOverOrigen)->toBeInstanceOf(LvRevisionPendiente::class);
    expect($row->asignacion)->toBeInstanceOf(Asignacion::class);
    expect($piv->revisionesPendientes()->whereKey($row->id)->exists())->toBeTrue();
    expect($row->isCarryOver())->toBeTrue();
});

it('scope incompletas filtra solo los 3 status', function (): void {
    collect([
        LvRevisionPendiente::STATUS_PENDIENTE,
        LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
        LvRevisionPendiente::STATUS_REQUIERE_VISITA,
        LvRevisionPendiente::STATUS_EXCEPCION,
        LvRevisionPendiente::STATUS_COMPLETADA,
    ])->each(function (string $status): void {
        LvRevisionPendiente::factory()->create(['status' => $status]);
    });

    expect(LvRevisionPendiente::query()->incompletas()->count())->toBe(3);
});

it('scope satisfechas filtra solo verificada_remoto y completada', function (): void {
    collect([
        LvRevisionPendiente::STATUS_PENDIENTE,
        LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
        LvRevisionPendiente::STATUS_REQUIERE_VISITA,
        LvRevisionPendiente::STATUS_EXCEPCION,
        LvRevisionPendiente::STATUS_COMPLETADA,
    ])->each(function (string $status): void {
        LvRevisionPendiente::factory()->create(['status' => $status]);
    });

    expect(LvRevisionPendiente::query()->satisfechas()->count())->toBe(2);
});

it('scope delMes filtra por year y month', function (): void {
    LvRevisionPendiente::factory()->create(['periodo_year' => 2026, 'periodo_month' => 5]);
    LvRevisionPendiente::factory()->create(['periodo_year' => 2026, 'periodo_month' => 6]);
    LvRevisionPendiente::factory()->create(['periodo_year' => 2025, 'periodo_month' => 5]);

    expect(LvRevisionPendiente::query()->delMes(2026, 5)->count())->toBe(1);
});

it('scope noPromocionadas filtra solo asignacion_id null', function (): void {
    LvRevisionPendiente::factory()->create(['asignacion_id' => null]);
    LvRevisionPendiente::factory()->create(['asignacion_id' => 12345]);

    expect(LvRevisionPendiente::query()->noPromocionadas()->count())->toBe(1);
});

it('scope requiereVisitaParaFecha filtra status y fecha', function (): void {
    $today = CarbonImmutable::parse('2026-05-04', 'Europe/Madrid');

    LvRevisionPendiente::factory()->requiereVisita()->create([
        'fecha_planificada' => $today,
    ]);
    LvRevisionPendiente::factory()->pendiente()->create([
        'fecha_planificada' => $today,
    ]);
    LvRevisionPendiente::factory()->requiereVisita()->create([
        'fecha_planificada' => $today->addDay(),
    ]);

    expect(LvRevisionPendiente::query()->requiereVisitaParaFecha($today)->count())->toBe(1);
});
