# Bloque 08 — Resources Filament `Averia` + `Asignacion`

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~90-110 min.

---

## Objetivo

Filament Resources para `Averia` y `Asignacion`: el corazón del flujo operativo del CMMS. Admin gestiona incidencias (averías) y la asignación técnico↔avería (con tipo=1 correctivo o tipo=2 revisión mensual rutinaria).

**Volumetría:**
- `averia`: 66.485 filas
- `asignacion`: 66.416 filas (tipo=1 = 20.313 correctivos, tipo=2 = 46.097 revisiones, tipo=0 = 6 raros legacy)

Ambos siguen el patrón Airtable-Mode (Bloque 07d): tabla densa, slideOver inspector, group-by, eager loading explícito.

**Decisión visual crítica — regla #11 (DESIGN.md §10.1)**: separación TAJANTE entre `tipo=1` (avería real) y `tipo=2` (revisión mensual). En cualquier UI que liste asignaciones, los dos casos se distinguen por color + icon + label, **nunca por valor numérico crudo**.

## Lo que entra

1. **`AveriaResource`** — admin lista incidencias de los 575 paneles (484 activos + 91 archivados — el listing los muestra todos porque las averías históricas siguen siendo relevantes incluso para paneles archivados).
2. **`AsignacionResource`** — admin lista asignaciones técnico↔avería con visual diferenciado correctivo/revisión.
3. **Eager loading explícito** en ambas: averías → piv + tecnico + operador; asignaciones → averia.piv + tecnico.
4. **Pagination obligatoria** (default 25, opciones 25/50/100). 66k filas sin paginar es DoS auto-infligido.
5. **Status filter + tipo filter** + group-by toggle.
6. **Stripe lateral cromático** en filas de asignación (rojo `--error` para correctivo, teal `--success` para revisión).
7. **Cierre de asignación** (form con diagnóstico/recambios/foto) **NO entra aquí** — es Bloque 09.

## Lo que NO entra

- Resource de `Correctivo` o `Revision` (cerrados de asignación) — Bloque 09.
- Action "cerrar asignación" desde el listing — Bloque 09.
- Dashboard widgets / KPIs — Bloque 10.
- PWA técnico (donde el técnico cierra desde móvil) — Bloque 11.
- PWA operador (donde reporta avería desde móvil) — Bloque 12.

## Definition of Done

1. `app/Filament/Resources/AveriaResource.php` + `AveriaResource/Pages/{ListAverias,CreateAveria,EditAveria}.php`.
2. `app/Filament/Resources/AsignacionResource.php` + `AsignacionResource/Pages/{ListAsignaciones,CreateAsignacion,EditAsignacion}.php`.
3. **AveriaResource**:
   - `getEloquentQuery()` con `with(['piv:piv_id,parada_cod,direccion,municipio', 'piv.municipioModulo:modulo_id,nombre', 'piv.operadorPrincipal:operador_id,razon_social', 'tecnico:tecnico_id,nombre_completo', 'operador:operador_id,razon_social', 'asignacion:asignacion_id,averia_id,tipo,status'])`.
   - Tabla con columnas: `averia_id` (mono), `fecha`, parada_cod (via piv), municipio (via piv.municipioModulo), operador (via operador.razon_social), tecnico (via tecnico.nombre_completo), tipo asignación (badge coloreado por tipo), status, notas (truncated 60).
   - Filtros: `status`, `tecnico_id`, fecha range, search por notas/averia_id/parada_cod.
   - Default sort: `fecha` desc.
   - `->paginated([25, 50, 100])`, `->striped()`.
   - ViewAction slideOver con infolist completo (datos avería + panel relacionado + asignación si existe + notas full).
   - Form: piv_id Select relationship, operador_id Select, tecnico_id Select, notas Textarea, fecha DateTimePicker, status numérico.
4. **AsignacionResource**:
   - `getEloquentQuery()` con `with(['averia:averia_id,piv_id,operador_id,notas', 'averia.piv:piv_id,parada_cod,municipio', 'averia.piv.municipioModulo:modulo_id,nombre', 'tecnico:tecnico_id,nombre_completo'])`.
   - Tabla con columnas: `asignacion_id`, fecha, horario (`hora_inicial-hora_final`), **tipo con badge color** (rojo correctivo / teal revisión / gris tipo=0), tecnico, parada_cod (via averia.piv), municipio, status.
   - **Stripe lateral**: `recordClasses(fn ($r) => $r->tipo == 1 ? 'border-l-4 border-l-error' : ($r->tipo == 2 ? 'border-l-4 border-l-success' : 'border-l-4 border-l-gray-300'))`.
   - Filtros: `tipo` (Select Correctivo/Revisión), `status`, `tecnico_id`, fecha range.
   - **Default group por tipo**: dos grupos "Correctivos" / "Revisiones rutinarias" (omitir tipo=0 por default — toggle "Incluir tipo desconocido").
   - `->paginated([25, 50, 100])`, `->striped()`.
   - ViewAction slideOver con infolist (datos asignación + panel + avería relacionada + cierre si existe — esto último readonly hasta Bloque 09).
   - Form: averia_id Select (search by notas + averia_id), tecnico_id Select, fecha DatePicker, hora_inicial/final NumberInput 0-24, **tipo Select obligatorio "Correctivo" / "Revisión rutinaria"**, status numérico.
5. Tests Pest:
   - `admin_can_list_averias` (Livewire ListAverias)
   - `admin_can_list_asignaciones` (Livewire ListAsignaciones)
   - `non_admin_cannot_access_averia_resource` (403 vía canAccessPanel)
   - `non_admin_cannot_access_asignacion_resource`
   - `averia_listing_no_n_plus_one` — 50 averías, ≤10 queries.
   - `asignacion_listing_no_n_plus_one` — 50 asignaciones con relaciones a piv via averia, ≤10 queries.
   - `asignacion_tipo_filter_separates_correctivo_from_revision` — filter tipo=1 muestra solo correctivos.
   - `asignacion_default_group_by_tipo` — group "Correctivos" / "Revisiones".
6. `pint --test`, `pest`, `npm run build` verdes.
7. PR creado, CI 3/3 verde.
8. **Post-merge smoke real**: `/admin/averias` muestra 66k filas paginadas + filtros. `/admin/asignaciones` muestra agrupadas por tipo con stripe lateral diferenciador.

---

## Riesgos y mitigaciones

- **66k filas → paginación SIN excepciones**. Si admin desactiva pagination, el navegador muere. Mantener `->paginated()` siempre.
- **N+1 con `Asignacion → averia → piv`**: requiere `with('averia.piv')`. Sin esto, 50 asignaciones = 100+ queries. Test obligatorio.
- **`Asignacion::getPivAttribute()` accessor virtual**: depende de `averia.piv` cargado. Si Filament accede a `$record->piv` y `averia` no está eager-loaded, lazy-load y N+1. Forzar el eager loading.
- **`tipo=0` raros (6 filas)**: legacy data pre-cleanup. NO archivar en este bloque (no hay sistema de archivado para asignaciones — solo para piv via Bloque 07e). Mostrar tipo=0 como badge gris "Indefinido" para que admin pueda revisar manualmente.
- **`status` semántica no clara** (averia: 1, 2, 4; asignacion: 1, 2): Bloque 10 dashboard investigará el diccionario. Por ahora, mostrar valor crudo + filter Select con valores observados. Comentar TODO.
- **Latin1String en `averia.notas`**: ya configurado (Bloque 03). Cast funciona transparente.
- **Eager-loading de relación `piv` en averia**: el panel puede estar archivado (bloque 07e). NO filtrar por `notArchived` aquí — averías históricas de paneles archivados siguen siendo info legítima.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md
- CLAUDE.md
- DESIGN.md §10.1 "Separación tajante avería real / revisión mensual" (regla #11)
- ARCHITECTURE.md §5.1 (schema Averia, Asignacion verificado)
- ARCHITECTURE.md §7 Flujos principales
- docs/decisions/0004-revision-vs-averia-ux.md
- docs/prompts/08-filament-averia-asignacion.md (este archivo)
- app/Models/Averia.php, app/Models/Asignacion.php (modelos existentes con relaciones)
- app/Filament/Resources/PivResource.php (resource referencia para el patrón Airtable-Mode + slideOver)

Tu tarea: implementar Bloque 08 — Resources Filament para Averia y Asignacion con visual diferenciado correctivo/revisión.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd
git branch --show-current        # main
git rev-parse HEAD               # debe ser a51234d (post Bloque 07e)
git status --short               # vacío
./vendor/bin/pest --colors=never --compact 2>&1 | tail -3
```

110+ tests verdes esperados.

```bash
git checkout -b bloque-08-filament-averia-asignacion
```

PARA: "Branch creada. ¿Procedo a Fase 1 (AveriaResource scaffold)?"

## FASE 1 — Generar AveriaResource scaffold

```bash
php artisan make:filament-resource Averia
```

Esto crea:
- `app/Filament/Resources/AveriaResource.php`
- `app/Filament/Resources/AveriaResource/Pages/{ListAverias,CreateAveria,EditAveria}.php`

NO uses `--generate`. Vamos a configurar manualmente en Fase 2.

PARA: "Fase 1 completa: scaffold AveriaResource creado. ¿Procedo a Fase 2 (configurar)?"

## FASE 2 — Configurar AveriaResource

Reescribe `app/Filament/Resources/AveriaResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AveriaResource\Pages;
use App\Models\Averia;
use App\Models\Operador;
use App\Models\Piv;
use App\Models\Tecnico;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AveriaResource extends Resource
{
    protected static ?string $model = Averia::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $modelLabel = 'avería';

    protected static ?string $pluralModelLabel = 'averías';

    protected static ?string $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'piv:piv_id,parada_cod,direccion,municipio',
            'piv.municipioModulo:modulo_id,nombre',
            'piv.operadorPrincipal:operador_id,razon_social',
            'tecnico:tecnico_id,nombre_completo',
            'operador:operador_id,razon_social',
            'asignacion:asignacion_id,averia_id,tipo,status',
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('averia_id')
                        ->label('ID Avería')
                        ->numeric()
                        ->required()
                        ->disabled(fn (string $context) => $context === 'edit')
                        ->dehydrated(fn (string $context) => $context === 'create'),
                    Forms\Components\DateTimePicker::make('fecha')
                        ->label('Fecha y hora')
                        ->seconds(false),
                    Forms\Components\TextInput::make('status')
                        ->label('Status')
                        ->numeric()
                        ->default(1),
                ]),

            Forms\Components\Section::make('Panel y participantes')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('piv_id')
                        ->label('Panel PIV')
                        ->relationship('piv', 'parada_cod')
                        ->searchable(['piv_id', 'parada_cod', 'direccion'])
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn (Piv $r) => "#{$r->piv_id} · ".trim($r->parada_cod ?? '').' · '.($r->direccion ?? '—'))
                        ->required(),
                    Forms\Components\Select::make('operador_id')
                        ->label('Operador reporta')
                        ->relationship('operador', 'razon_social')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('tecnico_id')
                        ->label('Técnico asignado (inicial)')
                        ->relationship('tecnico', 'nombre_completo')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ]),

            Forms\Components\Section::make('Notas')
                ->schema([
                    Forms\Components\Textarea::make('notas')
                        ->label('Notas del operador')
                        ->rows(4)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('averia_id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 5, '0', STR_PAD_LEFT))
                    ->extraAttributes(['data-mono' => true])
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d M Y · H:i')
                    ->sortable()
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('piv.parada_cod')
                    ->label('Parada')
                    ->formatStateUsing(fn ($state) => mb_strtoupper(trim((string) $state)))
                    ->extraAttributes(['data-mono' => true])
                    ->searchable(),
                Tables\Columns\TextColumn::make('piv.municipioModulo.nombre')
                    ->label('Municipio')
                    ->default('—')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('operador.razon_social')
                    ->label('Operador')
                    ->limit(20)
                    ->color('gray'),
                Tables\Columns\TextColumn::make('tecnico.nombre_completo')
                    ->label('Técnico')
                    ->limit(20)
                    ->placeholder('—')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('asignacion.tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        1 => 'Correctivo',
                        2 => 'Revisión',
                        default => '—',
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        1 => 'danger',
                        2 => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('notas')
                    ->label('Notas')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([1 => 'Abierta', 2 => 'Cerrada', 4 => 'Status 4']),
                Tables\Filters\SelectFilter::make('tecnico_id')
                    ->label('Técnico')
                    ->relationship('tecnico', 'nombre_completo')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('fecha_range')
                    ->form([
                        Forms\Components\DatePicker::make('desde'),
                        Forms\Components\DatePicker::make('hasta'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
                            ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('2xl')
                    ->infolist(fn (Infolist $i) => self::infolist($i)),
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Avería')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('averia_id')->label('ID')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('fecha')->dateTime('d M Y · H:i')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('asignacion.tipo')
                        ->label('Tipo')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ((int) $state) { 1 => 'Correctivo', 2 => 'Revisión', default => '—' })
                        ->color(fn ($state) => match ((int) $state) { 1 => 'danger', 2 => 'success', default => 'gray' }),
                ]),

            Infolists\Components\Section::make('Panel afectado')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('piv.parada_cod')->label('Parada')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('piv.direccion')->label('Dirección'),
                    Infolists\Components\TextEntry::make('piv.municipioModulo.nombre')->label('Municipio')->placeholder('—'),
                    Infolists\Components\TextEntry::make('piv.operadorPrincipal.razon_social')->label('Operador panel')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Participantes')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('operador.razon_social')->label('Operador reporta')->placeholder('—'),
                    Infolists\Components\TextEntry::make('tecnico.nombre_completo')->label('Técnico asignado')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->schema([
                    Infolists\Components\TextEntry::make('notas')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAverias::route('/'),
            'create' => Pages\CreateAveria::route('/create'),
            'edit' => Pages\EditAveria::route('/{record}/edit'),
        ];
    }
}
```

PARA: "Fase 2 completa: AveriaResource configurado. ¿Procedo a Fase 3 (AsignacionResource scaffold)?"

## FASE 3 — Generar AsignacionResource scaffold

```bash
php artisan make:filament-resource Asignacion
```

PARA: "Fase 3 completa: scaffold AsignacionResource. ¿Procedo a Fase 4 (configurar con stripe lateral)?"

## FASE 4 — Configurar AsignacionResource

Reescribe `app/Filament/Resources/AsignacionResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AsignacionResource\Pages;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Tecnico;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AsignacionResource extends Resource
{
    protected static ?string $model = Asignacion::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $modelLabel = 'asignación';

    protected static ?string $pluralModelLabel = 'asignaciones';

    protected static ?string $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'averia:averia_id,piv_id,operador_id,notas,fecha,status',
            'averia.piv:piv_id,parada_cod,municipio',
            'averia.piv.municipioModulo:modulo_id,nombre',
            'averia.operador:operador_id,razon_social',
            'tecnico:tecnico_id,nombre_completo',
            'correctivo:correctivo_id,asignacion_id,estado_final',
            'revision:revision_id,asignacion_id',
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Asignación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('asignacion_id')
                        ->label('ID')
                        ->numeric()
                        ->required()
                        ->disabled(fn (string $context) => $context === 'edit')
                        ->dehydrated(fn (string $context) => $context === 'create'),
                    Forms\Components\DatePicker::make('fecha')->label('Fecha'),
                    Forms\Components\Select::make('tipo')
                        ->label('Tipo')
                        ->options([
                            1 => 'Correctivo (avería real)',
                            2 => 'Revisión rutinaria',
                        ])
                        ->required(),
                    Forms\Components\TextInput::make('hora_inicial')
                        ->label('Hora inicio')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(24)
                        ->placeholder('Ej. 8'),
                    Forms\Components\TextInput::make('hora_final')
                        ->label('Hora fin')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(24)
                        ->placeholder('Ej. 10'),
                    Forms\Components\TextInput::make('status')->numeric()->default(1),
                ]),

            Forms\Components\Section::make('Avería relacionada')
                ->schema([
                    Forms\Components\Select::make('averia_id')
                        ->label('Avería (toda asignación requiere una — incluso revisiones rutinarias usan avería stub)')
                        ->relationship('averia', 'averia_id')
                        ->searchable(['averia_id', 'notas'])
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn (Averia $r) => "#{$r->averia_id} · ".substr($r->notas ?? '—', 0, 60))
                        ->required(),
                    Forms\Components\Select::make('tecnico_id')
                        ->label('Técnico')
                        ->relationship('tecnico', 'nombre_completo')
                        ->searchable()
                        ->preload(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordClasses(fn (Asignacion $record) => match ((int) $record->tipo) {
                1 => 'border-l-4 border-l-error-500',
                2 => 'border-l-4 border-l-success-500',
                default => 'border-l-4 border-l-gray-300',
            })
            ->columns([
                Tables\Columns\TextColumn::make('asignacion_id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 5, '0', STR_PAD_LEFT))
                    ->extraAttributes(['data-mono' => true])
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d M Y')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('horario')
                    ->label('Horario')
                    ->getStateUsing(fn (Asignacion $r) => $r->hora_inicial && $r->hora_final ? sprintf('%02d–%02d h', $r->hora_inicial, $r->hora_final) : '—')
                    ->extraAttributes(['data-mono' => true])
                    ->color('gray'),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        1 => 'Correctivo',
                        2 => 'Revisión rutinaria',
                        default => 'Indefinido',
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        1 => 'danger',
                        2 => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('tecnico.nombre_completo')
                    ->label('Técnico')
                    ->limit(25)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('averia.piv.parada_cod')
                    ->label('Parada')
                    ->formatStateUsing(fn ($state) => mb_strtoupper(trim((string) $state)))
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('averia.piv.municipioModulo.nombre')
                    ->label('Municipio')
                    ->default('—')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->extraAttributes(['data-mono' => true]),
            ])
            ->defaultSort('fecha', 'desc')
            ->groups([
                Tables\Grouping\Group::make('tipo')
                    ->label('Tipo')
                    ->getTitleFromRecordUsing(fn (Asignacion $r) => match ((int) $r->tipo) {
                        1 => 'Correctivos',
                        2 => 'Revisiones rutinarias',
                        default => 'Sin tipo definido',
                    }),
            ])
            ->defaultGroup('tipo')
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->options([
                        1 => 'Correctivo (avería real)',
                        2 => 'Revisión rutinaria',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([1 => 'Abierta', 2 => 'Cerrada']),
                Tables\Filters\SelectFilter::make('tecnico_id')
                    ->label('Técnico')
                    ->relationship('tecnico', 'nombre_completo')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('fecha_range')
                    ->form([
                        Forms\Components\DatePicker::make('desde'),
                        Forms\Components\DatePicker::make('hasta'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
                            ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('2xl')
                    ->infolist(fn (Infolist $i) => self::infolist($i)),
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Asignación')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('asignacion_id')->label('ID')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('fecha')->date('d M Y'),
                    Infolists\Components\TextEntry::make('tipo')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ((int) $state) { 1 => 'Correctivo', 2 => 'Revisión rutinaria', default => 'Indefinido' })
                        ->color(fn ($state) => match ((int) $state) { 1 => 'danger', 2 => 'success', default => 'gray' }),
                    Infolists\Components\TextEntry::make('horario')
                        ->getStateUsing(fn (Asignacion $r) => $r->hora_inicial && $r->hora_final ? sprintf('%02d–%02d h', $r->hora_inicial, $r->hora_final) : '—'),
                    Infolists\Components\TextEntry::make('tecnico.nombre_completo')->label('Técnico')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')->badge(),
                ]),

            Infolists\Components\Section::make('Avería origen')
                ->schema([
                    Infolists\Components\TextEntry::make('averia.averia_id')->label('Avería')->prefix('#')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('averia.fecha')->dateTime('d M Y · H:i'),
                    Infolists\Components\TextEntry::make('averia.notas')->label('Notas')->columnSpanFull()->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Panel afectado')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('averia.piv.parada_cod')->label('Parada')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('averia.piv.municipioModulo.nombre')->label('Municipio')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Cierre')
                ->description('Form de cierre llegará en Bloque 09 — aquí solo readonly de lo existente')
                ->schema([
                    Infolists\Components\TextEntry::make('correctivo.estado_final')->label('Estado final correctivo')->placeholder('—'),
                    Infolists\Components\TextEntry::make('revision.id')->label('Revisión cerrada')->formatStateUsing(fn ($state) => $state ? 'Sí (id #'.$state.')' : 'No')->placeholder('No'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsignaciones::route('/'),
            'create' => Pages\CreateAsignacion::route('/create'),
            'edit' => Pages\EditAsignacion::route('/{record}/edit'),
        ];
    }
}
```

NOTA: El `getStateUsing` para `horario` en column + infolist depende de que `hora_inicial` y `hora_final` estén accesibles (cast a integer en modelo Asignacion ya hecho). Verificar.

PARA: "Fase 4 completa: AsignacionResource configurado con stripe lateral por tipo. ¿Procedo a Fase 5 (tests)?"

## FASE 5 — Tests

Crea `tests/Feature/Filament/AveriaResourceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\AveriaResource\Pages\ListAverias;
use App\Models\Averia;
use App\Models\Modulo;
use App\Models\Operador;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin_can_list_averias', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $piv = Piv::factory()->create(['piv_id' => 99100, 'municipio' => (string) $municipio->modulo_id]);
    $averia = Averia::factory()->create(['averia_id' => 99200, 'piv_id' => 99100]);

    Livewire::test(ListAverias::class)
        ->assertCanSeeTableRecords([$averia]);
});

it('non_admin_cannot_access_averia_resource', function () {
    $tecnico = User::factory()->tecnico()->create();
    $this->actingAs($tecnico);
    $this->get(\App\Filament\Resources\AveriaResource::getUrl('index'))->assertForbidden();
});

it('averia_listing_no_n_plus_one', function () {
    $municipio = Modulo::factory()->municipio()->create();
    $operador = Operador::factory()->create();
    $tecnico = Tecnico::factory()->create();

    collect(range(1, 50))->each(function ($i) use ($municipio, $operador, $tecnico) {
        $pivId = 50000 + $i;
        Piv::factory()->create(['piv_id' => $pivId, 'municipio' => (string) $municipio->modulo_id, 'operador_id' => $operador->operador_id]);
        Averia::factory()->create([
            'averia_id' => 50000 + $i,
            'piv_id' => $pivId,
            'operador_id' => $operador->operador_id,
            'tecnico_id' => $tecnico->tecnico_id,
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListAverias::class)->assertSuccessful();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12, 'Eager loading roto: '.count(DB::getQueryLog()).' queries');
});
```

Crea `tests/Feature/Filament/AsignacionResourceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Modulo;
use App\Models\Operador;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin_can_list_asignaciones', function () {
    $piv = Piv::factory()->create(['piv_id' => 99300]);
    $averia = Averia::factory()->create(['averia_id' => 99300, 'piv_id' => 99300]);
    $asig = Asignacion::factory()->create(['asignacion_id' => 99300, 'averia_id' => 99300, 'tipo' => 1]);

    Livewire::test(ListAsignaciones::class)
        ->assertCanSeeTableRecords([$asig]);
});

it('non_admin_cannot_access_asignacion_resource', function () {
    $tecnico = User::factory()->tecnico()->create();
    $this->actingAs($tecnico);
    $this->get(\App\Filament\Resources\AsignacionResource::getUrl('index'))->assertForbidden();
});

it('asignacion_listing_no_n_plus_one', function () {
    $municipio = Modulo::factory()->municipio()->create();
    $operador = Operador::factory()->create();
    $tecnico = Tecnico::factory()->create();

    collect(range(1, 50))->each(function ($i) use ($municipio, $operador, $tecnico) {
        $pivId = 60000 + $i;
        Piv::factory()->create(['piv_id' => $pivId, 'municipio' => (string) $municipio->modulo_id]);
        Averia::factory()->create(['averia_id' => 60000 + $i, 'piv_id' => $pivId, 'operador_id' => $operador->operador_id]);
        Asignacion::factory()->create([
            'asignacion_id' => 60000 + $i,
            'averia_id' => 60000 + $i,
            'tecnico_id' => $tecnico->tecnico_id,
            'tipo' => $i % 2 + 1,  // alternar 1/2
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListAsignaciones::class)->assertSuccessful();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(15, 'Eager loading roto: '.count(DB::getQueryLog()).' queries');
});

it('asignacion_tipo_filter_separates_correctivo_from_revision', function () {
    $piv = Piv::factory()->create(['piv_id' => 99400]);
    $av = Averia::factory()->create(['averia_id' => 99400, 'piv_id' => 99400]);
    $correctivo = Asignacion::factory()->create(['asignacion_id' => 99401, 'averia_id' => 99400, 'tipo' => 1]);
    $revision = Asignacion::factory()->create(['asignacion_id' => 99402, 'averia_id' => 99400, 'tipo' => 2]);

    Livewire::test(ListAsignaciones::class)
        ->filterTable('tipo', 1)
        ->assertCanSeeTableRecords([$correctivo])
        ->assertCanNotSeeTableRecords([$revision]);
});
```

Corre tests:
```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -15
```

Suite total esperada: 110 + 7 = 117. Si rompe alguno por relación faltante o eager-load missing, AVISA.

PARA: "Fase 5 completa: 7 tests nuevos verdes. ¿Procedo a Fase 6 (pint + commits + PR)?"

## FASE 6 — Pint + smoke + commits + PR

```bash
./vendor/bin/pint --test 2>&1 | tail -3
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
npm run build 2>&1 | tail -3
```

Stage explícito:
1. `docs: add Bloque 08 prompt (Filament Averia + Asignacion resources)` — `docs/prompts/08-filament-averia-asignacion.md`.
2. `feat(filament): add AveriaResource with eager loading + filters + slideOver` — `app/Filament/Resources/AveriaResource.php` + sus 3 Pages.
3. `feat(filament): add AsignacionResource with tipo stripe + group-by + slideOver` — `app/Filament/Resources/AsignacionResource.php` + sus 3 Pages.
4. `test: cover Averia + Asignacion resources listing + N+1 + tipo filter` — los 2 archivos de test.

Push + PR:
```bash
git push -u origin bloque-08-filament-averia-asignacion
gh pr create --base main --head bloque-08-filament-averia-asignacion \
  --title "Bloque 08 — Filament resources Averia + Asignacion (regla #11 stripe lateral)" \
  --body "$(cat <<'BODY'
## Resumen

Resources Filament para `Averia` y `Asignacion` (66k filas cada uno). Patrón Airtable-Mode (Bloque 07d): tabla densa, slideOver inspector, eager loading explícito, group-by tipo en asignación. Implementa visualmente la regla #11 (DESIGN.md §10.1): separación tajante correctivo/revisión vía stripe lateral cromático + badge color.

## Cambios

- AveriaResource con tabla (parada, municipio, operador, tecnico, tipo asociado, status, notas), filtros (status, tecnico, fecha range), slideOver con detalles + panel + participantes + notas full.
- AsignacionResource con stripe lateral por tipo (rojo correctivo / teal revisión / gris indefinido), group-by tipo default ("Correctivos" / "Revisiones rutinarias"), tabla con horario formateado, slideOver con relación a avería + panel + cierre readonly.
- Eager loading explícito con select de campos en ambos.
- Pagination obligatoria (default 25, opciones 25/50/100) — 66k filas requieren.
- 7 tests Pest (listing admin, 403 non-admin, N+1 ≤12-15 queries, tipo filter separation).

## Decisiones

- `tipo=0` raro (6 filas legacy pre-cleanup) se muestra con badge gris "Indefinido" — admin las ve para revisar.
- Status crudo (1, 2, 4) sin diccionario aún — TODO Bloque 10 dashboard.
- Form Averia: piv + operador + tecnico Selects con relationship; notas Textarea max 500.
- Form Asignacion: averia_id Select con search por notas + averia_id; tipo Select obligatorio "Correctivo" / "Revisión rutinaria"; horario hora_inicial/hora_final NumberInput 0-24.

## Qué NO entra

- Cierre de asignación (form correctivo/revisión) — Bloque 09.
- Dashboard widgets — Bloque 10.

## CI esperado

3/3 jobs verde.
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-08-filament-averia-asignacion --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ Qué he hecho:
   - AveriaResource con eager loading explícito + filters + slideOver inspector.
   - AsignacionResource con stripe lateral cromático por tipo (regla #11) + group-by + slideOver.
   - Eager loading: averia → piv + tecnico + operador; asignacion → averia.piv + tecnico + correctivo + revision.
   - 7 tests Pest verdes (listing, 403, N+1, tipo filter).
   - Suite total verde.
   - Pint + build OK.
   - 4 commits.
   - PR #N: [URL].
   - CI 3/3 verde.

⏳ Qué falta:
   - (Manual, post-merge) Smoke real /admin/averias + /admin/asignaciones con 66k filas reales.
   - Bloque 09 — Cierre de asignación (form correctivo/revisión + foto + tests obligatorios DoD).

❓ Qué necesito del usuario:
   - Confirmar PR.
   - Mergear (Rebase and merge).
   - Smoke real en navegador.
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 08

1. Smoke real `/admin/averias` con 66k filas — verifica pagination + eager loading rápido + filters funcionan.
2. Smoke real `/admin/asignaciones` — el visual del stripe lateral (rojo/teal/gris) por tipo es lo que valida la regla #11 del DESIGN.md.
3. Pasar a **Bloque 09 — Cierre asignación**: form para que el técnico cierre la asignación con diagnóstico/recambios/foto. Tests obligatorios DoD del proyecto (`tipo_1_writes_correctivo_columns_not_notas`, etc — ver copilot-instructions.md). Cabeza fresca recomendada.
