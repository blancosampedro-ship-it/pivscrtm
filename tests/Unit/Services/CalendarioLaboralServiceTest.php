<?php

declare(strict_types=1);

use App\Services\CalendarioLaboralService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->svc = new CalendarioLaboralService;
});

it('lunes es laborable 8 horas en un slot 07-15', function (): void {
    $lunes = CarbonImmutable::parse('2026-05-04', CalendarioLaboralService::TZ);

    expect($this->svc->isLaborable($lunes))->toBeTrue();
    expect($this->svc->horasLaborables($lunes))->toBe(8.0);
    expect($this->svc->slotsLaborables($lunes))->toBe([
        ['start' => '07:00', 'end' => '15:00'],
    ]);
});

it('martes es laborable 8 horas en un slot 07-15', function (): void {
    $martes = CarbonImmutable::parse('2026-05-05', CalendarioLaboralService::TZ);

    expect($this->svc->isLaborable($martes))->toBeTrue();
    expect($this->svc->horasLaborables($martes))->toBe(8.0);
    expect($this->svc->slotsLaborables($martes))->toHaveCount(1);
});

it('miercoles es laborable 9 horas en dos slots con pausa 14-15', function (): void {
    $miercoles = CarbonImmutable::parse('2026-05-06', CalendarioLaboralService::TZ);

    expect($this->svc->isLaborable($miercoles))->toBeTrue();
    expect($this->svc->horasLaborables($miercoles))->toBe(9.0);
    expect($this->svc->slotsLaborables($miercoles))->toBe([
        ['start' => '08:00', 'end' => '14:00'],
        ['start' => '15:00', 'end' => '18:00'],
    ]);
});

it('jueves es laborable 9 horas en dos slots', function (): void {
    $jueves = CarbonImmutable::parse('2026-05-07', CalendarioLaboralService::TZ);

    expect($this->svc->isLaborable($jueves))->toBeTrue();
    expect($this->svc->horasLaborables($jueves))->toBe(9.0);
    expect($this->svc->slotsLaborables($jueves))->toHaveCount(2);
});

it('viernes es laborable 6 horas en un slot 08-14', function (): void {
    $viernes = CarbonImmutable::parse('2026-05-08', CalendarioLaboralService::TZ);

    expect($this->svc->isLaborable($viernes))->toBeTrue();
    expect($this->svc->horasLaborables($viernes))->toBe(6.0);
    expect($this->svc->slotsLaborables($viernes))->toBe([
        ['start' => '08:00', 'end' => '14:00'],
    ]);
});

it('sabado no es laborable y devuelve 0 horas', function (): void {
    $sabado = CarbonImmutable::parse('2026-05-09', CalendarioLaboralService::TZ);

    expect($this->svc->isLaborable($sabado))->toBeFalse();
    expect($this->svc->horasLaborables($sabado))->toBe(0.0);
    expect($this->svc->slotsLaborables($sabado))->toBe([]);
});

it('domingo no es laborable', function (): void {
    $domingo = CarbonImmutable::parse('2026-05-10', CalendarioLaboralService::TZ);

    expect($this->svc->isLaborable($domingo))->toBeFalse();
});

it('festivo Madrid nacional Anio Nuevo no es laborable aunque sea entre semana', function (): void {
    $anioNuevo = CarbonImmutable::parse('2026-01-01', CalendarioLaboralService::TZ);

    expect($anioNuevo->dayOfWeekIso)->toBe(CarbonImmutable::THURSDAY);
    expect($this->svc->isFestivo($anioNuevo))->toBeTrue();
    expect($this->svc->isLaborable($anioNuevo))->toBeFalse();
    expect($this->svc->horasLaborables($anioNuevo))->toBe(0.0);
});

it('festivo Madrid Comunidad 2 mayo no es laborable', function (): void {
    $diaMadrid = CarbonImmutable::parse('2026-05-02', CalendarioLaboralService::TZ);

    expect($this->svc->isFestivo($diaMadrid))->toBeTrue();
    expect($this->svc->isLaborable($diaMadrid))->toBeFalse();
});

it('jueves santo 2026 no es laborable', function (): void {
    $juevesSanto = CarbonImmutable::parse('2026-04-02', CalendarioLaboralService::TZ);

    expect($this->svc->isFestivo($juevesSanto))->toBeTrue();
    expect($this->svc->isLaborable($juevesSanto))->toBeFalse();
});

it('cualquier dia 2025 no esta en festivos y no se marca como festivo', function (): void {
    $cualquier = CarbonImmutable::parse('2025-05-15', CalendarioLaboralService::TZ);

    expect($this->svc->isFestivo($cualquier))->toBeFalse();
});

it('proximo dia laborable salta fin de semana sabado a lunes', function (): void {
    $sabado = CarbonImmutable::parse('2026-05-09', CalendarioLaboralService::TZ);

    $proximo = $this->svc->proximoDiaLaborable($sabado);

    expect($proximo->format('Y-m-d'))->toBe('2026-05-11');
});

it('proximo dia laborable salta festivo viernes a lunes', function (): void {
    $viernesFestivo = CarbonImmutable::parse('2026-05-01', CalendarioLaboralService::TZ);

    $proximo = $this->svc->proximoDiaLaborable($viernesFestivo);

    expect($proximo->format('Y-m-d'))->toBe('2026-05-04');
});

it('proximo dia laborable de un dia ya laborable devuelve la misma fecha', function (): void {
    $martes = CarbonImmutable::parse('2026-05-05', CalendarioLaboralService::TZ);

    $proximo = $this->svc->proximoDiaLaborable($martes);

    expect($proximo->format('Y-m-d'))->toBe('2026-05-05');
});

it('dias laborables entre lunes y viernes de semana sin festivos es 5', function (): void {
    $lunes = CarbonImmutable::parse('2026-05-04', CalendarioLaboralService::TZ);
    $viernes = CarbonImmutable::parse('2026-05-08', CalendarioLaboralService::TZ);

    expect($this->svc->diasLaborablesEntre($lunes, $viernes))->toBe(5);
});

it('dias laborables entre lunes y domingo de semana sin festivos es 5', function (): void {
    $lunes = CarbonImmutable::parse('2026-05-04', CalendarioLaboralService::TZ);
    $domingo = CarbonImmutable::parse('2026-05-10', CalendarioLaboralService::TZ);

    expect($this->svc->diasLaborablesEntre($lunes, $domingo))->toBe(5);
});

it('horas laborables semana completa sin festivos es 40h', function (): void {
    $lunes = CarbonImmutable::parse('2026-05-04', CalendarioLaboralService::TZ);
    $domingo = CarbonImmutable::parse('2026-05-10', CalendarioLaboralService::TZ);

    expect($this->svc->horasLaborablesEntre($lunes, $domingo))->toBe(40.0);
});

it('horas laborables semana con festivo viernes 1 mayo es 34h', function (): void {
    $lunes = CarbonImmutable::parse('2026-04-27', CalendarioLaboralService::TZ);
    $domingo = CarbonImmutable::parse('2026-05-03', CalendarioLaboralService::TZ);

    expect($this->svc->horasLaborablesEntre($lunes, $domingo))->toBe(34.0);
});

it('rango invertido devuelve 0', function (): void {
    $start = CarbonImmutable::parse('2026-05-10', CalendarioLaboralService::TZ);
    $end = CarbonImmutable::parse('2026-05-04', CalendarioLaboralService::TZ);

    expect($this->svc->diasLaborablesEntre($start, $end))->toBe(0);
    expect($this->svc->horasLaborablesEntre($start, $end))->toBe(0.0);
});

it('Carbon UTC de domingo noche se interpreta como lunes en Europe Madrid', function (): void {
    $borderline = CarbonImmutable::parse('2026-05-03 23:30', 'UTC');

    expect($this->svc->isLaborable($borderline))->toBeTrue();
    expect($this->svc->horasLaborables($borderline))->toBe(8.0);
});

it('Carbon mutable se acepta sin mutar el input', function (): void {
    $lunes = Carbon::parse('2026-05-04', CalendarioLaboralService::TZ);
    $original = $lunes->format('Y-m-d H:i:s');

    $this->svc->isLaborable($lunes);
    $this->svc->proximoDiaLaborable($lunes);

    expect($lunes->format('Y-m-d H:i:s'))->toBe($original);
});
