<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seeder idempotente y autolimpiante de las 9 zonas oficiales (puntos cardinales).
 *
 * Fuente de verdad: Arquitectura/rutas e items.xlsx (hoja "Rutas"). Datos auditados y validados
 * 1:1 contra el clon el 2026-06-01 (101 municipios; el Excel tiene 102 contando "CRTM CiTRAM",
 * que no es municipio del catálogo y se omite). Los nombres de municipio son los EXACTOS del
 * catálogo `modulo` (mayúsculas/acentos/typos incluidos) para que el match sea directo.
 *
 * Tras run(): en BD quedan EXACTAMENTE estas 9 rutas y 101 asignaciones (borra cualquier ruta o
 * asignación que no esté en la lista canónica). Reemplaza el esquema antiguo de 5 rutas-color.
 */
final class PivRutaSeeder extends Seeder
{
    /**
     * @var list<array{codigo: string, nombre: string, zona_geografica: string, color_hint: string, km_medio: int|null, sort_order: int}>
     */
    public const RUTAS = [
        ['codigo' => 'CENTRO', 'nombre' => 'Ruta Centro', 'zona_geografica' => 'Centro', 'color_hint' => '#6F6F6F', 'km_medio' => null, 'sort_order' => 1],
        ['codigo' => 'NORTE', 'nombre' => 'Ruta Norte', 'zona_geografica' => 'Norte', 'color_hint' => '#0F62FE', 'km_medio' => null, 'sort_order' => 2],
        ['codigo' => 'NORESTE', 'nombre' => 'Ruta Noreste', 'zona_geografica' => 'Noreste', 'color_hint' => '#009D9A', 'km_medio' => null, 'sort_order' => 3],
        ['codigo' => 'ESTE', 'nombre' => 'Ruta Este', 'zona_geografica' => 'Este', 'color_hint' => '#8A3FFC', 'km_medio' => null, 'sort_order' => 4],
        ['codigo' => 'SURESTE', 'nombre' => 'Ruta Sureste', 'zona_geografica' => 'Sureste', 'color_hint' => '#EE5396', 'km_medio' => null, 'sort_order' => 5],
        ['codigo' => 'SUR', 'nombre' => 'Ruta Sur', 'zona_geografica' => 'Sur', 'color_hint' => '#FA4D56', 'km_medio' => null, 'sort_order' => 6],
        ['codigo' => 'SUROESTE', 'nombre' => 'Ruta Suroeste', 'zona_geografica' => 'Suroeste', 'color_hint' => '#FF832B', 'km_medio' => null, 'sort_order' => 7],
        ['codigo' => 'OESTE', 'nombre' => 'Ruta Oeste', 'zona_geografica' => 'Oeste', 'color_hint' => '#24A148', 'km_medio' => null, 'sort_order' => 8],
        ['codigo' => 'NOROESTE', 'nombre' => 'Ruta Noroeste', 'zona_geografica' => 'Noroeste', 'color_hint' => '#B28600', 'km_medio' => null, 'sort_order' => 9],
    ];

    /**
     * Pares [nombre_catálogo_modulo, codigo_ruta]. Nombres EXACTOS del catálogo (no editar).
     *
     * @var list<array{0: string, 1: string}>
     */
    public const MUNICIPIOS = [
        ['Madrid', 'CENTRO'],
        ['Becerril de la Sierra', 'NORTE'],
        ['Boalo, El', 'NORTE'],
        ['Buitrago de Lozoya', 'NORTE'],
        ['Bustarviejo', 'NORTE'],
        ['Cabanillas de la Sierra', 'NORTE'],
        ['Cabrera, La', 'NORTE'],
        ['Colmenar Viejo', 'NORTE'],
        ['El Molar', 'NORTE'],
        ['Garganta de los Montes', 'NORTE'],
        ['Guadalix de la Sierra', 'NORTE'],
        ['Manzanares el Real', 'NORTE'],
        ['Navarredonda', 'NORTE'],
        ['Rascafria', 'NORTE'],
        ['San Agustín de Guadalíx', 'NORTE'],
        ['Soto del Real', 'NORTE'],
        ['Talamanca del Jarama', 'NORTE'],
        ['Torrelaguna', 'NORTE'],
        ['Tres Cantos', 'NORTE'],
        ['Valdemanco', 'NORTE'],
        ['Villavieja de Lozoya', 'NORTE'],
        ['Alcobendas', 'NORESTE'],
        ['Algete', 'NORESTE'],
        ['Daganzo de Arriba', 'NORESTE'],
        ['Fresno de Torote', 'NORESTE'],
        ['Fuente el Saz de Jarama', 'NORESTE'],
        ['Paracuellos del Jarama', 'NORESTE'],
        ['Ribatejada', 'NORESTE'],
        ['San Sebastián de los R.', 'NORESTE'],
        ['Valdeavero', 'NORESTE'],
        ['Valdeolmos-Alalpardo', 'NORESTE'],
        ['Valdetorres de Jarama', 'NORESTE'],
        ['Alcalá de Henares', 'ESTE'],
        ['Anchuelo', 'ESTE'],
        ['Camarma de Esteruelas', 'ESTE'],
        ['Coslada', 'ESTE'],
        ['Loeches', 'ESTE'],
        ['Meco', 'ESTE'],
        ['Mejorada del Campo', 'ESTE'],
        ['Pezuela de las Torres', 'ESTE'],
        ['Pozuelo del Rey', 'ESTE'],
        ['San Fernando de Henares', 'ESTE'],
        ['Torrejón de Ardoz', 'ESTE'],
        ['Torres de la Alameda', 'ESTE'],
        ['Velilla de San Antonio', 'ESTE'],
        ['villalbilla', 'ESTE'],
        ['Aranjuez', 'SURESTE'],
        ['Arganda del Rey', 'SURESTE'],
        ['Campo Real', 'SURESTE'],
        ['Chinchón', 'SURESTE'],
        ['Ciempozuelos', 'SURESTE'],
        ['Colmenar de Oreja', 'SURESTE'],
        ['Nuevo Baztan', 'SURESTE'],
        ['Valdemoro', 'SURESTE'],
        ['Valdilecha', 'SURESTE'],
        ['Villarejo de Salvanés', 'SURESTE'],
        ['Cubas de la Sagra', 'SUR'],
        ['Fuenlabrada', 'SUR'],
        ['GRIÑON', 'SUR'],
        ['Getafe', 'SUR'],
        ['Humanes', 'SUR'],
        ['Leganés', 'SUR'],
        ['Parla', 'SUR'],
        ['Pinto', 'SUR'],
        ['Serranillos del Valle', 'SUR'],
        ['Torrejon de la Calzada', 'SUR'],
        ['Aldea del Fresno', 'SUROESTE'],
        ['Arroyomolinos', 'SUROESTE'],
        ['Brunete', 'SUROESTE'],
        ['El Álamo', 'SUROESTE'],
        ['Moraleja de Enmedio', 'SUROESTE'],
        ['Móstoles', 'SUROESTE'],
        ['Navalcarnero', 'SUROESTE'],
        ['San martin de Valdeiglesias', 'SUROESTE'],
        ['Sevilla la Nueva', 'SUROESTE'],
        ['Villamanta', 'SUROESTE'],
        ['Villaviciosa de Odón', 'SUROESTE'],
        ['Alcorcón', 'OESTE'],
        ['Boadilla del Monte', 'OESTE'],
        ['Majadahonda', 'OESTE'],
        ['Pozuelo de Alarcón', 'OESTE'],
        ['Rozas de Madrid, Las', 'OESTE'],
        ['Villanueva de la Cañada', 'OESTE'],
        ['Villanueva del Pardillo', 'OESTE'],
        ['Alpedrete', 'NOROESTE'],
        ['Cercedilla', 'NOROESTE'],
        ['Collado Mediano', 'NOROESTE'],
        ['Collado Villalba', 'NOROESTE'],
        ['Colmenarejo', 'NOROESTE'],
        ['El Escorial', 'NOROESTE'],
        ['Fresnedillas de la Oliva', 'NOROESTE'],
        ['Galapagar', 'NOROESTE'],
        ['Guadarrama', 'NOROESTE'],
        ['Hoyo de Manzanares', 'NOROESTE'],
        ['Moralzarzal', 'NOROESTE'],
        ['Navacerrada', 'NOROESTE'],
        ['Navalagamella', 'NOROESTE'],
        ['Robledo de Chavela', 'NOROESTE'],
        ['San Lorenzo del Escorial', 'NOROESTE'],
        ['Torrelodones', 'NOROESTE'],
        ['Valdemorillo', 'NOROESTE'],
    ];

    /**
     * Variantes mal escritas en el catálogo legacy `modulo` de PRODUCCIÓN. canónico => grafía exacta
     * del catálogo. NO se toca la tabla legacy (corrección pendiente en TODOS / Bloque 02b); aquí solo
     * se mapea para que el match no falle. En el clon estos nombres ya están corregidos → alias inocuo.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'Guadalix de la Sierra' => 'Guadalix de laSierra',
        'Fuente el Saz de Jarama' => 'Fuente del sanz de Jarama',
        'Valdeolmos-Alalpardo' => 'Valdeolmos-Alapardo',
        'San Fernando de Henares' => 'San Fernando de Henarres',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $canonCodigos = array_column(self::RUTAS, 'codigo');

            // 1. Upsert de las 9 rutas canónicas (por codigo, preserva ids existentes).
            foreach (self::RUTAS as $rutaData) {
                PivRuta::updateOrCreate(['codigo' => $rutaData['codigo']], $rutaData);
            }

            // 2. Eliminar rutas obsoletas (esquema viejo de 5 colores) y sus asignaciones.
            $obsoletas = PivRuta::whereNotIn('codigo', $canonCodigos)->pluck('id');
            if ($obsoletas->isNotEmpty()) {
                PivRutaMunicipio::whereIn('ruta_id', $obsoletas)->delete();
                PivRuta::whereIn('id', $obsoletas)->delete();
            }

            // 3. Índice de municipios del catálogo por nombre normalizado (cast latin1 → UTF-8 limpio).
            $modulosByName = Modulo::municipios()
                ->get(['modulo_id', 'nombre'])
                ->mapWithKeys(fn (Modulo $m): array => [$this->normalize((string) $m->nombre) => $m->modulo_id]);

            $resolvedModuloIds = [];
            $skipped = [];

            foreach (self::MUNICIPIOS as [$nombreCatalogo, $codigoRuta]) {
                $moduloId = $this->lookupModuloId($nombreCatalogo, $modulosByName);
                if ($moduloId === null) {
                    $skipped[] = $nombreCatalogo;

                    continue;
                }

                $ruta = PivRuta::where('codigo', $codigoRuta)->first();
                if ($ruta === null) {
                    $skipped[] = $nombreCatalogo." (ruta {$codigoRuta} no existe)";

                    continue;
                }

                PivRutaMunicipio::updateOrCreate(
                    ['municipio_modulo_id' => $moduloId],
                    ['ruta_id' => $ruta->id, 'km_desde_ciempozuelos' => null]
                );
                $resolvedModuloIds[] = $moduloId;
            }

            // 4. Eliminar asignaciones que no correspondan a la lista canónica resuelta.
            if ($resolvedModuloIds !== []) {
                PivRutaMunicipio::whereNotIn('municipio_modulo_id', $resolvedModuloIds)->delete();
            }

            $this->command?->info('Rutas: '.count(self::RUTAS).' · Municipios asignados: '.count($resolvedModuloIds).' · Skipped: '.count($skipped));
            foreach ($skipped as $s) {
                $this->command?->warn("Sin match en catálogo modulo: {$s}");
            }
        });
    }

    /**
     * Normaliza espacios en blanco (trim + colapsa runs internos) preservando acentos y mayúsculas.
     */
    private function normalize(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }

    /**
     * Resuelve el modulo_id de un municipio probando el nombre exacto y variantes de artículo/acento.
     *
     * @param  Collection<string, int>  $modulosByName
     */
    private function lookupModuloId(string $nombreCatalogo, Collection $modulosByName): ?int
    {
        $base = $this->normalize($nombreCatalogo);
        $candidates = [$base];

        // Alias explícito para typos del catálogo legacy de producción.
        if (isset(self::ALIASES[$nombreCatalogo])) {
            $candidates[] = $this->normalize(self::ALIASES[$nombreCatalogo]);
        }

        // Variante "El X" <-> "X, El" (y artículos similares), por si el catálogo invierte el artículo.
        foreach (['El', 'La', 'Los', 'Las'] as $article) {
            if (str_starts_with($base, $article.' ')) {
                $candidates[] = substr($base, strlen($article) + 1).', '.$article;
            }
            if (str_ends_with($base, ', '.$article)) {
                $candidates[] = $article.' '.substr($base, 0, -strlen(', '.$article));
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
