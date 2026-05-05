<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeder idempotente con datos del Excel WINFIN_Rutas_PIV_Madrid.xlsx.
 */
final class PivRutaSeeder extends Seeder
{
    /**
     * @var list<array{codigo: string, nombre: string, zona_geografica: string, color_hint: string, km_medio: int, sort_order: int}>
     */
    public const RUTAS = [
        ['codigo' => 'ROSA-NO', 'nombre' => 'Rosa Noroeste', 'zona_geografica' => 'Sierra de Guadarrama / Cuenca Alta del Manzanares', 'color_hint' => '#FF7EB6', 'km_medio' => 80, 'sort_order' => 1],
        ['codigo' => 'ROSA-E', 'nombre' => 'Rosa Este', 'zona_geografica' => 'Corredor del Henares / Tajuña Norte', 'color_hint' => '#D02670', 'km_medio' => 51, 'sort_order' => 2],
        ['codigo' => 'VERDE', 'nombre' => 'Verde Norte', 'zona_geografica' => 'Sierra Norte / Centro Norte (Colmenar-Buitrago)', 'color_hint' => '#42BE65', 'km_medio' => 85, 'sort_order' => 3],
        ['codigo' => 'AZUL', 'nombre' => 'Azul Suroeste', 'zona_geografica' => 'Suroeste (Navalcarnero-San Martín de Valdeiglesias)', 'color_hint' => '#0F62FE', 'km_medio' => 84, 'sort_order' => 4],
        ['codigo' => 'AMARILLO', 'nombre' => 'Amarillo Sureste', 'zona_geografica' => 'Sureste / Vega del Tajo y Tajuña', 'color_hint' => '#F1C21B', 'km_medio' => 36, 'sort_order' => 5],
    ];

    /**
     * @var list<array{0: string, 1: string, 2: int}>
     */
    public const MUNICIPIOS = [
        ['Alpedrete', 'ROSA-NO', 84],
        ['Cercedilla', 'ROSA-NO', 95],
        ['Collado Mediano', 'ROSA-NO', 85],
        ['Collado Villalba', 'ROSA-NO', 82],
        ['Colmenarejo', 'ROSA-NO', 80],
        ['El Escorial', 'ROSA-NO', 88],
        ['Galapagar', 'ROSA-NO', 78],
        ['Guadarrama', 'ROSA-NO', 88],
        ['Hoyo de Manzanares', 'ROSA-NO', 78],
        ['Las Rozas de Madrid', 'ROSA-NO', 65],
        ['Los Molinos', 'ROSA-NO', 92],
        ['Majadahonda', 'ROSA-NO', 62],
        ['Manzanares el Real', 'ROSA-NO', 75],
        ['Navacerrada', 'ROSA-NO', 90],
        ['San Lorenzo de El Escorial', 'ROSA-NO', 90],
        ['Torrelodones', 'ROSA-NO', 70],
        ['Villanueva de la Cañada', 'ROSA-NO', 70],
        ['Villanueva del Pardillo', 'ROSA-NO', 72],
        ['Alcalá de Henares', 'ROSA-E', 55],
        ['Ambite', 'ROSA-E', 60],
        ['Campo Real', 'ROSA-E', 40],
        ['Carabaña', 'ROSA-E', 50],
        ['Loeches', 'ROSA-E', 42],
        ['Nuevo Baztán', 'ROSA-E', 55],
        ['Olmeda de las Fuentes', 'ROSA-E', 52],
        ['Orusco de Tajuña', 'ROSA-E', 55],
        ['Pezuela de las Torres', 'ROSA-E', 58],
        ['Torres de la Alameda', 'ROSA-E', 45],
        ['Villar del Olmo', 'ROSA-E', 50],
        ['Buitrago del Lozoya', 'VERDE', 105],
        ['Bustarviejo', 'VERDE', 90],
        ['Colmenar Viejo', 'VERDE', 65],
        ['El Berrueco', 'VERDE', 95],
        ['El Molar', 'VERDE', 65],
        ['Garganta de los Montes', 'VERDE', 100],
        ['Guadalix de la Sierra', 'VERDE', 80],
        ['La Cabrera', 'VERDE', 90],
        ['Lozoya', 'VERDE', 105],
        ['Lozoyuela-Navas-Sieteiglesias', 'VERDE', 100],
        ['Miraflores de la Sierra', 'VERDE', 85],
        ['Navalafuente', 'VERDE', 85],
        ['Patones', 'VERDE', 90],
        ['Pedrezuela', 'VERDE', 72],
        ['Rascafría', 'VERDE', 110],
        ['San Agustín del Guadalix', 'VERDE', 65],
        ['Soto del Real', 'VERDE', 78],
        ['Torrelaguna', 'VERDE', 85],
        ['Tres Cantos', 'VERDE', 60],
        ['Venturada', 'VERDE', 75],
        ['Aldea del Fresno', 'AZUL', 75],
        ['Brunete', 'AZUL', 65],
        ['Cadalso de los Vidrios', 'AZUL', 105],
        ['Cenicientos', 'AZUL', 110],
        ['Chapinería', 'AZUL', 85],
        ['Navalcarnero', 'AZUL', 55],
        ['Navas del Rey', 'AZUL', 90],
        ['Pelayos de la Presa', 'AZUL', 95],
        ['Quijorna', 'AZUL', 70],
        ['Rozas de Puerto Real', 'AZUL', 115],
        ['San Martín de Valdeiglesias', 'AZUL', 100],
        ['Sevilla la Nueva', 'AZUL', 60],
        ['Villa del Prado', 'AZUL', 90],
        ['Villamanta', 'AZUL', 70],
        ['Villamantilla', 'AZUL', 72],
        ['Aranjuez', 'AMARILLO', 18],
        ['Belmonte de Tajo', 'AMARILLO', 30],
        ['Brea de Tajo', 'AMARILLO', 65],
        ['Chinchón', 'AMARILLO', 25],
        ['Colmenar de Oreja', 'AMARILLO', 25],
        ['Estremera', 'AMARILLO', 60],
        ['Fuentidueña de Tajo', 'AMARILLO', 55],
        ['Morata de Tajuña', 'AMARILLO', 30],
        ['Perales de Tajuña', 'AMARILLO', 35],
        ['San Martín de la Vega', 'AMARILLO', 15],
        ['Tielmes', 'AMARILLO', 40],
        ['Titulcia', 'AMARILLO', 10],
        ['Valdaracete', 'AMARILLO', 55],
        ['Valdelaguna', 'AMARILLO', 30],
        ['Villaconejos', 'AMARILLO', 22],
        ['Villamanrique de Tajo', 'AMARILLO', 50],
        ['Villarejo de Salvanés', 'AMARILLO', 45],
    ];

    public function run(): void
    {
        foreach (self::RUTAS as $rutaData) {
            PivRuta::updateOrCreate(['codigo' => $rutaData['codigo']], $rutaData);
        }

        $modulosByName = Modulo::municipios()
            ->get(['modulo_id', 'nombre'])
            ->mapWithKeys(fn (Modulo $modulo): array => [trim((string) $modulo->nombre) => $modulo->modulo_id]);

        $created = 0;
        $skipped = 0;

        foreach (self::MUNICIPIOS as [$nombreExcel, $codigoRuta, $km]) {
            $moduloId = $this->lookupModuloId($nombreExcel, $modulosByName);
            if ($moduloId === null) {
                $this->command?->warn("Municipio sin match en modulo BD: {$nombreExcel} (ruta {$codigoRuta})");
                $skipped++;

                continue;
            }

            $ruta = PivRuta::where('codigo', $codigoRuta)->first();
            if ($ruta === null) {
                $this->command?->warn("Ruta no encontrada: {$codigoRuta} (skip {$nombreExcel})");
                $skipped++;

                continue;
            }

            PivRutaMunicipio::updateOrCreate(
                ['municipio_modulo_id' => $moduloId],
                [
                    'ruta_id' => $ruta->id,
                    'km_desde_ciempozuelos' => $km,
                ]
            );
            $created++;
        }

        $this->command?->info('Rutas: '.count(self::RUTAS)." · Municipios asignados: {$created} · Skipped (sin match): {$skipped}");
    }

    /**
     * @param  Collection<string, int>  $modulosByName
     */
    private function lookupModuloId(string $nombreExcel, Collection $modulosByName): ?int
    {
        $base = trim($nombreExcel);
        $candidates = [$base];

        foreach (['El', 'La', 'Los', 'Las'] as $article) {
            if (str_starts_with($base, $article.' ')) {
                $candidates[] = substr($base, strlen($article) + 1).', '.$article;
            }
        }

        if (str_contains($base, ' del ')) {
            $candidates[] = str_replace(' del ', ' de ', $base);
        }

        foreach ($candidates as $candidate) {
            if ($modulosByName->has($candidate)) {
                return (int) $modulosByName->get($candidate);
            }
        }

        return null;
    }
}
