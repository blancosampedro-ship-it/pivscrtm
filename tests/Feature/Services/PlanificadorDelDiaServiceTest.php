<?php

declare(strict_types=1);

use App\Models\LvAveriaIcca;
use App\Models\LvRevisionPendiente;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use App\Services\PlanificadorDelDiaService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->svc = new PlanificadorDelDiaService;

    $this->munAlcala = Modulo::factory()->municipio('Alcalá')->create();
    $this->munAranjuez = Modulo::factory()->municipio('Aranjuez')->create();

    $this->rutaHenares = PivRuta::factory()->create([
        'codigo' => 'ROSA-E',
        'nombre' => 'Rosa Este',
        'sort_order' => 2,
    ]);
    $this->rutaSureste = PivRuta::factory()->create([
        'codigo' => 'AMARILLO',
        'nombre' => 'Amarillo Sureste',
        'sort_order' => 5,
    ]);

    PivRutaMunicipio::factory()->create([
        'ruta_id' => $this->rutaHenares->id,
        'municipio_modulo_id' => $this->munAlcala->modulo_id,
        'km_desde_ciempozuelos' => 55,
    ]);
    PivRutaMunicipio::factory()->create([
        'ruta_id' => $this->rutaSureste->id,
        'municipio_modulo_id' => $this->munAranjuez->modulo_id,
        'km_desde_ciempozuelos' => 18,
    ]);

    $this->pivAlcala = Piv::factory()->create([
        'parada_cod' => '12345',
        'municipio' => (string) $this->munAlcala->modulo_id,
    ]);
    $this->pivAranjuez = Piv::factory()->create([
        'parada_cod' => '67890',
        'municipio' => (string) $this->munAranjuez->modulo_id,
    ]);
});

it('return shape: fecha + totales + 3 fuentes + ambiguous + distribucion + grupos', function (): void {
    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($result)->toHaveKeys([
        'fecha',
        'total_items',
        'total_correctivos',
        'total_preventivos',
        'total_carry_overs',
        'ambiguous_count',
        'distribucion',
        'grupos',
    ]);
    expect($result['fecha'])->toBe('2026-05-06');
});

it('agrupa correctivos por ruta del piv', function (): void {
    LvAveriaIcca::factory()->create([
        'piv_id' => $this->pivAlcala->piv_id,
        'panel_id_sgip' => 'PANEL 12345 ALCALA',
        'categoria' => 'Problemas de comunicación',
    ]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($result['total_correctivos'])->toBe(1);
    expect($result['total_items'])->toBe(1);

    $rosaEste = collect($result['grupos'])->firstWhere('ruta_codigo', 'ROSA-E');
    expect($rosaEste['items_count'])->toBe(1);
    expect($rosaEste['items'][0]['tipo'])->toBe(PlanificadorDelDiaService::TIPO_CORRECTIVO);
    expect($rosaEste['items'][0]['piv_id'])->toBe($this->pivAlcala->piv_id);
    expect($rosaEste['items'][0]['km_desde_ciempozuelos'])->toBe(55);
});

it('preventivos requiere_visita today aparecen en grupo correcto', function (): void {
    LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $this->pivAranjuez->piv_id,
        'fecha_planificada' => '2026-05-06',
    ]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($result['total_preventivos'])->toBe(1);
    $amarillo = collect($result['grupos'])->firstWhere('ruta_codigo', 'AMARILLO');
    expect($amarillo['items_count'])->toBe(1);
    expect($amarillo['items'][0]['tipo'])->toBe(PlanificadorDelDiaService::TIPO_PREVENTIVO);
    expect($amarillo['items'][0]['km_desde_ciempozuelos'])->toBe(18);
});

it('preventivos con fecha distinta NO aparecen', function (): void {
    LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $this->pivAlcala->piv_id,
        'fecha_planificada' => '2026-05-10',
    ]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($result['total_preventivos'])->toBe(0);
});

it('carry overs pendientes aparecen con periodo origen', function (): void {
    $origen = LvRevisionPendiente::factory()->pendiente()->create([
        'piv_id' => $this->pivAlcala->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);
    LvRevisionPendiente::factory()->pendiente()->create([
        'piv_id' => $this->pivAlcala->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 5,
        'carry_over_origen_id' => $origen->id,
    ]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($result['total_carry_overs'])->toBe(1);
    $rosaEste = collect($result['grupos'])->firstWhere('ruta_codigo', 'ROSA-E');
    expect($rosaEste['items'][0]['tipo'])->toBe(PlanificadorDelDiaService::TIPO_CARRY_OVER);
    expect($rosaEste['items'][0]['carry_origen_periodo'])->toBe('2026-04');
});

it('excluye preventivos verificada_remoto excepcion completada', function (): void {
    $pivExtra = Piv::factory()->create([
        'municipio' => (string) $this->munAlcala->modulo_id,
    ]);

    LvRevisionPendiente::factory()->verificadaRemoto()->create([
        'piv_id' => $this->pivAlcala->piv_id,
        'fecha_planificada' => '2026-05-06',
    ]);
    LvRevisionPendiente::factory()->excepcion()->create([
        'piv_id' => $this->pivAranjuez->piv_id,
        'fecha_planificada' => '2026-05-06',
    ]);
    LvRevisionPendiente::factory()->completada()->create([
        'piv_id' => $pivExtra->piv_id,
        'fecha_planificada' => '2026-05-06',
    ]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($result['total_items'])->toBe(0);
});

it('items dentro de cada ruta ordenados por km ASC', function (): void {
    $munLoeches = Modulo::factory()->municipio('Loeches')->create();
    PivRutaMunicipio::factory()->create([
        'ruta_id' => $this->rutaHenares->id,
        'municipio_modulo_id' => $munLoeches->modulo_id,
        'km_desde_ciempozuelos' => 40,
    ]);
    $pivLoeches = Piv::factory()->create([
        'parada_cod' => 'XX',
        'municipio' => (string) $munLoeches->modulo_id,
    ]);

    LvAveriaIcca::factory()->create(['piv_id' => $this->pivAlcala->piv_id]);
    LvAveriaIcca::factory()->create(['piv_id' => $pivLoeches->piv_id]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    $rosaEste = collect($result['grupos'])->firstWhere('ruta_codigo', 'ROSA-E');
    expect($rosaEste['items'][0]['km_desde_ciempozuelos'])->toBe(40);
    expect($rosaEste['items'][1]['km_desde_ciempozuelos'])->toBe(55);
});

it('paneles en municipio sin ruta asignada van a SIN_RUTA', function (): void {
    $munHuerfano = Modulo::factory()->municipio('Mostoles')->create();
    $pivOrphan = Piv::factory()->create([
        'parada_cod' => 'OR',
        'municipio' => (string) $munHuerfano->modulo_id,
    ]);
    LvAveriaIcca::factory()->create(['piv_id' => $pivOrphan->piv_id]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    $sinRuta = collect($result['grupos'])->firstWhere('ruta_codigo', PlanificadorDelDiaService::SIN_RUTA_CODIGO);
    expect($sinRuta['items_count'])->toBe(1);
});

it('SIN_RUTA siempre al final de grupos aunque tenga 0 items en otras rutas', function (): void {
    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    $codigos = collect($result['grupos'])->pluck('ruta_codigo')->all();
    expect(end($codigos))->toBe(PlanificadorDelDiaService::SIN_RUTA_CODIGO);
});

it('averias con piv_id NULL cuentan en ambiguous_count y van a SIN_RUTA', function (): void {
    LvAveriaIcca::factory()->create([
        'piv_id' => null,
        'panel_id_sgip' => 'PANEL 17474B SAN SEBASTIAN',
    ]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($result['ambiguous_count'])->toBe(1);
    expect($result['total_correctivos'])->toBe(1);

    $sinRuta = collect($result['grupos'])->firstWhere('ruta_codigo', PlanificadorDelDiaService::SIN_RUTA_CODIGO);
    expect($sinRuta['items_count'])->toBe(1);
});

it('averias inactivas NO aparecen', function (): void {
    LvAveriaIcca::factory()->inactiva()->create(['piv_id' => $this->pivAlcala->piv_id]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($result['total_correctivos'])->toBe(0);
});

it('mismo panel con averia y preventivo aparece dos veces', function (): void {
    LvAveriaIcca::factory()->create(['piv_id' => $this->pivAlcala->piv_id]);
    LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $this->pivAlcala->piv_id,
        'fecha_planificada' => '2026-05-06',
    ]);

    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($result['total_items'])->toBe(2);
    $rosaEste = collect($result['grupos'])->firstWhere('ruta_codigo', 'ROSA-E');
    expect($rosaEste['items_count'])->toBe(2);
});

it('servicio NO escribe en BD', function (): void {
    LvAveriaIcca::factory()->create(['piv_id' => $this->pivAlcala->piv_id]);
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower(ltrim($query->sql));
    });

    $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($queries)->not->toBeEmpty();
    expect(collect($queries)->filter(fn (string $sql): bool => preg_match('/^(insert|update|delete|replace|alter|drop|create|truncate)\b/', $sql) === 1))->toBeEmpty();
});

it('distribucion incluye SIN_RUTA aunque sea 0', function (): void {
    $result = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect(array_keys($result['distribucion']))->toContain(PlanificadorDelDiaService::SIN_RUTA_CODIGO);
    expect($result['distribucion'][PlanificadorDelDiaService::SIN_RUTA_CODIGO])->toBe(0);
});
