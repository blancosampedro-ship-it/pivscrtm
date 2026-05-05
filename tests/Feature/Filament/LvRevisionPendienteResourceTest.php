<?php

declare(strict_types=1);

use App\Filament\Resources\LvRevisionPendienteResource;
use App\Filament\Resources\LvRevisionPendienteResource\Pages\ListLvRevisionPendientes;
use App\Models\LvRevisionPendiente;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin puede acceder al listado de revisiones pendientes', function (): void {
    $this->get(LvRevisionPendienteResource::getUrl('index'))->assertOk();
});

it('non admin no puede acceder al listado', function (): void {
    $this->actingAs(User::factory()->tecnico()->create());

    $this->get(LvRevisionPendienteResource::getUrl('index'))->assertForbidden();
});

it('lista muestra filas pendientes del mes actual', function (): void {
    $now = CarbonImmutable::now('Europe/Madrid');
    $row = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
        'fecha_planificada' => $now->toDateString(),
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->assertCanSeeTableRecords([$row]);
});

it('filter solo_hoy default activo muestra filas requiere_visita today y carry overs pendientes', function (): void {
    $now = CarbonImmutable::now('Europe/Madrid');
    $previousMonth = $now->subMonth();

    $rowToday = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
        'fecha_planificada' => $now->toDateString(),
    ]);
    $rowFuture = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
        'fecha_planificada' => $now->addDays(3)->toDateString(),
    ]);
    $rowCarryOrigen = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'periodo_year' => $previousMonth->year,
        'periodo_month' => $previousMonth->month,
    ]);
    $rowCarry = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
        'carry_over_origen_id' => $rowCarryOrigen->id,
    ]);
    $rowOtra = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
        'carry_over_origen_id' => null,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->assertCanSeeTableRecords([$rowToday, $rowCarry])
        ->assertCanNotSeeTableRecords([$rowFuture, $rowOtra]);
});

it('filter solo_hoy desactivado muestra todas las del mes', function (): void {
    $now = CarbonImmutable::now('Europe/Madrid');

    $rowA = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
        'carry_over_origen_id' => null,
    ]);
    $rowB = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
        'fecha_planificada' => $now->addDays(3)->toDateString(),
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->assertCanSeeTableRecords([$rowA, $rowB]);
});

it('filter solo_hoy incluye carry over verificada_remoto solo si tiene fecha_planificada today', function (): void {
    $now = CarbonImmutable::now('Europe/Madrid');
    $previousMonth = $now->subMonth();

    $rowOrigen = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'periodo_year' => $previousMonth->year,
        'periodo_month' => $previousMonth->month,
    ]);
    $rowVerificada = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->verificadaRemoto()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
        'carry_over_origen_id' => $rowOrigen->id,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->assertCanNotSeeTableRecords([$rowVerificada]);
});

it('filter ruta muestra paneles de esa ruta y permite ver sin ruta', function (): void {
    $now = CarbonImmutable::now('Europe/Madrid');
    $ruta = PivRuta::factory()->create(['nombre' => 'Rosa Este']);
    $municipioRuta = Modulo::factory()->municipio('Campo Real')->create(['modulo_id' => 71001]);
    $municipioSinRuta = Modulo::factory()->municipio('Móstoles')->create(['modulo_id' => 71002]);
    PivRutaMunicipio::factory()->create([
        'ruta_id' => $ruta->id,
        'municipio_modulo_id' => $municipioRuta->modulo_id,
    ]);

    $rowRuta = LvRevisionPendiente::factory()->for(Piv::factory()->state([
        'municipio' => (string) $municipioRuta->modulo_id,
    ]), 'piv')->pendiente()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
    ]);
    $rowSinRuta = LvRevisionPendiente::factory()->for(Piv::factory()->state([
        'municipio' => (string) $municipioSinRuta->modulo_id,
    ]), 'piv')->pendiente()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->filterTable('ruta', $ruta->id)
        ->assertCanSeeTableRecords([$rowRuta])
        ->assertCanNotSeeTableRecords([$rowSinRuta]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->filterTable('ruta', '__none')
        ->assertCanSeeTableRecords([$rowSinRuta])
        ->assertCanNotSeeTableRecords([$rowRuta]);
});

it('action verificarRemoto cambia status y registra decision user', function (): void {
    $row = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create(currentMonthPeriod());

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->callTableAction('verificarRemoto', $row, data: ['decision_notas' => 'Visto OK desde panel externo'])
        ->assertHasNoTableActionErrors();

    $row->refresh();
    expect($row->status)->toBe(LvRevisionPendiente::STATUS_VERIFICADA_REMOTO);
    expect($row->decision_user_id)->toBe($this->admin->id);
    expect($row->decision_notas)->toBe('Visto OK desde panel externo');
    expect($row->decision_at)->not->toBeNull();
});

it('action requiereVisita exige fecha y la guarda', function (): void {
    $row = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create(currentMonthPeriod());
    $fecha = CarbonImmutable::now('Europe/Madrid')->addDay()->format('Y-m-d');

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->callTableAction('requiereVisita', $row, data: ['fecha_planificada' => $fecha])
        ->assertHasNoTableActionErrors();

    $row->refresh();
    expect($row->status)->toBe(LvRevisionPendiente::STATUS_REQUIERE_VISITA);
    expect($row->fecha_planificada->format('Y-m-d'))->toBe($fecha);
});

it('action requiereVisita rechaza fecha pasada', function (): void {
    $row = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create(currentMonthPeriod());
    $ayer = CarbonImmutable::now('Europe/Madrid')->subDay()->format('Y-m-d');

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->callTableAction('requiereVisita', $row, data: ['fecha_planificada' => $ayer])
        ->assertHasTableActionErrors(['fecha_planificada']);
});

it('action marcarExcepcion exige notas', function (): void {
    $row = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create(currentMonthPeriod());

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->callTableAction('marcarExcepcion', $row, data: ['decision_notas' => ''])
        ->assertHasTableActionErrors(['decision_notas']);

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->callTableAction('marcarExcepcion', $row, data: ['decision_notas' => 'Panel en obras municipales'])
        ->assertHasNoTableActionErrors();

    expect($row->fresh()->status)->toBe(LvRevisionPendiente::STATUS_EXCEPCION);
});

it('action revertir restaura a pendiente y limpia decision', function (): void {
    $row = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->verificadaRemoto()->create(array_merge(currentMonthPeriod(), [
        'decision_user_id' => $this->admin->id,
        'decision_notas' => 'algo',
    ]));

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->callTableAction('revertir', $row)
        ->assertHasNoTableActionErrors();

    $row->refresh();
    expect($row->status)->toBe(LvRevisionPendiente::STATUS_PENDIENTE);
    expect($row->decision_user_id)->toBeNull();
    expect($row->decision_notas)->toBeNull();
    expect($row->decision_at)->toBeNull();
});

it('action revertir no visible si fila ya promocionada', function (): void {
    $row = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create(array_merge(currentMonthPeriod(), [
        'asignacion_id' => 99999,
    ]));

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->assertTableActionHidden('revertir', $row);
});

it('bulk verificarRemoto marca solo filas pendientes', function (): void {
    $rowsPendientes = collect(range(1, 3))->map(fn () => LvRevisionPendiente::factory()
        ->for(Piv::factory(), 'piv')
        ->pendiente()
        ->create(currentMonthPeriod()));
    $rowExcepcion = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->excepcion()->create(currentMonthPeriod());
    $selectedRows = $rowsPendientes->concat([$rowExcepcion]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->callTableBulkAction('verificarRemotoBulk', $selectedRows, data: ['decision_notas' => null])
        ->assertHasNoTableBulkActionErrors();

    $rowsPendientes->each(function (LvRevisionPendiente $row): void {
        expect($row->fresh()->status)->toBe(LvRevisionPendiente::STATUS_VERIFICADA_REMOTO);
    });
    expect($rowExcepcion->fresh()->status)->toBe(LvRevisionPendiente::STATUS_EXCEPCION);
});

it('header action promoverAhora ejecuta promotor del dia', function (): void {
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create(array_merge(currentMonthPeriod(), [
        'fecha_planificada' => CarbonImmutable::now('Europe/Madrid')->startOfDay(),
    ]));

    Livewire::test(ListLvRevisionPendientes::class)
        ->callTableAction('promoverAhora')
        ->assertHasNoTableActionErrors();

    expect(LvRevisionPendiente::query()->firstOrFail()->fresh()->asignacion_id)->not->toBeNull();
});

it('navigation badge cuenta solo pendientes del mes actual', function (): void {
    $now = CarbonImmutable::now('Europe/Madrid');
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create(currentMonthPeriod());
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->verificadaRemoto()->create(currentMonthPeriod());
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'periodo_year' => $now->year - 1,
        'periodo_month' => $now->month,
    ]);

    expect(LvRevisionPendienteResource::getNavigationBadge())->toBe('1');
});

it('resource tiene slug explicito y grupo planificacion', function (): void {
    expect(LvRevisionPendienteResource::getSlug())->toBe('revisiones-pendientes');
    expect(LvRevisionPendienteResource::getNavigationLabel())->toBe('Decisiones del día');
    expect(LvRevisionPendienteResource::getNavigationGroup())->toBe('Planificación');
});

function currentMonthPeriod(): array
{
    $now = CarbonImmutable::now('Europe/Madrid');

    return [
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
    ];
}
