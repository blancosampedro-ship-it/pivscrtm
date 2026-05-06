<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Correctivo;
use App\Models\LvAveriaIcca;
use App\Models\LvRevisionPendiente;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\LvRutaDiaItemImagen;
use App\Models\Piv;
use App\Models\Revision;
use App\Models\Tecnico;
use App\Services\RutaDiaItemCierreService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function rutaDiaCierreTecnico(int $tecnicoId = 78101): Tecnico
{
    return Tecnico::factory()->create([
        'tecnico_id' => $tecnicoId,
        'status' => 1,
    ]);
}

function rutaDiaCierreRuta(Tecnico $tecnico, array $attributes = []): LvRutaDia
{
    return LvRutaDia::factory()->create([
        'tecnico_id' => $tecnico->tecnico_id,
        'fecha' => $attributes['fecha'] ?? CarbonImmutable::parse('2026-05-06', 'Europe/Madrid')->toDateString(),
        'status' => $attributes['status'] ?? LvRutaDia::STATUS_PLANIFICADA,
    ]);
}

function rutaDiaCierreCorrectivoItem(LvRutaDia $ruta, array $attributes = []): LvRutaDiaItem
{
    $piv = Piv::factory()->create($attributes['piv'] ?? []);
    $averiaIcca = LvAveriaIcca::factory()->create([
        'piv_id' => $piv->piv_id,
        'categoria' => $attributes['categoria'] ?? LvAveriaIcca::CAT_AUDIO,
    ]);

    return LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'orden' => $attributes['orden'] ?? 1,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'lv_averia_icca_id' => $averiaIcca->id,
        'lv_revision_pendiente_id' => null,
        'status' => $attributes['status'] ?? LvRutaDiaItem::STATUS_PENDIENTE,
    ]);
}

function rutaDiaCierreRevisionItem(LvRutaDia $ruta, string $tipo = LvRutaDiaItem::TIPO_PREVENTIVO, array $attributes = []): LvRutaDiaItem
{
    $piv = Piv::factory()->create();
    $revisionPendiente = LvRevisionPendiente::factory()->create([
        'piv_id' => $piv->piv_id,
        'status' => LvRevisionPendiente::STATUS_REQUIERE_VISITA,
        'asignacion_id' => $attributes['asignacion_id'] ?? null,
        'carry_over_origen_id' => $attributes['carry_over_origen_id'] ?? null,
    ]);

    return LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'orden' => $attributes['orden'] ?? 1,
        'tipo_item' => $tipo,
        'lv_averia_icca_id' => null,
        'lv_revision_pendiente_id' => $revisionPendiente->id,
        'status' => $attributes['status'] ?? LvRutaDiaItem::STATUS_PENDIENTE,
    ]);
}

it('cierra item correctivo solo actualizando item y fotos sin crear revision legacy', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $ruta = rutaDiaCierreRuta($tecnico);
    $item = rutaDiaCierreCorrectivoItem($ruta);

    app(RutaDiaItemCierreService::class)->cerrar($item, [
        'status' => LvRutaDiaItem::STATUS_CERRADO,
        'notas_tecnico' => 'Audio reiniciado',
        'fotos' => ['piv-images/ruta-dia-item/audio.jpg'],
    ], $tecnico);

    expect($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_CERRADO);
    expect($item->fresh()->notas_tecnico)->toBe('Audio reiniciado');
    expect($item->fresh()->cerrado_at)->not->toBeNull();
    expect(Revision::query()->count())->toBe(0);
    expect(Correctivo::query()->count())->toBe(0);
    expect(LvRutaDiaItemImagen::query()->first()?->posicion)->toBe(1);
});

it('cierra preventivo sin asignacion y crea averia asignacion revision legacy', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $ruta = rutaDiaCierreRuta($tecnico);
    $item = rutaDiaCierreRevisionItem($ruta);

    app(RutaDiaItemCierreService::class)->cerrar($item, [
        'status' => LvRutaDiaItem::STATUS_CERRADO,
        'aspecto' => 'OK',
        'funcionamiento' => 'OK',
        'actuacion' => 'OK',
        'audio' => 'KO',
        'lineas' => 'OK',
        'fecha_hora' => 'OK',
        'precision_paso' => 'OK',
        'notas_tecnico' => 'Audio bajo',
    ], $tecnico);

    $asignacion = Asignacion::query()->firstOrFail();
    $revision = Revision::query()->firstOrFail();

    expect(Averia::query()->first()?->notas)->toBe(RutaDiaItemCierreService::NOTAS_AVERIA_STUB);
    expect((int) $asignacion->tipo)->toBe(Asignacion::TIPO_REVISION);
    expect((int) $asignacion->status)->toBe(2);
    expect($asignacion->tecnico_id)->toBe($tecnico->tecnico_id);
    expect($revision->audio)->toBe('KO');
    expect($revision->notas)->toBe('Audio bajo');
    expect($item->revisionPendiente->fresh()->status)->toBe(LvRevisionPendiente::STATUS_COMPLETADA);
});

it('cierra preventivo reutilizando asignacion existente', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $ruta = rutaDiaCierreRuta($tecnico);
    $piv = Piv::factory()->create();
    $averia = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    $asignacion = Asignacion::factory()->revision()->create([
        'averia_id' => $averia->averia_id,
        'tecnico_id' => $tecnico->tecnico_id,
        'status' => 1,
    ]);
    $revisionPendiente = LvRevisionPendiente::factory()->create([
        'piv_id' => $piv->piv_id,
        'asignacion_id' => $asignacion->asignacion_id,
    ]);
    $item = LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'tipo_item' => LvRutaDiaItem::TIPO_PREVENTIVO,
        'lv_averia_icca_id' => null,
        'lv_revision_pendiente_id' => $revisionPendiente->id,
    ]);

    app(RutaDiaItemCierreService::class)->cerrar($item, ['status' => LvRutaDiaItem::STATUS_CERRADO], $tecnico);

    expect(Asignacion::query()->count())->toBe(1);
    expect(Revision::where('asignacion_id', $asignacion->asignacion_id)->exists())->toBeTrue();
});

it('cierra carry over como revision legacy', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $ruta = rutaDiaCierreRuta($tecnico);
    $origen = LvRevisionPendiente::factory()->create(['periodo_year' => 2026, 'periodo_month' => 4]);
    $item = rutaDiaCierreRevisionItem($ruta, LvRutaDiaItem::TIPO_CARRY_OVER, [
        'carry_over_origen_id' => $origen->id,
    ]);

    app(RutaDiaItemCierreService::class)->cerrar($item, ['status' => LvRutaDiaItem::STATUS_CERRADO], $tecnico);

    expect(Revision::query()->count())->toBe(1);
    expect($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_CERRADO);
});

it('tecnico no dueño de ruta lanza DomainException', function (): void {
    $tecnico = rutaDiaCierreTecnico(78120);
    $otroTecnico = rutaDiaCierreTecnico(78121);
    $item = rutaDiaCierreCorrectivoItem(rutaDiaCierreRuta($otroTecnico));

    expect(fn () => app(RutaDiaItemCierreService::class)->cerrar($item, [], $tecnico))
        ->toThrow(DomainException::class);
});

it('item ya cerrado lanza ValidationException idempotente', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $item = rutaDiaCierreCorrectivoItem(rutaDiaCierreRuta($tecnico), [
        'status' => LvRutaDiaItem::STATUS_CERRADO,
    ]);

    expect(fn () => app(RutaDiaItemCierreService::class)->cerrar($item, [], $tecnico))
        ->toThrow(ValidationException::class);
});

it('status no_resuelto sin causa lanza ValidationException', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $item = rutaDiaCierreCorrectivoItem(rutaDiaCierreRuta($tecnico));

    expect(fn () => app(RutaDiaItemCierreService::class)->cerrar($item, [
        'status' => LvRutaDiaItem::STATUS_NO_RESUELTO,
    ], $tecnico))->toThrow(ValidationException::class);
});

it('status no_resuelto no crea revision legacy', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $item = rutaDiaCierreRevisionItem(rutaDiaCierreRuta($tecnico));

    app(RutaDiaItemCierreService::class)->cerrar($item, [
        'status' => LvRutaDiaItem::STATUS_NO_RESUELTO,
        'causa_no_resolucion' => 'Acceso bloqueado',
    ], $tecnico);

    expect(Revision::query()->count())->toBe(0);
    expect($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_NO_RESUELTO);
});

it('auto cambia ruta planificada a en_progreso al primer cierre', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $ruta = rutaDiaCierreRuta($tecnico);
    $itemAbierto = rutaDiaCierreCorrectivoItem($ruta, ['orden' => 1]);
    rutaDiaCierreCorrectivoItem($ruta, ['orden' => 2]);

    app(RutaDiaItemCierreService::class)->cerrar($itemAbierto, [], $tecnico);

    expect($ruta->fresh()->status)->toBe(LvRutaDia::STATUS_EN_PROGRESO);
});

it('auto completa ruta cuando todos los items estan cerrados o no resueltos', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $ruta = rutaDiaCierreRuta($tecnico, ['status' => LvRutaDia::STATUS_EN_PROGRESO]);
    $itemCerrado = rutaDiaCierreCorrectivoItem($ruta, ['orden' => 1, 'status' => LvRutaDiaItem::STATUS_CERRADO]);
    $itemPendiente = rutaDiaCierreCorrectivoItem($ruta, ['orden' => 2]);

    app(RutaDiaItemCierreService::class)->cerrar($itemPendiente, [], $tecnico);

    expect($itemCerrado->fresh()->status)->toBe(LvRutaDiaItem::STATUS_CERRADO);
    expect($ruta->fresh()->status)->toBe(LvRutaDia::STATUS_COMPLETADA);
});

it('mismo panel con averia y preventivo se cierran independientemente', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $ruta = rutaDiaCierreRuta($tecnico);
    $piv = Piv::factory()->create();
    $averiaIcca = LvAveriaIcca::factory()->create(['piv_id' => $piv->piv_id]);
    $revisionPendiente = LvRevisionPendiente::factory()->create(['piv_id' => $piv->piv_id]);
    $correctivo = LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'orden' => 1,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'lv_averia_icca_id' => $averiaIcca->id,
        'lv_revision_pendiente_id' => null,
    ]);
    $preventivo = LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'orden' => 2,
        'tipo_item' => LvRutaDiaItem::TIPO_PREVENTIVO,
        'lv_averia_icca_id' => null,
        'lv_revision_pendiente_id' => $revisionPendiente->id,
    ]);

    app(RutaDiaItemCierreService::class)->cerrar($correctivo, [], $tecnico);
    app(RutaDiaItemCierreService::class)->cerrar($preventivo, [], $tecnico);

    expect($correctivo->fresh()->status)->toBe(LvRutaDiaItem::STATUS_CERRADO);
    expect($preventivo->fresh()->status)->toBe(LvRutaDiaItem::STATUS_CERRADO);
    expect(Revision::query()->count())->toBe(1);
});

it('tecnico_id null en asignacion legacy se asigna al cerrar', function (): void {
    $tecnico = rutaDiaCierreTecnico();
    $ruta = rutaDiaCierreRuta($tecnico);
    $piv = Piv::factory()->create();
    $averia = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    $asignacion = Asignacion::factory()->revision()->create([
        'averia_id' => $averia->averia_id,
        'tecnico_id' => null,
    ]);
    $revisionPendiente = LvRevisionPendiente::factory()->create([
        'piv_id' => $piv->piv_id,
        'asignacion_id' => $asignacion->asignacion_id,
    ]);
    $item = LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'tipo_item' => LvRutaDiaItem::TIPO_PREVENTIVO,
        'lv_averia_icca_id' => null,
        'lv_revision_pendiente_id' => $revisionPendiente->id,
    ]);

    app(RutaDiaItemCierreService::class)->cerrar($item, [], $tecnico);

    expect($asignacion->fresh()->tecnico_id)->toBe($tecnico->tecnico_id);
});
