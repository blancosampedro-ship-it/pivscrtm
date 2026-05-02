# Bloque 09d — Implementación Carbon Pivot (theme + tokens + sticky + iconButton)

## Contexto

Branch `bloque-09d-design-pivot-carbon` (PR #23 draft) ya contiene el commit que reescribe `DESIGN.md` raíz con el pivot completo a IBM Carbon Design System (commit `6e61578`). Los archivos de referencia Carbon están en `docs/references/ibm-carbon-*.{md,html}`.

Este bloque materializa los tokens documentados en código: tailwind config, theme.css de Filament, AdminPanelProvider, app.css, y un fix puntual en PivResource. Folde dentro de este PR el sticky actions column + el iconButton fix que iban en el (ahora cerrado) PR #22.

**Lectura obligatoria antes de empezar:**
- `DESIGN.md` (en este branch — está reescrito; NO es el de main).
- `docs/references/ibm-carbon-design.md` §2 Color, §3 Typography, §4 Component Stylings, §5 Layout, §7 Do's and Don'ts.

## Restricciones inviolables que aplican

- **Wordmark "Winfin *PIV*" conserva Instrument Serif italic en la "f"** — único uso permitido de serif. NO eliminar `.brand em` del CSS, NO eliminar el import de Instrument Serif. Esto es decisión deliberada del usuario (DESIGN.md §3 Wordmark).
- **NO migrar Heroicons** a IBM Carbon Icons — el coste de churn no compensa. Heroicons existing usage stays.
- **NO romper tests existentes**. Los 144 tests deben pasar tras el bloque sin modificar ninguno.
- **Touch targets ≥ 88px en PWA técnico** (regla de producto) — NO sobrescribir esto si toca PWA shell.
- **Tailwind 3.4.19 obligatorio** (Bloque 01 pin para compat Filament). NO upgradar a Tailwind 4.

## Plan de cambios — 5 archivos

### 1. `tailwind.config.js` (root) — reemplazar tokens completos

Reemplazar el bloque `theme.extend` con la paleta Carbon. Eliminar los grupos `canvas`, `ink`, `line` (no se usan en código — verificado por Claude con grep antes del prompt). Mantener el plugin `forms` y `content` paths intactos.

**Nuevos tokens:**

```js
theme: {
  extend: {
    colors: {
      // Carbon Blue 60 — único acento. Reemplaza cobalto editorial #1D3F8C.
      primary: {
        10:  "#EDF5FF",  // accent-soft, selected row tint
        20:  "#D0E2FF",
        30:  "#A6C8FF",
        40:  "#78A9FF",  // dark mode accent
        50:  "#4589FF",
        60:  "#0F62FE",  // BASE — Blue 60. CTAs, links, focus.
        70:  "#0353E9",  // hover
        80:  "#002D9C",  // active
        90:  "#001D6C",
        100: "#001141",
      },
      // Layers Carbon — profundidad por capas, no shadows
      layer: {
        0:  "#FFFFFF",   // page base
        1:  "#F4F4F4",   // Gray 10 — cards, tiles
        2:  "#E0E0E0",   // Gray 20 — elevated
        hover: "#E8E8E8",
      },
      // Texto Carbon
      ink: {
        primary:     "#161616",  // Gray 100
        secondary:   "#525252",  // Gray 70
        placeholder: "#6F6F6F",  // Gray 60
        on_color:    "#FFFFFF",
        disabled:    "#8D8D8D",  // Gray 50
      },
      // Bordes Carbon
      line: {
        subtle: "#C6C6C6",  // Gray 30
        strong: "#8D8D8D",  // Gray 50
      },
      // Status Carbon
      success: { DEFAULT: "#24A148", soft: "#DEFBE6" },  // Green 50
      warning: { DEFAULT: "#F1C21B", soft: "#FCF4D6" },  // Yellow 30
      error:   { DEFAULT: "#DA1E28", soft: "#FFF1F1" },  // Red 60
      info:    { DEFAULT: "#0F62FE", soft: "#EDF5FF" },  // = primary 60
    },
    fontFamily: {
      sans:  ['"IBM Plex Sans"', '"Helvetica Neue"', "Arial", "sans-serif"],
      mono:  ['"IBM Plex Mono"', "Menlo", "Courier", "monospace"],
      serif: ['"Instrument Serif"', "ui-serif", "Georgia", "serif"],  // SOLO wordmark
    },
    borderRadius: {
      none: "0",
      DEFAULT: "0",   // Carbon: 0px en buttons/inputs/cards
      sm:  "0",
      md:  "0",
      lg:  "0",
      pill: "24px",   // status pills, tags (excepción Carbon)
      full: "9999px", // avatars, dots
    },
    fontSize: {
      // Carbon Productive scale
      "2xs":  ["10px", { lineHeight: "1.4", letterSpacing: "0.32px" }],
      xs:     ["12px", { lineHeight: "1.33", letterSpacing: "0.32px" }],   // Caption 01
      sm:     ["14px", { lineHeight: "1.29", letterSpacing: "0.16px" }],   // Body Short 01
      base:   ["14px", { lineHeight: "1.29", letterSpacing: "0.16px" }],   // = sm (Carbon body default)
      md:     ["16px", { lineHeight: "1.50", letterSpacing: "0" }],         // Body Long 01
      lg:     ["20px", { lineHeight: "1.40", letterSpacing: "0" }],         // Heading 04
      xl:     ["24px", { lineHeight: "1.33", letterSpacing: "0" }],         // Heading 03
      "2xl":  ["32px", { lineHeight: "1.25", letterSpacing: "0" }],         // Heading 02
      "3xl":  ["42px", { lineHeight: "1.19", letterSpacing: "0" }],         // Heading 01 (light 300)
      "4xl":  ["48px", { lineHeight: "1.17", letterSpacing: "0" }],         // Display 02
    },
    transitionDuration: {
      // Carbon timings
      "fast-01":     "70ms",
      "fast-02":     "110ms",
      "moderate-01": "150ms",
      "moderate-02": "240ms",
      "slow-01":     "400ms",
    },
    transitionTimingFunction: {
      "carbon-productive": "cubic-bezier(0.2, 0, 0.38, 0.9)",
      "carbon-expressive": "cubic-bezier(0.4, 0.14, 0.3, 1)",
    },
  },
},
```

### 2. `resources/css/app.css` — Bunny Fonts pesos Carbon

Cambios:
- Plex Sans imports: `400,500,600,700` → **`300,400,600`** (drop 500 y 700, add 300 Light).
- Plex Mono imports: `400,500,600` (sin cambios).
- Instrument Serif: `400,400i` → `400i` (drop 400 plano, solo italic 400i).
- `:root` rename: `--sans/--mono/--display` → `--font-sans/--font-mono/--font-serif` (consistencia con DESIGN.md §3).
- `html` background: `theme("colors.canvas.base")` → `theme("colors.layer.0")` (white puro).
- `html` color: `theme("colors.ink.DEFAULT")` → `theme("colors.ink.primary")`.

Final esperado de `app.css`:

```css
/* Bunny Fonts (RGPD-friendly mirror de Google Fonts).
   Pivot 2026-05-02 (DESIGN.md §3 IBM Carbon): Plex Sans pesos 300/400/600
   (Carbon Productive scale), Plex Mono 400/500/600, Instrument Serif italic
   solo para wordmark. */
@import url("https://fonts.bunny.net/css?family=ibm-plex-sans:300,400,600&display=swap");
@import url("https://fonts.bunny.net/css?family=ibm-plex-mono:400,500,600&display=swap");
@import url("https://fonts.bunny.net/css?family=instrument-serif:400i&display=swap");

@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
  :root {
    --font-sans:  "IBM Plex Sans", "Helvetica Neue", Arial, sans-serif;
    --font-mono:  "IBM Plex Mono", Menlo, Courier, monospace;
    --font-serif: "Instrument Serif", ui-serif, Georgia, serif;
  }

  html {
    font-family: var(--font-sans);
    background: theme("colors.layer.0");
    color: theme("colors.ink.primary");
  }

  /* Tabular nums por defecto en clases de datos */
  .mono,
  [class*="kpi"],
  [data-numeric="true"],
  [data-mono] {
    font-family: var(--font-mono);
    font-variant-numeric: tabular-nums;
  }

  /* Wordmark "Winfin PIV" — único uso permitido de Instrument Serif (excepción documentada DESIGN.md §3) */
  .brand em {
    font-family: var(--font-serif);
    font-style: italic;
    font-weight: 400;
  }
}
```

### 3. `resources/css/filament/admin/theme.css` — rewrite Carbon

Reemplazar el archivo entero. Mantener el `@import` del vendor Filament theme y el `@config 'tailwind.config.js'` que ya están al inicio. Substituir todo lo demás por:

```css
/* Filament admin theme — Winfin PIV
 * Pivot 2026-05-02 (DESIGN.md §10.1 IBM Carbon).
 * Tokens semánticos vía CSS custom properties. Profundidad por capas de color,
 * 0px border-radius en buttons/inputs/cards, inputs bottom-border-only,
 * sticky actions column, type scale Carbon Productive.
 * Wordmark "Winfin PIV" conserva Instrument Serif italic en .brand em (excepción única). */

@import "../../../../vendor/filament/filament/resources/css/theme.css";

@import url("https://fonts.bunny.net/css?family=ibm-plex-sans:300,400,600&display=swap");
@import url("https://fonts.bunny.net/css?family=ibm-plex-mono:400,500,600&display=swap");
@import url("https://fonts.bunny.net/css?family=instrument-serif:400i&display=swap");

@config 'tailwind.config.js';

/* ---- Carbon tokens (mapeo 1:1 con DESIGN.md §4) ---- */
:root {
    /* Surfaces */
    --winfin-bg-base:        #FFFFFF;  /* layer 0 */
    --winfin-bg-layer-01:    #F4F4F4;  /* Gray 10 — cards, hover de filas */
    --winfin-bg-layer-02:    #E0E0E0;  /* Gray 20 — elevated */
    --winfin-bg-field:       #F4F4F4;  /* inputs */
    --winfin-bg-hover:       #E8E8E8;  /* hover layer-01 */
    /* Text */
    --winfin-text-primary:     #161616; /* Gray 100 */
    --winfin-text-secondary:   #525252; /* Gray 70 */
    --winfin-text-placeholder: #6F6F6F; /* Gray 60 */
    --winfin-text-disabled:    #8D8D8D; /* Gray 50 */
    /* Borders */
    --winfin-border-subtle: #C6C6C6;  /* Gray 30 */
    --winfin-border-strong: #8D8D8D;
    /* Accent */
    --winfin-accent:        #0F62FE;  /* Blue 60 */
    --winfin-accent-hover:  #0353E9;
    --winfin-accent-active: #002D9C;
    --winfin-accent-soft:   #EDF5FF;  /* Blue 10 */
    /* Status */
    --winfin-error:        #DA1E28;
    --winfin-error-soft:   #FFF1F1;
    --winfin-success:      #24A148;
    --winfin-success-soft: #DEFBE6;
    --winfin-warning:      #F1C21B;
    --winfin-warning-soft: #FCF4D6;
    /* Filament overrides */
    --fi-font-family:      '"IBM Plex Sans"', "Helvetica Neue", Arial, sans-serif;
    --fi-mono-font-family: '"IBM Plex Mono"', Menlo, Courier, monospace;
}

/* ---- Body & globals ---- */
body.fi-body {
    font-family: var(--fi-font-family);
    background-color: var(--winfin-bg-base);
    color: var(--winfin-text-primary);
    font-size: 14px;
    letter-spacing: 0.16px; /* Carbon micro-tracking en 14px body */
    -webkit-font-smoothing: antialiased;
}

/* Page headings — Carbon Productive scale */
.fi-header-heading,
.fi-page-heading {
    font-family: var(--fi-font-family);
    font-weight: 400;          /* Heading 03 = 24px Regular */
    font-size: 24px;
    line-height: 1.33;
    letter-spacing: 0;
}

.fi-section-header-heading {
    font-family: var(--fi-font-family);
    font-weight: 600;          /* Heading 04 = 20px Semibold */
    font-size: 20px;
    line-height: 1.40;
}

/* ---- Cards — flat, 0px radius ---- */
.fi-section,
.fi-card {
    border-radius: 0;
    background-color: var(--winfin-bg-base);
    box-shadow: none;
}

/* ---- Status pills — 24px (excepción Carbon) ---- */
.fi-badge {
    border-radius: 24px;
    font-weight: 400;
    font-family: var(--fi-mono-font-family);
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.32px;
    font-size: 12px;
    text-transform: none;       /* Carbon NO uppercase en badges */
    padding: 4px 8px;
}

/* ---- Tabla — Carbon DataTable density ---- */
.fi-ta-table {
    font-size: 14px;
    letter-spacing: 0.16px;
}

.fi-ta-row {
    height: 40px;
}

.fi-ta-header-cell {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.32px;
    text-transform: none;       /* Carbon: header sin uppercase */
    color: var(--winfin-text-secondary);
}

.fi-ta-group-header {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.32px;
    text-transform: uppercase;  /* group headers SÍ small-caps por densidad */
    color: var(--winfin-text-secondary);
    background-color: var(--winfin-bg-layer-01);
}

/* Tabular nums para columnas mono y badges */
.fi-ta-text-item-mono,
[data-mono] {
    font-family: var(--fi-mono-font-family);
    font-variant-numeric: tabular-nums;
}

/* ---- Sticky actions column (folded de Bloque 09c PR #22 cerrado) ---- */
.fi-ta-table .fi-ta-actions-header-cell,
.fi-ta-table .fi-ta-actions-cell {
    position: sticky;
    right: 0;
    z-index: 2;
    background-color: var(--winfin-bg-base);
}

.fi-ta-table .fi-ta-row:hover .fi-ta-actions-cell {
    background-color: var(--winfin-bg-layer-01);
}

.fi-ta-table .fi-ta-actions-header-cell::before,
.fi-ta-table .fi-ta-actions-cell::before {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 8px;
    transform: translateX(-100%);
    background: linear-gradient(to left, rgba(15, 17, 21, 0.06), transparent);
    pointer-events: none;
}

/* ---- Inputs — bottom-border-only (firma Carbon) ---- */
.fi-input,
.fi-select-input,
.fi-textarea,
.fi-fo-text-input,
.fi-fo-textarea,
.fi-fo-select {
    border-radius: 0;
    background-color: var(--winfin-bg-field);
    border: none;
    border-bottom: 2px solid transparent;
    transition: border-color 110ms cubic-bezier(0.2, 0, 0.38, 0.9);
}

.fi-input:focus,
.fi-select-input:focus,
.fi-textarea:focus,
.fi-fo-text-input:focus-within,
.fi-fo-textarea:focus-within,
.fi-fo-select:focus-within {
    border-bottom-color: var(--winfin-accent);
    outline: none;
    box-shadow: none;
}

.fi-fo-field-wrp-error .fi-input,
.fi-fo-field-wrp-error .fi-select-input,
.fi-fo-field-wrp-error .fi-textarea {
    border-bottom-color: var(--winfin-error);
}

/* Labels Carbon */
.fi-fo-field-wrp-label,
.fi-fo-field-label {
    font-size: 12px;
    font-weight: 400;
    letter-spacing: 0.32px;
    color: var(--winfin-text-secondary);
}

/* ---- Buttons Carbon — 0px radius, Blue 60 primary ---- */
.fi-btn {
    border-radius: 0;
    font-weight: 400;
    font-size: 14px;
    letter-spacing: 0.16px;
    transition: background-color 110ms cubic-bezier(0.2, 0, 0.38, 0.9);
}

.fi-btn-color-primary {
    background-color: var(--winfin-accent);
    color: var(--winfin-bg-base);
}

.fi-btn-color-primary:hover {
    background-color: var(--winfin-accent-hover);
}

.fi-btn-color-primary:active {
    background-color: var(--winfin-accent-active);
}

/* ---- Wordmark "Winfin PIV" — excepción documentada (DESIGN.md §3) ---- */
.brand em {
    font-family: '"Instrument Serif"', "ui-serif", "Georgia", serif;
    font-style: italic;
    font-weight: 400;
}

/* ---- Dark mode (Carbon Gray 100 theme) ---- */
@media (prefers-color-scheme: dark) {
    :root {
        --winfin-bg-base:        #161616;  /* Gray 100 */
        --winfin-bg-layer-01:    #262626;  /* Gray 90 */
        --winfin-bg-layer-02:    #393939;  /* Gray 80 */
        --winfin-bg-field:       #262626;
        --winfin-bg-hover:       #333333;
        --winfin-text-primary:     #F4F4F4;
        --winfin-text-secondary:   #C6C6C6;
        --winfin-text-placeholder: #6F6F6F;
        --winfin-text-disabled:    #6F6F6F;
        --winfin-border-subtle: #393939;
        --winfin-border-strong: #6F6F6F;
        --winfin-accent:        #78A9FF;  /* Blue 40 — más legible en dark */
        --winfin-accent-hover:  #A6C8FF;
        --winfin-accent-active: #4589FF;
        --winfin-accent-soft:   #001141;
    }
}
```

### 4. `app/Providers/Filament/AdminPanelProvider.php` — primary color

Cambio en una línea: el hex del color primary registrado para Filament.

**De:**
```php
->colors([
    // Cobalto Winfin (DESIGN.md §4) — único acento de marca.
    'primary' => Color::hex('#1D3F8C'),
])
```

**A:**
```php
->colors([
    // Carbon Blue 60 — único acento (DESIGN.md §4).
    'primary' => Color::hex('#0F62FE'),
])
```

### 5. `app/Filament/Resources/PivResource.php` — drop `->button()` (folded de extension prompt)

En el chain del `Tables\Actions\ActionGroup` (líneas ~285-289), eliminar la línea `->button(),`.

**De:**
```php
])
    ->label('Acciones')
    ->icon('heroicon-m-ellipsis-vertical')
    ->size('sm')
    ->color('gray')
    ->button(),
```

**A:**
```php
])
    ->label('Acciones')
    ->icon('heroicon-m-ellipsis-vertical')
    ->size('sm')
    ->color('gray'),
```

NO modificar nada más en el archivo. NO tocar tests.

## Verificación obligatoria antes del commit final

1. **Dependencies y build:**
   - `npm run build` debe completar sin errores. Output debe incluir `theme-*.css` con tokens Carbon.
2. **Test suite:**
   - `vendor/bin/pest` → **144/144 verde**.
   - El test `piv_resource_uses_action_group_for_row_actions` (Bloque09bUxTest:20) sigue pasando — el icon `heroicon-m-ellipsis-vertical` se conserva.
3. **Servidor local:**
   - `php artisan serve --host=127.0.0.1 --port=8000` arranca limpio en background.
   - `curl -sI http://127.0.0.1:8000/up` → 200.
   - `curl -sI http://127.0.0.1:8000/admin/login` → 200.
   - `curl -sI http://127.0.0.1:8000/admin/pivs` → 200 o 302.
4. **CI:**
   - Push debe pasar 3/3 jobs (PHP 8.2, PHP 8.3, Vite build).

## Smoke real obligatorio (post-merge, a cargo del usuario)

CSS + theme + paleta no se testean en CI. Usuario debe abrir Safari (con Cmd+Opt+R para forzar reload sin caché) y validar:

1. **Tipografía**:
   - Body en Plex Sans 400 weight, 14px size, micro-tracking 0.16px (debe verse "abierto" en columnas pequeñas).
   - Headings de página en Plex Sans 400 weight 24px (Heading 03).
   - Card titles en Plex Sans 600 weight 20px (Heading 04).
   - IDs/paradas/fechas en Plex Mono.
2. **Color**:
   - Body bg blanco puro (NO el off-white anterior `#FAFAF7`).
   - Botones primarios en Blue 60 (`#0F62FE`).
   - Status badges en Red 60 / Green 50 / Yellow 30.
   - Hover de filas en Gray 10 (`#F4F4F4`).
3. **Forma**:
   - Cards 0px radius (rectangulares).
   - Botones 0px radius.
   - Inputs SIN borde lateral, solo línea inferior 2px (transparent default, Blue 60 al focus, Red 60 en error).
   - Status pills SÍ rounded (24px → visualmente pill).
4. **Sticky kebab + ActionGroup compacto**:
   - `/admin/pivs` muestra solo el icono kebab vertical (~32px), NO el botón ancho "⋮ Acciones".
   - El kebab queda pinneado a la derecha del viewport.
   - Sombra sutil aparece al hacer scroll horizontal en la tabla.
   - Click → menú con las 5 acciones, sin truncar.
5. **Wordmark "Winfin *PIV*"**:
   - La "f" sigue mostrándose en Instrument Serif italic (excepción única documentada).
   - Resto del wordmark en Plex Sans 600.
6. **Asignaciones sidebar**:
   - `/admin/asignaciones` accesible desde sidebar bajo "Operaciones".
   - Tabla se ve consistente con `/admin/pivs`.
7. **Dark mode** (opcional — el toggle de modo no está implementado, solo respeta `prefers-color-scheme`):
   - Si Safari está en modo oscuro: bg Gray 100, text Gray 10, accent Blue 40.

## Definition of Done

- 1 commit nuevo encima de `6e61578` (commit DESIGN.md):
  - `feat(theme): implement IBM Carbon tokens across tailwind, theme.css, panel provider`
- (Opcional, si se prefiere atomicidad) 2 commits separados:
  - `feat(theme): pivot to IBM Carbon tokens` (los 4 primeros cambios).
  - `chore(filament): drop ActionGroup button mode for compact kebab` (PivResource).
- Push a `bloque-09d-design-pivot-carbon`. PR #23 sale del estado "draft" y queda mergeable.
- CI 3/3 verde.
- 144/144 tests verde.
- Working tree clean tras el push.

## Reporte final que Copilot debe entregar

- SHA(s) del commit(s) nuevo(s).
- Diff resumen (archivos modificados + líneas).
- Estado CI tras el push.
- Confirmación de que `Bloque09bUxTest::piv_resource_uses_action_group_for_row_actions` sigue verde.
- Confirmación HTTP de los 3 endpoints del smoke local (`/up`, `/admin/login`, `/admin/pivs`).
- Lista visual pendiente para el usuario (los 7 puntos del smoke real arriba).
- Nota explícita si hubo que pivotar en algún punto (selector CSS de input no funcionó como esperado, etc.) — recordar la regla del checklist: pivots silenciosos = banderazo rojo.
