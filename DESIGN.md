# Design System — Winfin PIV

> Sistema visual de la app nueva. Editorial industrial española: tipografía hace el trabajo
> pesado, espacio en blanco hace el resto, color como acento puntual con significado.
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

- **Direction:** **Modern SaaS — productive precision.** Híbrido tipo Airtable / Linear / Stripe Dashboard / Notion databases — herramienta densa de operaciones diarias, no documento editorial. Tipografía técnica humanista, restraint cromático mantenido, foto del panel como dato visual primario, side-panel inspector para triage rápido sin navegar de página.
- **Decoration level:** mínimo. Cero gradientes, cero patterns, cero blobs decorativos, cero iconos en círculos coloreados. Borders 1px hairline + cobalto solo en acentos puntuales (CTA primario, fila seleccionada, hover, active state).
- **Mood:** la app debe sentirse como una **base de datos productiva con personalidad técnica** (Airtable + Plex). Power user dashboard. Información densa, escaneable, sin ceremonia. Foto del panel siempre cerca del nombre porque el ojo humano la usa antes que el código de parada.
- **Reference sites estudiados:**
  - https://airtable.com (referente principal: spreadsheet hybrid + side panel inspector + group-by toggles).
  - https://linear.app (referente secundario: tabla densa, hover refinado, Geist típography).
  - https://stripe.com/dashboard (referente terciario: card grids cuando aplica, restraint elegante).
  - https://notion.so/databases (similar al referente principal).
  - Anti-referentes: limble.com, getmaintainx.com, filamentphp.com default.

**Pivot de dirección (1 may 2026):** la versión inicial fue "Editorial industrial española" con Instrument Serif + General Sans (29 abr 2026). Smoke real con datos prod reveló que el serif en chrome de UI lee como prensa cultural, no como herramienta diaria de operaciones. Tras `/design-shotgun` con 3 variantes (Linear / Stripe / Airtable), el usuario eligió Airtable-Mode. Ver §11 Decisions Log y `~/.gstack/projects/winfin-piv/designs/admin-pivs-list-saas-pivot-20260501/`.

---

## 3. Typography

> Dos familias técnicas (sans + mono) + un único gesto editorial residual (serif solo en wordmark). Ninguna otra fuente permitida.

| Rol | Fuente | Tamaño / weight | Notas |
|---|---|---|---|
| **Body / UI / formularios / tablas** | **IBM Plex Sans** (Bunny Fonts) | 12–15 px / 400, 500, 600, 700 | Toda la chrome de la app: labels, inputs, navegación, columnas de tabla, captions. Humanist grotesque con personalidad técnica más expresiva que un Helvetica neutro. |
| **Headings de página** | IBM Plex Sans | 20–24 px / 600 (semibold) | `<h1>` y `<h2>`. Letter-spacing tight (-0.005em). NUNCA serif. |
| **Section headers / labels small-caps** | IBM Plex Sans | 9–11 px / 600 + letter-spacing 0.06–0.10em + uppercase | Field labels en inspector, group headers ("OPERATIVOS · 561"), table column headers, sidebar section dividers. |
| **Tabular data (IDs, paradas, fechas, métricas)** | **IBM Plex Mono** (Bunny Fonts) | 10–14 px / 400, 500, 600 | TODOS los códigos: `piv_id` (`#00176`), `parada_cod` (`06036`), n_serie, fechas, contadores. `font-variant-numeric: tabular-nums` automático en mono. Usar también para counts en pills (`561`) y badges. |
| **Wordmark "Winfin *PIV*" (gesto editorial residual)** | **Instrument Serif** italic 400, mezclada con IBM Plex Sans 700 | 14–16 px | Único uso permitido. Solo en sidebar header y top bar. La "P" cursiva es el gesto distintivo de marca. |

### Carga de fuentes

- **Producción:** vía **Bunny Fonts** (`fonts.bunny.net`) — mirror RGPD-friendly de Google Fonts.
- **Stack CSS:**
  ```css
  --sans: "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif;
  --mono: "IBM Plex Mono", ui-monospace, "SF Mono", monospace;
  --display: "Instrument Serif", ui-serif, Georgia, serif;  /* SOLO wordmark */
  ```
- **Imports en `resources/css/app.css`:**
  ```css
  @import url("https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600,700&display=swap");
  @import url("https://fonts.bunny.net/css?family=ibm-plex-mono:400,500,600&display=swap");
  @import url("https://fonts.bunny.net/css?family=instrument-serif:400,400i&display=swap");
  ```
- **Activar tabular-nums por defecto en datos:**
  ```css
  .mono, [class*="kpi"], [data-numeric] { font-variant-numeric: tabular-nums; }
  ```

### Escala modular (ratio 1.200, más densa que la anterior 1.250)

`10 / 11 / 12 / 13 / 14 / 15 / 18 / 22 / 26 / 32 px`

Mapeo a Tailwind extendido:
- `text-2xs` 10px (small caps labels)
- `text-xs` 11px
- `text-sm` 12px (table data default)
- `text-base` 13px (UI body)
- `text-md` 14px (input values)
- `text-lg` 15px (card titles)
- `text-xl` 18px (section h2)
- `text-2xl` 22px (page h1 — semibold)
- `text-3xl` 26px (rare display)

Densidad alta significa muchas cosas a 12-13 px. Asegurar contraste WCAG AAA en esos tamaños.

### Anti-patrones tipográficos (NUNCA)

- ❌ Inter, Roboto, Arial, Helvetica, Open Sans, Lato, Montserrat, Poppins, Space Grotesk, General Sans como sans principal.
- ❌ Geist Sans (descartada en favor de Plex tras evaluar — Plex tiene más carácter humanista).
- ❌ `system-ui` / `-apple-system` como fuente principal.
- ❌ Instrument Serif fuera del wordmark "Winfin PIV".
- ❌ Tipografía no-monospaced para `piv_id`, `parada_cod`, `n_serie`, fechas en tabla. Plex Mono obligatoria.
- ❌ Mezclar más de tres familias (sans + mono + 1 serif residual del wordmark).

---

## 4. Color

> Paleta restraída con un único acento de marca. Los semánticos son versiones profundas
> (no "rojo brillante / verde lima"), más cercanas al lenguaje cromático de la señalización pública.

### Tokens

| Token | Light hex | Dark hex | Uso |
|---|---|---|---|
| `--bg-base` | `#FAFAF7` | `#0F1115` | Fondo principal. Off-white cálido en light, near-black en dark. |
| `--bg-surface` | `#FFFFFF` | `#1A1D23` | Cards, modales, info-rows. |
| `--bg-subtle` | `#F2F2EC` | `#14171C` | Hover de filas, bandas de tabla. |
| `--text-primary` | `#0F1115` | `#FAFAF7` | Cuerpo y titulares. |
| `--text-muted` | `#5A6068` | `#A8AEB6` | Secundario, labels. |
| `--text-faint` | `#8A8F96` | `#6E747C` | Captions, kickers, breadcrumbs. |
| `--border` | `#E5E5E0` | `#2A2E36` | Hairlines de cards y tablas. |
| `--border-strong` | `#C9C9C2` | `#3A3F49` | Borders de inputs y chips. |
| **`--accent`** | **`#1D3F8C`** | **`#5A7AC4`** | **Azul cobalto Winfin. Único acento de marca.** |
| `--accent-strong` | `#163070` | `#7C95D4` | Hover/active del acento. |
| `--accent-soft` | `#E6EBF5` | `#1A2440` | Tinte del acento (chips activos, pills). |
| `--success` | `#0F766E` | `#0F766E` | Teal profundo. NUNCA verde lime. |
| `--success-soft` | — | `#0F2926` | Tinte de fondo. Light usa `rgba(15,118,110,0.08)`. |
| `--warning` | `#B45309` | `#B45309` | Ámbar. NUNCA amarillo brillante. |
| `--warning-soft` | — | `#2E1F0A` | Tinte de fondo. Light usa `#FBEFD9`. |
| `--error` | `#B91C1C` | `#B91C1C` | Rojo profundo. NUNCA rojo bright. |
| `--error-soft` | — | `#2E0E0E` | Tinte de fondo. Light usa `#FBE6E6`. |
| `--info` | = `--accent` | = `--accent` | Reutilizamos el cobalto para info. |

### Justificación del azul cobalto `#1D3F8C`

Es el azul de la señalética pública española (RENFE, Metro). Carga "infraestructura, transporte, instituciones" en un microsegundo, que es exactamente el contexto del producto (paneles informativos en transporte público). Evita deliberadamente el `blue-600` de Tailwind (cliché SaaS) y el azul corporativo bright de MaintainX.

### Modo oscuro

- **No es invertir colores** — es redibujado.
- El acento se desatura ~15 % a `#5A7AC4` (más legible sobre fondos oscuros).
- Surfaces cálidas (`#1A1D23`, no `#000000`).
- Activación: respeta `prefers-color-scheme` por defecto + toggle manual persistido en `localStorage`.

### Anti-patrones de color (NUNCA)

- ❌ Gradientes (`linear-gradient`, `bg-gradient-to-*`) en cualquier elemento — botones, headers, fondos.
- ❌ Verde lime `#84CC16` o similares como success.
- ❌ Rojo bright `#EF4444` o similares como error.
- ❌ Más de un color de marca. Cobalto es el único.
- ❌ Iconos en círculos de colores ("3-column feature grid" SaaS pattern).

### Contraste mínimo

- Texto primario sobre cualquier fondo: **WCAG AAA (≥ 7:1)**. Justificación: el técnico lee en pantalla bajo sol.
- Texto muted: WCAG AA (≥ 4.5:1).
- Bordes: visibles sin necesidad de zoom.

---

## 5. Spacing

- **Base unit:** 4 px.
- **Density:**
  - Admin Filament: **comfortable** — la default de Filament es buena, no la tocamos.
  - PWA técnico: **generosa** — touch targets ≥ 88 px de alto en botones-tarjeta principales, ≥ 56 px en botones secundarios. Justificación: guantes y sol.
- **Escala:**

| Token | px | Uso típico |
|---|---|---|
| `2xs` | 2 | Borders, separación interna mínima |
| `xs` | 4 | Padding interno de chips |
| `sm` | 8 | Padding interno de inputs, gap entre icono y texto |
| `md` | 16 | Padding de cards, gap entre filas |
| `lg` | 24 | Padding de paneles, separación entre secciones cortas |
| `xl` | 32 | Padding de page containers, separación entre secciones medias |
| `2xl` | 48 | Separación entre bloques mayores |
| `3xl` | 64 | Separación entre páginas/headers |

---

## 6. Layout

- **Approach:**
  - Admin/operador (Filament): **grid-disciplinado** — la composición default de Filament respeta esto.
  - PWA técnico: **single column generosa** — ancho completo del viewport menos 16 px de padding lateral.
- **Grid (admin):**
  - `≥ 1024 px`: sidebar 220 px + main flex.
  - `768–1023 px`: sidebar colapsada a iconos + main flex.
  - `< 768 px`: drawer.
- **Max content width** en main: 1180 px. Paneles de detalle: 880 px (legibilidad).
- **Border radius:**
  - Cards: **8 px**.
  - Botones: **6 px**.
  - Pills / chips: **9999 px** (pill total).
  - **Nunca** "fully rounded" en cards o inputs — eso es bubble-radius / SaaS slop.

---

## 7. Motion

- **Approach:** **mínima funcional.** Solo transiciones que ayudan a comprender el estado. Cero animaciones decorativas.
- **Easing:**
  - Enter (aparición de elementos): `ease-out`.
  - Exit (desaparición): `ease-in`.
  - Move (cambios de estado): `ease-in-out`.
- **Duración:**
  - Micro (hover, focus, color): **150 ms**.
  - Short (tooltips, dropdowns): **200 ms**.
  - Medium (modales, drawers): **300 ms**.
  - Long: NO usar. Si algo necesita > 400 ms, probablemente la respuesta no es animarlo.
- **Reduce motion:** respetar `prefers-reduced-motion: reduce` desactivando todas las transiciones no esenciales.

---

## 8. Iconografía

- **Set:** **Lucide** (`lucide-react` o equivalente). Stroke 1.5 px, monocromo, hereda `currentColor`.
- **Tamaños:** 16 / 20 / 24 px (alineados a la escala tipográfica).
- **Anti-patrón:** iconos rellenos de color, iconos en círculos coloreados, iconos decorativos sin función.

---

## 9. Aplicación al stack

### Filament 3.2

- **Theme custom** ya implementado en `resources/css/filament/admin/theme.css` (Bloque 05). Pivot Bloque 07d: reemplazar fuentes Instrument Serif + General Sans → **IBM Plex Sans + Plex Mono**, mantener Instrument Serif solo para wordmark.
- Override del primary color en `tailwind.config.js`:
  ```js
  primary: {
    50:  '#E6EBF5',
    100: '#C7D1E8',
    500: '#1D3F8C',
    600: '#163070',
    700: '#102358',
    900: '#0A1838',
  }
  ```
- **Densidad**: aplicar `->striped()` + `->paginated([25, 50, 100])` + row height ~36-40px (clase utility custom o CSS variable `--fi-row-height`). Tabla tipo Airtable, no spaced-out.
- **Resource pattern para Variant C "Airtable-Mode"**:
  - Tabla con `ImageColumn::make('imagenes_first.url')` 28×28 rounded-4px en primera columna.
  - `ViewAction::make()->slideOver()->infolist(...)` para el side panel inspector — Filament tiene esto built-in.
  - Infolist con `Components\ImageEntry::make('imagenes.0.url')` para galería.
  - Columnas pequeñas: `text-xs` Plex Mono para IDs/paradas, `text-sm` Plex Sans para dirección/municipio.
  - Filtros como `Tables\Grouping\Group` (Status / Municipio / Operador / Industria).
- Usar `\Filament\Support\Colors\Color::rgb(...)` para registrar el cobalto.

### PWA técnico (Livewire + Volt + Tailwind 3)

- Mismos tokens que Filament (compartir `app.css`).
- Diferencia clave: padding y touch targets más generosos. Considerar utilidad `tap-target` (height ≥ 88 px) y aplicarla a botones primarios.
- `<meta name="theme-color" content="#1D3F8C">` en el shell PWA.
- Manifest `theme_color` y `background_color` derivan de los tokens.

### Tailwind config (esqueleto)

```js
extend: {
  colors: {
    primary: { /* cobalto */ },
    success: '#0F766E',
    warning: '#B45309',
    error:   '#B91C1C',
  },
  fontFamily: {
    serif: ['"Instrument Serif"', 'ui-serif', 'Georgia', 'serif'],
    sans:  ['"General Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
  },
  borderRadius: { sm: '4px', DEFAULT: '6px', md: '6px', lg: '8px', xl: '12px' },
}
```

---

## 10. Patrones críticos del producto

### 10.1 Separación tajante avería real / revisión mensual (regla #11)

Esta es la decisión visual **más importante** de toda la app. Implementación obligatoria en el técnico:

- Dos action-cards apiladas, **nunca** dos botones del mismo color.
- Card "avería real": stripe izquierdo `--error` (4 px), tinte de fondo `--error-soft`, icono triángulo de alerta, título "Reportar avería real" en serif.
- Card "revisión mensual": stripe izquierdo `--success` (4 px), tinte de fondo `--success-soft`, icono check-circle, título "Registrar revisión mensual" en serif.
- Subtítulo desambiguador obligatorio: "Hay un fallo. Crear parte correctivo." vs "Todo OK. Checklist mensual rutinario."
- En cualquier vista que liste asignaciones (admin o técnico), distinguir las dos con el mismo lenguaje cromático: stripe + tinte + icono.

Ver [ADR-0004](docs/decisions/0004-revision-vs-averia-ux.md).

### 10.2 RGPD en exports al cliente (regla #3)

- Cualquier export (CSV, PDF, email) que vaya al operador-cliente solo puede mostrar `tecnico.nombre_completo`.
- Visualmente: en cards de técnico mostradas al operador, NO renderizar campos sensibles (DNI, NSS, teléfono, dirección, email). El componente de Filament debe diferenciar `TecnicoCard::forAdmin()` vs `TecnicoCard::forOperador()`.

### 10.3 Status pills

| Estado | Token | Texto |
|---|---|---|
| Operativo | `success` | "Operativo" |
| Revisión vencida | `warning` | "Revisión vencida" |
| Avería abierta | `error` | "Avería abierta" |
| Sin operador | `error` | "Sin operador asignado" |
| Desinstalado | `text-muted` (gris neutro) | "Desinstalado" |

Implementación: pill rounded-full, padding `2px 9px`, dot 6 px del color saturado a la izquierda, fondo `*-soft`, texto del color saturado.

### 10.4 Parent-child IA: averías se consultan desde el panel

Las averías NO viven como entries top-level del menú primario. Pertenecen al panel afectado y se consultan dentro de la View page del panel:

- `/admin/pivs` → click panel → View page con dos secciones apiladas:
  1. **Detalles** — infolist con foto + 5 secciones (Bloque 07d).
  2. **Histórico de averías** — tabla densa server-rendered (Blade) filtrada al panel. Columna "Tipo" muestra el tipo de asignación asociada (Correctivo/Revisión/Sin asignar) con badge cromático regla #11. Muestra hasta 50 últimas; reportes históricos más amplios viven en Bloque 10.

Justificación: cada avería pertenece a un panel — la trazabilidad operativa exige verlas en contexto del panel, no en abstracto. Reportes cross-panel (filtros agregados por fecha/operador, exports CSV/PDF) viven en Bloque 10 Dashboard como uso secundario.

**Asignaciones**: NO tienen sección propia porque `Piv::asignaciones()` es `HasManyThrough` (asignacion vía averia.piv_id) y Filament 3 RelationManager no soporta HasManyThrough. La info clave de la asignación (tipo, horario, status) se expone como columnas dentro de la tabla de averías. Si en el futuro se requiere vista enfocada de asignaciones, reincorporar como página standalone (no RelationManager).

**Implementación**: NO se usan Filament 3 RelationManagers (lazy mount roto contra modelos legacy con primary key custom — ver Bloque 08g). En su lugar, la View page hace override de `protected static string $view` apuntando a un Blade custom que renderiza el infolist + un partial server-rendered con la tabla. AveriaResource y AsignacionResource quedan accesibles por URL pero sin entrada en sidebar (`shouldRegisterNavigation = false`). Bloque 10 reincorporará una entrada "Reportes" para vistas agregadas con filtros interactivos (página standalone, no RM).

Inspiración: la app vieja `winfin.es/paneles.php?action=edit&id=N#tabs-3` ya usaba tabs en la edit del panel — el patrón parent-child está validado por años de uso operativo.

---

## 11. Decisions Log

| Fecha | Decisión | Rationale |
|---|---|---|
| 2026-04-29 | Sistema visual creado | Generado por `/design-consultation`. Frase brújula: "serio, claro, profesional". Research vía 4 referentes (Limble, MaintainX, Tractian, Filament). Preview HTML wireframe aprobado por usuario. AI mockups pospuestos (cuenta OpenAI sin verificación de organización). |
| 2026-04-29 | Azul cobalto `#1D3F8C` como único acento | Coherente con la señalética pública española (RENFE, Metro) y con el dominio del producto (transporte público). Evita deliberadamente el `blue-600` de Tailwind y el azul corporativo SaaS. |
| 2026-04-29 | Instrument Serif + General Sans como únicas fuentes | Editorial serif para titulares y KPIs (gesto diferenciador frente a la categoría, que usa solo grotesques). Humanist sans para body/UI. Servidas vía Bunny Fonts (RGPD). |
| 2026-04-29 | Action-cards apiladas con stripe lateral para separar avería/revisión | Implementación visual de la regla #11. Stripe + tinte + icono + subtítulo desambiguador = imposible confundir los dos flujos. |
| 2026-05-01 | **Pivot a "Modern SaaS — productive precision" (Airtable-Mode).** Reemplazar Instrument Serif + General Sans por IBM Plex Sans + Plex Mono. Mantener Instrument Serif SOLO en wordmark. | Smoke real post-Bloque 07 con datos prod reveló que el serif en chrome lee como prensa cultural, no como herramienta diaria de operaciones. `/design-shotgun` con 3 variantes (Linear / Stripe / Airtable) — usuario eligió Airtable-Mode (variante C) por densidad alta + side panel inspector + group-by + galería multi-imagen. Cobalto y restraint cromático preservados. Variantes guardadas en `~/.gstack/projects/winfin-piv/designs/admin-pivs-list-saas-pivot-20260501/`. |
| 2026-05-02 | **IA refactorizada a parent-child con RelationManagers**. Averías y asignaciones se consultan desde el panel via tabs (10.4). Top-level entries quitados del sidebar (`shouldRegisterNavigation = false`). | Bloque 08 inicial las puso peer-level con paneles — incorrecto. La app vieja siempre usó tabs (`#tabs-3`); revelado por feedback del usuario tras smoke real. Bloque 08d corrige la arquitectura. Bloque 10 reincorporará una entrada "Reportes" para uso secundario (cross-panel filtros agregados). |
| 2026-05-02 | **AsignacionesRelationManager dropped**. Filament 3 RelationManager no soporta `HasManyThrough` (limitación documentada). Info de asignación se mantiene visible vía columnas tipo/horario/status en AveriasRelationManager. `Piv::asignaciones()` HasManyThrough sigue para queries programáticas y Bloque 10 reportes. | Bloque 08d intentó implementarlo, smoke real reveló crash "Cannot use ::class on null". Bloque 08e drop + enriquece AveriasRM. Si futuro Filament añade soporte HasManyThrough o se necesita vista enfocada, reincorporar como página standalone. |
| 2026-05-01 | **AveriasRelationManager eliminado, reemplazado por tabla server-rendered**. Filament 3 RM + Livewire 3 lazy mount fase 2 no rehidrata `$ownerRecord` antes de `bootedInteractsWithTable` → `Table::getModel()` devuelve null → `TypeError "::class on null"` en `HasRecords::76`. Ni `$isLazy = false` ni quitar `->filters([...])` arregla el bug razón. **Workaround Bloque 08g**: ViewPiv override `$view` → Blade custom (`view-piv.blade.php`) que compone infolist + `partials/averias-table.blade.php` (tabla HTML pura, eager loading manual, sin Livewire interno). Filtros interactivos diferidos a página standalone Bloque 10. | Investigación del stack de Livewire confirmó que el bug está en el orden de hidratación del snapshot lazy contra modelos legacy con primary key custom (`piv_id`). Tres intentos de fix dentro del framework fallaron; la salida limpia es no usar el RM. Patrón documentado en `.github/copilot-instructions.md` como restricción para futuros bloques. |
