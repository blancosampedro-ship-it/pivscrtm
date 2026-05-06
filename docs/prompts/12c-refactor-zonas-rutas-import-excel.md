# Bloque 12c — Refactor `lv_piv_zona*` → `lv_piv_ruta*` + import Excel oficial Winfin

## Contexto

Tras Bloque 12b.4b (PR #38) cerramos el smoke real prod del módulo "Decisiones del día". Después la responsable del contrato Winfin–CRTM compartió:

1. **WINFIN_Rutas_PIV_Madrid.xlsx** — 5 rutas oficiales + 81 municipios maestros con km desde Ciempozuelos. Es la fuente de verdad operativa para preventivos.
2. **SGIP_winfin.csv** — 28 averías ICCA activas asignadas a Winfin (esto se usa en Bloque 12d, NO en este).

**Mismatch detectado**: nuestras 6 zonas del Bloque 12b.1 (Madrid Sur/Norte/Capital/Henares/Sierra/Otros) NO matchean con las 5 rutas oficiales. Solo Corredor Henares ≈ ROSA-E.

**Decisión cerrada con usuario (5 may mediodía)**:
- **Opción A**: refactor completo `lv_piv_zona` → `lv_piv_ruta` con las 5 rutas oficiales. Cambio semántico en código y datos.
- **Madrid Capital y Otros desaparecen** del seed. Paneles fuera del Excel quedan con `ruta_id = NULL` (gestión ad-hoc admin).
- **Coexistencia con app vieja** se mantiene durante migración.

## Datos reales del Excel (cross-checked con BD legacy)

**5 rutas oficiales** (de la hoja "Rutas Resumen"):

| Código | Nombre | Zona Geográfica | Nº Mun | Km mín | Km máx | Km medio |
|---|---|---|---|---|---|---|
| `ROSA-NO` | Rosa Noroeste | Sierra de Guadarrama / Cuenca Alta del Manzanares | 18 | 62 | 95 | 80 |
| `ROSA-E` | Rosa Este | Corredor del Henares / Tajuña Norte | 11 | 40 | 60 | 51 |
| `VERDE` | Verde Norte | Sierra Norte / Centro Norte (Colmenar–Buitrago) | 20 | 60 | 110 | 85 |
| `AZUL` | Azul Suroeste | Suroeste (Navalcarnero–San Martín de Valdeiglesias) | 15 | 55 | 115 | 84 |
| `AMARILLO` | Amarillo Sureste | Sureste / Vega del Tajo y Tajuña | 17 | 10 | 65 | 36 |

Total **81 municipios** maestros + 0 fuera de ruta = 81. (El Excel TOTAL row dice 81, no 82; matchea.)

**Cobertura BD legacy actual**:
- 103 municipios `modulo` con `tipo=5` en BD (subió de 102 a 103 desde último audit).
- **40 matchean** con Excel via regla normalización (trim + prefijo→suffix + del→de).
- **41 NO** matchean (los del Excel que no tienen panel en BD legacy todavía — pueden ser previsión futura del contrato).
- **63 municipios BD NO en Excel** → 316 paneles activos en núcleos urbanos cercanos (Móstoles 24, Getafe 23, Fuenlabrada 18, Pozuelo Alarcón 18, Alcobendas 17, Coslada 15, Leganés 15, Parla 15, etc.). Estos son operación ad-hoc, NO ruta planificada. Quedan con `ruta_id = NULL` legítimamente.

Resumen post-import esperado: **40 paneles_municipio_asignaciones reales** (los que matchean) → distribuidos entre las 5 rutas. **41 entradas Excel con `municipio_modulo_id = NULL`** → NO se crean filas (warn + skip). 168 paneles de los 484 activos quedarán "con ruta", 316 quedarán "sin ruta".

## Restricciones inviolables

- **NO modificar tablas legacy** (`piv`, `modulo`, `tecnico`, etc.). Solo schema cambia es `lv_piv_zona*` → `lv_piv_ruta*` (drop + create).
- **NO data migration**: las 6 zonas + 51 asignaciones del seed actual se PIERDEN (incluido Campo Real → Madrid Sur del smoke 12b.1). El usuario confirmó que el reemplazo es total.
- **NO subida runtime de Excel via UI** en este bloque. El seeder hardcodea los 81 municipios. Si el Excel cambia, admin actualiza el seeder + re-corre. (UI runtime de Excel queda como mejora futura.)
- **PHP 8.2 floor**, sin paquetes nuevos.
- **Tests Pest verde obligatorio**. Suite actual 291 → ≥305 verde.
- **CI 3/3 verde**.
- **Pint clean**.
- **NO ejecutar** `php artisan migrate` ni tinker contra prod (`.env` LOCAL apunta a SiteGround prod, lección Bloque 12b.3). Solo `php artisan test` (SQLite memory).
- **DESIGN.md Carbon**: el `PivRutaResource` mantiene el mismo estilo que el actual `PivZonaResource` (kebab compacto + slideOver + RelationManager). Solo cambia label/slug/data.
- **Slug explícito** en el Resource para evitar pluralizer bug Bloque 08b: `$slug = 'rutas-operativas'`.

## Plan de cambios

### Step 1 — Migration `2026_05_05_000000_drop_lv_piv_zona_tables.php`

Drop de las 2 tablas existentes. Idempotente con `dropIfExists`.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lv_piv_zona_municipio');
        Schema::dropIfExists('lv_piv_zona');
    }

    public function down(): void
    {
        // Recreate originales del Bloque 12b.1 si hace falta rollback.
        Schema::create('lv_piv_zona', function ($t) {
            $t->id();
            $t->string('nombre', 80)->unique();
            $t->string('color_hint', 7)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('lv_piv_zona_municipio', function ($t) {
            $t->id();
            $t->foreignId('zona_id')->constrained('lv_piv_zona')->cascadeOnDelete();
            $t->unsignedInteger('municipio_modulo_id');
            $t->timestamps();
            $t->unique('municipio_modulo_id', 'idx_municipio_unique_zona');
            $t->index('zona_id', 'idx_zona_id');
        });
    }
};
```

### Step 2 — Migration `2026_05_05_000001_create_lv_piv_ruta_tables.php`

Schema nuevo con columnas adicionales:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_piv_ruta', function (Blueprint $t) {
            $t->id();
            $t->string('codigo', 12)->unique()->comment('ROSA-NO, ROSA-E, VERDE, AZUL, AMARILLO');
            $t->string('nombre', 80)->unique()->comment('Rosa Noroeste, Rosa Este, ...');
            $t->string('zona_geografica', 120)->nullable()->comment('Sierra de Guadarrama / Cuenca Alta del Manzanares');
            $t->string('color_hint', 7)->nullable()->comment('Hex Carbon-aligned');
            $t->unsignedSmallInteger('km_medio')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('lv_piv_ruta_municipio', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ruta_id')->constrained('lv_piv_ruta')->cascadeOnDelete();
            $t->unsignedInteger('municipio_modulo_id')->comment('FK lógica modulo.modulo_id tipo=5 (sin constraint físico, ADR-0002)');
            $t->unsignedSmallInteger('km_desde_ciempozuelos')->nullable()->comment('Del Excel Maestro Municipios');
            $t->timestamps();

            $t->unique('municipio_modulo_id', 'idx_municipio_unique_ruta');
            $t->index('ruta_id', 'idx_ruta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_piv_ruta_municipio');
        Schema::dropIfExists('lv_piv_ruta');
    }
};
```

### Step 3 — Modelo `App\Models\PivRuta` (rename `PivZona`)

Reemplazar el archivo `app/Models/PivZona.php` por `app/Models/PivRuta.php` (delete old + create new). Incluye constantes con los 5 códigos:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PivRutaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ruta operativa Winfin (planificación preventiva).
 *
 * 5 rutas oficiales del Excel WINFIN_Rutas_PIV_Madrid.xlsx (5 may 2026).
 * Cada panel pertenece a una ruta via su municipio (piv.municipio →
 * modulo_id → lv_piv_ruta_municipio.municipio_modulo_id → ruta_id).
 * Sin FK física a modulo (ADR-0002 coexistencia).
 *
 * Paneles en municipios fuera del Excel oficial → ruta_id NULL
 * (gestión ad-hoc desde administración). Decisión usuario 5 may.
 */
final class PivRuta extends Model
{
    use HasFactory;

    protected $table = 'lv_piv_ruta';

    public const COD_ROSA_NO = 'ROSA-NO';
    public const COD_ROSA_E = 'ROSA-E';
    public const COD_VERDE = 'VERDE';
    public const COD_AZUL = 'AZUL';
    public const COD_AMARILLO = 'AMARILLO';

    public const CODIGOS = [
        self::COD_ROSA_NO,
        self::COD_ROSA_E,
        self::COD_VERDE,
        self::COD_AZUL,
        self::COD_AMARILLO,
    ];

    protected $fillable = [
        'codigo',
        'nombre',
        'zona_geografica',
        'color_hint',
        'km_medio',
        'sort_order',
    ];

    protected $casts = [
        'km_medio' => 'int',
        'sort_order' => 'int',
    ];

    protected static function newFactory(): PivRutaFactory
    {
        return PivRutaFactory::new();
    }

    public function municipios(): HasMany
    {
        return $this->hasMany(PivRutaMunicipio::class, 'ruta_id');
    }
}
```

### Step 4 — Modelo `App\Models\PivRutaMunicipio` (rename `PivZonaMunicipio`)

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PivRutaMunicipioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PivRutaMunicipio extends Model
{
    use HasFactory;

    protected $table = 'lv_piv_ruta_municipio';

    protected $fillable = [
        'ruta_id',
        'municipio_modulo_id',
        'km_desde_ciempozuelos',
    ];

    protected $casts = [
        'ruta_id' => 'int',
        'municipio_modulo_id' => 'int',
        'km_desde_ciempozuelos' => 'int',
    ];

    protected static function newFactory(): PivRutaMunicipioFactory
    {
        return PivRutaMunicipioFactory::new();
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(PivRuta::class, 'ruta_id');
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'municipio_modulo_id', 'modulo_id');
    }
}
```

### Step 5 — Factories `database/factories/PivRutaFactory.php` + `PivRutaMunicipioFactory.php`

Reemplazar las antiguas (delete + create).

```php
// PivRutaFactory.php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PivRuta;
use Illuminate\Database\Eloquent\Factories\Factory;

class PivRutaFactory extends Factory
{
    protected $model = PivRuta::class;

    public function definition(): array
    {
        $codigo = strtoupper($this->faker->unique()->lexify('R???'));
        return [
            'codigo' => $codigo,
            'nombre' => $this->faker->unique()->city(),
            'zona_geografica' => $this->faker->words(3, true),
            'color_hint' => '#'.$this->faker->hexColor(),
            'km_medio' => $this->faker->numberBetween(20, 120),
            'sort_order' => $this->faker->numberBetween(1, 99),
        ];
    }
}

// PivRutaMunicipioFactory.php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use Illuminate\Database\Eloquent\Factories\Factory;

class PivRutaMunicipioFactory extends Factory
{
    protected $model = PivRutaMunicipio::class;

    public function definition(): array
    {
        return [
            'ruta_id' => PivRuta::factory(),
            'municipio_modulo_id' => $this->faker->unique()->numberBetween(1, 1000000),
            'km_desde_ciempozuelos' => $this->faker->numberBetween(10, 120),
        ];
    }
}
```

### Step 6 — Seeder `database/seeders/PivRutaSeeder.php` con 5 rutas + 81 municipios reales

Reemplazar el seeder antiguo. **Datos exactos del Excel + Maestro Municipios**:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use Illuminate\Database\Seeder;

/**
 * Seeder idempotente con datos del Excel WINFIN_Rutas_PIV_Madrid.xlsx (5 may 2026).
 *
 * 5 rutas oficiales + 81 municipios maestros. Match con modulo BD vía nombre
 * (regla trim + prefijo→suffix + del→de). Si el municipio no existe en
 * modulo legacy, warn + skip (la asignación se completará cuando el panel
 * se instale en BD).
 */
final class PivRutaSeeder extends Seeder
{
    /**
     * 5 rutas oficiales del Excel "Rutas Resumen".
     * @var list<array{codigo: string, nombre: string, zona_geografica: string, color_hint: string, km_medio: int, sort_order: int}>
     */
    public const RUTAS = [
        ['codigo' => 'ROSA-NO',  'nombre' => 'Rosa Noroeste',     'zona_geografica' => 'Sierra de Guadarrama / Cuenca Alta del Manzanares', 'color_hint' => '#FF7EB6', 'km_medio' => 80, 'sort_order' => 1],
        ['codigo' => 'ROSA-E',   'nombre' => 'Rosa Este',         'zona_geografica' => 'Corredor del Henares / Tajuña Norte',               'color_hint' => '#D02670', 'km_medio' => 51, 'sort_order' => 2],
        ['codigo' => 'VERDE',    'nombre' => 'Verde Norte',       'zona_geografica' => 'Sierra Norte / Centro Norte (Colmenar–Buitrago)',   'color_hint' => '#42BE65', 'km_medio' => 85, 'sort_order' => 3],
        ['codigo' => 'AZUL',     'nombre' => 'Azul Suroeste',     'zona_geografica' => 'Suroeste (Navalcarnero–San Martín de Valdeiglesias)','color_hint' => '#0F62FE','km_medio' => 84, 'sort_order' => 4],
        ['codigo' => 'AMARILLO', 'nombre' => 'Amarillo Sureste',  'zona_geografica' => 'Sureste / Vega del Tajo y Tajuña',                  'color_hint' => '#F1C21B', 'km_medio' => 36, 'sort_order' => 5],
    ];

    /**
     * 81 municipios maestros del Excel "Maestro Municipios".
     * Formato: [nombre_excel, codigo_ruta, km_desde_ciempozuelos].
     * Match con modulo BD vía regla en lookupModuloId().
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
        // Crear/actualizar las 5 rutas (idempotente por código).
        foreach (self::RUTAS as $rutaData) {
            PivRuta::updateOrCreate(['codigo' => $rutaData['codigo']], $rutaData);
        }

        // Precargar municipios BD legacy con cast Latin1String aplicado.
        $modulosByName = Modulo::municipios()->get()->mapWithKeys(
            fn (Modulo $m) => [trim((string) $m->nombre) => $m->modulo_id]
        );

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
                ],
            );
            $created++;
        }

        $this->command?->info("Rutas: " . count(self::RUTAS) . " · Municipios asignados: {$created} · Skipped (sin match): {$skipped}");
    }

    /**
     * Aplica reglas de normalización Excel → BD legacy:
     * 1. trim().
     * 2. Reordenar prefijo "El/La/Los/Las " → suffix ", El/La/Los/Las".
     * 3. Reemplazar " del " → " de " (Buitrago del Lozoya → Buitrago de Lozoya).
     *
     * @param  array<string, int>  $modulosByName
     */
    private function lookupModuloId(string $nombreExcel, $modulosByName): ?int
    {
        $candidates = [trim($nombreExcel)];
        $base = trim($nombreExcel);

        foreach (['El', 'La', 'Los', 'Las'] as $art) {
            if (str_starts_with($base, $art . ' ')) {
                $candidates[] = substr($base, strlen($art) + 1) . ', ' . $art;
            }
        }
        if (str_contains($base, ' del ')) {
            $candidates[] = str_replace(' del ', ' de ', $base);
        }

        foreach ($candidates as $c) {
            if ($modulosByName->has($c)) {
                return $modulosByName->get($c);
            }
        }

        return null;
    }
}
```

**Eliminar el seeder antiguo `PivZonaSeeder.php`** del directorio.

### Step 7 — Filament Resource `PivRutaResource` (rename de `PivZonaResource`)

Reemplazar el archivo + directorio antiguo. Estructura idéntica al actual pero con campos nuevos:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PivRutaResource\Pages;
use App\Filament\Resources\PivRutaResource\RelationManagers\MunicipiosRelationManager;
use App\Models\PivRuta;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class PivRutaResource extends Resource
{
    protected static ?string $model = PivRuta::class;

    protected static ?string $slug = 'rutas-operativas';

    protected static ?string $navigationLabel = 'Rutas';

    protected static ?string $modelLabel = 'ruta';

    protected static ?string $pluralModelLabel = 'rutas';

    protected static ?string $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('codigo')
                ->label('Código')
                ->required()
                ->maxLength(12)
                ->unique(ignoreRecord: true)
                ->extraInputAttributes(['style' => 'text-transform:uppercase']),
            Forms\Components\TextInput::make('nombre')
                ->required()
                ->maxLength(80)
                ->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('zona_geografica')
                ->label('Zona geográfica')
                ->maxLength(120),
            Forms\Components\ColorPicker::make('color_hint')->label('Color'),
            Forms\Components\TextInput::make('km_medio')
                ->numeric()
                ->minValue(0)->maxValue(500),
            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->minValue(0)->maxValue(999)
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('codigo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PivRuta::COD_ROSA_NO => 'pink',
                        PivRuta::COD_ROSA_E => 'pink',
                        PivRuta::COD_VERDE => 'success',
                        PivRuta::COD_AZUL => 'primary',
                        PivRuta::COD_AMARILLO => 'warning',
                        default => 'gray',
                    })
                    ->extraAttributes(['data-mono' => true])
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('nombre')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('zona_geografica')->label('Zona geográfica')->limit(50),
                Tables\Columns\ColorColumn::make('color_hint')->label('Color'),
                Tables\Columns\TextColumn::make('km_medio')->label('Km medio')->numeric()->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('municipios_count')
                    ->label('Municipios')
                    ->counts('municipios')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('sort_order')->label('Orden')->extraAttributes(['data-mono' => true]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->slideOver()->modalWidth('xl'),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [MunicipiosRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPivRutas::route('/'),
            'create' => Pages\CreatePivRuta::route('/create'),
            'edit' => Pages\EditPivRuta::route('/{record}/edit'),
        ];
    }
}
```

Pages en `app/Filament/Resources/PivRutaResource/Pages/`:
- `ListPivRutas.php`
- `CreatePivRuta.php`
- `EditPivRuta.php`

RelationManager en `app/Filament/Resources/PivRutaResource/RelationManagers/MunicipiosRelationManager.php` que expone los `lv_piv_ruta_municipio` con `municipio_modulo_id` + `km_desde_ciempozuelos`.

**Eliminar todo el directorio `app/Filament/Resources/PivZonaResource/` y archivo `PivZonaResource.php`**.

### Step 8 — Update `LvRevisionPendienteResource` filtro Zona → Ruta

Editar `app/Filament/Resources/LvRevisionPendienteResource.php`:

1. Reemplazar import `App\Models\PivZona` → `App\Models\PivRuta`.
2. En filtros, renombrar `SelectFilter::make('zona')` → `SelectFilter::make('ruta')` con label "Ruta".
3. La query del filtro: cambiar `->from('lv_piv_zona_municipio as zona_municipio')->where('zona_municipio.zona_id', $data['value'])` → `->from('lv_piv_ruta_municipio as ruta_municipio')->where('ruta_municipio.ruta_id', $data['value'])`.
4. El método `zonaNombrePorMunicipio()` → renombrar a `rutaNombrePorMunicipio()` con join contra `lv_piv_ruta_municipio` y `lv_piv_ruta`.
5. La columna `'zona'` en la tabla → renombrar a `'ruta'` con label "Ruta" y getStateUsing actualizado.

### Step 9 — Tests Pest

#### 9.1 — Reemplazar `tests/Feature/Filament/Bloque12b1ZonaResourceTest.php` por `Bloque12cRutaResourceTest.php`

Mismos tests pero adaptados:
- Resource accesible para admin / 403 non-admin.
- Lista 5 rutas tras seed.
- Slug correcto `rutas-operativas`.
- ColorColumn render.
- RelationManager municipios.
- Asignar municipio long-tail via UI → counts ajustan.

#### 9.2 — Test del seeder con datos reales

`tests/Feature/Seeders/PivRutaSeederTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Modulo;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use Database\Seeders\PivRutaSeeder;

it('seeder crea las 5 rutas con códigos correctos', function () {
    (new PivRutaSeeder())->run();

    expect(PivRuta::count())->toBe(5);
    expect(PivRuta::pluck('codigo')->sort()->values()->toArray())
        ->toBe(['AMARILLO', 'AZUL', 'ROSA-E', 'ROSA-NO', 'VERDE']);
});

it('seeder es idempotente: re-ejecutar no duplica', function () {
    (new PivRutaSeeder())->run();
    (new PivRutaSeeder())->run();

    expect(PivRuta::count())->toBe(5);
});

it('seeder asigna municipios solo si existen en modulo', function () {
    // Crear modulo Aranjuez (existe en Excel AMARILLO).
    Modulo::factory()->municipio()->create(['nombre' => 'Aranjuez']);

    (new PivRutaSeeder())->run();

    // Solo 1 asignación porque solo Aranjuez existe en modulo.
    expect(PivRutaMunicipio::count())->toBe(1);
});

it('seeder normaliza Las Rozas de Madrid a Rozas de Madrid Las', function () {
    Modulo::factory()->municipio()->create(['nombre' => 'Rozas de Madrid, Las']);

    (new PivRutaSeeder())->run();

    expect(PivRutaMunicipio::count())->toBe(1);
});

it('seeder normaliza Buitrago del Lozoya a Buitrago de Lozoya', function () {
    Modulo::factory()->municipio()->create(['nombre' => 'Buitrago de Lozoya']);

    (new PivRutaSeeder())->run();

    expect(PivRutaMunicipio::count())->toBe(1);
});

it('seeder skip silencioso para municipios no en modulo', function () {
    // BD vacía de modulo → 0 asignaciones esperadas.
    (new PivRutaSeeder())->run();
    expect(PivRutaMunicipio::count())->toBe(0);
    expect(PivRuta::count())->toBe(5); // rutas sí se crean
});

it('UNIQUE codigo previene duplicados', function () {
    PivRuta::factory()->create(['codigo' => 'TEST']);
    expect(fn () => PivRuta::factory()->create(['codigo' => 'TEST']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('UNIQUE municipio_modulo_id previene un municipio en 2 rutas', function () {
    $r1 = PivRuta::factory()->create();
    $r2 = PivRuta::factory()->create();
    PivRutaMunicipio::factory()->create(['ruta_id' => $r1->id, 'municipio_modulo_id' => 999]);

    expect(fn () => PivRutaMunicipio::factory()->create(['ruta_id' => $r2->id, 'municipio_modulo_id' => 999]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('relación PivRuta::municipios devuelve PivRutaMunicipio collection', function () {
    $ruta = PivRuta::factory()->create();
    PivRutaMunicipio::factory()->count(3)->create(['ruta_id' => $ruta->id]);

    expect($ruta->municipios)->toHaveCount(3);
    expect($ruta->municipios->first())->toBeInstanceOf(PivRutaMunicipio::class);
});

it('CODIGOS const tiene los 5 códigos oficiales', function () {
    expect(PivRuta::CODIGOS)->toBe([
        PivRuta::COD_ROSA_NO,
        PivRuta::COD_ROSA_E,
        PivRuta::COD_VERDE,
        PivRuta::COD_AZUL,
        PivRuta::COD_AMARILLO,
    ]);
});
```

#### 9.3 — Update `LvRevisionPendienteResourceTest`

Renombrar referencias `'zona'` → `'ruta'` en los tests del filtro. Cambiar nombre del helper `zonaNombrePorMunicipio` → `rutaNombrePorMunicipio` si lo testea. Verificar que el filter "ruta" filtra por `lv_piv_ruta_municipio` correctamente.

### Step 10 — Smoke local (text-only, NO contra prod)

```bash
# 1. Migration + seeder en SQLite memory tests
php artisan test tests/Feature/Seeders/PivRutaSeederTest.php
php artisan test tests/Feature/Filament/Bloque12cRutaResourceTest.php

# 2. Suite total
php artisan test

# 3. Pint
./vendor/bin/pint --test

# 4. NO ejecutar php artisan migrate ni tinker contra prod (.env apunta a SiteGround).
```

## DoD

- [ ] Migration drop `lv_piv_zona*` aplicable + reverse.
- [ ] Migration create `lv_piv_ruta` (con `codigo`, `nombre`, `zona_geografica`, `color_hint`, `km_medio`, `sort_order`) + `lv_piv_ruta_municipio` (con `ruta_id`, `municipio_modulo_id`, `km_desde_ciempozuelos`).
- [ ] Modelos `PivRuta` (5 constantes COD_*) + `PivRutaMunicipio` con relaciones.
- [ ] Factories `PivRutaFactory` + `PivRutaMunicipioFactory`.
- [ ] Seeder `PivRutaSeeder` con 5 rutas + 81 municipios + regla normalización trim+prefijo+del→de + warn silencioso si no match.
- [ ] Filament Resource `PivRutaResource` con slug `rutas-operativas`, nav group "Operaciones", color badge por código, ColorColumn, ViewAction slideOver, RelationManager municipios.
- [ ] `LvRevisionPendienteResource` con filtro Zona → **Ruta** (label, query, helper renombrados).
- [ ] **Eliminados**: `PivZona*.php`, `PivZonaResource*` (modelo, factory, resource, pages, RelationManager, seeder, tests), referencias en otros archivos.
- [ ] Tests Pest verde: ~15 nuevos en seeder + resource + relación. Suite total 291 → ≥306 verde.
- [ ] CI 3/3 verde.
- [ ] Pint clean.
- [ ] Smoke local OK.

## Smoke real obligatorio post-merge

**Antes**: backup fresh prod cifrado (runbook nuevo).

**Smoke** (pasos previstos):
1. `php artisan migrate --pretend` → debe ser DROP + CREATE de las 4 tablas (zona x2, ruta x2). Cero ALTER legacy.
2. `php artisan migrate --force` real.
3. `php artisan db:seed --class=PivRutaSeeder --force` → output esperado:
   - Rutas: 5
   - Municipios asignados: 40 (de los 81 Excel, los que matchean con BD `modulo`).
   - Skipped: 41.
4. Tinker assert:
   - `PivRuta::count() == 5`.
   - `PivRutaMunicipio::count() == 40`.
   - `PivRuta::where('codigo', 'ROSA-E')->first()->municipios()->count()` ≈ 5-7 (los del Corredor Henares que matchean).
5. Server local + login admin → `/admin/rutas-operativas` → 5 rutas con badges color + km_medio + counts municipios.
6. Click `ROSA-E` slideOver → RelationManager con los municipios asignados.
7. `/admin/revisiones-pendientes` → filtro **Ruta** funciona. Selecciona ROSA-E → tabla muestra solo paneles cuyos municipios pertenecen a ROSA-E.
8. Cleanup: ninguna decisión smoke (las rutas + asignaciones quedan permanentes en prod, son uso real).

## Riesgos y decisiones diferidas

1. **41 municipios Excel sin match** en BD legacy: skip silencioso. Cuando admin instale panel en uno de esos municipios y aparezca en `modulo`, re-correr `PivRutaSeeder` (idempotente) añade la asignación.
2. **316 paneles BD sin ruta asignada**: legítimo (núcleos urbanos cercanos gestión ad-hoc). El UI del Bloque 12b.4 con filtro ruta mostrará "Sin ruta" como opción.
3. **Cambio del Excel oficial**: si la responsable actualiza el Excel (más rutas, cambio km), hay que editar el `PivRutaSeeder` + re-correr. Ningún UI runtime de upload Excel en este bloque.
4. **Cleanup datos previos**: las 6 zonas + 51 asignaciones del Bloque 12b.1 se pierden. Incluido Campo Real → Madrid Sur (corregido implícitamente: Excel pone Campo Real en ROSA-E, lo correcto).
5. **Color badges en el Resource**: los códigos que tienen "ROSA-NO" y "ROSA-E" comparten color `pink`. Si el usuario quiere distinguirlos visualmente, tone diferente — decisión post-smoke.

## REPORTE FINAL (formato esperado)

```
## Bloque 12c — REPORTE FINAL

### Estado
- Branch: bloque-12c-refactor-rutas-import-excel
- Commits: N
- Tests: 291 → 306+ verde (~15 nuevos).
- CI: 3/3 verde.
- Pint: clean.
- Smoke local: tests verde, seeder local OK con factories.

### Decisiones aplicadas
- Refactor zonas → rutas (Opción A).
- 5 rutas oficiales del Excel.
- Skip silencioso 41 municipios sin match en BD.
- Pivots si los hubo.
```

---

## Aplicación checklist obligatoria

| Sección | Aplicado | Cómo |
|---|---|---|
| 1. Compatibilidad framework | ✓ | Migration + Eloquent + Filament Resource estándar. Slug explícito (Bloque 08b). Seeder idempotente. Cero RelationManager en ViewRecord (Bloque 08g/h, no aplica aquí). |
| 2. Inferir de app vieja | N/A | App vieja no tiene rutas. Dato real viene del Excel WINFIN_Rutas_PIV_Madrid.xlsx 5 may 2026. |
| 3. Smoke real obligatorio | ✓ | Backup fresh + migrate + seeder + verificación admin UI + filtro ruta funciona en LvRevisionPendienteResource. Cleanup: NO (quedan permanentes — uso real). |
| 4. Test pivots = banderazo rojo | ✓ | Tests del seeder con factories + reglas normalización (Las → suffix, del → de). Si Copilot pivota, banderazo. |
| 5. Datos prod-shaped | ✓ | Seeder hardcodea los 81 municipios reales del Excel. Cross-checked con BD: 40 matches, 41 sin match (esperado). El seeder tiene warn silencioso para los 41. |
