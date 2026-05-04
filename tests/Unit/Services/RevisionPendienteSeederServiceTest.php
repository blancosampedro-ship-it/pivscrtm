<?php

declare(strict_types=1);

use App\Models\LvPivArchived;
use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use App\Services\RevisionPendienteSeederService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->svc = new RevisionPendienteSeederService;
});

it('crea una fila por cada Piv no archivado', function (): void {
    Piv::factory()->count(5)->create();
    $archived = Piv::factory()->create();
    LvPivArchived::factory()->create(['piv_id' => $archived->piv_id]);

    $result = $this->svc->generarMes(2026, 5);

    expect($result['created'])->toBe(5);
    expect($result['total_panels'])->toBe(5);
    expect(LvRevisionPendiente::query()->count())->toBe(5);
});

it('es idempotente y re-run no duplica ni cambia status decidido', function (): void {
    Piv::factory()->count(3)->create();

    $first = $this->svc->generarMes(2026, 5);
    expect($first['created'])->toBe(3);

    $decidida = LvRevisionPendiente::query()->first();
    $decidida->update(['status' => LvRevisionPendiente::STATUS_EXCEPCION]);

    $second = $this->svc->generarMes(2026, 5);

    expect($second['created'])->toBe(0);
    expect($second['already_existed'])->toBe(3);
    expect(LvRevisionPendiente::query()->count())->toBe(3);
    expect($decidida->fresh()->status)->toBe(LvRevisionPendiente::STATUS_EXCEPCION);
});

it('carry over panel pendiente mes anterior recibe carry_over_origen_id', function (): void {
    $piv = Piv::factory()->create();
    $previous = LvRevisionPendiente::factory()->pendiente()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::query()->where('piv_id', $piv->piv_id)->delMes(2026, 5)->first();

    expect($current)->not->toBeNull();
    expect($current->carry_over_origen_id)->toBe($previous->id);
    expect($current->status)->toBe(LvRevisionPendiente::STATUS_PENDIENTE);
});

it('carry over panel requiere_visita mes anterior arrastra', function (): void {
    $piv = Piv::factory()->create();
    $previous = LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::query()->where('piv_id', $piv->piv_id)->delMes(2026, 5)->first();
    expect($current->carry_over_origen_id)->toBe($previous->id);
});

it('carry over panel excepcion mes anterior arrastra', function (): void {
    $piv = Piv::factory()->create();
    $previous = LvRevisionPendiente::factory()->excepcion()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::query()->where('piv_id', $piv->piv_id)->delMes(2026, 5)->first();
    expect($current->carry_over_origen_id)->toBe($previous->id);
});

it('carry over panel verificada_remoto mes anterior no arrastra', function (): void {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->verificadaRemoto()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::query()->where('piv_id', $piv->piv_id)->delMes(2026, 5)->first();
    expect($current)->not->toBeNull();
    expect($current->carry_over_origen_id)->toBeNull();
});

it('carry over panel completada mes anterior no arrastra', function (): void {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->completada()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::query()->where('piv_id', $piv->piv_id)->delMes(2026, 5)->first();
    expect($current->carry_over_origen_id)->toBeNull();
});

it('cruce de anio enero busca diciembre del anio anterior', function (): void {
    $piv = Piv::factory()->create();
    $previous = LvRevisionPendiente::factory()->pendiente()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2025,
        'periodo_month' => 12,
    ]);

    $this->svc->generarMes(2026, 1);

    $current = LvRevisionPendiente::query()->where('piv_id', $piv->piv_id)->delMes(2026, 1)->first();
    expect($current->carry_over_origen_id)->toBe($previous->id);
});
