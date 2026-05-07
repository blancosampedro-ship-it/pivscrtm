<?php

declare(strict_types=1);

use App\Models\LvDerivacion;
use App\Models\LvRutaDiaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('tabla lv_derivacion existe con columnas indexes y fks fisicas', function (): void {
    expect(Schema::hasTable('lv_derivacion'))->toBeTrue();
    expect(Schema::hasColumns('lv_derivacion', [
        'id',
        'lv_ruta_dia_item_id',
        'tipo_causa',
        'causa_otros_texto',
        'actor_responsable',
        'actor_notas',
        'notas_derivacion',
        'fecha_derivacion',
        'derivado_por_user_id',
        'status',
        'fecha_resolucion',
        'resuelto_notas',
        'resuelto_por_user_id',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('lv_derivacion')"))->pluck('name')->all();

    expect($indexes)->toContain('idx_derivacion_status');
    expect($indexes)->toContain('idx_derivacion_tipo_causa');
    expect($indexes)->toContain('idx_derivacion_actor');
    expect($indexes)->toContain('idx_derivacion_fecha');
    expect($indexes)->toContain('idx_derivacion_item_status');

    $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('lv_derivacion')"));

    expect($foreignKeys->firstWhere('from', 'lv_ruta_dia_item_id')?->on_delete)->toBe('CASCADE');
    expect($foreignKeys->firstWhere('from', 'derivado_por_user_id')?->on_delete)->toBe('SET NULL');
    expect($foreignKeys->firstWhere('from', 'resuelto_por_user_id')?->on_delete)->toBe('SET NULL');
});

it('modelo LvDerivacion resuelve relaciones scopes y helpers', function (): void {
    $admin = User::factory()->admin()->create();
    $item = LvRutaDiaItem::factory()->create();
    $abierta = LvDerivacion::factory()->create([
        'lv_ruta_dia_item_id' => $item->id,
        'derivado_por_user_id' => $admin->id,
        'tipo_causa' => LvDerivacion::CAUSA_OTROS,
    ]);
    LvDerivacion::factory()->cerrada(LvDerivacion::STATUS_CANCELADA)->create();

    expect($abierta->item->id)->toBe($item->id);
    expect($abierta->derivadoPor->id)->toBe($admin->id);
    expect(LvDerivacion::query()->abiertas()->count())->toBe(1);
    expect(LvDerivacion::query()->cerradas()->count())->toBe(1);
    expect(LvDerivacion::query()->porActor(LvDerivacion::ACTOR_INTERNO_WINFIN)->count())->toBe(2);
    expect($abierta->isAbierta())->toBeTrue();
    expect($abierta->isCerrada())->toBeFalse();
    expect($abierta->requiereCausaOtrosTexto())->toBeTrue();
});

it('catalogos de causa y actor no estan vacios y causa otros se detecta solo en otros', function (): void {
    expect(LvDerivacion::CAUSAS)->not->toBeEmpty();
    expect(LvDerivacion::ACTORES)->not->toBeEmpty();

    expect(LvDerivacion::factory()->make(['tipo_causa' => LvDerivacion::CAUSA_OTROS])->requiereCausaOtrosTexto())->toBeTrue();
    expect(LvDerivacion::factory()->make(['tipo_causa' => LvDerivacion::CAUSA_TERCERO])->requiereCausaOtrosTexto())->toBeFalse();
});

it('LvRutaDiaItem expone status derivado relacion abierta e historial', function (): void {
    $item = LvRutaDiaItem::factory()->create();
    $cerrada = LvDerivacion::factory()->cerrada(LvDerivacion::STATUS_DEVUELTO_A_RUTA)->create(['lv_ruta_dia_item_id' => $item->id]);

    expect(LvRutaDiaItem::STATUSES)->toContain(LvRutaDiaItem::STATUS_DERIVADO);
    expect($item->fresh()->tieneDerivacionAbierta())->toBeFalse();
    expect($item->fresh()->derivacionAbierta)->toBeNull();
    expect($item->fresh()->derivaciones->pluck('id')->all())->toBe([$cerrada->id]);

    $abierta = LvDerivacion::factory()->create(['lv_ruta_dia_item_id' => $item->id]);

    expect($item->fresh()->tieneDerivacionAbierta())->toBeTrue();
    expect($item->fresh()->derivacionAbierta->id)->toBe($abierta->id);
});
