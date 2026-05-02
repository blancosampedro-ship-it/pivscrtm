# Design System — Winfin PIV

> Sistema visual de la app nueva. **Carbon corporate-engineering**: cuadrícula 8px estricta,
> rectángulos sin redondear, tipografía IBM Plex como columna vertebral, profundidad por
> capas de color (no por sombras), un único acento — Blue 60 — para todo lo interactivo.
> Esta es la **única fuente de verdad** para cualquier decisión visual o de UI. Cualquier
> deviation requiere actualizar este archivo y dejar entrada en el log de decisiones al final.

---

## 1. Product Context

- **Qué es:** CMMS interno para gestionar 575 paneles de información al viajero (PIVs) instalados en marquesinas de bus. Sustituye a una app PHP legacy (2014) sin downtime.
- **Para quién:** tres roles muy distintos.
  - Admin (1 persona) — back-office en escritorio (Filament). Densidad alta de información.
  - Técnico (3 activos) — PWA móvil. En la calle, posiblemente con sol y guantes. Pocas decisiones, big touch targets.
  - Operador cliente (41 empresas) — escritorio, baja frecuencia. Reporta averías y consulta histórico.
- **Espacio / categoría:** software B2B operacional (CMMS + field service). Single tenant Winfin Systems S.L.
- **Tipo de proyecto:** app web híbrida (admin Filament + PWA móvil + portal operador). NO marketing site.
- **Frase brújula:** **"Serio, claro y profesional."** Toda decisión visual posterior se justifica contra esta frase.

---

## 2. Aesthetic Direction

- **Direction:** **IBM Carbon — corporate-engineering precision.** La app es la materialización digital de una herramienta industrial: sistema de tokens semánticos sobre cuadrícula 8px estricta, densidad productiva, profundidad por capas de color (white → Gray 10 → Gray 20) en lugar de sombras, rectángulos sin redondear como firma estilística. Lee como spec de ingeniería renderizada como UI.
- **Decoration level:** **cero**. Sin gradientes, sin patterns, sin shadows en cards o tiles, sin iconos en círculos coloreados, sin corner-rounding en buttons/inputs/cards. La planitud ES la identidad. Los shadows existen únicamente en elementos flotantes (dropdowns, tooltips, modales).
- **Mood:** información densa, escaneable, sin ceremonia. Power user dashboard. La foto del panel siempre cerca del nombre porque el ojo humano la usa antes que el código de parada.
- **Reference principal:** [IBM Carbon Design System](docs/references/ibm-carbon-design.md) (extraído del sitio público de IBM, no es la spec oficial pero captura los principios). Ver también `docs/references/ibm-carbon-preview.html` para la previsualización interactiva de tokens.
- **Anti-referentes:** limble.com, getmaintainx.com, filamentphp.com default, Material Design redondeado, cualquier dashboard con shadows en cards.

**Pivot histórico:** la versión inicial fue "Editorial industrial española" con Instrument Serif + General Sans (29 abr 2026). Pivot intermedio a "Modern SaaS — Airtable Mode" con cobalto `#1D3F8C` (1 may 2026). Pivot definitivo a IBM Carbon (2 may 2026) tras decisión del usuario de adoptar el sistema completo. Ver §11 Decisions Log.

---

## 3. Typography

> Carbon's productive type scale. Tres pesos funcionales (300 display / 400 body / 600 emphasis), nunca 700. IBM Plex Sans + Plex Mono como sistema; **Instrument Serif italic conservado como excepción única y deliberada en el wordmark "Winfin *PIV*"** (no es regla general — ver §3 anti-patrones).

### Familias

| Familia | Uso | Pesos cargados |
|---|---|---|
| **IBM Plex Sans** | Toda la chrome — body, UI, forms, headings, labels, navigation, table columns | 300, 400, 600 |
| **IBM Plex Mono** | Datos tabulares — `piv_id`, `parada_cod`, n_serie, fechas, métricas, captions monoespaciadas | 400, 500, 600 |
| **Instrument Serif** *(excepción única)* | Solo en el wordmark "Winfin *PIV*" — la "f" italic es el gesto residual de identidad | 400 italic |

### Escala (Carbon Productive)

| Rol | Tamaño | Weight | Line height | Letter spacing | Notas |
|---|---|---|---|---|---|
| Display 01 | 60px (3.75rem) | **300** Light | 1.17 | 0 | Marketing/hero solo (rara vez en CMMS). Lightness = elegancia corporativa. |
| Display 02 | 48px | 300 Light | 1.17 | 0 | |
| Heading 01 | 42px | 300 Light | 1.19 | 0 | Page hero expressive. |
| Heading 02 | 32px | 400 Regular | 1.25 | 0 | Section headings. |
| Heading 03 | 24px | 400 Regular | 1.33 | 0 | `<h1>` de página standard en Filament. |
| Heading 04 | 20px | **600** Semibold | 1.40 | 0 | Card titles, feature headers. |
| Body Long 01 | 16px | 400 Regular | 1.50 | 0 | Texto de lectura larga. |
| Body Long 02 | 16px | 600 Semibold | 1.50 | 0 | Énfasis en body, labels destacados. |
| Body Short 01 | 14px | 400 Regular | 1.29 | **0.16px** | UI body default. |
| Body Short 02 | 14px | 600 Semibold | 1.29 | **0.16px** | Bold captions, nav items, table column headers. |
| Caption 01 | 12px | 400 Regular | 1.33 | **0.32px** | Metadata, timestamps, helper text, group labels. |
| Code 01 | 14px | 400 Regular | 1.43 | 0.16px | IBM Plex Mono. Inline code, table data IDs/paradas/fechas. |
| Code 02 | 16px | 400 Regular | 1.50 | 0 | IBM Plex Mono. Code blocks. |

### Reglas Carbon obligatorias

- **Light weight (300) en display 42px+**: la lightness en sizes grandes es deliberada — Carbon's signature.
- **Micro-tracking en small sizes**: 0.16px en 14px, 0.32px en 12px. Carbon's secret weapon para legibilidad densa. NO aplicar tracking en sizes ≥16px.
- **Tres pesos funcionales**: 300 (display) · 400 (body) · 600 (emphasis). **Nunca 700 Bold** — Carbon explícitamente lo descarta.
- **Productive vs Expressive**: los body sizes usan line-heights productivas (1.29) para densidad UI. Los display sizes respiran (1.40-1.50) para hero/marketing.

### Carga de fuentes (RGPD-friendly via Bunny Fonts)

```css
@import url("https://fonts.bunny.net/css?family=ibm-plex-sans:300,400,600&display=swap");
@import url("https://fonts.bunny.net/css?family=ibm-plex-mono:400,500,600&display=swap");
@import url("https://fonts.bunny.net/css?family=instrument-serif:400i&display=swap");
```

Stack CSS:

```css
--font-sans:  "IBM Plex Sans", "Helvetica Neue", Arial, sans-serif;
--font-mono:  "IBM Plex Mono", Menlo, Courier, monospace;
--font-serif: "Instrument Serif", ui-serif, Georgia, serif;  /* SOLO wordmark */
```

Activar tabular-nums por defecto en datos numéricos:

```css
.mono, [data-mono], [class*="kpi"], [data-numeric] {
    font-variant-numeric: tabular-nums;
}
```

### Wordmark "Winfin *PIV*" — excepción documentada

El wordmark conserva Instrument Serif italic en la "f" como decisión deliberada de identidad de marca, mezclada con IBM Plex Sans 600 en el resto del logo. Es la **única** ocurrencia de serif en toda la app. Justificación: pequeño guiño editorial que diferencia a Winfin de la pureza corporativa total de Carbon, sin comprometer la coherencia del sistema (un solo elemento, un solo lugar — sidebar header y top bar).

Implementación:

```html
<span class="brand">Win<em>f</em>in <strong>PIV</strong></span>
```

```css
.brand            { font-family: var(--font-sans); font-weight: 600; letter-spacing: -0.005em; }
.brand em         { font-family: var(--font-serif); font-style: italic; font-weight: 400; }
.brand strong     { font-weight: 600; }
```

### Anti-patrones tipográficos (NUNCA)

- ❌ Inter, Roboto, Arial, Helvetica, Open Sans, Lato, Montserrat, Poppins, Geist, General Sans, Space Grotesk como fuente sans principal.
- ❌ `system-ui` / `-apple-system` como fuente principal.
- ❌ Instrument Serif fuera del wordmark "Winfin *PIV*" (sidebar header + top bar). Cualquier otro uso es violación.
- ❌ Tipografía no-monospaced para `piv_id`, `parada_cod`, `n_serie`, fechas en tabla. Plex Mono obligatoria.
- ❌ Weight 700 (Bold). El tope productivo es 600 Semibold.
- ❌ Letter-spacing en sizes ≥16px (display y body normal). Solo en 14px (0.16px) y 12px (0.32px).
- ❌ Más de tres familias activas (sans + mono + 1 serif residual del wordmark).

---

## 4. Color

> Paleta Carbon. Monocroma + Blue 60 como único acento interactivo. Profundidad lograda
> apilando capas de gris (no shadows). Status colors mapean a la spec Carbon: Red 60 / Green 50 / Yellow 30.

### Tokens semánticos (mapean 1:1 a Carbon `--cds-*`)

| Token Winfin | Light | Dark | Carbon equivalente | Uso |
|---|---|---|---|---|
| `--bg-base` | `#FFFFFF` | `#161616` | `--cds-background` | Fondo principal de página. |
| `--bg-layer-01` | `#F4F4F4` | `#262626` | `--cds-layer-01` | Cards, tiles, alternating sections, hover de filas. |
| `--bg-layer-02` | `#E0E0E0` | `#393939` | `--cds-layer-02` | Elevated panels dentro de Layer 01. |
| `--bg-field` | `#F4F4F4` | `#262626` | `--cds-field` | Background de inputs. |
| `--bg-hover` | `#E8E8E8` | `#333333` | `--cds-layer-hover-01` | Hover de cards/tiles clickables. |
| `--text-primary` | `#161616` | `#F4F4F4` | `--cds-text-primary` | Cuerpo, headings, navbar (en surfaces oscuras). |
| `--text-secondary` | `#525252` | `#C6C6C6` | `--cds-text-secondary` | Helper text, descriptions. |
| `--text-placeholder` | `#6F6F6F` | `#6F6F6F` | `--cds-text-placeholder` | Placeholders, disabled. |
| `--text-on-color` | `#FFFFFF` | `#FFFFFF` | `--cds-text-on-color` | Texto sobre Blue 60 / Red 60 / Gray 100. |
| `--border-subtle` | `#C6C6C6` | `#393939` | `--cds-border-subtle` | Bottom-border de inputs, dividers, hairlines de tabla. |
| `--border-strong` | `#8D8D8D` | `#6F6F6F` | `--cds-border-strong` | Borders activos. |
| **`--accent`** | **`#0F62FE`** | **`#78A9FF`** | **`--cds-link-primary` / `--cds-button-primary`** | **Blue 60. Único acento interactivo.** |
| `--accent-hover` | `#0353E9` | `#A6C8FF` | `--cds-button-primary-hover` | Hover de Blue 60. |
| `--accent-active` | `#002D9C` | `#4589FF` | `--cds-button-primary-active` | Pressed/active. |
| `--accent-soft` | `#EDF5FF` | `#001141` | `--cds-highlight` | Selected row background, blue tint. |
| `--focus` | `#0F62FE` | `#FFFFFF` | `--cds-focus` | 2px inset border en elementos con focus. |
| `--focus-inset` | `#FFFFFF` | `#161616` | `--cds-focus-inset` | Inner ring de focus en surfaces oscuras. |
| `--support-error` | `#DA1E28` | `#FA4D56` | `--cds-support-error` | Red 60. Error, danger, regla #11 stripe avería. |
| `--support-error-soft` | `#FFF1F1` | `#2D0709` | — | Tinte de fondo error. |
| `--support-success` | `#24A148` | `#42BE65` | `--cds-support-success` | Green 50. Success, regla #11 stripe revisión, status "Operativo". |
| `--support-success-soft` | `#DEFBE6` | `#022D0D` | — | Tinte de fondo success. |
| `--support-warning` | `#F1C21B` | `#F1C21B` | `--cds-support-warning` | Yellow 30. Revisión vencida, alerts no críticos. |
| `--support-warning-soft` | `#FCF4D6` | `#3B2200` | — | Tinte de fondo warning. |
| `--support-info` | `#0F62FE` | `#78A9FF` | `--cds-support-info` | Reutiliza `--accent`. |

### Justificación de Blue 60 como único acento

Blue 60 (`#0F62FE`) es el azul corporativo de IBM Carbon — saturado, vivo, claramente interactivo. Reemplaza el cobalto editorial `#1D3F8C` previo (RENFE/Metro español) por coherencia con un sistema reconocible mundialmente. Coste: se pierde el guiño a la señalética pública española. Beneficio: alineación total con un sistema documentado, reconocible, y que el equipo puede consultar externamente sin ambigüedad.

### Modo oscuro (Carbon Gray 100 Theme)

- **No es invertir colores** — es redibujado siguiendo Carbon's Gray 100 theme.
- Background base: Gray 100 (`#161616`). Layer 01: Gray 90. Layer 02: Gray 80.
- Acento se aclara a Blue 40 (`#78A9FF`) para legibilidad sobre fondos oscuros.
- Activación: respeta `prefers-color-scheme` por defecto + toggle manual persistido en `localStorage`.

### Profundidad por capas de color (NO shadows)

Carbon logra jerarquía visual apilando surfaces de gris progresivamente:

```
Page      (white, #FFFFFF)
  └── Card (Gray 10, #F4F4F4)
        └── Elevated panel (Gray 20, #E0E0E0)
```

Las shadows existen ÚNICAMENTE en elementos flotantes que se despegan visualmente del contenido (dropdowns, tooltips, modales, side panels). Ver §6 Depth & Elevation.

### Anti-patrones de color (NUNCA)

- ❌ Gradientes (`linear-gradient`, `bg-gradient-to-*`) en cualquier elemento.
- ❌ Más de un acento. Blue 60 es el único — no introducir secondary accents.
- ❌ Verde lime (`#84CC16`), rojo bright Tailwind (`#EF4444`), amarillo bright (`#FACC15`). Status colors son los de Carbon: `#DA1E28` / `#24A148` / `#F1C21B`.
- ❌ El cobalto deeper `#1D3F8C` (token de la era editorial). Migrado a Blue 60.
- ❌ El warm off-white `#FAFAF7` (token de la era editorial). Migrado a `#FFFFFF` puro.
- ❌ Iconos en círculos de colores ("3-column feature grid" SaaS pattern).
- ❌ Box-shadow en cards, tiles, inputs. Solo en floating UI (dropdowns/modales).

### Contraste mínimo

- Texto primario (`--text-primary`) sobre cualquier fondo: **WCAG AAA (≥ 7:1)**. Justificación: el técnico lee en pantalla bajo sol.
- Texto secondary: WCAG AA (≥ 4.5:1).
- Bordes: visibles sin necesidad de zoom.

---

## 5. Spacing

- **Base unit:** **8px (Carbon 2x grid).** Cada valor de spacing es divisible por 8, con 2px y 4px reservados para micro-ajustes (border thickness, hairlines).
- **Density:**
  - Admin Filament: **productive** — Carbon-style densidad alta, row height 36-40px en tablas.
  - PWA técnico: **override por contexto físico** — touch targets ≥ 88px en botones-tarjeta principales, ≥ 56px en secundarios. Esta es una regla de producto (guantes y sol), NO desviación estética. Carbon's standard 48px no aplica al técnico.
  - Operador cliente: productive (escritorio, baja frecuencia).
- **Escala Carbon:**

| Token | px | Uso típico |
|---|---|---|
| `2xs` | 2 | Borders, hairlines |
| `xs`  | 4 | Padding interno mínimo |
| `sm`  | 8 | Padding de inputs, gap icono-texto, mini-unit Carbon |
| `md`  | 16 | Padding de cards, gap entre filas, container default |
| `lg`  | 24 | Padding de paneles, separación entre secciones cortas |
| `xl`  | 32 | Padding de page containers, gutter de columnas |
| `2xl` | 48 | Major section transitions (Carbon's "consistent 48px rhythm") |
| `3xl` | 64 | Separación entre páginas/zones |
| `4xl` | 96 | Hero sections (rara vez en CMMS) |

Anti-patrón: **valores arbitrarios fuera del 8px grid**. Si una distancia "no encaja", el problema es el layout, no el grid.

---

## 6. Layout

- **Approach:**
  - Admin/operador (Filament): **grid-disciplinado Carbon**. La composición default de Filament respeta esto.
  - PWA técnico: **single column generosa** — ancho completo del viewport menos 16px de padding lateral.
- **Grid (admin):**
  - `≥ 1056 px` (Carbon Large): sidebar 220px + main flex.
  - `672–1055 px` (Carbon Medium): sidebar colapsada a iconos + main flex.
  - `< 672 px` (Carbon Small): drawer.
- **Max content width** en main: 1180px. Paneles de detalle: 880px (legibilidad).
- **Whitespace philosophy**: **functional density**. Carbon favorece densidad productiva sobre whitespace expansivo. La separación entre secciones se logra mediante alternancia de background-color (white → Gray 10 → white), no mediante márgenes verticales gigantes.

### Border radius — la regla rectangular

| Componente | Radius |
|---|---|
| **Buttons** (primary, secondary, tertiary, danger) | **0px** |
| **Inputs** (text, select, textarea) | **0px** |
| **Cards / tiles / panels** | **0px** |
| **Modales / drawers / side panels** | **0px** |
| **Status pills / tags** | **24px** (excepción Carbon — pill shape) |
| **Avatars / icon containers circulares** | **50%** |

**Cero deviation.** Los rectángulos sin redondear son la firma de Carbon. Si algo "se ve raro sin radius", el problema es el espaciado o el contraste, no el radius.

---

## 7. Depth & Elevation

| Level | Treatment | Uso |
|---|---|---|
| Flat (Level 0) | Sin shadow, `--bg-base` | Default page surface |
| Layer 01 | Sin shadow, `--bg-layer-01` | Cards, tiles, alternating sections |
| Layer 02 | Sin shadow, `--bg-layer-02` | Elevated panels dentro de Layer 01 |
| Raised | `0 2px 6px rgba(0,0,0,0.3)` | **Solo** dropdowns, tooltips, overflow menus |
| Overlay | `0 2px 6px rgba(0,0,0,0.3)` + dark scrim | **Solo** modales, side panels |
| Focus | `2px solid #0F62FE` inset + `1px solid #FFFFFF` | Keyboard focus ring |
| Bottom-border | `2px solid #161616` bottom edge | Active input, active tab indicator |

**Shadow philosophy**: Carbon es deliberadamente shadow-averse. La profundidad se logra apilando capas de gris (white → Gray 10 → Gray 20). Las shadows están reservadas para elementos verdaderamente flotantes — esto da a la rara shadow significado real. Cuando algo "flota" en Carbon, importa.

---

## 8. Motion

- **Approach:** mínima funcional. Solo transiciones que ayudan a comprender estado. Cero animaciones decorativas.
- **Easing Carbon:**
  - Productive (UI moves, micro): `cubic-bezier(0.2, 0, 0.38, 0.9)` — easing.standard.productive.
  - Expressive (hero animations, large surface changes): `cubic-bezier(0.4, 0.14, 0.3, 1)` — easing.standard.expressive.
- **Duración Carbon:**
  - Fast 01: **70ms** — micro-interactions (color, opacity).
  - Fast 02: **110ms** — hover, focus.
  - Moderate 01: **150ms** — small UI moves.
  - Moderate 02: **240ms** — modals, drawers, side panels (Carbon default).
  - Slow 01: **400ms** — large surface transitions.
  - Slow 02: **700ms** — solo expressive contexts.
- **Reduce motion:** respetar `prefers-reduced-motion: reduce` desactivando todas las transiciones no esenciales.

---

## 9. Iconografía

- **Set:** **Heroicons** (ya integrado nativamente con Filament 3.2 vía `heroicon-o-*` y `heroicon-m-*`). NO migrar a IBM Carbon Icons — el coste de churn no compensa la pureza Carbon.
- **Tamaños Carbon:** **20px** default (Carbon icon size), 16px en chips/captions, 24px en hero/headers.
- **Stroke / weight:** Heroicons-outline (`heroicon-o-*`) por defecto. Para iconos pequeños y action triggers (kebab, close), usar Heroicons-mini (`heroicon-m-*`). Hereda `currentColor`.
- **Anti-patrón:** iconos rellenos de color, iconos en círculos coloreados, iconos decorativos sin función.

---

## 10. Aplicación al stack

### 10.1 Filament 3.2

- **Theme custom:** `resources/css/filament/admin/theme.css` (override completo). Bloque 09d aplica el pivot Carbon: tokens, fonts pesos 300/400/600, 0px border-radius en buttons/cards/inputs, bottom-border en inputs, sticky actions column con tokens Carbon.
- **Tailwind config — primary color como Blue 60:**

```js
primary: {
  10:  '#EDF5FF',  // accent-soft
  40:  '#78A9FF',  // dark mode accent
  60:  '#0F62FE',  // accent — Carbon Blue 60
  70:  '#0353E9',  // hover
  80:  '#002D9C',  // active
  90:  '#001D6C',
}
```

- **Densidad obligatoria:** `->striped()` + `->paginated([25, 50, 100])` + row height 36-40px. Tabla tipo Carbon DataTable, no spaced-out.
- **Pattern Resource (parent-child IA):**
  - Tabla con `ImageColumn::make('thumbnail_url')` 28×28 0px-radius en primera columna.
  - `ViewAction::make()->slideOver()->infolist(...)` para side-panel inspector.
  - `ActionGroup` icon-only (NUNCA `->button()`) — kebab compact `heroicon-m-ellipsis-vertical`.
  - Columnas pequeñas: `text-sm` Plex Mono para IDs/paradas (con `data-mono`), `text-sm` Plex Sans para dirección/municipio.
  - Status pills via `->badge()` mapeados a tokens Carbon (success/warning/danger/info).

- **Inputs Carbon (override del default Filament):**
  - Background: `--bg-field` (`#F4F4F4`).
  - Border: ninguno arriba/lados, `2px solid transparent` abajo.
  - Focus: `2px solid #0F62FE` bottom-border.
  - Error: `2px solid #DA1E28` bottom-border.
  - Border-radius: 0px.

- **Buttons Carbon:**
  - Primary: bg `#0F62FE`, text white, 0px radius, height 48px, padding `14px 16px`.
  - Secondary: bg `#393939` (Gray 80), text white.
  - Tertiary: bg transparent, border `1px solid #0F62FE`, text Blue 60.
  - Ghost: bg transparent, text Blue 60, hover `#E8E8E8` background.
  - Danger: bg `#DA1E28` Red 60.

- **Sidebar:** background `--bg-base` (`#FFFFFF`) light / Gray 100 dark. Top bar wordmark "Winfin *PIV*" en clase `.brand` con la f italic Instrument Serif.

### 10.2 PWA técnico (Livewire + Volt + Tailwind 3)

- Mismos tokens que Filament (compartir `app.css` + theme.css).
- **Diferencia clave:** padding y touch targets más generosos por contexto físico (guantes/sol). Utilidad `tap-target` (height ≥ 88px) en botones primarios.
- `<meta name="theme-color" content="#0F62FE">` en el shell PWA.
- Manifest `theme_color` y `background_color` derivan de los tokens Carbon.

### 10.3 Tailwind config esqueleto

```js
extend: {
  colors: {
    primary: { /* Blue 60 scale, ver arriba */ },
    success: '#24A148',
    warning: '#F1C21B',
    error:   '#DA1E28',
    info:    '#0F62FE',
    layer: {
      1: '#F4F4F4',
      2: '#E0E0E0',
    },
  },
  fontFamily: {
    sans:  ['"IBM Plex Sans"', 'Helvetica Neue', 'Arial', 'sans-serif'],
    mono:  ['"IBM Plex Mono"', 'Menlo', 'Courier', 'monospace'],
    serif: ['"Instrument Serif"', 'ui-serif', 'Georgia', 'serif'],  // SOLO wordmark
  },
  borderRadius: {
    none: '0',
    DEFAULT: '0',  // Carbon default: 0px en todo
    pill: '24px',   // status pills, tags
    full: '9999px', // avatars
  },
}
```

---

## 11. Patrones críticos del producto

### 11.1 Separación tajante avería real / revisión mensual (regla #11)

Esta es la decisión visual **más importante** de toda la app. Implementación obligatoria en el técnico:

- Dos action-cards apiladas, **nunca** dos botones del mismo color.
- Card "avería real": stripe izquierdo `--support-error` (4px solid), tinte de fondo `--support-error-soft`, icono `heroicon-o-exclamation-triangle`, título "Reportar avería real" en Plex Sans Semibold.
- Card "revisión mensual": stripe izquierdo `--support-success` (4px solid), tinte de fondo `--support-success-soft`, icono `heroicon-o-check-circle`, título "Registrar revisión mensual" en Plex Sans Semibold.
- Subtítulo desambiguador obligatorio: "Hay un fallo. Crear parte correctivo." vs "Todo OK. Checklist mensual rutinario."
- En cualquier vista que liste asignaciones (admin o técnico), distinguir las dos con el mismo lenguaje cromático: stripe + tinte + icono.

Ver [ADR-0004](docs/decisions/0004-revision-vs-averia-ux.md).

### 11.2 RGPD en exports al cliente (regla #3)

- Cualquier export (CSV, PDF, email) que vaya al operador-cliente solo puede mostrar `tecnico.nombre_completo`.
- Visualmente: en cards de técnico mostradas al operador, NO renderizar campos sensibles (DNI, NSS, teléfono, dirección, email). El componente Filament debe diferenciar `TecnicoCard::forAdmin()` vs `TecnicoCard::forOperador()`.

### 11.3 Status pills

| Estado | Token | Background | Texto | Dot color |
|---|---|---|---|---|
| Operativo | success | `--support-success-soft` (`#DEFBE6`) | `--support-success` (`#24A148`) | `#24A148` |
| Revisión vencida | warning | `--support-warning-soft` (`#FCF4D6`) | `#8A6A00` | `#F1C21B` |
| Avería abierta | error | `--support-error-soft` (`#FFF1F1`) | `--support-error` (`#DA1E28`) | `#DA1E28` |
| Sin operador | error | `--support-error-soft` | `--support-error` | `#DA1E28` |
| Desinstalado | neutral | `#F4F4F4` (Gray 10) | `#525252` (Gray 70) | `#8D8D8D` |

Implementación: pill `border-radius: 24px`, padding `4px 8px`, dot 6px del color saturado a la izquierda, fondo `*-soft`, texto del color saturado. Excepción Carbon (24px en lugar de 0px) — los pills son la única forma rounded permitida en chrome.

### 11.4 Parent-child IA: averías se consultan desde el panel; asignaciones tienen sidebar propio

**Averías** NO viven como entries top-level del menú primario. Pertenecen al panel afectado y se consultan dentro de la View page del panel:

- `/admin/pivs` → click panel → View page con dos secciones apiladas:
  1. **Detalles** — infolist con foto + 5 secciones (Bloque 07d).
  2. **Histórico de averías** — tabla densa server-rendered (Blade) filtrada al panel. Columna "Tipo" muestra el tipo de asignación asociada (Correctivo/Revisión/Sin asignar) con badge cromático regla #11. Muestra hasta 50 últimas; reportes históricos más amplios viven en Bloque 10.

`AveriaResource` queda **oculta del sidebar** (`shouldRegisterNavigation = false`) pero accesible por URL para deep-links.

**Asignaciones** SÍ tienen entrada propia en el sidebar bajo el grupo **"Operaciones"** (Bloque 09b decision). Justificación: la cola de trabajo diaria del admin (asignaciones abiertas) es operacional, no investigativa — el admin necesita acceso directo. El badge `getNavigationBadge()` muestra la cuenta de asignaciones con `status=1` (abiertas), `null` cuando no hay ninguna.

**`Piv::asignaciones()`** es `HasManyThrough` (asignacion vía averia.piv_id). Filament 3 RelationManager NO soporta HasManyThrough — por eso AsignacionResource es página standalone con sidebar own, no RelationManager dentro de ViewPiv.

**Implementación View page del panel:** NO se usan Filament 3 RelationManagers (lazy mount roto contra modelos legacy con primary key custom — ver Bloque 08g). En su lugar, ViewPiv hace override de `protected static string $view` apuntando a un Blade custom (`view-piv.blade.php`) que renderiza el infolist + un partial server-rendered (`partials/averias-table.blade.php`) con la tabla.

Inspiración: la app vieja `winfin.es/paneles.php?action=edit&id=N#tabs-3` ya usaba tabs en la edit del panel — el patrón parent-child está validado por años de uso operativo.

---

## 12. Decisions Log

| Fecha | Decisión | Rationale |
|---|---|---|
| 2026-04-29 | Sistema visual creado | Generado por `/design-consultation`. Frase brújula: "serio, claro, profesional". Research vía 4 referentes (Limble, MaintainX, Tractian, Filament). Preview HTML wireframe aprobado por usuario. AI mockups pospuestos. |
| 2026-04-29 | Azul cobalto `#1D3F8C` como único acento | Coherente con la señalética pública española (RENFE, Metro). **Reemplazado 2026-05-02 por Blue 60 Carbon.** |
| 2026-04-29 | Instrument Serif + General Sans como únicas fuentes | Editorial serif para titulares y KPIs. **Reemplazado 2026-05-01 por IBM Plex Sans + Plex Mono.** |
| 2026-04-29 | Action-cards apiladas con stripe lateral para separar avería/revisión | Implementación visual de la regla #11. Stripe + tinte + icono + subtítulo desambiguador = imposible confundir los dos flujos. |
| 2026-05-01 | Pivot a "Modern SaaS — productive precision" (Airtable-Mode). Reemplazar Instrument Serif + General Sans por IBM Plex Sans + Plex Mono. Mantener Instrument Serif SOLO en wordmark. | Smoke real post-Bloque 07 con datos prod reveló que el serif en chrome lee como prensa cultural, no como herramienta diaria de operaciones. `/design-shotgun` con 3 variantes (Linear / Stripe / Airtable) — usuario eligió Airtable-Mode (variante C) por densidad alta + side panel inspector + group-by + galería multi-imagen. Cobalto y restraint cromático preservados. |
| 2026-05-02 | IA refactorizada a parent-child con tabs en ViewPiv | Bloque 08 inicial puso averías peer-level con paneles — incorrecto. La app vieja siempre usó tabs (`#tabs-3`). Bloque 08d corrige la arquitectura. |
| 2026-05-02 | AsignacionesRelationManager dropped | Filament 3 RelationManager no soporta `HasManyThrough` (limitación documentada). Bloque 08e drop. Info de asignación se mantiene visible vía columnas tipo/horario/status en AveriasRM. |
| 2026-05-01 | AveriasRelationManager eliminado, reemplazado por tabla server-rendered | Filament 3 RM + Livewire 3 lazy mount fase 2 no rehidrata `$ownerRecord` antes de `bootedInteractsWithTable` → `Table::getModel()` devuelve null → `TypeError "::class on null"`. Workaround Bloque 08g: ViewPiv override `$view` → Blade custom que compone infolist + partial tabla HTML pura. |
| 2026-05-02 | AsignacionResource restaurado al sidebar (grupo "Operaciones") | Bloque 08d lo había ocultado. Smoke real reveló que la cola de asignaciones es uso operacional diario del admin — debe estar accesible directamente. AveriaResource permanece oculto (per-panel via tabs sigue siendo correcto para investigación de averías). Bloque 09b. |
| **2026-05-02** | **Pivot completo a IBM Carbon Design System.** Reemplazar paleta cobalto/off-white por Carbon tokens (Blue 60 acento, white base, Gray 100 text, Gray 10 layer). Border-radius 0px en buttons/cards/inputs (Carbon signature). Inputs bottom-border-only (firma Carbon). Type scale Carbon Productive (300/400/600, micro-tracking 0.16/0.32px). Profundidad por capas de color, no shadows. Status: Red 60 / Green 50 / Yellow 30. Heroicons mantenidos (no migrar a IBM Carbon Icons). | Usuario decidió adoptar el sistema completo tras evaluar `docs/references/ibm-carbon-design.md`. Beneficios: sistema reconocible mundialmente, semantic tokens documentados externamente, coherencia industrial-engineering alineada con el dominio (CMMS B2B). Coste: pierde el guiño cromático a la señalética española (cobalto deeper) y la calidez del off-white. **Excepción documentada:** wordmark "Winfin *PIV*" conserva Instrument Serif italic en la "f" como decisión deliberada de identidad — único uso de serif en la app, NO regla general. Implementación en Bloque 09d (theme.css + tailwind config + sticky actions con tokens Carbon + ActionGroup icon-only). |
