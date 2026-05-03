<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Correctivo;
use App\Models\LvCorrectivoImagen;
use App\Models\Piv;
use App\Models\Revision;
use App\Services\AsignacionCierreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function asignacionCierreServiceAsignacion(array $attributes = []): Asignacion
{
    $piv = Piv::factory()->create(['piv_id' => $attributes['piv_id'] ?? 88000]);
    $averia = Averia::factory()->create([
        'averia_id' => $attributes['averia_id'] ?? 88000,
        'piv_id' => $piv->piv_id,
        'notas' => $attributes['averia_notas'] ?? 'Notas originales del operador',
    ]);

    return Asignacion::factory()->create([
        'asignacion_id' => $attributes['asignacion_id'] ?? 88000,
        'averia_id' => $averia->averia_id,
        'tecnico_id' => $attributes['tecnico_id'] ?? 880,
        'tipo' => $attributes['tipo'] ?? Asignacion::TIPO_CORRECTIVO,
        'status' => $attributes['status'] ?? 1,
    ]);
}

it('service_creates_correctivo_with_correct_fields_for_tipo_1', function (): void {
    $asignacion = asignacionCierreServiceAsignacion();

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'diagnostico' => 'Fallo alimentación',
        'recambios' => 'Sustituida fuente',
        'estado_final' => 'OK',
        'tiempo' => '1.5',
        'contrato' => true,
        'facturar_horas' => true,
        'facturar_desplazamiento' => false,
        'facturar_recambios' => true,
        'fotos' => [],
    ]);

    $correctivo = Correctivo::where('asignacion_id', $asignacion->asignacion_id)->first();

    expect($correctivo)->not->toBeNull();
    expect($correctivo->tecnico_id)->toBe(880);
    expect($correctivo->diagnostico)->toBe('Fallo alimentación');
    expect($correctivo->recambios)->toBe('Sustituida fuente');
    expect($correctivo->estado_final)->toBe('OK');
    expect($correctivo->tiempo)->toBe('1.5');
    expect($correctivo->contrato)->toBeTrue();
    expect($correctivo->facturar_horas)->toBeTrue();
    expect($correctivo->facturar_desplazamiento)->toBeFalse();
    expect($correctivo->facturar_recambios)->toBeTrue();
});

it('service_creates_revision_with_correct_fields_for_tipo_2', function (): void {
    $asignacion = asignacionCierreServiceAsignacion([
        'asignacion_id' => 88001,
        'averia_id' => 88001,
        'piv_id' => 88001,
        'tipo' => Asignacion::TIPO_REVISION,
    ]);

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'fecha' => '2026-05-03',
        'ruta' => 'Ruta Centro',
        'fecha_hora' => 'OK hora panel',
        'aspecto' => 'OK',
        'funcionamiento' => 'KO',
        'actuacion' => 'N/A',
        'audio' => 'OK',
        'lineas' => 'OK',
        'precision_paso' => 'OK',
        'notas' => 'Revisado sin autofill',
    ]);

    $revision = Revision::where('asignacion_id', $asignacion->asignacion_id)->first();

    expect($revision)->not->toBeNull();
    expect($revision->tecnico_id)->toBe(880);
    expect($revision->fecha)->toBe('2026-05-03');
    expect($revision->ruta)->toBe('Ruta Centro');
    expect($revision->fecha_hora)->toBe('OK hora panel');
    expect($revision->aspecto)->toBe('OK');
    expect($revision->funcionamiento)->toBe('KO');
    expect($revision->actuacion)->toBe('N/A');
    expect($revision->notas)->toBe('Revisado sin autofill');
});

it('service_sets_status_2_after_cierre', function (): void {
    $asignacion = asignacionCierreServiceAsignacion([
        'asignacion_id' => 88002,
        'averia_id' => 88002,
        'piv_id' => 88002,
    ]);

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'diagnostico' => 'X',
        'recambios' => 'Y',
        'estado_final' => 'OK',
        'fotos' => [],
    ]);

    expect($asignacion->fresh()->status)->toBe(2);
});

it('service_does_not_touch_averia_notas', function (): void {
    $asignacion = asignacionCierreServiceAsignacion([
        'asignacion_id' => 88003,
        'averia_id' => 88003,
        'piv_id' => 88003,
        'averia_notas' => 'Texto operador intacto',
    ]);

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'diagnostico' => 'Diagnóstico técnico',
        'recambios' => 'Recambio técnico',
        'estado_final' => 'OK',
        'fotos' => [],
    ]);

    expect($asignacion->averia->fresh()->notas)->toBe('Texto operador intacto');
});

it('service_throws_if_correctivo_already_exists', function (): void {
    $asignacion = asignacionCierreServiceAsignacion([
        'asignacion_id' => 88004,
        'averia_id' => 88004,
        'piv_id' => 88004,
    ]);
    Correctivo::factory()->create(['asignacion_id' => $asignacion->asignacion_id]);

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'diagnostico' => 'X',
        'recambios' => 'Y',
        'estado_final' => 'OK',
        'fotos' => [],
    ]);
})->throws(ValidationException::class, 'Esta asignación ya tiene un correctivo registrado.');

it('service_creates_lv_correctivo_imagen_rows_for_uploaded_paths', function (): void {
    $asignacion = asignacionCierreServiceAsignacion([
        'asignacion_id' => 88005,
        'averia_id' => 88005,
        'piv_id' => 88005,
    ]);

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'diagnostico' => 'X',
        'recambios' => 'Y',
        'estado_final' => 'OK',
        'fotos' => [
            'piv-images/correctivo/foto-1.jpg',
            'piv-images/correctivo/foto-2.jpg',
        ],
    ]);

    $correctivo = Correctivo::where('asignacion_id', $asignacion->asignacion_id)->first();

    expect(LvCorrectivoImagen::where('correctivo_id', $correctivo->correctivo_id)->pluck('url')->all())->toBe([
        'piv-images/correctivo/foto-1.jpg',
        'piv-images/correctivo/foto-2.jpg',
    ]);
    expect(LvCorrectivoImagen::where('correctivo_id', $correctivo->correctivo_id)->pluck('posicion')->all())->toBe([1, 2]);
});