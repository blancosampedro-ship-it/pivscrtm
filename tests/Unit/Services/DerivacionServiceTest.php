<?php

declare(strict_types=1);

use App\Models\LvDerivacion;
use App\Models\LvRutaDiaItem;
use App\Models\User;
use App\Services\DerivacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function derivacionAdmin(): User
{
    return User::factory()->admin()->create();
}

function derivacionData(array $overrides = []): array
{
    return array_merge([
        'tipo_causa' => LvDerivacion::CAUSA_TERCERO,
        'actor_responsable' => LvDerivacion::ACTOR_INTERNO_WINFIN,
        'actor_notas' => null,
        'notas_derivacion' => 'Pendiente de coordinación',
    ], $overrides);
}

it('derivar happy path crea derivacion y actualiza item status', function (): void {
    $item = LvRutaDiaItem::factory()->create();
    $admin = derivacionAdmin();

    $derivacion = app(DerivacionService::class)->derivar($item, derivacionData(), $admin);

    expect($derivacion->status)->toBe(LvDerivacion::STATUS_PENDIENTE_TERCERO);
    expect($derivacion->derivado_por_user_id)->toBe($admin->id);
    expect($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_DERIVADO);
});

it('derivar tipo otros exige causa otros texto no null ni string vacio', function (?string $texto): void {
    expect(fn () => app(DerivacionService::class)->derivar(
        LvRutaDiaItem::factory()->create(),
        derivacionData([
            'tipo_causa' => LvDerivacion::CAUSA_OTROS,
            'causa_otros_texto' => $texto,
        ]),
        derivacionAdmin(),
    ))->toThrow(ValidationException::class);
})->with([null, '']);

it('derivar item ya derivado o con derivacion abierta lanza DomainException', function (): void {
    $admin = derivacionAdmin();
    $item = LvRutaDiaItem::factory()->create();

    app(DerivacionService::class)->derivar($item, derivacionData(), $admin);

    expect(fn () => app(DerivacionService::class)->derivar($item->fresh(), derivacionData(), $admin))
        ->toThrow(DomainException::class, 'Item ya tiene derivación abierta');

    expect(fn () => app(DerivacionService::class)->derivar(
        LvRutaDiaItem::factory()->create(['status' => LvRutaDiaItem::STATUS_DERIVADO]),
        derivacionData(),
        $admin,
    ))->toThrow(DomainException::class);
});

it('resolver externamente cierra derivacion y mantiene item derivado', function (): void {
    $admin = derivacionAdmin();
    $item = LvRutaDiaItem::factory()->create();
    $derivacion = app(DerivacionService::class)->derivar($item, derivacionData(), $admin);

    app(DerivacionService::class)->resolverExternamente($derivacion, ['notas' => 'Resuelto por tercero'], $admin);

    expect($derivacion->fresh()->status)->toBe(LvDerivacion::STATUS_RESUELTO_EXTERNO);
    expect($derivacion->fresh()->resuelto_por_user_id)->toBe($admin->id);
    expect($derivacion->fresh()->resuelto_notas)->toBe('Resuelto por tercero');
    expect($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_DERIVADO);
});

it('devolver a ruta cierra derivacion y devuelve item a pendiente', function (): void {
    $admin = derivacionAdmin();
    $item = LvRutaDiaItem::factory()->create();
    $derivacion = app(DerivacionService::class)->derivar($item, derivacionData(), $admin);

    app(DerivacionService::class)->devolverARuta($derivacion, ['notas' => 'Revisitar'], $admin);

    expect($derivacion->fresh()->status)->toBe(LvDerivacion::STATUS_DEVUELTO_A_RUTA);
    expect($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_PENDIENTE);
});

it('cancelar cierra derivacion y devuelve item a pendiente', function (): void {
    $admin = derivacionAdmin();
    $item = LvRutaDiaItem::factory()->create();
    $derivacion = app(DerivacionService::class)->derivar($item, derivacionData(), $admin);

    app(DerivacionService::class)->cancelar($derivacion, ['notas' => 'Error administrativo'], $admin);

    expect($derivacion->fresh()->status)->toBe(LvDerivacion::STATUS_CANCELADA);
    expect($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_PENDIENTE);
});

it('transicion sobre derivacion cerrada lanza DomainException', function (): void {
    $admin = derivacionAdmin();
    $derivacion = LvDerivacion::factory()->cerrada()->create();

    expect(fn () => app(DerivacionService::class)->resolverExternamente($derivacion, ['notas' => 'x'], $admin))
        ->toThrow(DomainException::class);
    expect(fn () => app(DerivacionService::class)->devolverARuta($derivacion, ['notas' => 'x'], $admin))
        ->toThrow(DomainException::class);
    expect(fn () => app(DerivacionService::class)->cancelar($derivacion, ['notas' => 'x'], $admin))
        ->toThrow(DomainException::class);
});

it('rederivar item devuelto a ruta crea nueva derivacion preservando historial', function (): void {
    $admin = derivacionAdmin();
    $item = LvRutaDiaItem::factory()->create();
    $primera = app(DerivacionService::class)->derivar($item, derivacionData(), $admin);
    app(DerivacionService::class)->devolverARuta($primera, ['notas' => 'Vuelve'], $admin);

    $segunda = app(DerivacionService::class)->derivar($item->fresh(), derivacionData(), $admin);

    expect($segunda->id)->not->toBe($primera->id);
    expect($primera->fresh()->status)->toBe(LvDerivacion::STATUS_DEVUELTO_A_RUTA);
    expect($item->fresh()->derivaciones()->count())->toBe(2);
});

it('actor notas con acentos se persiste como utf8', function (): void {
    $derivacion = app(DerivacionService::class)->derivar(
        LvRutaDiaItem::factory()->create(),
        derivacionData(['actor_notas' => 'Pozuelo de Alarcón']),
        derivacionAdmin(),
    );

    expect($derivacion->fresh()->actor_notas)->toBe('Pozuelo de Alarcón');
});
