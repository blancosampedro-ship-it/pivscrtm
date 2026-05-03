<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\PivZona;
use App\Models\PivZonaMunicipio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed inicial de zonas operativas Madrid metropolitano + asignación de
 * los municipios top con paneles activos.
 *
 * Los municipios restantes quedan sin zona: el admin los asigna desde la UI.
 */
class PivZonaSeeder extends Seeder
{
    /**
     * Estructura: [zona_nombre => [color_hint, sort_order, municipios_nombres[]]].
     */
    private const ZONAS = [
        'Madrid Sur' => [
            'color' => '#0F62FE',
            'sort' => 1,
            'municipios' => [
                'Móstoles', 'Getafe', 'Fuenlabrada', 'Leganés', 'Parla',
                'Alcorcón', 'Pinto', 'Valdemoro', 'Aranjuez', 'Humanes',
                'Arroyomolinos', 'Moraleja de Enmedio', 'Sevilla la Nueva',
                'Navalcarnero', 'Brunete', 'Villaviciosa de Odón',
            ],
        ],
        'Madrid Norte' => [
            'color' => '#33B1FF',
            'sort' => 2,
            'municipios' => [
                'Pozuelo de Alarcón', 'Alcobendas', 'San Sebastián de los R.',
                'Tres Cantos', 'Algete', 'Colmenar Viejo', 'Boadilla del Monte',
                'Villanueva de la Cañada', 'Villanueva del Pardillo',
                'Rozas de Madrid, Las', 'Majadahonda', 'San Agustín de Guadalíx',
            ],
        ],
        'Corredor Henares' => [
            'color' => '#A56EFF',
            'sort' => 3,
            'municipios' => [
                'Alcalá de Henares', 'Torrejón de Ardoz', 'Coslada',
                'Mejorada del Campo', 'Velilla de San Antonio', 'Arganda del Rey',
                'Loeches', 'Paracuellos del Jarama', 'villalbilla',
                'Pozuelo del Rey',
            ],
        ],
        'Sierra Madrid' => [
            'color' => '#42BE65',
            'sort' => 4,
            'municipios' => [
                'Collado Villalba', 'El Escorial', 'San Lorenzo del Escorial',
                'Torrelodones', 'Galapagar', 'Guadarrama', 'Alpedrete',
                'Hoyo de Manzanares', 'Boalo, El', 'Collado Mediano',
                'Robledo de Chavela',
            ],
        ],
        'Madrid Capital' => [
            'color' => '#1D3F8C',
            'sort' => 5,
            'municipios' => ['Madrid'],
        ],
        'Otros' => [
            'color' => '#8D8D8D',
            'sort' => 99,
            'municipios' => [
                'Chinchón',
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $municipios = Modulo::municipios()
                ->get(['modulo_id', 'nombre'])
                ->keyBy('nombre');

            foreach (self::ZONAS as $nombre => $config) {
                $zona = PivZona::firstOrCreate(
                    ['nombre' => $nombre],
                    [
                        'color_hint' => $config['color'],
                        'sort_order' => $config['sort'],
                    ]
                );

                foreach ($config['municipios'] as $municipioNombre) {
                    $modulo = $municipios->get($municipioNombre);

                    if (! $modulo) {
                        $this->command?->warn("Municipio NO encontrado en modulo: {$municipioNombre} (zona {$nombre})");

                        continue;
                    }

                    PivZonaMunicipio::firstOrCreate(
                        ['municipio_modulo_id' => $modulo->modulo_id],
                        ['zona_id' => $zona->id]
                    );
                }
            }
        });
    }
}
