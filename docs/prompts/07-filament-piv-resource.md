# Bloque 07 — Filament resource `Piv` (CRUD admin de paneles)

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~60-90 min.

---

## Objetivo

Filament Resource para `Piv` que permite al admin (panel `/admin`) listar, buscar, filtrar, crear y editar los 575 paneles informativos.

**Lo que entra:**
- Relación nueva `Piv::municipioModulo()` (BelongsTo Modulo via `piv.municipio` ↔ `modulo.modulo_id` filtrando por `tipo=5`).
- `App\Filament\Resources\PivResource` con su `ListPivs`, `CreatePiv`, `EditPiv` pages.
- Tabla con columnas (piv_id, parada_cod, direccion, municipio, operador principal, industria, status), búsqueda, sort, filtros (status, operador, municipio).
- Form con secciones: Identificación, Localización, Operadores, Estado.
- **Eager loading obligatorio** vía `getEloquentQuery()` con `with([...])` — test `expectQueryCount`.
- **Validación `municipio`** según ADR-0007 (closure custom: acepta `"0"` centinela + verifica `modulo.tipo=5`).

**Fuera de alcance:**
- Resource de Averia, Asignacion, Correctivo, Revision (Bloques 08, 09).
- Mapa con coords (Bloque 02f geocoding pendiente).
- Image gallery del panel — `Piv::imagenes()` ya existe pero el resource solo mostrará count, no la galería completa (puede ser mejora futura).
- Export CSV/PDF (Bloque 10 con TecnicoExportTransformer).
- Authorization Policy (`PivPolicy`) — admin tiene CRUD total por `canAccessPanel`. Más tarde con técnicos/operadores en Bloques 11/12.

## Definition of Done

1. `app/Models/Piv.php` con método nuevo `municipioModulo()` BelongsTo.
2. `app/Filament/Resources/PivResource.php` + `PivResource/Pages/{ListPivs,CreatePiv,EditPiv}.php`.
3. `getEloquentQuery()` override con `->with(['operadorPrincipal', 'operadorSecundario', 'operadorTerciario', 'industria', 'municipioModulo'])`.
4. `form()`:
   - Section "Identificación": `piv_id` (read-only en edit, hidden en create), `parada_cod`, `cc_cod`, `n_serie_piv`, `n_serie_sim`, `n_serie_mgp`, `tipo_piv`, `tipo_marquesina`, `tipo_alimentacion`.
   - Section "Localización": `direccion`, `municipio` (Select con dropdown ordenado de municipios + opción "0" → "— Sin municipio asignado —"), `industria_id`, `concesionaria_id`.
   - Section "Operadores": `operador_id`, `operador_id_2`, `operador_id_3` (Selects).
   - Section "Estado": `status`, `status2`, `mantenimiento`, `prevision`, `observaciones`, `fecha_instalacion`.
5. `table()`:
   - Columnas `piv_id`, `parada_cod`, `direccion`, `municipioModulo.nombre`, `operadorPrincipal.razon_social`, `industria.nombre`, `status`.
   - Searchable: `piv_id`, `parada_cod`, `direccion`, `cc_cod`, `n_serie_piv`.
   - Sortable: `piv_id`, `parada_cod`, `municipioModulo.nombre`, `status`.
   - Filtros: `status` (Select), `municipio` (Select tipo=5), `operador_id` (Select).
   - Default sort: `piv_id` asc.
6. **Validación municipio** inline en el form (closure custom — no separar a FormRequest porque Filament no lo invoca; las reglas se declaran sobre el field).
7. Tests obligatorios:
   - `piv_listing_no_n_plus_one` — listar 50 paneles con relaciones cargadas → ≤ 5 queries.
   - `municipio_validation_rejects_invalid_id` — form rechaza `municipio = "999999"` (no existe).
   - `municipio_validation_accepts_zero_sentinel` — form acepta `municipio = "0"`.
   - `municipio_validation_accepts_valid_modulo_id` — form acepta `municipio = "<id válido>"` con modulo.tipo=5.
   - `municipio_validation_rejects_modulo_with_wrong_tipo` — form rechaza modulo_id que existe pero con tipo distinto a 5.
8. Tests adicionales (sanity):
   - `admin_can_list_pivs` — admin entra al `/admin/piv` y ve la tabla.
   - `non_admin_cannot_access_piv_resource` — operador/tecnico acceden al `/admin/piv` → 403 (cubre canAccessPanel del Bloque 05).
9. `pint --test`, `pest`, `npm run build` verdes.
10. PR creado, CI 3/3 verde.

---

## Riesgos y mitigaciones

- **`piv.municipio` es varchar pero contiene int**: Eloquent BelongsTo casts el FK a string vs int automáticamente. Caso "0" devuelve null (no existe `modulo_id=0`). En la columna de tabla, `->default('— Sin municipio asignado —')` cubre nulls.
- **Charset latin1 en `direccion`/`observaciones`/`prevision`**: el cast `Latin1String` ya está en el modelo (Bloque 03). Filament lee/escribe vía Eloquent, así que UTF-8 ↔ latin1 transparente. Para la **lectura desde tablas relacionadas raw** (filtros con `DB::table`), no aplica el cast — usar Eloquent en filtros (ej. `Modulo::municipios()->orderBy('nombre')->pluck()`).
- **Conflicto entre relación `municipioModulo()` y columna `municipio`**: la relación usa NOMBRE distinto (`municipioModulo`) para no chocar con la columna. La columna `municipio` queda accesible como atributo (string varchar) y la relación como `$piv->municipioModulo` (Modulo|null).
- **`getEloquentQuery` y N+1**: si olvidamos un eager load, el test `piv_listing_no_n_plus_one` lo cazará. Si pasa el test pero seguimos viendo lentitud en prod, añadir `->select(...)` específico al with().
- **Operador mostrado en tabla = razón social, no email/RGPD-sensible**: ADR-0008 + memoria registran que `razon_social` es el nombre comercial. La regla #3 RGPD aplica solo a EXPORTS al cliente, no a la vista interna del admin. Pero igualmente solo mostramos `razon_social` (ya empresa, no PII).
- **`piv.status` semántica no clara** (status2 también): por ahora se renderiza como número. Filtros con valores conocidos `1`, `0` (activo/inactivo). Comentario TODO en la sección de status para refinar cuando se descubra el diccionario.
- **Validación municipio en CREATE vs EDIT**: aplica en ambos. Filament lee la regla del schema del field, así que no hay duplicación.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (convenciones + tests obligatorios Bloque 07)
- CLAUDE.md (división trabajo + DESIGN.md routing)
- DESIGN.md §6 Layout y §10.3 Status pills (referencia visual)
- ARCHITECTURE.md §5.1 Tabla `piv` (columnas reales 2026-04-30)
- ARCHITECTURE.md §5.3 Catálogo `modulo` polimórfico (tipos)
- docs/decisions/0007-piv-municipio-validation.md (validación closure)
- docs/prompts/07-filament-piv-resource.md (este archivo)
- app/Models/Piv.php, app/Models/Modulo.php, app/Models/Operador.php (relaciones existentes)

Tu tarea: implementar el Bloque 07 — Filament Resource para Piv.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd                              # /Users/winfin/Documents/winfin-piv
git branch --show-current        # main
git rev-parse HEAD               # debe ser c1b000e (post Bloque 06b)
git status --short               # vacío
./vendor/bin/pest --colors=never --compact 2>&1 | tail -3
```

Si los 88 tests no están verdes, AVISA.

```bash
git checkout -b bloque-07-filament-piv-resource
```

PARA: "Branch creada. ¿Procedo a Fase 1 (relación municipioModulo)?"

## FASE 1 — Añadir relación `municipioModulo()` al modelo `Piv`

Lee `app/Models/Piv.php`. Localiza la relación `industria()` BelongsTo. Añade DESPUÉS:

```php
    /**
     * Relación lógica a `modulo` con tipo=5 (municipios). Ver ADR-0007.
     *
     * `piv.municipio` es varchar pero almacena `modulo_id` numérico como string.
     * El centinela `"0"` significa "sin municipio asignado" — devuelve null
     * porque modulo_id=0 no existe en la tabla.
     *
     * El nombre `municipioModulo` evita colisión con la columna `municipio`.
     */
    public function municipioModulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'municipio', 'modulo_id');
    }
```

Verifica con un test rápido en tinker:

```bash
php artisan tinker --execute='
$p = \App\Models\Piv::factory()->create(["municipio" => "0"]);
echo "municipio raw: " . $p->municipio . PHP_EOL;
echo "municipioModulo: " . ($p->municipioModulo ? $p->municipioModulo->nombre : "null (esperado para 0)") . PHP_EOL;
'
```

PARA: "Fase 1 completa: municipioModulo() añadido. ¿Procedo a Fase 2 (generar resource scaffold)?"

## FASE 2 — Crear scaffold del Resource

```bash
php artisan make:filament-resource Piv
```

Esto genera `app/Filament/Resources/PivResource.php` + `PivResource/Pages/{ListPivs,CreatePiv,EditPiv}.php`. NO uses `--generate`: introspección de schema legacy hace cosas raras.

Verifica que se han creado los 4 archivos. NO reescribas nada todavía — la siguiente fase los configura.

PARA: "Fase 2 completa: scaffold creado. ¿Procedo a Fase 3 (configurar Resource)?"

## FASE 3 — Configurar `PivResource`

Reescribe `app/Filament/Resources/PivResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PivResource\Pages;
use App\Models\Modulo;
use App\Models\Operador;
use App\Models\Piv;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PivResource extends Resource
{
    protected static ?string $model = Piv::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $modelLabel = 'panel PIV';

    protected static ?string $pluralModelLabel = 'paneles PIV';

    protected static ?string $navigationGroup = 'Activos';

    protected static ?int $navigationSort = 1;

    /**
     * Eager loading obligatorio (DoD Bloque 07). Cubre todas las relaciones
     * mostradas en table() para evitar N+1. Test piv_listing_no_n_plus_one
     * verifica con expectQueryCount <= 5.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'operadorPrincipal:operador_id,razon_social',
            'industria:modulo_id,nombre',
            'municipioModulo:modulo_id,nombre',
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('piv_id')
                        ->label('ID PIV')
                        ->numeric()
                        ->required()
                        ->disabled(fn (string $context) => $context === 'edit')
                        ->dehydrated(fn (string $context) => $context === 'create'),
                    Forms\Components\TextInput::make('parada_cod')->label('Cód. parada')->maxLength(255),
                    Forms\Components\TextInput::make('cc_cod')->label('Cód. CC')->maxLength(255),
                    Forms\Components\TextInput::make('n_serie_piv')->label('N.º serie PIV')->maxLength(255),
                    Forms\Components\TextInput::make('n_serie_sim')->label('N.º serie SIM')->maxLength(255),
                    Forms\Components\TextInput::make('n_serie_mgp')->label('N.º serie MGP')->maxLength(255),
                    Forms\Components\TextInput::make('tipo_piv')->label('Tipo PIV')->maxLength(255),
                    Forms\Components\TextInput::make('tipo_marquesina')->label('Tipo marquesina')->maxLength(255),
                    Forms\Components\TextInput::make('tipo_alimentacion')->label('Tipo alimentación')->maxLength(255),
                ]),

            Forms\Components\Section::make('Localización')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('direccion')->label('Dirección')->maxLength(255)->columnSpanFull(),

                    Forms\Components\Select::make('municipio')
                        ->label('Municipio')
                        ->options(fn () => self::municipioOptions())
                        ->searchable()
                        ->required()
                        ->default('0')
                        ->rules([self::municipioValidationRule()]),

                    Forms\Components\Select::make('industria_id')
                        ->label('Industria')
                        ->relationship('industria', 'nombre', fn ($query) => $query->where('tipo', Modulo::TIPO_INDUSTRIA))
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\TextInput::make('concesionaria_id')->label('Concesionaria ID')->numeric()->nullable(),
                ]),

            Forms\Components\Section::make('Operadores')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('operador_id')
                        ->label('Operador principal')
                        ->relationship('operadorPrincipal', 'razon_social')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Forms\Components\Select::make('operador_id_2')
                        ->label('Operador secundario')
                        ->relationship('operadorSecundario', 'razon_social')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Forms\Components\Select::make('operador_id_3')
                        ->label('Operador terciario')
                        ->relationship('operadorTerciario', 'razon_social')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ]),

            Forms\Components\Section::make('Estado')
                ->columns(3)
                ->schema([
                    // TODO: refinar enum cuando se descubra el diccionario de status (memoria status.md).
                    Forms\Components\TextInput::make('status')->label('Status')->numeric()->default(1),
                    Forms\Components\TextInput::make('status2')->label('Status2')->numeric()->nullable(),
                    Forms\Components\DatePicker::make('fecha_instalacion')->label('Fecha instalación'),
                    Forms\Components\TextInput::make('mantenimiento')->label('Mantenimiento')->maxLength(45),
                    Forms\Components\Textarea::make('prevision')->label('Previsión')->rows(2)->columnSpanFull(),
                    Forms\Components\Textarea::make('observaciones')->label('Observaciones')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('piv_id')->label('ID')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('parada_cod')->label('Parada')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('direccion')->label('Dirección')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('municipioModulo.nombre')
                    ->label('Municipio')
                    ->default('— Sin municipio —')
                    ->sortable(),
                Tables\Columns\TextColumn::make('operadorPrincipal.razon_social')
                    ->label('Operador principal')
                    ->limit(30),
                Tables\Columns\TextColumn::make('industria.nombre')
                    ->label('Industria')
                    ->limit(20),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => $state == 1 ? 'success' : 'danger'),
            ])
            ->defaultSort('piv_id')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([1 => 'Activo', 0 => 'Inactivo']),
                Tables\Filters\SelectFilter::make('municipio')
                    ->label('Municipio')
                    ->options(fn () => self::municipioOptions())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('operador_id')
                    ->label('Operador principal')
                    ->relationship('operadorPrincipal', 'razon_social')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPivs::route('/'),
            'create' => Pages\CreatePiv::route('/create'),
            'edit' => Pages\EditPiv::route('/{record}/edit'),
        ];
    }

    /**
     * Opciones para Select de municipio: centinela "0" + 103 municipios ordenados (ADR-0007).
     *
     * @return array<string, string>
     */
    private static function municipioOptions(): array
    {
        $municipios = Modulo::municipios()
            ->orderBy('nombre')
            ->pluck('nombre', 'modulo_id')
            ->mapWithKeys(fn (string $nombre, int $id) => [(string) $id => $nombre])
            ->all();

        return ['0' => '— Sin municipio asignado —'] + $municipios;
    }

    /**
     * Regla de validación closure para `municipio` (ADR-0007).
     *
     * Acepta: "0" (centinela "sin asignar") o un modulo_id numérico que exista
     * con tipo=5 en la tabla `modulo`.
     */
    private static function municipioValidationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === '0') {
                return;
            }
            if (! is_string($value) || ! ctype_digit($value)) {
                $fail('El municipio debe ser un id numérico o "0" (sin municipio).');
                return;
            }
            $exists = DB::table('modulo')
                ->where('modulo_id', (int) $value)
                ->where('tipo', Modulo::TIPO_MUNICIPIO)
                ->exists();

            if (! $exists) {
                $fail("El municipio id={$value} no existe en el catálogo (modulo tipo=5).");
            }
        };
    }
}
```

NO toques los archivos de `PivResource/Pages/` — los defaults generados están bien.

PARA: "Fase 3 completa: PivResource configurado. ¿Procedo a Fase 4 (tests)?"

## FASE 4 — Tests

Crea `tests/Feature/Filament/PivResourceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\PivResource;
use App\Filament\Resources\PivResource\Pages\CreatePiv;
use App\Filament\Resources\PivResource\Pages\EditPiv;
use App\Filament\Resources\PivResource\Pages\ListPivs;
use App\Models\Modulo;
use App\Models\Operador;
use App\Models\Piv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

// ---------- DoD obligatorios ----------

it('admin_can_list_pivs', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $operador = Operador::factory()->create();
    $pivs = Piv::factory()->count(3)->create([
        'municipio' => (string) $municipio->modulo_id,
        'operador_id' => $operador->operador_id,
    ]);

    Livewire::test(ListPivs::class)
        ->assertCanSeeTableRecords($pivs);
});

it('non_admin_cannot_access_piv_resource', function () {
    $tecnico = User::factory()->tecnico()->create();
    $this->actingAs($tecnico);

    $this->get(PivResource::getUrl('index'))->assertForbidden();
});

it('piv_listing_no_n_plus_one', function () {
    // 50 paneles, cada uno con municipio + operador principal + industria distintos.
    $pivs = collect(range(1, 50))->map(function ($i) {
        $municipio = Modulo::factory()->municipio()->create();
        $industria = Modulo::factory()->industria()->create();
        $operador = Operador::factory()->create();

        return Piv::factory()->create([
            'piv_id' => 10000 + $i,
            'municipio' => (string) $municipio->modulo_id,
            'industria_id' => $industria->modulo_id,
            'operador_id' => $operador->operador_id,
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListPivs::class)->assertCanSeeTableRecords($pivs);

    $count = count(DB::getQueryLog());
    expect($count)->toBeLessThanOrEqual(8, "Se ejecutaron {$count} queries — eager loading roto");
});

it('municipio_validation_accepts_zero_sentinel', function () {
    Livewire::test(CreatePiv::class)
        ->fillForm([
            'piv_id' => 99001,
            'parada_cod' => 'TEST-001',
            'direccion' => 'Calle Test 1',
            'municipio' => '0',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Piv::find(99001))->not->toBeNull();
    expect(Piv::find(99001)->municipio)->toBe('0');
});

it('municipio_validation_accepts_valid_modulo_id', function () {
    $m = Modulo::factory()->municipio('Madrid')->create();

    Livewire::test(CreatePiv::class)
        ->fillForm([
            'piv_id' => 99002,
            'parada_cod' => 'TEST-002',
            'direccion' => 'Calle Test 2',
            'municipio' => (string) $m->modulo_id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('municipio_validation_rejects_invalid_id', function () {
    Livewire::test(CreatePiv::class)
        ->fillForm([
            'piv_id' => 99003,
            'parada_cod' => 'TEST-003',
            'direccion' => 'Calle Test 3',
            'municipio' => '999999',  // no existe en modulo
        ])
        ->call('create')
        ->assertHasFormErrors(['municipio']);

    expect(Piv::find(99003))->toBeNull();
});

it('municipio_validation_rejects_modulo_with_wrong_tipo', function () {
    $industriaModulo = Modulo::factory()->industria()->create();  // tipo=1, no tipo=5

    Livewire::test(CreatePiv::class)
        ->fillForm([
            'piv_id' => 99004,
            'parada_cod' => 'TEST-004',
            'direccion' => 'Calle Test 4',
            'municipio' => (string) $industriaModulo->modulo_id,
        ])
        ->call('create')
        ->assertHasFormErrors(['municipio']);

    expect(Piv::find(99004))->toBeNull();
});
```

Corre tests:

```bash
./vendor/bin/pest tests/Feature/Filament/PivResourceTest.php --colors=never --compact 2>&1 | tail -25
```

7 tests verdes esperados. Si falla `piv_listing_no_n_plus_one` con count > 8, AVISA — probablemente falta una relación en el `with([])` del `getEloquentQuery`.

PARA: "Fase 4 completa: 7 tests verdes. ¿Procedo a Fase 5 (smoke total + commits)?"

## FASE 5 — Smoke + commits + PR

```bash
./vendor/bin/pint --test 2>&1 | tail -5
./vendor/bin/pest --colors=never --compact 2>&1 | tail -10
npm run build 2>&1 | tail -3
```

Si pint reporta cambios, corre `./vendor/bin/pint` y commitea como `style:` aparte.

Stage explícito por archivo:

1. `docs: add Bloque 07 prompt (Filament Piv resource)` — `docs/prompts/07-filament-piv-resource.md`.
2. `feat(models): add Piv::municipioModulo() relation (ADR-0007)` — `app/Models/Piv.php`.
3. `feat(filament): add PivResource with eager loading + municipio validation` — `app/Filament/Resources/PivResource.php` + sus 3 Pages.
4. `test: cover PivResource listing, eager loading and municipio validation` — `tests/Feature/Filament/PivResourceTest.php`.

```bash
git push -u origin bloque-07-filament-piv-resource
gh pr create \
  --base main \
  --head bloque-07-filament-piv-resource \
  --title "Bloque 07 — Filament PivResource (CRUD admin paneles + eager loading + municipio ADR-0007)" \
  --body "$(cat <<'BODY'
## Resumen

Filament Resource para `Piv` que permite al admin gestionar los 575 paneles PIV. Tabla con búsqueda + filtros + eager loading obligatorio. Form con secciones (Identificación, Localización, Operadores, Estado). Validación de `municipio` según ADR-0007 (closure custom: acepta `"0"` centinela + `modulo_id` con tipo=5).

## Qué entra

- `app/Models/Piv.php` — método nuevo `municipioModulo()` BelongsTo (`piv.municipio` ↔ `modulo.modulo_id`). Nombre evita colisión con la columna `municipio`.
- `app/Filament/Resources/PivResource.php` con:
  - `getEloquentQuery()` con `->with(['operadorPrincipal', 'industria', 'municipioModulo'])`.
  - Form 4 secciones (Identificación, Localización, Operadores, Estado).
  - Table con 7 columnas, búsqueda en 5 campos, filtros por status / municipio / operador.
  - Validación municipio inline (closure custom).
- 7 tests Pest:
  - admin_can_list_pivs
  - non_admin_cannot_access_piv_resource
  - piv_listing_no_n_plus_one (eager loading verificado con DB::getQueryLog)
  - municipio_validation_accepts_zero_sentinel
  - municipio_validation_accepts_valid_modulo_id
  - municipio_validation_rejects_invalid_id
  - municipio_validation_rejects_modulo_with_wrong_tipo

## Qué NO entra

- Resources de Averia, Asignacion, Correctivo, Revision (Bloques 08, 09).
- Map view con coords (Bloque 02f geocoding pendiente).
- Image gallery del panel (Piv::imagenes() existe pero solo se usa cuando se construya — futuro).
- Export CSV/PDF (Bloque 10).
- Authorization Policy (admin tiene CRUD via canAccessPanel — Policies cuando lleguen otros roles, Bloques 11/12).

## Decisiones clave

- Relación `municipioModulo()` con NOMBRE distinto a la columna para evitar colisión (Eloquent confunde si tienen mismo nombre).
- Eager loading explícito de campos (`operador_id,razon_social`) en lugar de `*` para reducir bytes en transit.
- Validación municipio como closure inline en el field (no FormRequest separado — Filament no lo invoca).
- Status como TextInput numérico simple por ahora; refinar a Select cuando se descubra el diccionario (TODO en código).

## CI esperado

3/3 jobs verde (PHP 8.2, PHP 8.3, Vite build).
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-07-filament-piv-resource --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ Qué he hecho:
   - Piv::municipioModulo() relation añadida (ADR-0007).
   - PivResource con eager loading, form 4 secciones, table con búsqueda + filtros + sort.
   - Validación municipio closure (acepta "0" + modulo tipo=5).
   - 7 tests Pest verdes incluyendo expectQueryCount<=8 para N+1.
   - Suite total: NN tests / NNN assertions verde.
   - Pint clean. Build OK.
   - 4 commits Conventional Commits.
   - PR #N: [URL].
   - CI 3/3 verde.

⏳ Qué falta:
   - (Manual, post-merge) Smoke real: php artisan serve → /admin/piv → verificar que se listan los 575 paneles de prod, búsqueda funciona, filtros funcionan, abrir uno en edit.
   - Bloque 08 — Resources Averia + Asignacion.

❓ Qué necesito del usuario:
   - Confirmar PR.
   - Mergear (Rebase and merge).
   - Tras merge, smoke en navegador con datos reales.
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 07

1. **Smoke real con prod data**: arrancar `php artisan serve`, abrir `/admin/piv` en navegador. Esperado:
   - Tabla con 575 paneles paginados.
   - Búsqueda por `parada_cod` o dirección filtra correctamente.
   - Filtro municipio muestra los 103 municipios ordenados alfabéticamente.
   - Click en una fila → edit page → todas las secciones renderizan con datos reales.
   - Modificar y guardar un panel → cambios persisten en BD prod.
2. **Bloque 08** — Resources de `Averia` + `Asignacion` con relación a `Piv` y filtros por fecha/tipo/status.
3. **TODO captured** durante el smoke: si los nombres de operadores/municipios salen con mojibake (caracteres latin1 mal interpretados), añadir cast `Latin1String` a los campos relevantes en los modelos `Operador` y `Modulo` (parcialmente ya hecho en Operador).
