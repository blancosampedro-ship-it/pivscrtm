<?php

declare(strict_types=1);

use App\Filament\Resources\AsignacionResource;
use App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Correctivo;
use App\Models\LvCorrectivoImagen;
use App\Models\Modulo;
use App\Models\Operador;
use App\Models\Piv;
use App\Models\Revision;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin_can_list_asignaciones', function () {
    Piv::factory()->create(['piv_id' => 99300]);
    Averia::factory()->create(['averia_id' => 99300, 'piv_id' => 99300]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 99300,
        'averia_id' => 99300,
        'tipo' => 1,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->assertCanSeeTableRecords([$asig]);
});

it('non_admin_cannot_access_asignacion_resource', function () {
    $tecnico = User::factory()->tecnico()->create();
    $this->actingAs($tecnico);

    $this->get(AsignacionResource::getUrl('index'))->assertForbidden();
});

it('asignacion_listing_no_n_plus_one', function () {
    $municipio = Modulo::factory()->municipio()->create();
    $operador = Operador::factory()->create();
    $tecnico = Tecnico::factory()->create();

    collect(range(1, 50))->each(function ($i) use ($municipio, $operador, $tecnico) {
        $pivId = 60000 + $i;
        Piv::factory()->create([
            'piv_id' => $pivId,
            'municipio' => (string) $municipio->modulo_id,
        ]);
        Averia::factory()->create([
            'averia_id' => 60000 + $i,
            'piv_id' => $pivId,
            'operador_id' => $operador->operador_id,
        ]);
        Asignacion::factory()->create([
            'asignacion_id' => 60000 + $i,
            'averia_id' => 60000 + $i,
            'tecnico_id' => $tecnico->tecnico_id,
            'tipo' => $i % 2 + 1,
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListAsignaciones::class)->assertSuccessful();
    $count = count(DB::getQueryLog());
    expect($count)->toBeLessThanOrEqual(15, "Eager loading roto: {$count} queries");
});

it('asignacion_tipo_filter_separates_correctivo_from_revision', function () {
    Piv::factory()->create(['piv_id' => 99400]);
    Averia::factory()->create(['averia_id' => 99400, 'piv_id' => 99400]);
    Averia::factory()->create(['averia_id' => 99401, 'piv_id' => 99400]);
    $correctivo = Asignacion::factory()->create([
        'asignacion_id' => 99401,
        'averia_id' => 99400,
        'tipo' => 1,
    ]);
    $revision = Asignacion::factory()->create([
        'asignacion_id' => 99402,
        'averia_id' => 99401,
        'tipo' => 2,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->filterTable('tipo', 1)
        ->assertCanSeeTableRecords([$correctivo])
        ->assertCanNotSeeTableRecords([$revision]);
});

it('asignacion_view_action_renders_when_cierre_is_null', function () {
    Piv::factory()->create(['piv_id' => 99600]);
    Averia::factory()->create(['averia_id' => 99600, 'piv_id' => 99600]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 99600,
        'averia_id' => 99600,
        'tipo' => 2,
        'tecnico_id' => null,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->callTableAction('view', $asig->asignacion_id)
        ->assertSuccessful();
});

it('tipo_1_writes_correctivo_columns_not_notas', function () {
    Piv::factory()->create(['piv_id' => 91100]);
    Averia::factory()->create(['averia_id' => 91100, 'piv_id' => 91100, 'notas' => 'Notas originales del operador']);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91100,
        'averia_id' => 91100,
        'tecnico_id' => 99,
        'tipo' => 1,
        'status' => 1,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'Pantalla rota con acentos áéíóú',
            'recambios' => 'Sustituida pantalla LCD',
            'estado_final' => 'OK',
            'tiempo' => '1.5',
            'fotos' => [],
        ]);

    $cor = Correctivo::where('asignacion_id', 91100)->first();
    expect($cor)->not->toBeNull();
    expect($cor->diagnostico)->toBe('Pantalla rota con acentos áéíóú');
    expect($cor->recambios)->toBe('Sustituida pantalla LCD');
    expect($cor->estado_final)->toBe('OK');
    expect($cor->tiempo)->toBe('1.5');
});

it('tipo_1_does_not_modify_averia_notas', function () {
    Piv::factory()->create(['piv_id' => 91101]);
    $av = Averia::factory()->create(['averia_id' => 91101, 'piv_id' => 91101, 'notas' => 'NOTAS ORIGINALES INTOCABLES']);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91101,
        'averia_id' => 91101,
        'tecnico_id' => 99,
        'tipo' => 1,
        'status' => 1,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'X',
            'recambios' => 'Y',
            'estado_final' => 'OK',
            'tiempo' => '0.5',
            'fotos' => [],
        ]);

    expect($av->fresh()->notas)->toBe('NOTAS ORIGINALES INTOCABLES');
});

it('tipo_2_writes_to_revision_only', function () {
    Piv::factory()->create(['piv_id' => 91102]);
    Averia::factory()->create(['averia_id' => 91102, 'piv_id' => 91102]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91102,
        'averia_id' => 91102,
        'tecnico_id' => 99,
        'tipo' => 2,
        'status' => 1,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'fecha' => now()->format('Y-m-d'),
            'ruta' => 'Ruta 1',
            'aspecto' => 'OK',
            'funcionamiento' => 'OK',
            'actuacion' => 'OK',
            'audio' => 'OK',
            'lineas' => 'OK',
            'fecha_hora' => 'OK',
            'precision_paso' => 'OK',
            'notas' => null,
        ]);

    expect(Revision::where('asignacion_id', 91102)->exists())->toBeTrue();
    expect(Correctivo::where('asignacion_id', 91102)->exists())->toBeFalse();
});

it('tipo_2_notas_never_autofilled_with_revision_mensual', function () {
    Piv::factory()->create(['piv_id' => 91103]);
    Averia::factory()->create(['averia_id' => 91103, 'piv_id' => 91103]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91103,
        'averia_id' => 91103,
        'tecnico_id' => 99,
        'tipo' => 2,
        'status' => 1,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'fecha' => now()->format('Y-m-d'),
            'aspecto' => 'OK',
            'funcionamiento' => 'OK',
            'actuacion' => 'OK',
            'audio' => 'OK',
            'lineas' => 'OK',
            'fecha_hora' => 'OK',
            'precision_paso' => 'OK',
        ]);

    $rev = Revision::where('asignacion_id', 91103)->first();
    $notas = (string) ($rev->notas ?? '');

    expect($rev)->not->toBeNull();
    expect($notas)->not->toContain('REVISION MENSUAL');
    expect($notas)->not->toContain('REVISION');
    expect(trim($notas))->toBe('');
});

it('tipo_1_creates_lv_correctivo_imagen_row_per_photo', function () {
    Piv::factory()->create(['piv_id' => 91110]);
    Averia::factory()->create(['averia_id' => 91110, 'piv_id' => 91110]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91110,
        'averia_id' => 91110,
        'tecnico_id' => 99,
        'tipo' => 1,
        'status' => 1,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'X',
            'recambios' => 'Y',
            'estado_final' => 'OK',
            'fotos' => [
                'piv-images/correctivo/foto con espacios.jpg',
                'piv-images/correctivo/foto-ñ-2.jpg',
            ],
        ]);

    $cor = Correctivo::where('asignacion_id', 91110)->first();
    expect($cor)->not->toBeNull();
    expect(LvCorrectivoImagen::where('correctivo_id', $cor->correctivo_id)->count())->toBe(2);
});

it('tipo_1_does_not_attempt_to_write_correctivo_accion_or_imagen', function () {
    Piv::factory()->create(['piv_id' => 91120]);
    Averia::factory()->create(['averia_id' => 91120, 'piv_id' => 91120]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91120,
        'averia_id' => 91120,
        'tecnico_id' => 99,
        'tipo' => 1,
        'status' => 1,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'X',
            'recambios' => 'Y',
            'estado_final' => 'OK',
            'fotos' => [],
        ]);

    $cor = Correctivo::where('asignacion_id', 91120)->first();
    expect($cor)->not->toBeNull();
    expect(isset($cor->accion))->toBeFalse('correctivo.accion no existe en schema');
    expect(isset($cor->imagen))->toBeFalse('correctivo.imagen no existe en schema');
    expect(isset($cor->fecha))->toBeFalse('correctivo.fecha no existe en schema');
});

it('cerrar_action_hidden_when_status_is_2', function () {
    Piv::factory()->create(['piv_id' => 91130]);
    Averia::factory()->create(['averia_id' => 91130, 'piv_id' => 91130]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91130,
        'averia_id' => 91130,
        'tipo' => 1,
        'status' => 2,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->assertTableActionHidden('cerrar', $asig);
});

it('cerrar_action_visible_when_status_is_open', function () {
    Piv::factory()->create(['piv_id' => 91131]);
    Averia::factory()->create(['averia_id' => 91131, 'piv_id' => 91131]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91131,
        'averia_id' => 91131,
        'tipo' => 1,
        'status' => 1,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->assertTableActionVisible('cerrar', $asig);
});

it('cierre_uses_asignacion_tecnico_id_not_admin_user_id', function () {
    Piv::factory()->create(['piv_id' => 91140]);
    Averia::factory()->create(['averia_id' => 91140, 'piv_id' => 91140]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91140,
        'averia_id' => 91140,
        'tecnico_id' => 777,
        'tipo' => 1,
        'status' => 1,
    ]);

    expect($this->admin->id)->not->toBe(777);

    Livewire::test(ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'X',
            'recambios' => 'Y',
            'estado_final' => 'OK',
            'fotos' => [],
        ]);

    $cor = Correctivo::where('asignacion_id', 91140)->first();
    expect($cor->tecnico_id)->toBe(777);
});
