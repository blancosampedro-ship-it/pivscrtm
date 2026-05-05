<?php

declare(strict_types=1);

use App\Models\Modulo;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PivRutaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeder crea las 5 rutas con códigos correctos', function (): void {
    (new PivRutaSeeder)->run();

    expect(PivRuta::count())->toBe(5);
    expect(PivRuta::pluck('codigo')->sort()->values()->toArray())
        ->toBe(['AMARILLO', 'AZUL', 'ROSA-E', 'ROSA-NO', 'VERDE']);
});

it('seeder es idempotente y no duplica rutas ni municipios', function (): void {
    Modulo::factory()->municipio('Aranjuez')->create();

    (new PivRutaSeeder)->run();
    (new PivRutaSeeder)->run();

    expect(PivRuta::count())->toBe(5);
    expect(PivRutaMunicipio::count())->toBe(1);
});

it('seeder asigna municipios solo si existen en modulo', function (): void {
    Modulo::factory()->municipio('Aranjuez')->create();

    (new PivRutaSeeder)->run();

    expect(PivRutaMunicipio::count())->toBe(1);
    expect(PivRutaMunicipio::firstOrFail()->ruta->codigo)->toBe(PivRuta::COD_AMARILLO);
});

it('seeder guarda km desde Ciempozuelos del Excel', function (): void {
    Modulo::factory()->municipio('Campo Real')->create(['modulo_id' => 91001]);

    (new PivRutaSeeder)->run();

    $assignment = PivRutaMunicipio::firstOrFail();
    expect($assignment->km_desde_ciempozuelos)->toBe(40);
    expect($assignment->ruta->codigo)->toBe(PivRuta::COD_ROSA_E);
});

it('seeder actualiza ruta y km si la asignacion ya existia', function (): void {
    $municipio = Modulo::factory()->municipio('Campo Real')->create(['modulo_id' => 91002]);
    $rutaVieja = PivRuta::factory()->create(['codigo' => 'OLD']);
    PivRutaMunicipio::factory()->create([
        'ruta_id' => $rutaVieja->id,
        'municipio_modulo_id' => $municipio->modulo_id,
        'km_desde_ciempozuelos' => 999,
    ]);

    (new PivRutaSeeder)->run();

    $assignment = PivRutaMunicipio::where('municipio_modulo_id', $municipio->modulo_id)->firstOrFail();
    expect($assignment->ruta->codigo)->toBe(PivRuta::COD_ROSA_E);
    expect($assignment->km_desde_ciempozuelos)->toBe(40);
});

it('seeder normaliza Las Rozas de Madrid a Rozas de Madrid Las', function (): void {
    Modulo::factory()->municipio('Rozas de Madrid, Las')->create();

    (new PivRutaSeeder)->run();

    expect(PivRutaMunicipio::count())->toBe(1);
    expect(PivRutaMunicipio::firstOrFail()->ruta->codigo)->toBe(PivRuta::COD_ROSA_NO);
});

it('seeder normaliza Buitrago del Lozoya a Buitrago de Lozoya', function (): void {
    Modulo::factory()->municipio('Buitrago de Lozoya')->create();

    (new PivRutaSeeder)->run();

    expect(PivRutaMunicipio::count())->toBe(1);
    expect(PivRutaMunicipio::firstOrFail()->ruta->codigo)->toBe(PivRuta::COD_VERDE);
});

it('seeder salta municipios no presentes en modulo', function (): void {
    (new PivRutaSeeder)->run();

    expect(PivRuta::count())->toBe(5);
    expect(PivRutaMunicipio::count())->toBe(0);
});

it('municipios const contiene 81 filas y distribucion oficial por ruta', function (): void {
    expect(PivRutaSeeder::MUNICIPIOS)->toHaveCount(81);

    $counts = collect(PivRutaSeeder::MUNICIPIOS)
        ->countBy(fn (array $row): string => $row[1])
        ->all();

    expect($counts)->toBe([
        PivRuta::COD_ROSA_NO => 18,
        PivRuta::COD_ROSA_E => 11,
        PivRuta::COD_VERDE => 20,
        PivRuta::COD_AZUL => 15,
        PivRuta::COD_AMARILLO => 17,
    ]);
});

it('rutas const conserva km medio oficial', function (): void {
    $kmMedio = collect(PivRutaSeeder::RUTAS)->pluck('km_medio', 'codigo')->all();

    expect($kmMedio)->toBe([
        PivRuta::COD_ROSA_NO => 80,
        PivRuta::COD_ROSA_E => 51,
        PivRuta::COD_VERDE => 85,
        PivRuta::COD_AZUL => 84,
        PivRuta::COD_AMARILLO => 36,
    ]);
});

it('DatabaseSeeder llama a PivRutaSeeder', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(PivRuta::count())->toBe(5);
});

it('unique codigo previene duplicados', function (): void {
    PivRuta::factory()->create(['codigo' => 'TEST']);

    expect(fn () => PivRuta::factory()->create(['codigo' => 'TEST']))
        ->toThrow(QueryException::class);
});

it('unique municipio_modulo_id previene un municipio en dos rutas', function (): void {
    $rutaA = PivRuta::factory()->create();
    $rutaB = PivRuta::factory()->create();
    PivRutaMunicipio::factory()->create(['ruta_id' => $rutaA->id, 'municipio_modulo_id' => 999]);

    expect(fn () => PivRutaMunicipio::factory()->create(['ruta_id' => $rutaB->id, 'municipio_modulo_id' => 999]))
        ->toThrow(QueryException::class);
});

it('relacion PivRuta municipios devuelve PivRutaMunicipio collection', function (): void {
    $ruta = PivRuta::factory()->create();
    PivRutaMunicipio::factory()->count(3)->create(['ruta_id' => $ruta->id]);

    expect($ruta->municipios)->toHaveCount(3);
    expect($ruta->municipios->first())->toBeInstanceOf(PivRutaMunicipio::class);
});

it('codigos const tiene los 5 códigos oficiales', function (): void {
    expect(PivRuta::CODIGOS)->toBe([
        PivRuta::COD_ROSA_NO,
        PivRuta::COD_ROSA_E,
        PivRuta::COD_VERDE,
        PivRuta::COD_AZUL,
        PivRuta::COD_AMARILLO,
    ]);
});
