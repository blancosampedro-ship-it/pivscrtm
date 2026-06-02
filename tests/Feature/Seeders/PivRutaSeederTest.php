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

it('seeder crea las 9 zonas con códigos correctos', function (): void {
    (new PivRutaSeeder)->run();

    expect(PivRuta::count())->toBe(9);
    expect(PivRuta::pluck('codigo')->sort()->values()->toArray())
        ->toBe(['CENTRO', 'ESTE', 'NORESTE', 'NOROESTE', 'NORTE', 'OESTE', 'SUR', 'SURESTE', 'SUROESTE']);
});

it('seeder es idempotente y no duplica rutas ni municipios', function (): void {
    Modulo::factory()->municipio('Madrid')->create();

    (new PivRutaSeeder)->run();
    (new PivRutaSeeder)->run();

    expect(PivRuta::count())->toBe(9);
    expect(PivRutaMunicipio::count())->toBe(1);
});

it('asigna cada municipio a su zona correcta', function (): void {
    Modulo::factory()->municipio('Madrid')->create(['modulo_id' => 90001]);
    Modulo::factory()->municipio('Coslada')->create(['modulo_id' => 90002]);
    Modulo::factory()->municipio('Brunete')->create(['modulo_id' => 90003]);
    Modulo::factory()->municipio('Getafe')->create(['modulo_id' => 90004]);

    (new PivRutaSeeder)->run();

    $byModulo = fn (int $id): string => PivRutaMunicipio::where('municipio_modulo_id', $id)->firstOrFail()->ruta->codigo;

    expect($byModulo(90001))->toBe(PivRuta::COD_CENTRO);
    expect($byModulo(90002))->toBe(PivRuta::COD_ESTE);
    expect($byModulo(90003))->toBe(PivRuta::COD_SUROESTE);
    expect($byModulo(90004))->toBe(PivRuta::COD_SUR);
});

it('km_desde_ciempozuelos es null en el esquema nuevo', function (): void {
    Modulo::factory()->municipio('Madrid')->create();

    (new PivRutaSeeder)->run();

    expect(PivRutaMunicipio::firstOrFail()->km_desde_ciempozuelos)->toBeNull();
});

it('elimina rutas y asignaciones obsoletas (esquema viejo)', function (): void {
    // Ruta vieja + su asignación a un municipio que no está en la lista canónica.
    $vieja = PivRuta::factory()->create(['codigo' => 'ROSA-NO']);
    $foraneo = Modulo::factory()->municipio('Municipio Inexistente XYZ')->create(['modulo_id' => 91234]);
    PivRutaMunicipio::factory()->create([
        'ruta_id' => $vieja->id,
        'municipio_modulo_id' => $foraneo->modulo_id,
        'km_desde_ciempozuelos' => 999,
    ]);

    (new PivRutaSeeder)->run();

    expect(PivRuta::where('codigo', 'ROSA-NO')->exists())->toBeFalse();
    expect(PivRuta::count())->toBe(9);
    // La asignación huérfana (a un municipio no canónico) se elimina.
    expect(PivRutaMunicipio::where('municipio_modulo_id', 91234)->exists())->toBeFalse();
    expect(PivRutaMunicipio::count())->toBe(0);
});

it('resuelve typos del catálogo legacy vía alias', function (): void {
    // Grafías exactas como aparecen (mal escritas) en el catálogo modulo de producción.
    Modulo::factory()->municipio('Guadalix de laSierra')->create(['modulo_id' => 92001]);
    Modulo::factory()->municipio('San Fernando de Henarres')->create(['modulo_id' => 92002]);
    Modulo::factory()->municipio('Valdeolmos-Alapardo')->create(['modulo_id' => 92003]);
    Modulo::factory()->municipio('Fuente del sanz de Jarama')->create(['modulo_id' => 92004]);

    (new PivRutaSeeder)->run();

    $byModulo = fn (int $id): string => PivRutaMunicipio::where('municipio_modulo_id', $id)->firstOrFail()->ruta->codigo;

    expect($byModulo(92001))->toBe(PivRuta::COD_NORTE);
    expect($byModulo(92002))->toBe(PivRuta::COD_ESTE);
    expect($byModulo(92003))->toBe(PivRuta::COD_NORESTE);
    expect($byModulo(92004))->toBe(PivRuta::COD_NORESTE);
});

it('salta municipios no presentes en el catálogo modulo', function (): void {
    (new PivRutaSeeder)->run();

    expect(PivRuta::count())->toBe(9);
    expect(PivRutaMunicipio::count())->toBe(0);
});

it('MUNICIPIOS const tiene 101 filas y la distribución oficial por zona', function (): void {
    expect(PivRutaSeeder::MUNICIPIOS)->toHaveCount(101);

    $counts = collect(PivRutaSeeder::MUNICIPIOS)
        ->countBy(fn (array $row): string => $row[1])
        ->all();

    expect($counts)->toBe([
        PivRuta::COD_CENTRO => 1,
        PivRuta::COD_NORTE => 20,
        PivRuta::COD_NORESTE => 11,
        PivRuta::COD_ESTE => 14,
        PivRuta::COD_SURESTE => 10,
        PivRuta::COD_SUR => 10,
        PivRuta::COD_SUROESTE => 11,
        PivRuta::COD_OESTE => 7,
        PivRuta::COD_NOROESTE => 17,
    ]);
});

it('RUTAS const define 9 zonas con km_medio null', function (): void {
    expect(PivRutaSeeder::RUTAS)->toHaveCount(9);

    $kmMedio = collect(PivRutaSeeder::RUTAS)->pluck('km_medio', 'codigo')->all();
    foreach (PivRuta::CODIGOS as $codigo) {
        expect($kmMedio)->toHaveKey($codigo);
        expect($kmMedio[$codigo])->toBeNull();
    }
});

it('DatabaseSeeder llama a PivRutaSeeder', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(PivRuta::count())->toBe(9);
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

it('CODIGOS const tiene los 9 códigos oficiales', function (): void {
    expect(PivRuta::CODIGOS)->toBe([
        PivRuta::COD_CENTRO,
        PivRuta::COD_NORTE,
        PivRuta::COD_NORESTE,
        PivRuta::COD_ESTE,
        PivRuta::COD_SURESTE,
        PivRuta::COD_SUR,
        PivRuta::COD_SUROESTE,
        PivRuta::COD_OESTE,
        PivRuta::COD_NOROESTE,
    ]);
});
