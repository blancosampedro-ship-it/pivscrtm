<?php

declare(strict_types=1);

use App\Models\LvAveriaIcca;
use App\Models\LvRevisionPendiente;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use App\Models\Tecnico;
use App\Models\User;
use App\Services\PlanificadorDelDiaService;
use App\Services\RutaDiaSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->tecnico = Tecnico::factory()->create(['status' => 1, 'nombre_completo' => 'Técnico Ruta']);
    $this->service = new RutaDiaSnapshotService(new PlanificadorDelDiaService);

    $this->municipioA = Modulo::factory()->municipio('Alcalá')->create();
    $this->municipioB = Modulo::factory()->municipio('Aranjuez')->create();
    $this->rutaA = PivRuta::factory()->create(['codigo' => 'ROSA-E', 'nombre' => 'Rosa Este', 'sort_order' => 1]);
    $this->rutaB = PivRuta::factory()->create(['codigo' => 'AMARILLO', 'nombre' => 'Amarillo', 'sort_order' => 2]);
    PivRutaMunicipio::factory()->create(['ruta_id' => $this->rutaA->id, 'municipio_modulo_id' => $this->municipioA->modulo_id, 'km_desde_ciempozuelos' => 20]);
    PivRutaMunicipio::factory()->create(['ruta_id' => $this->rutaB->id, 'municipio_modulo_id' => $this->municipioB->modulo_id, 'km_desde_ciempozuelos' => 5]);

    $this->pivA = Piv::factory()->create(['parada_cod' => 'A-001', 'municipio' => (string) $this->municipioA->modulo_id]);
    $this->pivB = Piv::factory()->create(['parada_cod' => 'B-001', 'municipio' => (string) $this->municipioB->modulo_id]);
});

it('snapshot crea ruta y copia items de las tres fuentes en orden', function (): void {
    $preventivo = LvRevisionPendiente::factory()->requiereVisita()->create(['piv_id' => $this->pivA->piv_id, 'fecha_planificada' => '2026-05-06']);
    $pivCarry = Piv::factory()->create(['parada_cod' => 'A-002', 'municipio' => (string) $this->municipioA->modulo_id]);
    $origen = LvRevisionPendiente::factory()->pendiente()->create(['piv_id' => $pivCarry->piv_id, 'periodo_year' => 2026, 'periodo_month' => 4]);
    $carry = LvRevisionPendiente::factory()->pendiente()->create(['piv_id' => $pivCarry->piv_id, 'carry_over_origen_id' => $origen->id]);
    $averia = LvAveriaIcca::factory()->create(['piv_id' => $this->pivB->piv_id]);

    $ruta = $this->service->snapshot($this->tecnico->tecnico_id, CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'), $this->admin);

    expect($ruta->tecnico_id)->toBe($this->tecnico->tecnico_id);
    expect($ruta->fecha->format('Y-m-d'))->toBe('2026-05-06');
    expect($ruta->status)->toBe(LvRutaDia::STATUS_PLANIFICADA);
    expect($ruta->created_by_user_id)->toBe($this->admin->id);
    expect($ruta->items)->toHaveCount(3);
    expect($ruta->items->pluck('orden')->all())->toBe([1, 2, 3]);
    expect($ruta->items->pluck('tipo_item')->all())->toBe([
        LvRutaDiaItem::TIPO_PREVENTIVO,
        LvRutaDiaItem::TIPO_CARRY_OVER,
        LvRutaDiaItem::TIPO_CORRECTIVO,
    ]);
    expect($ruta->items[0]->lv_revision_pendiente_id)->toBe($preventivo->id);
    expect($ruta->items[1]->lv_revision_pendiente_id)->toBe($carry->id);
    expect($ruta->items[2]->lv_averia_icca_id)->toBe($averia->id);
});

it('snapshot incluye averias ambiguas por defecto', function (): void {
    LvAveriaIcca::factory()->create(['piv_id' => null, 'panel_id_sgip' => 'PANEL 17474B']);

    $ruta = $this->service->snapshot($this->tecnico->tecnico_id, CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'), $this->admin);

    expect($ruta->items)->toHaveCount(1);
    expect($ruta->items->first()->lv_averia_icca_id)->not->toBeNull();
});

it('snapshot puede excluir averias ambiguas', function (): void {
    LvAveriaIcca::factory()->create(['piv_id' => null, 'panel_id_sgip' => 'PANEL 9079A']);

    $ruta = $this->service->snapshot($this->tecnico->tecnico_id, CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'), $this->admin, false);

    expect($ruta->items)->toHaveCount(0);
});

it('snapshot falla si tecnico no existe', function (): void {
    expect(fn () => $this->service->snapshot(9999999, CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'), $this->admin))
        ->toThrow(DomainException::class, 'no existe o no está activo');
});

it('snapshot falla si tecnico esta inactivo', function (): void {
    $inactive = Tecnico::factory()->create(['status' => 0]);

    expect(fn () => $this->service->snapshot($inactive->tecnico_id, CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'), $this->admin))
        ->toThrow(DomainException::class, 'no existe o no está activo');
});

it('snapshot falla si ya existe ruta para tecnico y fecha', function (): void {
    LvRutaDia::factory()->create(['tecnico_id' => $this->tecnico->tecnico_id, 'fecha' => '2026-05-06']);

    expect(fn () => $this->service->snapshot($this->tecnico->tecnico_id, CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'), $this->admin))
        ->toThrow(DomainException::class, 'Ya existe ruta');
});

it('snapshot no toca tablas origen', function (): void {
    $averia = LvAveriaIcca::factory()->create(['piv_id' => $this->pivA->piv_id]);
    $revision = LvRevisionPendiente::factory()->requiereVisita()->create(['piv_id' => $this->pivA->piv_id, 'fecha_planificada' => '2026-05-06']);

    $this->service->snapshot($this->tecnico->tecnico_id, CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'), $this->admin);

    expect($averia->fresh()->activa)->toBeTrue();
    expect($revision->fresh()->status)->toBe(LvRevisionPendiente::STATUS_REQUIERE_VISITA);
    expect($revision->fresh()->asignacion_id)->toBeNull();
});
