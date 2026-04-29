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

- **Direction:** **Editorial industrial española.** Disciplina tipográfica suiza/editorial + restraint cromático + un único gesto distintivo (regla 1px en color acento bajo títulos de página y de sección).
- **Decoration level:** mínimo. Cero gradientes, cero patterns, cero blobs decorativos, cero iconos en círculos de colores. Solo la regla cobalto y los stripes verticales de los action-cards del técnico.
- **Mood:** la app debe sentirse como un buen documento técnico europeo o como la señalética de RENFE/Metro. Lo opuesto a "otro panel admin Laravel". Nunca SaaS-marketing-glossy, nunca playful.
- **Reference sites estudiados (research del 29 abr 2026):**
  - https://limble.com (rechazado: lime + decoración SaaS)
  - https://getmaintainx.com (rechazado: azul corporativo genérico)
  - https://tractian.com/en (referente parcial: dirección industrial seria)
  - https://filamentphp.com (estética por defecto a sobreescribir)

---

## 3. Typography

> Solo dos familias en todo el sistema. Ninguna otra está permitida.

| Rol | Fuente | Tamaño / weight | Notas |
|---|---|---|---|
| **Display / titulares de página** | **Instrument Serif** (Google Fonts) | 36–56 px / 400 | Solo en `<h1>`, KPIs grandes y los títulos de los action-cards del técnico. Cursiva (`<em>`) reservada para acentuar el wordmark "Winfin *PIV*" y muy puntual énfasis. |
| **Section headings** | Instrument Serif | 22–28 px / 400 | Subsecciones, título de la pregunta "¿Qué vas a registrar?" en el técnico. |
| **Body / UI / formularios / tablas** | **General Sans** (Fontshare) | 14–16 px / 400, 500, 600, 700 | Todo lo demás. Incluye labels, inputs, navegación, captions. |
| **Tabular numerics (datos)** | General Sans con `font-variant-numeric: tabular-nums` | 13–15 px | Fechas, IDs, métricas en tablas y KPIs. NO usar fuente monoespaciada separada. |
| **Code / terminal output** | N/A (no se muestra código a usuarios finales). | — | Si hace falta en algún panel admin de debug, `ui-monospace`. |

### Carga de fuentes

- **Producción:** vía **Bunny Fonts** (`fonts.bunny.net`) — mirror RGPD-friendly de Google Fonts. Justificación: tribunales europeos han fallado contra el uso directo de `fonts.googleapis.com` por enviar IPs de usuarios a Google. Bunny no traquea.
- **Stack CSS:**
  ```css
  --serif: "Instrument Serif", ui-serif, Georgia, serif;
  --sans: "General Sans", ui-sans-serif, system-ui, sans-serif;
  ```
- **Imports recomendados** en `resources/css/app.css`:
  ```css
  @import url("https://fonts.bunny.net/css?family=instrument-serif:400,400i&display=swap");
  @import url("https://api.fontshare.com/v2/css?f[]=general-sans@400,500,600,700&display=swap");
  ```
- **Activar tabular-nums por defecto en tablas y datos:**
  ```css
  .data, .kpi-value, .info-value { font-variant-numeric: tabular-nums; }
  ```

### Escala modular (ratio 1.250)

`12 / 14 / 16 / 20 / 25 / 31 / 39 / 49 / 61 px`

Mapeo a Tailwind:
`text-xs / text-sm / text-base / text-lg / text-xl / text-2xl / text-3xl / text-4xl / text-5xl`.

### Anti-patrones tipográficos (NUNCA)

- ❌ Inter, Roboto, Arial, Helvetica, Open Sans, Lato, Montserrat, Poppins, Space Grotesk como display ni body.
- ❌ `system-ui` / `-apple-system` como fuente principal.
- ❌ Mezclar más de dos familias.
- ❌ Usar Instrument Serif en formularios, inputs, body o navegación.
- ❌ Cursiva (`<em>` Instrument Serif) fuera del wordmark y ocasional acento — nunca en párrafos enteros.

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

- **Theme custom** vía `php artisan make:filament-theme`.
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
- Inyectar Instrument Serif y General Sans en el theme CSS.
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

---

## 11. Decisions Log

| Fecha | Decisión | Rationale |
|---|---|---|
| 2026-04-29 | Sistema visual creado | Generado por `/design-consultation`. Frase brújula: "serio, claro, profesional". Research vía 4 referentes (Limble, MaintainX, Tractian, Filament). Preview HTML wireframe aprobado por usuario. AI mockups pospuestos (cuenta OpenAI sin verificación de organización). |
| 2026-04-29 | Azul cobalto `#1D3F8C` como único acento | Coherente con la señalética pública española (RENFE, Metro) y con el dominio del producto (transporte público). Evita deliberadamente el `blue-600` de Tailwind y el azul corporativo SaaS. |
| 2026-04-29 | Instrument Serif + General Sans como únicas fuentes | Editorial serif para titulares y KPIs (gesto diferenciador frente a la categoría, que usa solo grotesques). Humanist sans para body/UI. Servidas vía Bunny Fonts (RGPD). |
| 2026-04-29 | Action-cards apiladas con stripe lateral para separar avería/revisión | Implementación visual de la regla #11. Stripe + tinte + icono + subtítulo desambiguador = imposible confundir los dos flujos. |
