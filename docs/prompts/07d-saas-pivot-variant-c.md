# Bloque 07d — Implementación variante C "Airtable-Mode" (DESIGN.md pivot)

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~75-100 min.

---

## Objetivo

Implementar la nueva dirección visual aprobada en `/design-shotgun`: **"Modern SaaS — productive precision" (Airtable-Mode)**. Sustituye Instrument Serif + General Sans por **IBM Plex Sans + Plex Mono** y reescribe el `PivResource` para conseguir el look-and-feel del wireframe `variant-C-airtable.html`.

**El wireframe aprobado vive en:**
`/Users/winfin/.gstack/projects/winfin-piv/designs/admin-pivs-list-saas-pivot-20260501/variant-C-airtable.html`

Léelo en navegador antes de implementar — es la fuente de verdad visual.

## Lo que cambia

1. **Tipografía global**: IBM Plex Sans (UI) + IBM Plex Mono (IDs/paradas/datos) + Instrument Serif (residual solo en wordmark "Winfin *PIV*").
2. **Escala tipográfica más densa** (ratio 1.200): 10 / 11 / 12 / 13 / 14 / 15 / 18 / 22 / 26 px.
3. **Tabla `Piv` con thumbnail real** 28×28 px en primera columna usando el patrón URL descubierto: `https://www.winfin.es/images/piv/<piv_imagen.url>`.
4. **Densidad alta**: row-height 36px, `->striped()`, paginado 25/50/100.
5. **Group-by Status** por defecto: tabla agrupada en "OPERATIVOS · 561" / "INACTIVOS · 14" con count badges.
6. **Side panel inspector** (Airtable killer feature) vía `Tables\Actions\ViewAction::make()->slideOver()->infolist(...)`:
   - Galería de imágenes (todas las del panel) en strip horizontal.
   - Field grid 2-col con label small-caps + value Plex Sans/Mono.
   - Tabs futuros (Detalles / Averías / Histórico / Imágenes) — solo "Detalles" funcional en este bloque.
7. **Wordmark "Winfin *PIV*"** en sidebar header con la "P" cursiva en Instrument Serif.

## Lo que NO cambia

- Cobalto `#1D3F8C` sigue siendo el único acento (DESIGN.md §4 sin modificar).
- Tokens de color, semánticos, layout, motion, iconografía: sin cambios.
- Login Filament page (Bloque 06): tipografía nueva la hereda automáticamente.
- PivResource form (Bloque 07): se mantiene. Cambios solo en table + view action + infolist.
- Validación municipio (ADR-0007): sin tocar.
- Eager loading: añadir `imagenes` al `with([])`.

## Definition of Done

1. `resources/css/app.css` con imports Bunny Fonts: IBM Plex Sans + Plex Mono + Instrument Serif (solo wordmark). Borrar General Sans/Fontshare import. Variables CSS `--sans`, `--mono`, `--display`.
2. `resources/css/filament/admin/theme.css` con las mismas fuentes — body usa Plex Sans, headings Plex Sans semibold, NO serif en headings. `font-variant-numeric: tabular-nums` en mono.
3. `tailwind.config.js` raíz con `fontFamily.sans = ['IBM Plex Sans', ...]`, `fontFamily.mono = ['IBM Plex Mono', ...]`, `fontSize` extendido con escala 1.200.
4. `resources/css/filament/admin/tailwind.config.js` actualizado paralelamente (mismas fuentes).
5. `App\Models\Piv` con accessor `thumbnailUrl` (computed) que devuelve la URL completa de la primera imagen.
6. `App\Filament\Resources\PivResource`:
   - `getEloquentQuery()` añade `'imagenes'` al `with([])`.
   - Tabla con primera columna `ImageColumn::make('thumbnail_url')` (28×28, rounded-4px), seguida de `piv_id` mono, `parada_cod` mono uppercase, dirección, municipio, operador, status inline dot+label.
   - `->defaultGroup('status_label')` agrupando por OPERATIVOS / INACTIVOS con custom group label.
   - `->actions([ViewAction::make()->slideOver()->infolist(self::infolist(...))])`.
   - Método estático nuevo `infolist(Infolist $infolist): Infolist` con sections: Galería + Identificación + Localización + Operadores + Estado.
7. Imports Filament Infolists: `use Filament\Infolists\Components\ImageEntry, TextEntry, Section, Grid, Tabs, Group;` (los que aplique).
8. Tests:
   - Existing tests siguen verde.
   - Test nuevo `pivs_list_shows_thumbnail_when_imagenes_present` — fixture con `PivImagen::factory()`, verificar que la columna renderiza la URL completa con prefijo.
   - Test nuevo `pivs_view_action_renders_infolist_with_imagenes` — Livewire test del slideOver.
9. `pint --test`, `pest`, `npm run build` verdes.
10. PR creado, CI 3/3 verde.
11. **Post-merge smoke real**: `/admin/pivs` muestra tipografía Plex, thumbnails, status pills inline, group-by status, click row → side panel slide-in con galería.

---

## Riesgos y mitigaciones

- **Filament `slideOver()` con `infolist`** en ViewAction es estándar Filament 3 — pero el infolist dentro del ViewAction es relativamente nuevo. Verificar versión instalada (≥3.2 OK).
- **Group-by status** vía `Tables\Grouping\Group::make('status')` con `->getTitleFromRecordUsing(fn ($r) => $r->status === 1 ? 'Operativos' : 'Inactivos')` — built-in, sin custom code.
- **Thumbnail accessor**: el primer `imagenes` puede ser `null` (paneles sin foto, ~0 actuales pero defensivo). Devolver `null` y dejar que Filament muestre placeholder.
- **Eager loading**: añadir `imagenes` al `with([])` puede tirar muchas filas si un panel tiene muchas imágenes. Limitamos a la primera con un `->with(['imagenes' => fn($q) => $q->orderBy('posicion')])` y el accessor toma `first()`. 1135 imágenes / 575 paneles = avg 2, acceptable.
- **Charset Latin1String**: `piv_imagen.url` no tiene cast (URL es ASCII); confirmar que no aparece en la lista de columnas con cast en `PivImagen` model. Si tiene cast, removerlo.
- **Tests existentes** que asuman `Instrument Serif` o `General Sans` en theme.css: cambiar a `IBM Plex`. Test del Bloque 05 `admin panel theme points to resources/css/filament/admin/theme.css` solo verifica el path, no el contenido — sin cambios.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (convenciones)
- CLAUDE.md (división trabajo)
- DESIGN.md (RECIENTEMENTE ACTUALIZADO — secciones 2 Aesthetic, 3 Typography, 9 Filament, 11 Decisions Log con entrada 2026-05-01 "Pivot Modern SaaS")
- docs/prompts/07d-saas-pivot-variant-c.md (este archivo)
- /Users/winfin/.gstack/projects/winfin-piv/designs/admin-pivs-list-saas-pivot-20260501/variant-C-airtable.html (LEE ESTE WIREFRAME EN NAVEGADOR ANTES DE EMPEZAR — es la fuente de verdad visual)

Tu tarea: implementar Bloque 07d. Pivot tipográfico + table density + thumbnail + slideOver inspector con galería.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd
git branch --show-current        # main
git rev-parse HEAD               # debe ser 45d417e (post Bloque 07c)
git status --short               # vacío excepto cambios pendientes en DESIGN.md y este prompt (ambos los committeas en Fase 8)
./vendor/bin/pest --colors=never --compact 2>&1 | tail -3
```

97 tests verdes esperados. Si DESIGN.md tiene cambios uncommitted (Claude lo actualizó antes), incluyelo en el commit 1.

```bash
git checkout -b bloque-07d-saas-pivot-variant-c
```

PARA: "Branch creada. Confirmas que has visto el wireframe variant-C-airtable.html en navegador? ¿Procedo a Fase 1 (fuentes globales)?"

## FASE 1 — Reescribir `resources/css/app.css`

Reemplaza el archivo entero:

```css
/* Bunny Fonts (RGPD-friendly mirror de Google Fonts).
   Justificación: tribunales europeos contra fonts.googleapis.com.
   Ver DESIGN.md §3 Typography. */
@import url("https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600,700&display=swap");
@import url("https://fonts.bunny.net/css?family=ibm-plex-mono:400,500,600&display=swap");
@import url("https://fonts.bunny.net/css?family=instrument-serif:400,400i&display=swap");

@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
  :root {
    --sans: "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif;
    --mono: "IBM Plex Mono", ui-monospace, "SF Mono", monospace;
    --display: "Instrument Serif", ui-serif, Georgia, serif;
  }

  html {
    font-family: var(--sans);
    background: theme("colors.canvas.base");
    color: theme("colors.ink.DEFAULT");
  }

  /* Tabular nums por defecto en clases de datos */
  .mono,
  [class*="kpi"],
  [data-numeric="true"] {
    font-family: var(--mono);
    font-variant-numeric: tabular-nums;
  }

  /* Wordmark "Winfin PIV" — único uso permitido de Instrument Serif */
  .brand em {
    font-family: var(--display);
    font-style: italic;
    font-weight: 400;
  }
}
```

PARA: "Fase 1 completa: app.css reescrito con Plex Sans + Plex Mono + Instrument Serif residual. ¿Procedo a Fase 2 (Filament theme)?"

## FASE 2 — Reescribir `resources/css/filament/admin/theme.css`

Reemplaza el archivo entero:

```css
/* Filament admin theme — Winfin PIV (DESIGN.md §9 Aplicación al stack/Filament).
 * Pivot Bloque 07d (1 may 2026): IBM Plex Sans + Plex Mono. Cero serif en chrome
 * salvo el wordmark. Ver DESIGN.md §3 Typography y §11 Decisions Log entrada
 * 2026-05-01 "Pivot Modern SaaS".
 */

@import "../../../../vendor/filament/filament/resources/css/theme.css";

@import url("https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600,700&display=swap");
@import url("https://fonts.bunny.net/css?family=ibm-plex-mono:400,500,600&display=swap");
@import url("https://fonts.bunny.net/css?family=instrument-serif:400,400i&display=swap");

@config 'tailwind.config.js';

:root {
    --fi-font-family: '"IBM Plex Sans"', "ui-sans-serif", "system-ui", sans-serif;
    --fi-mono-font-family: '"IBM Plex Mono"', "ui-monospace", monospace;
}

body.fi-body {
    font-family: var(--fi-font-family);
    background-color: #FAFAF7;
    color: #0F1115;
    font-size: 13px;
    font-feature-settings: "ss01";
    -webkit-font-smoothing: antialiased;
}

/* Page headings — Plex Sans semibold (NO serif) */
.fi-header-heading,
.fi-section-header-heading,
.fi-page-heading {
    font-family: var(--fi-font-family);
    font-weight: 600;
    letter-spacing: -0.005em;
}

/* Cards 6px (más técnico, menos editorial que el 8px previo) */
.fi-section,
.fi-card {
    border-radius: 6px;
}

/* Status pills */
.fi-badge {
    border-radius: 9999px;
    font-weight: 500;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    font-size: 10px;
}

/* Tabular nums en columnas mono y badges */
.fi-ta-text-item-mono,
[data-mono],
.fi-badge {
    font-family: var(--fi-mono-font-family);
    font-variant-numeric: tabular-nums;
}

/* Tabla densa estilo Airtable */
.fi-ta-table {
    font-size: 12px;
}

.fi-ta-row {
    height: 40px;
}

.fi-ta-header-cell {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

/* Group headers (status grouping) */
.fi-ta-group-header {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

/* Wordmark gesture — solo donde aplique clase .brand */
.brand em {
    font-family: '"Instrument Serif"', "ui-serif", "Georgia", serif;
    font-style: italic;
    font-weight: 400;
}
```

PARA: "Fase 2 completa: Filament theme reescrito con Plex. ¿Procedo a Fase 3 (tailwind configs)?"

## FASE 3 — Tailwind configs (raíz + Filament)

### 3a — `tailwind.config.js` raíz

Edita el archivo. Cambios:

1. `fontFamily`:
```js
fontFamily: {
  sans:  ['"IBM Plex Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
  mono:  ['"IBM Plex Mono"', "ui-monospace", '"SF Mono"', "monospace"],
  serif: ['"Instrument Serif"', "ui-serif", "Georgia", "serif"], // residual wordmark only
},
```

2. `fontSize` (escala 1.200, más densa):
```js
fontSize: {
  "2xs": "10px",
  xs:    "11px",
  sm:    "12px",
  base:  "13px",
  md:    "14px",
  lg:    "15px",
  xl:    "18px",
  "2xl": "22px",
  "3xl": "26px",
  "4xl": "32px",
},
```

Mantén el resto de tokens (colors, borderRadius, transitionDuration, content, plugins) intactos.

### 3b — `resources/css/filament/admin/tailwind.config.js`

Mismo cambio en `extend.fontFamily`:

```js
fontFamily: {
  sans:  ['"IBM Plex Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
  mono:  ['"IBM Plex Mono"', "ui-monospace", '"SF Mono"', "monospace"],
  serif: ['"Instrument Serif"', "ui-serif", "Georgia", "serif"],
},
```

PARA: "Fase 3 completa: tailwind configs alineados. ¿Procedo a Fase 4 (Piv accessor + thumbnail)?"

## FASE 4 — `Piv::thumbnailUrl` accessor + eager loading

### 4a — Accessor en `app/Models/Piv.php`

Añade después del último método relación:

```php
    /**
     * URL completa de la primera imagen del panel para mostrar como thumbnail.
     *
     * Imágenes legacy viven en winfin.es/images/piv/<filename>. Bloque 07d
     * confirmó que son públicas (no requieren auth). Cuando migremos los
     * archivos al storage local de Laravel (post-cutover Fase 7), cambiar
     * el prefijo a Storage::url().
     *
     * @return string|null URL absoluta o null si el panel no tiene imágenes.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        $first = $this->imagenes->first();
        if (! $first) {
            return null;
        }
        return 'https://www.winfin.es/images/piv/'.$first->url;
    }
```

### 4b — Eager loading en `PivResource::getEloquentQuery()`

Añade `'imagenes'` al with array:

```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()->with([
        'operadorPrincipal:operador_id,razon_social',
        'industria:modulo_id,nombre',
        'municipioModulo:modulo_id,nombre',
        'imagenes',  // para thumbnail_url accessor
    ]);
}
```

PARA: "Fase 4 completa: accessor + eager loading. ¿Procedo a Fase 5 (table redesign)?"

## FASE 5 — Reescribir `PivResource::table()`

Cambios:

1. Primera columna `ImageColumn` para thumbnail.
2. Densidad: `->striped()` + `->paginated([25, 50, 100])`.
3. Status como inline dot+label vía custom column o `IconColumn`.
4. `->defaultGroup(...)` agrupando por status.
5. ViewAction con slideOver e infolist (Fase 6 lo define).

Reemplaza el método `table()` entero:

```php
public static function table(Table $table): Table
{
    return $table
        ->striped()
        ->paginated([25, 50, 100])
        ->defaultPaginationPageOption(25)
        ->columns([
            Tables\Columns\ImageColumn::make('thumbnail_url')
                ->label('')
                ->size(28)
                ->extraImgAttributes(['style' => 'border-radius:4px; object-fit:cover'])
                ->defaultImageUrl(asset('images/piv-placeholder.svg'))
                ->grow(false),
            Tables\Columns\TextColumn::make('piv_id')
                ->label('ID')
                ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 3, '0', STR_PAD_LEFT))
                ->sortable()
                ->searchable()
                ->extraAttributes(['data-mono' => true])
                ->size('xs')
                ->color('gray'),
            Tables\Columns\TextColumn::make('parada_cod')
                ->label('Parada')
                ->formatStateUsing(fn ($state) => mb_strtoupper(trim((string) $state)))
                ->searchable()
                ->sortable()
                ->extraAttributes(['data-mono' => true])
                ->weight('medium'),
            Tables\Columns\TextColumn::make('direccion')
                ->label('Dirección')
                ->searchable()
                ->limit(50),
            Tables\Columns\TextColumn::make('municipioModulo.nombre')
                ->label('Municipio')
                ->default('—')
                ->sortable()
                ->color('gray'),
            Tables\Columns\TextColumn::make('operadorPrincipal.razon_social')
                ->label('Operador')
                ->limit(20)
                ->color('gray'),
            Tables\Columns\TextColumn::make('industria.nombre')
                ->label('Industria')
                ->limit(15)
                ->extraAttributes(['data-mono' => true])
                ->color('gray'),
            Tables\Columns\TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn ($state) => $state == 1 ? 'Operativo' : 'Inactivo')
                ->color(fn ($state) => $state == 1 ? 'success' : 'danger'),
        ])
        ->defaultSort('piv_id')
        ->groups([
            Tables\Grouping\Group::make('status')
                ->label('Status')
                ->getTitleFromRecordUsing(fn ($record) => $record->status == 1 ? 'Operativos' : 'Inactivos / Averiados')
                ->orderQueryUsing(fn ($query, string $direction) => $query->orderBy('status', $direction === 'asc' ? 'desc' : 'asc')),
        ])
        ->defaultGroup('status')
        ->filters([
            Tables\Filters\SelectFilter::make('status')
                ->options([1 => 'Operativos', 0 => 'Inactivos']),
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
            Tables\Actions\ViewAction::make()
                ->slideOver()
                ->modalWidth('xl'),
            Tables\Actions\EditAction::make()
                ->iconButton(),
        ]);
}
```

NOTA: el `defaultImageUrl(asset('images/piv-placeholder.svg'))` requiere crear el archivo `public/images/piv-placeholder.svg` con un placeholder lineal simple (rectángulo con icono de marquesina en stroke). Si prefieres, usa `null` y deja Filament muestre fondo gris.

PARA: "Fase 5 completa: tabla redesigned con thumbnail + group + slideOver action. ¿Procedo a Fase 6 (Infolist del side panel)?"

## FASE 6 — `PivResource::infolist()` (side panel inspector)

Añade el método estático `infolist()` al `PivResource` y configúralo en el ViewAction de la Fase 5 (cambia `->slideOver()->modalWidth('xl')` por `->slideOver()->infolist(fn (Infolist $i) => self::infolist($i))`).

Imports nuevos al top del archivo:

```php
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\Grid as InfolistGrid;
use Filament\Infolists\Infolist;
```

Método nuevo:

```php
public static function infolist(Infolist $infolist): Infolist
{
    return $infolist->schema([
        // Galería: muestra primera imagen grande + repeater visual de imágenes
        InfolistSection::make('Galería')
            ->schema([
                ImageEntry::make('thumbnail_url')
                    ->label('')
                    ->getStateUsing(fn ($record) => $record->imagenes
                        ->map(fn ($img) => 'https://www.winfin.es/images/piv/'.$img->url)
                        ->all())
                    ->stacked(false)
                    ->ring(0)
                    ->height(180)
                    ->limit(6),
            ])
            ->collapsible(false)
            ->compact(),

        InfolistSection::make('Identificación')
            ->columns(3)
            ->schema([
                TextEntry::make('piv_id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 5, '0', STR_PAD_LEFT))
                    ->extraAttributes(['data-mono' => true]),
                TextEntry::make('parada_cod')
                    ->label('Parada')
                    ->formatStateUsing(fn ($state) => mb_strtoupper(trim((string) $state)))
                    ->extraAttributes(['data-mono' => true]),
                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state == 1 ? 'Operativo' : 'Inactivo')
                    ->color(fn ($state) => $state == 1 ? 'success' : 'danger'),
                TextEntry::make('direccion')
                    ->label('Dirección')
                    ->columnSpan(3),
                TextEntry::make('n_serie_piv')->label('N° serie PIV')->extraAttributes(['data-mono' => true])->placeholder('—'),
                TextEntry::make('n_serie_sim')->label('N° serie SIM')->extraAttributes(['data-mono' => true])->placeholder('—'),
                TextEntry::make('n_serie_mgp')->label('N° serie MGP')->extraAttributes(['data-mono' => true])->placeholder('—'),
            ]),

        InfolistSection::make('Localización')
            ->columns(2)
            ->schema([
                TextEntry::make('municipioModulo.nombre')->label('Municipio')->placeholder('— Sin asignar —'),
                TextEntry::make('industria.nombre')->label('Industria')->placeholder('—'),
                TextEntry::make('tipo_piv')->label('Tipo PIV')->placeholder('—'),
                TextEntry::make('tipo_marquesina')->label('Marquesina')->placeholder('—'),
                TextEntry::make('tipo_alimentacion')->label('Alimentación')->placeholder('—'),
            ]),

        InfolistSection::make('Operadores')
            ->columns(3)
            ->schema([
                TextEntry::make('operadorPrincipal.razon_social')->label('Principal')->placeholder('—'),
                TextEntry::make('operadorSecundario.razon_social')->label('Secundario')->placeholder('—'),
                TextEntry::make('operadorTerciario.razon_social')->label('Terciario')->placeholder('—'),
            ]),

        InfolistSection::make('Estado')
            ->columns(3)
            ->schema([
                TextEntry::make('mantenimiento')->label('Mantenimiento')->placeholder('—'),
                TextEntry::make('fecha_instalacion')->label('Instalación')->date('d M Y')->placeholder('—'),
                TextEntry::make('observaciones')->columnSpan(3)->placeholder('—'),
            ]),
    ]);
}
```

Verifica que el `ViewAction` ahora apunta a este infolist. Resultado en el array `actions([])`:

```php
Tables\Actions\ViewAction::make()
    ->slideOver()
    ->modalWidth('2xl')
    ->infolist(fn (Infolist $infolist) => self::infolist($infolist)),
```

PARA: "Fase 6 completa: Infolist del side panel listo. ¿Procedo a Fase 7 (tests)?"

## FASE 7 — Tests

Lee `tests/Feature/Filament/PivResourceTest.php`. Añade dos tests al final, antes del cierre del fichero:

```php
it('pivs_list_shows_thumbnail_when_imagenes_present', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $piv = Piv::factory()->create([
        'piv_id' => 99100,
        'municipio' => (string) $municipio->modulo_id,
    ]);
    \DB::table('piv_imagen')->insert([
        'piv_id' => 99100,
        'url' => '99100-test.jpg',
        'posicion' => 1,
    ]);

    $piv->refresh();
    expect($piv->thumbnail_url)->toBe('https://www.winfin.es/images/piv/99100-test.jpg');
});

it('piv_thumbnail_url_returns_null_without_imagenes', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $piv = Piv::factory()->create([
        'piv_id' => 99101,
        'municipio' => (string) $municipio->modulo_id,
    ]);

    expect($piv->thumbnail_url)->toBeNull();
});

it('pivs_list_view_action_renders_infolist_with_imagenes', function () {
    $municipio = Modulo::factory()->municipio('Móstoles')->create();
    $piv = Piv::factory()->create([
        'piv_id' => 99102,
        'parada_cod' => '06036',
        'direccion' => 'av. Juan Carlos I, 22',
        'municipio' => (string) $municipio->modulo_id,
    ]);

    Livewire::test(ListPivs::class)
        ->callTableAction('view', $piv->piv_id)
        ->assertSuccessful();
});
```

NOTA: el test `pivs_list_view_action` puede fallar si Filament 3 cambia el slug del action. En tal caso, usa el slug correcto (probablemente `'view'`).

Corre los tests:
```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -15
```

100 tests verdes esperados (97 + 3 nuevos). Si falla alguno por nombre de fuente en theme.css o tailwind, AVISA.

PARA: "Fase 7 completa: 100 tests verdes. ¿Procedo a Fase 8 (smoke build + commits + PR)?"

## FASE 8 — Smoke + commits + PR

```bash
./vendor/bin/pint --test 2>&1 | tail -5
./vendor/bin/pest --colors=never --compact 2>&1 | tail -10
npm run build 2>&1 | tail -3
```

Verifica:
- Pint clean (corre sin --test si reporta cambios, commitea como style: aparte).
- Pest 100 verdes.
- npm build OK (theme.css + app.css se compilan con los nuevos imports Bunny).

Stage explícito por archivo:

1. `docs: add Bloque 07d prompt + DESIGN.md pivot to Modern SaaS` — `docs/prompts/07d-saas-pivot-variant-c.md` + `DESIGN.md`. El DESIGN.md ya viene modificado por Claude antes del bloque.
2. `feat(theme): pivot to IBM Plex Sans + Plex Mono (DESIGN.md 2026-05-01)` — `resources/css/app.css` + `resources/css/filament/admin/theme.css` + `tailwind.config.js` + `resources/css/filament/admin/tailwind.config.js`.
3. `feat(models): add Piv::thumbnailUrl accessor` — `app/Models/Piv.php`.
4. `feat(filament): redesign PivResource table with thumbnail + group-by + slideOver inspector` — `app/Filament/Resources/PivResource.php`.
5. `test: cover PivResource thumbnail accessor and slideOver action` — `tests/Feature/Filament/PivResourceTest.php`.

Push + PR:

```bash
git push -u origin bloque-07d-saas-pivot-variant-c
gh pr create \
  --base main \
  --head bloque-07d-saas-pivot-variant-c \
  --title "Bloque 07d — Pivot a Airtable-Mode (IBM Plex + thumbnail + slideOver inspector)" \
  --body "$(cat <<'BODY'
## Resumen

Implementa la dirección visual aprobada en \`/design-shotgun\` (1 may 2026): **"Modern SaaS — productive precision" (Airtable-Mode)**. Pivot tipográfico (IBM Plex Sans + Plex Mono, Instrument Serif residual solo en wordmark), tabla densa con thumbnail real del panel, group-by status por defecto, side panel inspector vía Filament \`->slideOver()->infolist()\` con galería completa.

Wireframe aprobado: \`~/.gstack/projects/winfin-piv/designs/admin-pivs-list-saas-pivot-20260501/variant-C-airtable.html\`.

## Cambios

### Sistema visual
- DESIGN.md §2 Aesthetic + §3 Typography reescritas (Pivot 2026-05-01 en §11 log).
- Bunny Fonts: IBM Plex Sans + Plex Mono añadidos. General Sans (Fontshare) eliminado.
- Tailwind \`fontFamily.sans\` + \`fontFamily.mono\` apuntan a Plex.
- Escala tipográfica más densa (ratio 1.200): 10/11/12/13/14/15/18/22/26 px.
- Filament theme: row-height 40px, font-size base 12-13px, status badges con tracking + uppercase.

### Resource Piv
- \`Piv::thumbnailUrl\` accessor (URL completa via prefijo \`https://www.winfin.es/images/piv/\`).
- Eager loading: \`with(['imagenes'])\` añadido.
- Tabla:
  - Primera columna: \`ImageColumn\` 28px del thumbnail.
  - ID y parada en Plex Mono (data-mono attr).
  - \`->striped()\`, \`->paginated([25,50,100])\`, \`->defaultGroup('status')\`.
  - Group titles "Operativos" / "Inactivos / Averiados" via \`getTitleFromRecordUsing\`.
- Actions:
  - \`ViewAction::make()->slideOver()->infolist()\` para side panel inspector.
  - \`EditAction::make()->iconButton()\`.
- Infolist nuevo método \`infolist()\` con sections: Galería (todas las imágenes con \`ImageEntry\`), Identificación, Localización, Operadores, Estado.

### Tests
- 3 tests nuevos (thumbnail accessor + null fallback + ViewAction render).
- 97 tests existentes siguen verdes.

## Decisiones registradas en DESIGN.md

| | Decisión |
|---|---|
| Fuente principal | IBM Plex Sans (humanist grotesque, más carácter técnico que Helvetica neutro) |
| Fuente datos | IBM Plex Mono (tabular nums automático) |
| Serif | Solo en wordmark "Winfin *PIV*" — único gesto editorial residual |
| Cobalto | Sin cambios (#1D3F8C único acento) |
| Densidad | Row 40px, font 12-13px base |
| Patrón inspector | Filament \`slideOver\` + \`infolist\` (built-in Filament 3) |
| Group default | Status (Operativos/Inactivos) |

## Post-merge smoke

\`/admin/pivs\` debe mostrar:
1. Tipografía Plex (no Instrument/General Sans).
2. Thumbnails reales de paneles en primera columna.
3. Grupos "OPERATIVOS · 561" / "INACTIVOS · 14".
4. Click en row → side panel slide-in derecha con galería de imágenes + campos.
5. Wordmark "Winfin *PIV*" con la "P" cursiva.

## CI esperado

3/3 jobs verde.
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-07d-saas-pivot-variant-c --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ Qué he hecho:
   - DESIGN.md §2-3-9-11 actualizado (Pivot Modern SaaS, 2026-05-01).
   - Bunny Fonts: Plex Sans + Plex Mono + Instrument Serif (residual). General Sans eliminado.
   - Tailwind configs alineados (raíz + Filament admin theme).
   - Piv::thumbnailUrl accessor + eager loading imagenes.
   - PivResource tabla redesigned: thumbnail 28px, IDs/paradas en Plex Mono, group-by status, slideOver ViewAction.
   - Infolist completo con galería + 5 sections.
   - 100 tests verdes (97 + 3 nuevos).
   - Pint + build OK.
   - 5 commits Conventional Commits.
   - PR #N: [URL].
   - CI 3/3 verde.

⏳ Qué falta:
   - (Manual, post-merge) Smoke real /admin/pivs: typography Plex, thumbnails, group-by, slideOver con galería.
   - Bloque 07e (futuro): edit page con tabs Detalles/Averías/Histórico/Imágenes, gallery management.
   - Bloque 08 — Resources Averia + Asignacion.

❓ Qué necesito del usuario:
   - Confirmar PR.
   - Mergear (Rebase and merge).
   - Smoke real en navegador.
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 07d

1. Smoke real `/admin/pivs`:
   - Tipografía Plex visible (más técnica, menos editorial que la anterior).
   - Thumbnails reales en primera columna.
   - Group-by Status muestra "Operativos · 561" y "Inactivos / Averiados · 14".
   - Click en una fila → side panel slide-in derecha con galería + secciones.
   - El wordmark "Winfin *PIV*" mantiene la "P" cursiva en serif.
2. Si algo no encaja con el wireframe, anotar y crear Bloque 07e con ajustes.
3. Pasar a **Bloque 08 — Resources `Averia` + `Asignacion`** con la nueva dirección visual ya consolidada.
