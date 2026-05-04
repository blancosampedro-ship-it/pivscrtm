# Bloque 12b.1 — Schema zonas operativas + UI admin definir zonas

## Contexto

Tras cerrar el Bloque 11c (PWA read-only offline + iconos + manifest, mergeado en PR #31 y fix trustProxies en PR #32), el siguiente módulo es la **planificación mensual preventiva** del contrato — Bloque 12b, partido en 6 sub-bloques. Este es el primer sub-bloque (12b.1): la base geográfica.

**El módulo completo 12b** entrega un sistema donde:
1. Cada mes, todos los paneles activos no archivados (~475) entran en una lista de "pendientes este mes".
2. El admin tiene una **app externa** que muestra status online/offline de paneles ontime. Puede validar revisión preventiva remotamente (sin desplazar técnico).
3. Cada mañana (o el día anterior), el admin abre la app nuestra y por cada panel candidato del día decide:
   - "Verificado remoto OK" (revisión hecha desde la app externa).
   - "Requiere visita física" + fecha (hoy/mañana).
   - "Excepción justificada" (panel en obras, retirado).
4. Las que requieren visita se promueven automáticamente a `asignacion` legacy tipo=2 status=1 con stub averia, vía cron daily 06:00.
5. El técnico ve cards en su PWA, cierra revisión normalmente (Bloque 11d flow).
6. Hook en el cierre actualiza `lv_revision_pendiente` a status='completada'.
7. Final del mes: reporte contractual exportable. Pendientes carry over al siguiente mes con prioridad.

**Este sub-bloque (12b.1) entrega solo la base geográfica**: tablas zonas operativas + UI admin para definirlas + seed Madrid. Los siguientes sub-bloques (12b.2..12b.6) construyen encima.

## Decisiones del usuario ya tomadas

- **Capacity**: app diseñada para hasta 10 técnicos, realidad actual 1-2.
- **Calendario laboral**: L-Ma 7-15 (8h), Mi-J 8-14+15-18 (9h con descanso), V 8-14 (6h). S/D no. Festivos calendario municipal Madrid.
- **Zonas operativas**: agrupar municipios en zonas (Madrid Sur/Norte/Centro/Henares/Sierra/Otros). Admin define una vez, después solo edita.
- **Seed**: pre-cargar 6 zonas + asignación inicial de ~50 municipios top (los demás 52 quedan sin zona, el admin los asigna desde UI).
- **Validación remota dura 1 mes** (decisión 5 — relevante en 12b.3, no en este sub-bloque).
- **Sliding daily replanning** (relevante 12b.3+).

## Restricciones inviolables

- **NO modificar tabla legacy `piv` ni `modulo`** (regla #1 + #2). El campo `piv.municipio` (varchar con FK lógica a `modulo.modulo_id` donde `tipo=5`) ya existe y se sigue usando como verdad de qué municipio es cada panel.
- **`lv_piv_zona_municipio` NO añade FK física a `modulo`** — relación lógica por `municipio_modulo_id` integer. Regla coexistencia ADR-0002.
- **NO tocar PWA técnico** (`resources/views/livewire/tecnico/`, `app/Services/AsignacionCierreService.php`, etc.). Este bloque es 100% admin Filament.
- **NO escribir lógica de planificación** (algoritmo daily/mensual/cron). Eso es 12b.3+.
- **DESIGN.md Carbon visual** se mantiene para el admin Filament. Sin novedades estéticas.
- **Tests Pest verde obligatorio**. Suite actual 209. Sumar ~10 tests. Terminar ≥219 verde.
- **CI verde** (3 jobs) antes de PR ready.

## Plan de cambios

### Step 1 — Migration `lv_piv_zona`

`database/migrations/2026_05_03_000000_create_lv_piv_zona_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_piv_zona', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 80)->unique();
            $t->string('color_hint', 7)->nullable()->comment('Hex color para el calendario operacional UI');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_piv_zona');
    }
};
```

### Step 2 — Migration `lv_piv_zona_municipio`

`database/migrations/2026_05_03_000001_create_lv_piv_zona_municipio_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot zona ↔ municipio. Sin FK física a `modulo` (regla coexistencia
 * ADR-0002): la integridad la valida la app. Un municipio en exactamente
 * UNA zona (UNIQUE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_piv_zona_municipio', function (Blueprint $t) {
            $t->id();
            $t->foreignId('zona_id')->constrained('lv_piv_zona')->cascadeOnDelete();
            $t->unsignedInteger('municipio_modulo_id')->comment('FK lógica a modulo.modulo_id donde tipo=5');
            $t->timestamps();

            $t->unique('municipio_modulo_id', 'idx_municipio_unique_zona');
            $t->index('zona_id', 'idx_zona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_piv_zona_municipio');
    }
};
```

### Step 3 — Modelo Eloquent `App\Models\PivZona`

`app/Models/PivZona.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PivZonaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zona operativa para clusterizar paneles por proximidad geográfica.
 *
 * Cada panel pertenece a una zona via su municipio (piv.municipio →
 * modulo_id → lv_piv_zona_municipio.municipio_modulo_id → zona_id).
 * Sin FK física a modulo (ADR-0002 coexistencia).
 *
 * El admin define las zonas una vez y asigna los 102 municipios reales.
 * 12b.3+ usará esta agrupación para clusterizar la planificación diaria.
 */
class PivZona extends Model
{
    /** @use HasFactory<PivZonaFactory> */
    use HasFactory;

    protected $table = 'lv_piv_zona';

    protected $fillable = [
        'nombre', 'color_hint', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Asignaciones de municipios a esta zona (pivot).
     */
    public function municipios(): HasMany
    {
        return $this->hasMany(PivZonaMunicipio::class, 'zona_id');
    }
}
```

`app/Models/PivZonaMunicipio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot zona ↔ municipio. Modelo standalone (no via belongsToMany)
 * porque no podemos definir relación bidireccional con `modulo`
 * (legacy, sin FK física).
 */
class PivZonaMunicipio extends Model
{
    protected $table = 'lv_piv_zona_municipio';

    protected $fillable = [
        'zona_id', 'municipio_modulo_id',
    ];

    protected $casts = [
        'zona_id' => 'integer',
        'municipio_modulo_id' => 'integer',
    ];

    public function zona(): BelongsTo
    {
        return $this->belongsTo(PivZona::class, 'zona_id');
    }

    /**
     * Resolver al modelo Modulo (legacy) por `municipio_modulo_id`.
     * No es un BelongsTo Eloquent porque la PK de Modulo es `modulo_id`.
     */
    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'municipio_modulo_id', 'modulo_id');
    }
}
```

### Step 4 — Factories

`database/factories/PivZonaFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\PivZona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PivZona>
 */
class PivZonaFactory extends Factory
{
    protected $model = PivZona::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->unique()->city(),
            'color_hint' => $this->faker->hexColor(),
            'sort_order' => $this->faker->numberBetween(1, 99),
        ];
    }
}
```

(`PivZonaMunicipioFactory` análogo, pero `municipio_modulo_id` puede usar `Modulo::factory()` con tipo=5 si tu factory de `Modulo` lo soporta. Si no, hardcodear un integer válido para tests.)

### Step 5 — Seeder `PivZonaSeeder`

`database/seeders/PivZonaSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\PivZona;
use App\Models\PivZonaMunicipio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed inicial de zonas operativas Madrid metropolitano + asignación de
 * los ~50 municipios top con paneles activos (sale del análisis del
 * Bloque 12b.1 sobre la BD prod).
 *
 * Los 52 municipios restantes (long tail) quedan sin zona — el admin
 * los asigna desde la UI Filament en PivZonaResource.
 *
 * El seeder es idempotente: usa firstOrCreate por nombre de zona y por
 * municipio_modulo_id. Correrlo varias veces no duplica.
 */
class PivZonaSeeder extends Seeder
{
    /**
     * Estructura: [zona_nombre => [color_hint, sort_order, municipios_nombres[]]].
     * Los nombres de municipios deben coincidir EXACTAMENTE con `modulo.nombre`
     * en BD legacy (incluyendo acentos y comas).
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
                // Resto de long tail los asignará el admin desde UI.
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::ZONAS as $nombre => $config) {
                $zona = PivZona::firstOrCreate(
                    ['nombre' => $nombre],
                    [
                        'color_hint' => $config['color'],
                        'sort_order' => $config['sort'],
                    ]
                );

                foreach ($config['municipios'] as $munNombre) {
                    $modulo = Modulo::where('tipo', Modulo::TIPO_MUNICIPIO)
                        ->where('nombre', $munNombre)
                        ->first();

                    if (! $modulo) {
                        $this->command?->warn("Municipio NO encontrado en modulo: {$munNombre} (zona {$nombre})");
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
```

Registrar en `DatabaseSeeder::run()`:

```php
public function run(): void
{
    $this->call([
        PivZonaSeeder::class,
        // Otros seeders existentes
    ]);
}
```

Nota: muchas claves del array `'municipios'` son texto exacto del legacy (`'Boalo, El'`, `'Rozas de Madrid, Las'`). Verificar que matchean con `modulo.nombre` exacto. Si Copilot encuentra mismatches al correr el seeder, ajustar el array al string real (NO modificar `modulo`).

### Step 6 — Filament Resource `PivZonaResource`

`app/Filament/Resources/PivZonaResource.php`:

Patrón análogo a `TecnicoResource` (Bloque 11ab):

- `$navigationIcon = 'heroicon-o-map'`
- `$navigationGroup = 'Operaciones'`
- `$navigationSort = 5`
- `$modelLabel = 'zona operativa'`
- `$pluralModelLabel = 'zonas operativas'`
- `$slug = 'zonas'`

Form (en `form()`):
- Section "Identidad" con: `nombre` (text required maxLength=80), `color_hint` (ColorPicker o text con regex hex), `sort_order` (number).

Table (en `table()`):
- Columns:
  - `nombre` (sortable, searchable, weight medium).
  - `color_hint` ColorColumn (Filament built-in `Tables\Columns\ColorColumn` muestra el color como swatch).
  - `municipios_count` (TextColumn con `withCount('municipios')` en getEloquentQuery, sortable).
  - `sort_order` (TextColumn, sortable).
- Default sort: `sort_order asc`.
- Actions: ActionGroup kebab Carbon (igual que TecnicoResource):
  - ViewAction slideOver con infolist (zona + lista de municipios asignados).
  - EditAction.
  - DeleteAction (con confirmación, cascade borra los pivot).

RelationManager para municipios asignados a la zona (mostrar dentro del slideOver/edit):

`app/Filament/Resources/PivZonaResource/RelationManagers/MunicipiosRelationManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivZonaResource\RelationManagers;

use App\Models\Modulo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MunicipiosRelationManager extends RelationManager
{
    protected static string $relationship = 'municipios';

    protected static ?string $title = 'Municipios asignados';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('municipio_modulo_id')
                ->label('Municipio')
                ->options(fn () => Modulo::where('tipo', Modulo::TIPO_MUNICIPIO)
                    ->orderBy('nombre')
                    ->pluck('nombre', 'modulo_id'))
                ->searchable()
                ->required()
                ->unique(table: 'lv_piv_zona_municipio', column: 'municipio_modulo_id', ignoreRecord: true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('municipio_modulo_id')
            ->columns([
                Tables\Columns\TextColumn::make('modulo.nombre')
                    ->label('Municipio')
                    ->sortable(),
                // Count paneles activos en este municipio:
                Tables\Columns\TextColumn::make('paneles_count')
                    ->label('Paneles activos')
                    ->state(fn ($record) => \App\Models\Piv::where('status', 1)
                        ->where('municipio', (string) $record->municipio_modulo_id)
                        ->whereNotIn('piv_id', \App\Models\LvPivArchived::pluck('piv_id'))
                        ->count())
                    ->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Asignar municipio'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()->label('Quitar de zona'),
            ]);
    }
}
```

Registrar el RelationManager en `PivZonaResource::getRelations()`:

```php
public static function getRelations(): array
{
    return [MunicipiosRelationManager::class];
}
```

### Step 7 — Pages standard

`app/Filament/Resources/PivZonaResource/Pages/`:
- `ListPivZonas.php`
- `CreatePivZona.php`
- `EditPivZona.php`
- (NO ViewPage — el ViewAction slideOver es suficiente.)

### Step 8 — Tests obligatorios

Crear `tests/Feature/Filament/Bloque12b1ZonaResourceTest.php`. Mínimo:

1. `pivzona_table_exists_with_correct_columns` — assert tabla `lv_piv_zona` existe con columnas correctas + `lv_piv_zona_municipio` con UNIQUE en municipio_modulo_id.
2. `pivzona_seeder_creates_6_zonas_with_correct_names_and_colors` — `Artisan::call('db:seed', ['--class' => 'PivZonaSeeder'])` y assert PivZona::count() === 6, nombres y colores correctos.
3. `pivzona_seeder_assigns_madrid_sur_municipios` — assert que `Madrid Sur` tiene los 16 municipios del array (los que existan en `modulo`).
4. `pivzona_seeder_is_idempotent` — correr dos veces, count sigue siendo igual.
5. `pivzona_seeder_warns_on_missing_municipio` — capturar output, assert warning si un municipio del array no existe en `modulo`.
6. `admin_can_view_zonas_list_in_filament` — login admin, GET `/admin/zonas`, assertOk + see "Madrid Sur".
7. `admin_can_create_new_zona` — Livewire test create.
8. `admin_can_edit_zona_color` — Livewire test edit.
9. `admin_can_assign_new_municipio_to_zona_via_relation_manager` — Livewire test que abre el RelationManager y asigna un municipio.
10. `pivzona_zona_id_unique_per_municipio` — assert que asignar un municipio ya en otra zona falla con UNIQUE constraint.

**Tests pivots banderazo rojo:**
- Si Copilot dice "saltei test del seeder porque sus warnings son hard to capture" → fail.
- Si dice "no puedo testear RelationManager fuera de page" → fail (vimos en 11ab Bloque 08d). Hay que mountarlo correctamente.
- Si dice "tuve que crear un Modulo factory mock porque tipo=5 es complicado" → revisar. Si en BD test no hay modulos seedados, hay que asegurar que el setup de los tests crea al menos los modulos top necesarios (factory básica de Modulo con tipo=5 es viable).

### Step 9 — Smoke real

Pre-requisito: BD de pruebas no se afecta (los tests usan SQLite). Smoke real corre contra **prod BD**, así que ejecutar el seeder de inicio crea las zonas en prod directamente. Esto es OK porque:
- El seeder es idempotente.
- Si el admin ya tenía zonas, no se sobreescriben (firstOrCreate).
- Si es la primera vez, las zonas quedan creadas para uso real.

Pasos del smoke:

1. Mergear PR (espera ack del usuario).
2. Local: `git pull main`, `php artisan migrate --force` (aplica las 2 migrations en prod).
3. Local: `php artisan db:seed --class=PivZonaSeeder --force` (crea zonas + asigna ~50 municipios top en prod).
4. Verificar via tinker:
   ```bash
   php artisan tinker --execute="
   echo 'Zonas creadas: ' . App\\Models\\PivZona::count() . ' (esperado 6)' . PHP_EOL;
   foreach (App\\Models\\PivZona::orderBy('sort_order')->get() as \$z) {
       echo '  ' . str_pad(\$z->nombre, 25) . ' municipios=' . \$z->municipios()->count() . ' color=' . \$z->color_hint . PHP_EOL;
   }
   echo 'Total municipios asignados: ' . App\\Models\\PivZonaMunicipio::count() . PHP_EOL;
   "
   ```
5. Login admin en `http://127.0.0.1:8000/admin`. Sidebar "Operaciones → Zonas operativas" navegable. List page muestra 6 zonas con colores swatch.
6. Click una zona (ej: "Madrid Sur"). Slideover con detalle + RelationManager "Municipios asignados".
7. **Asignar 1 municipio nuevo via UI**: pick un municipio del long tail (que no esté seedeado, ej: "Cubas de la Sagra" si tiene panel) → assert aparece en la tabla del RelationManager.
8. Verificar BD: `App\Models\PivZonaMunicipio::count()` aumentó en 1.
9. Captura screenshot de:
   - Lista zonas con colores.
   - Slideover de "Madrid Sur" con sus municipios.
   - Asignar municipio dialog.
10. Cleanup post-smoke: si el admin asignó "Cubas" durante el smoke pero realmente debería estar en otra zona, **dejarlo asignado** — es decisión del admin de operación real, no contaminación del smoke. Las zonas seedeadas se quedan permanentes en prod.

Captura screenshots en `docs/runbooks/screenshots/12b1-smoke/`.

## Restricciones de proceso (CLAUDE.md)

- Branch: `bloque-12b1-schema-zonas-operativas`.
- Commits atómicos:
  1. `feat(zonas): add lv_piv_zona migration with nombre, color, sort_order`
  2. `feat(zonas): add lv_piv_zona_municipio pivot with unique municipio constraint`
  3. `feat(zonas): add PivZona and PivZonaMunicipio Eloquent models`
  4. `feat(zonas): add PivZona and PivZonaMunicipio factories`
  5. `feat(zonas): seed Madrid zonas with top 50 municipios`
  6. `feat(filament): add PivZonaResource with kebab actions and color column`
  7. `feat(filament): add MunicipiosRelationManager with assign and remove actions`
  8. `test(zonas): cover schema, seeder idempotency, Filament Resource and RelationManager`
- Push + PR contra `main`. NO mergear: el usuario revisa.
- NO modificar `app/Auth/`, `app/Services/`, `routes/`, `config/`.
- NO escribir lógica algorítmica de planificación (eso es 12b.3+).

## Reporte final

Devolver:

```
## Bloque 12b.1 — Reporte

### Commits
- <hash> feat(zonas): add lv_piv_zona migration
- <hash> feat(zonas): add lv_piv_zona_municipio pivot
- <hash> feat(zonas): add PivZona and PivZonaMunicipio models
- <hash> feat(zonas): add factories
- <hash> feat(zonas): seed Madrid zonas
- <hash> feat(filament): add PivZonaResource
- <hash> feat(filament): add MunicipiosRelationManager
- <hash> test(zonas): cover schema, seeder, Filament

### Tests
- Suite total: 209 → ~219 verde.
- 4 jobs CI verde.

### Seeder smoke
- Local en BD test: assert 6 zonas creadas.
- Smoke real prod pendiente al merge: aplicar migrations + correr seeder + asignar 1 municipio via UI + verificación BD.

### Pivots realizados (si los hubo)
- Si algún municipio del array de seeder no matcheó con modulo.nombre exacto, listar las correcciones.
- ...

### Riesgos conocidos
- Migrations sobre prod BD impactan datos vivos: el admin tendrá que correr el seeder explícitamente tras el merge para tener las zonas pre-cargadas.
- Los 52 municipios long tail siguen sin zona hasta que admin los asigne via UI. La UI debería tener un dashboard "Municipios sin zona" (lo planeamos para 12b.4 cuando tengamos el resto del módulo, no este sub-bloque).

### Deudas que NO se atacan en este bloque
- 12b.2 calendario laboral L-V con horarios variados + festivos Madrid.
- 12b.3 schema lv_revision_pendiente + cron mensual.
- 12b.4 UI admin "decisiones del día" + cron daily.
- 12b.5 UI calendario operacional admin.
- 12b.6 reporte mensual contractual exportable.
- Chip SW scope (Bloque 11c-fix-sw-scope).
- Chips 11ab/11b (race condition, kebab clip, throttle UX).
```
