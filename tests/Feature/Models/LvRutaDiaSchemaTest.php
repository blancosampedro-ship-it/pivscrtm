<?php

declare(strict_types=1);

use App\Models\LvAveriaIcca;
use App\Models\LvRevisionPendiente;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\Tecnico;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('lv_ruta_dia tables exist with expected columns and indexes', function (): void {
    expect(Schema::hasTable('lv_ruta_dia'))->toBeTrue();
    expect(Schema::hasColumns('lv_ruta_dia', [
        'id', 'tecnico_id', 'fecha', 'status', 'notas_admin', 'created_by_user_id', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasTable('lv_ruta_dia_item'))->toBeTrue();
    expect(Schema::hasColumns('lv_ruta_dia_item', [
        'id', 'ruta_dia_id', 'orden', 'tipo_item', 'lv_averia_icca_id', 'lv_revision_pendiente_id',
        'status', 'causa_no_resolucion', 'notas_tecnico', 'cerrado_at', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('lv_ruta_dia')"));
    $uniqueIndex = $indexes->first(fn (object $index): bool => $index->name === 'uniq_tecnico_fecha');

    expect($uniqueIndex)->not->toBeNull();
    expect((int) $uniqueIndex->unique)->toBe(1);
});

it('unique tecnico fecha prevents duplicate daily routes', function (): void {
    $tecnico = Tecnico::factory()->create();
    LvRutaDia::factory()->create(['tecnico_id' => $tecnico->tecnico_id, 'fecha' => '2026-05-06']);

    expect(fn () => LvRutaDia::factory()->create([
        'tecnico_id' => $tecnico->tecnico_id,
        'fecha' => '2026-05-06',
    ]))->toThrow(QueryException::class);
});

it('modelo LvRutaDia expone relaciones helpers y scope delDia', function (): void {
    $admin = User::factory()->admin()->create();
    $tecnico = Tecnico::factory()->create(['nombre_completo' => 'Ruta Técnico']);
    $ruta = LvRutaDia::factory()->create([
        'tecnico_id' => $tecnico->tecnico_id,
        'created_by_user_id' => $admin->id,
        'fecha' => '2026-05-06',
        'status' => LvRutaDia::STATUS_PLANIFICADA,
    ]);

    LvRutaDiaItem::factory()->create(['ruta_dia_id' => $ruta->id, 'orden' => 2]);
    LvRutaDiaItem::factory()->create(['ruta_dia_id' => $ruta->id, 'orden' => 1]);

    expect($ruta->tecnico->nombre_completo)->toBe('Ruta Técnico');
    expect($ruta->createdBy->id)->toBe($admin->id);
    expect($ruta->items->pluck('orden')->all())->toBe([1, 2]);
    expect($ruta->isEditable())->toBeTrue();
    expect(LvRutaDia::query()->delDia(CarbonImmutable::parse('2026-05-06'))->count())->toBe(1);
});

it('status constants de LvRutaDia son los esperados', function (): void {
    expect(LvRutaDia::STATUSES)->toBe([
        LvRutaDia::STATUS_PLANIFICADA,
        LvRutaDia::STATUS_EN_PROGRESO,
        LvRutaDia::STATUS_COMPLETADA,
        LvRutaDia::STATUS_CANCELADA,
    ]);
});

it('solo rutas planificada y en_progreso son editables', function (): void {
    expect(LvRutaDia::factory()->make(['status' => LvRutaDia::STATUS_PLANIFICADA])->isEditable())->toBeTrue();
    expect(LvRutaDia::factory()->make(['status' => LvRutaDia::STATUS_EN_PROGRESO])->isEditable())->toBeTrue();
    expect(LvRutaDia::factory()->make(['status' => LvRutaDia::STATUS_COMPLETADA])->isEditable())->toBeFalse();
    expect(LvRutaDia::factory()->make(['status' => LvRutaDia::STATUS_CANCELADA])->isEditable())->toBeFalse();
});

it('modelo LvRutaDiaItem exige exactamente un origen', function (): void {
    $ruta = LvRutaDia::factory()->create();
    $averia = LvAveriaIcca::factory()->create();
    $revision = LvRevisionPendiente::factory()->create();

    expect(fn () => LvRutaDiaItem::create([
        'ruta_dia_id' => $ruta->id,
        'orden' => 1,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'status' => LvRutaDiaItem::STATUS_PENDIENTE,
    ]))->toThrow(DomainException::class);

    expect(fn () => LvRutaDiaItem::create([
        'ruta_dia_id' => $ruta->id,
        'orden' => 1,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'lv_averia_icca_id' => $averia->id,
        'lv_revision_pendiente_id' => $revision->id,
        'status' => LvRutaDiaItem::STATUS_PENDIENTE,
    ]))->toThrow(DomainException::class);
});

it('modelo LvRutaDiaItem resuelve relaciones de origen', function (): void {
    $ruta = LvRutaDia::factory()->create();
    $averia = LvAveriaIcca::factory()->create();
    $item = LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'lv_averia_icca_id' => $averia->id,
    ]);

    expect($item->rutaDia->id)->toBe($ruta->id);
    expect($item->averiaIcca->id)->toBe($averia->id);
    expect($item->revisionPendiente)->toBeNull();
});
