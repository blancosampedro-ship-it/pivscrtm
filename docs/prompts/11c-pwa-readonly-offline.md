# Bloque 11c — PWA phased read-only offline + iconos definitivos

## Contexto

Bloques 11a (PR #25), 11ab (PR #26), 11b (PR #27) y 11d (PR #29) están mergeados en `main`. La PWA técnica funciona end-to-end: login persistente 90 días, dashboard rediseñado field-grade, cierre multi-step Glovo-pattern correctivo (4 pasos) + revisión (2 pasos), foto upload, dictado de voz, polling 30s. Suite **196 verde**, smoke real híbrido validado en iPhone Safari real.

**Lo que falta**: la app **no funciona sin red**. Si el técnico está en una marquesina sin cobertura, la app entera cae. También falta:
- App **instalable** en home screen del iPhone/Android (PWA install).
- **Iconos definitivos** (actualmente solo `favicon.ico` placeholder).
- **Service Worker** que cachee shell + assets + datos.
- **Banner "Nueva versión disponible"** cuando se publica deploy nuevo, sin auto-update agresivo (evita CSRF mismatch + perder formularios en progreso).

Este bloque entrega **PWA phased read-only**: el técnico puede abrir la app sin red, ver dashboard cacheado y navegar a las pantallas, **pero NO puede cerrar asignaciones offline**. La cola offline de formularios + foto upload offline + IndexedDB queue + retry policy queda explícitamente fuera — será **Bloque 11e** separado para evitar mezclar SW lifecycle (este bloque) con queue persistence (próximo).

## Decisiones tomadas con el usuario

1. **Scope phased** — solo read-only offline + SW + cache shell + iconos + banner update. La cola offline + foto offline va a Bloque 11e.
2. **Iconos generados desde wordmark Winfin PIV**:
   - Fondo: Carbon Blue 60 `#0F62FE`.
   - Monograma: **"PIV"** en blanco (no "WP" — identifica mejor el producto).
   - Tipografía: General Sans Bold o Inter Bold (matching DESIGN.md, sans-serif limpio).
   - Tamaños obligatorios: `192×192`, `512×512`, `180×180` (apple-touch-icon).
   - Variante `maskable` con safe area dentro del 80% central (texto más pequeño en el canvas).
3. **Smoke real con ngrok HTTPS** — no DevTools fake, no deploy aún. Túnel temporal para validar PWA install + modo avión real en iPhone físico.
4. **`registerType: 'prompt'`** confirmado — banner Livewire muestra "Nueva versión disponible — Recargar". El técnico decide cuándo recargar.

## Restricciones inviolables

- **NO modificar `app/Services/`, `app/Filament/`, `app/Auth/`, `app/Models/`** (excepto si imprescindible para integrar SW; primero discutirlo).
- **NO tocar la lógica de cierre** (`AsignacionCierreService`, Volt cierre flow, dashboard data fetching). Solo añadimos capa SW encima.
- **NO implementar cola offline** (IndexedDB persistence + retry + foto offline). Es Bloque 11e.
- **NO implementar push notifications** — eso es Bloque 13.
- **`registerType: 'prompt'` obligatorio**. Si Copilot propone `autoUpdate` por simplicidad → **fail**. ADR-0011 explica el riesgo: autoUpdate puede invalidar formularios en cola con CSRF mismatch silencioso al deployear cambios de session.
- **Tests Pest verde obligatorio**. Suite 196 actuales no se rompen. Sumar tests del manifest + SW config + banner. Terminar ≥200 verde.
- **CI verde** (3 jobs) antes de PR ready.
- **Carbon visual** se conserva. Banner de update debe seguir el sistema (Carbon Blue 60 background, Inter typography, sin border radius).

## Plan de cambios

### Step 1 — Instalar `vite-plugin-pwa` + `@vite-pwa/assets-generator`

```bash
npm install -D vite-plugin-pwa @vite-pwa/assets-generator
```

Versiones esperadas (mayo 2026): `vite-plugin-pwa@^0.20.x` o superior. Compatible con Vite 7.

### Step 2 — Crear icono fuente SVG `Winfin PIV`

Crear `resources/icons/source-icon.svg` con:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
  <rect width="512" height="512" fill="#0F62FE"/>
  <text x="50%" y="50%"
        text-anchor="middle"
        dominant-baseline="central"
        font-family="'Inter', 'General Sans', sans-serif"
        font-weight="700"
        font-size="180"
        fill="#FFFFFF"
        letter-spacing="-4">PIV</text>
</svg>
```

(Si Inter/General Sans no se renderiza en build sin fuente embebida, fallback a `font-family="system-ui"` o usar `<tspan>` con fuente embebida. Lo importante: monograma "PIV" blanco sobre Carbon Blue 60.)

Crear también `resources/icons/maskable-icon.svg` igual pero con texto `font-size="120"` (más pequeño, deja safe area al 80% central para máscaras circulares Android).

### Step 3 — Configurar `pwa-assets-generator`

Crear `pwa-assets.config.ts` (o `.js` si TS no está configurado):

```ts
import { defineConfig, minimal2023Preset } from '@vite-pwa/assets-generator/config';

export default defineConfig({
  preset: {
    ...minimal2023Preset,
    transparent: {
      sizes: [64, 192, 512],
      favicons: [[48, 'favicon.ico']],
    },
    maskable: {
      sizes: [512],
      padding: 0.0,
      resizeOptions: { background: '#0F62FE' },
    },
    apple: {
      sizes: [180],
      padding: 0.3,
      resizeOptions: { background: '#0F62FE' },
    },
  },
  images: ['resources/icons/source-icon.svg'],
  output: {
    folder: 'public',
  },
});
```

Generar:

```bash
npx pwa-assets-generator
```

Esto debe crear en `public/`:
- `pwa-64x64.png`
- `pwa-192x192.png`
- `pwa-512x512.png`
- `apple-touch-icon-180x180.png`
- `maskable-icon-512x512.png`
- `favicon.ico`

Si la herramienta falla (por ejemplo, requiere fuente del sistema), alternativa: usar `imagemagick` directamente:

```bash
# Source SVG → PNG con tamaños múltiples
convert -density 300 -background "#0F62FE" -resize 192x192 resources/icons/source-icon.svg public/pwa-192x192.png
convert -density 300 -background "#0F62FE" -resize 512x512 resources/icons/source-icon.svg public/pwa-512x512.png
convert -density 300 -background "#0F62FE" -resize 180x180 resources/icons/source-icon.svg public/apple-touch-icon-180x180.png
convert -density 300 -background "#0F62FE" -resize 512x512 resources/icons/maskable-icon.svg public/maskable-icon-512x512.png
```

Versionar los SVG fuente en `resources/icons/`. **NO versionar** los PNG generados — añadirlos a `.gitignore` con comentario "regenerated by `npx pwa-assets-generator`". Razón: son output, no source. Patrón ya usado en `.gitignore` con `/public/css/filament` y `/public/js/filament`.

Excepción: `favicon.ico` y `apple-touch-icon-180x180.png` SÍ versionar (son referencias estables del HTML head, no se regeneran a menudo).

### Step 4 — Configurar `vite-plugin-pwa`

Modificar `vite.config.js`:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        VitePWA({
            registerType: 'prompt',
            injectRegister: 'auto',
            srcDir: 'resources/js',
            filename: 'sw.js',
            strategies: 'generateSW',
            manifest: false, // El manifest lo servimos desde public/manifest.webmanifest manualmente
            workbox: {
                globPatterns: ['**/*.{js,css,html,svg,png,ico,woff2}'],
                navigateFallback: '/tecnico',
                navigateFallbackAllowlist: [/^\/tecnico/],
                runtimeCaching: [
                    {
                        // Bunny Fonts (DESIGN.md): cache long-lived
                        urlPattern: /^https:\/\/fonts\.bunny\.net\/.*$/,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'bunny-fonts',
                            expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 365 },
                        },
                    },
                    {
                        // Fotos legacy del panel (cross-origin opaque)
                        urlPattern: /^https:\/\/www\.winfin\.es\/images\/piv\/.*$/,
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'piv-photos-legacy',
                            expiration: { maxEntries: 200, maxAgeSeconds: 60 * 60 * 24 * 30 },
                        },
                    },
                    {
                        // Fotos cierre técnico (storage local)
                        urlPattern: /\/storage\/piv-images\/correctivo\/.*$/,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'piv-photos-cierre',
                            expiration: { maxEntries: 100, maxAgeSeconds: 60 * 60 * 24 * 90 },
                        },
                    },
                    {
                        // HTML del shell técnico — network-first para refrescar siempre que haya red
                        urlPattern: ({ request, url }) => request.mode === 'navigate' && url.pathname.startsWith('/tecnico'),
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'tecnico-html',
                            networkTimeoutSeconds: 3,
                            expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 7 },
                        },
                    },
                    {
                        // Livewire endpoints — siempre red, NO cachear (sino formularios fallarían)
                        urlPattern: /\/livewire\/.*$/,
                        handler: 'NetworkOnly',
                    },
                ],
            },
            devOptions: {
                enabled: false, // Solo en build prod
            },
        }),
    ],
});
```

Notas críticas:
- `strategies: 'generateSW'` deja a Workbox generar el SW. Más simple que `injectManifest`.
- `manifest: false` — servimos `public/manifest.webmanifest` ya existente (lo actualizaremos en Step 5). El plugin no debe generar otro.
- `registerType: 'prompt'` — el banner Livewire dispara la actualización manualmente.
- `navigateFallback: '/tecnico'` + allowlist — si offline navega a una URL no cacheada del scope técnico, sirve el `/tecnico` cacheado como fallback (la SPA shell).
- `Livewire endpoints NetworkOnly` — crítico. Sin esto, Livewire requests podrían servirse cached con CSRF tokens viejos → 419.

### Step 5 — Actualizar `public/manifest.webmanifest`

Reemplazar el contenido:

```json
{
  "name": "Winfin PIV - Técnico",
  "short_name": "Winfin PIV",
  "description": "Gestión de paneles de información al viajero — vista técnico de campo.",
  "start_url": "/tecnico",
  "scope": "/tecnico/",
  "display": "standalone",
  "orientation": "portrait",
  "theme_color": "#0F62FE",
  "background_color": "#FFFFFF",
  "lang": "es",
  "id": "/tecnico",
  "icons": [
    {
      "src": "/pwa-192x192.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/pwa-512x512.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "any"
    },
    {
      "src": "/maskable-icon-512x512.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "maskable"
    }
  ]
}
```

`id` es importante para que el navegador trate la PWA como una app independiente (no se confunda con otras instalaciones del mismo dominio).

### Step 6 — Actualizar `resources/views/components/tecnico/shell.blade.php`

En el `<head>`, junto al `<link rel="manifest">` ya existente, añadir:

```blade
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon-180x180.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Winfin PIV">
<meta name="theme-color" content="#0F62FE">
```

Estos meta tags son OBLIGATORIOS para iOS Safari Add-to-Home-Screen — sin ellos el icono y el nombre quedan feos.

### Step 7 — Banner Livewire "Nueva versión disponible"

Crear `resources/views/livewire/tecnico/pwa-update-banner.blade.php`:

```php
<?php
use Livewire\Volt\Component;

new class extends Component {
    public bool $show = false;

    protected $listeners = ['pwa:update-available' => 'showBanner'];

    public function showBanner(): void
    {
        $this->show = true;
    }

    public function reload(): void
    {
        $this->dispatch('pwa:reload-now');
    }
}; ?>

<div x-data="{
    init() {
        window.addEventListener('pwa:reload-now', () => {
            window.location.reload();
        });

        // Listener directo del SW lifecycle event
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                Livewire.dispatch('pwa:update-available');
            });
        }
    }
}"
@if (! $show) style="display:none" @endif
class="fixed bottom-0 left-0 right-0 bg-primary-60 text-ink-on_color px-4 py-3 flex items-center justify-between shadow-lg z-50">
    <div class="flex-1 text-md font-medium">
        Nueva versión disponible
    </div>
    <button wire:click="reload"
            class="bg-white text-primary-60 px-4 py-2 font-medium tap-target ml-3">
        Recargar
    </button>
</div>
```

Incluir el component en `resources/views/components/tecnico/shell.blade.php` justo antes del cierre del `<body>`:

```blade
@livewire('tecnico.pwa-update-banner')
```

Patrón Volt component minimalista. Listener Alpine `pwa:reload-now` dispara `window.location.reload()`. Livewire muestra/oculta el banner via `$show`.

**Detalle UX**: el banner solo aparece cuando hay update real disponible. En primera visita o sin update, queda invisible.

### Step 8 — Tests obligatorios

Crear `tests/Feature/Pwa/PwaConfigTest.php`. Tests posibles desde Pest (sin SW lifecycle real):

1. `manifest_webmanifest_returns_200_with_correct_content_type` — `$this->get('/manifest.webmanifest')->assertOk()->assertHeader('content-type', 'application/manifest+json')`. Si Laravel sirve `.webmanifest` con `application/octet-stream`, añadir mime type explícito en `app/Http/Middleware/` o `config/filesystems.php`.
2. `manifest_has_required_pwa_fields` — parse JSON, assert keys `name`, `start_url=/tecnico`, `scope=/tecnico/`, `display=standalone`, `theme_color=#0F62FE`, `id=/tecnico`.
3. `manifest_icons_have_192_512_and_maskable` — assert icons array tiene 3 entries con sizes correctos y purpose=maskable presente.
4. `apple_touch_icon_180_returns_200` — `$this->get('/apple-touch-icon-180x180.png')->assertOk()`.
5. `pwa_icons_192_and_512_return_200` — same para `pwa-192x192.png` y `pwa-512x512.png`.
6. `tecnico_shell_includes_apple_touch_icon_link` — render shell, assert `<link rel="apple-touch-icon">` presente.
7. `tecnico_shell_includes_apple_mobile_web_app_meta_tags` — assert `apple-mobile-web-app-capable=yes` y `apple-mobile-web-app-title=Winfin PIV`.
8. `tecnico_shell_includes_theme_color_meta` — assert `<meta name="theme-color" content="#0F62FE">`.
9. `pwa_update_banner_renders_hidden_by_default` — Livewire test, assert el component renderiza con `display:none` o equivalente.
10. `pwa_update_banner_shows_when_pwa_update_available_event_fires` — `Livewire::test(...)->dispatch('pwa:update-available')->assertSet('show', true)`.

**Tests pivots banderazo rojo:**
- Si Copilot dice "no puedo testear el SW desde Pest" → OK, skipear ese test, smoke manual cubre.
- Si dice "tuve que cambiar el manifest a un controller para testear el content-type" → es razonable solo si Laravel no permite custom mime type en archivos estáticos del `public/`. Verificar.
- Si dice "salté apple-touch-icon test porque el archivo es binario" → fail. `$this->get(...)->assertOk()` funciona contra binarios en Laravel.

### Step 9 — Build + test local

```bash
npm install
npm run build
./vendor/bin/pest --parallel
./vendor/bin/pint --test
```

Verificar:
- `public/build/sw.js` se ha generado.
- `public/build/manifest.webmanifest` (workbox lo bundlea) **NO** sobreescribe el `public/manifest.webmanifest` raíz. Si hay conflict, el plugin debe tener `manifest: false` (Step 4).
- 200+ tests verde.

### Step 10 — Smoke real con ngrok

**Pre-requisitos del usuario** (no automatizable):
- Cuenta gratuita ngrok (https://ngrok.com/signup).
- `ngrok config add-authtoken <TOKEN>` ejecutado una vez.

**Steps del smoke** (combinación Copilot + usuario):

1. Copilot arranca server local: `php artisan serve --host=127.0.0.1 --port=8000`.
2. Usuario arranca ngrok: `ngrok http 8000` en otra terminal. Obtiene URL HTTPS pública tipo `https://abc123.ngrok-free.app`.
3. Usuario abre la URL HTTPS desde **Safari iPhone real** (no Mac, no Playwright).
4. Usuario login PWA con técnico smoke (usar `<SMOKE_PASS>` del `.smoke-credentials.local.md`). **Antes**: Copilot reactiva técnico smoke id=66 + crea 2 stubs (igual patrón que smoke 11d).
5. Usuario tap "Compartir" en Safari iOS → "Añadir a la pantalla de inicio". Esperado: aparece el icono PIV blanco sobre fondo Carbon Blue en home screen.
6. Usuario tap el icono desde home. Esperado: app abre standalone (sin barra de Safari), va directo a `/tecnico`.
7. Usuario activa **Modo Avión** del iPhone.
8. Usuario tap el icono PWA otra vez. Esperado: app abre, dashboard renderiza con cards cacheadas (la última vista). NO error de red. Las fotos de paneles también cacheadas (Bunny Fonts y `/storage/piv-images/correctivo/` cacheados; las legacy de `winfin.es` puede no cargar si nunca se vieron antes).
9. Usuario tap una card. Esperado: navega a `/tecnico/asignaciones/{id}` con form cierre renderizado (HTML cacheado).
10. Usuario intenta submit cierre offline. Esperado: error de red (Livewire request falla NetworkOnly). UX puede ser feo aquí — es esperado, la cola offline es Bloque 11e. Captura screenshot del estado offline.
11. Usuario desactiva Modo Avión. Espera 5s. Reintenta submit. Esperado: cierre OK, redirect a dashboard, flash success.
12. **Banner update**: Copilot rebuild con cambio cosmético (bumpear versión en SW). Usuario refresca app → ver banner "Nueva versión disponible — Recargar". Tap banner → recarga.
13. Cleanup post-smoke: borrar stubs + re-desactivar técnico 66 + matar ngrok + matar server.

Capturar screenshots en `docs/runbooks/screenshots/11c-smoke/`:
- 01-pwa-install-prompt.png
- 02-icon-home-screen.png
- 03-app-standalone.png
- 04-modo-avion-dashboard-cached.png
- 05-form-offline.png
- 06-submit-failed-offline.png
- 07-submit-ok-online.png
- 08-update-banner.png

## Restricciones de proceso (CLAUDE.md)

- Branch: `bloque-11c-pwa-readonly-offline`.
- Commits atómicos:
  1. `feat(pwa): add Winfin PIV icon source SVGs (transparent + maskable)`
  2. `feat(pwa): generate PNG icons via @vite-pwa/assets-generator`
  3. `feat(pwa): configure vite-plugin-pwa with workbox cache strategies`
  4. `feat(pwa): update manifest.webmanifest with definitive icons`
  5. `feat(pwa): add apple-touch-icon and iOS PWA meta tags to shell`
  6. `feat(pwa): add update banner Livewire component with prompt strategy`
  7. `chore(gitignore): ignore generated PWA PNG icons (regenerable)`
  8. `test(pwa): cover manifest, meta tags, icon endpoints, banner state`
- Push + PR contra `main`. NO mergear: el usuario revisa y mergea.
- NO modificar `app/Services/`, `app/Filament/`, `app/Auth/`.
- NO implementar IndexedDB, retry policy, foto offline persistence — eso es Bloque 11e.
- NO implementar push notifications — eso es Bloque 13.
- NO tocar `routes/web.php` ni middleware existentes.
- ADR mini opcional: `docs/decisions/0011-pwa-prompt-strategy.md` justificando `registerType: 'prompt'` vs `autoUpdate` (referencia status.md líneas Bloque 11). Si no se quiere ADR, dejar comentario en `vite.config.js` con el porqué.

## Reporte final

Devolver:

```
## Bloque 11c — Reporte

### Commits
- <hash> feat(pwa): add Winfin PIV icon source SVGs
- <hash> feat(pwa): generate PNG icons
- <hash> feat(pwa): configure vite-plugin-pwa
- <hash> feat(pwa): update manifest.webmanifest
- <hash> feat(pwa): add iOS meta tags
- <hash> feat(pwa): add update banner Livewire component
- <hash> chore(gitignore): ignore generated PNGs
- <hash> test(pwa): cover manifest, meta, icons, banner

### Tests
- Suite total: 196 → ~206 verde.
- 4 jobs CI verde.

### Build
- public/build/sw.js generado: SI/NO + tamaño
- workbox precache count: ~X archivos

### Smoke pendiente al merge
- Reactivar técnico 66 + crear stubs.
- Arrancar server + ngrok HTTPS.
- iPhone real: install PWA, modo avión, navegación cacheada, banner update.
- Cleanup.

### Pivots realizados (si los hubo)
- ...

### Riesgos conocidos
- iOS Safari soporte PWA es limitado (no background sync, no push hasta iOS 16.4+ via web push).
- Formularios offline NO funcionan en este bloque — es por diseño (read-only). Bloque 11e atacará la cola.
- Si admin cambia datos legacy mientras técnico está offline con cache stale, al volver online la app puede mostrar info ligeramente desfasada hasta el siguiente NetworkFirst refresh (3s timeout).
- Cross-origin photos de winfin.es solo cachean si se vieron al menos una vez online (opaque cache).

### Deudas que NO se atacan en este bloque
- Cola offline de formularios + IndexedDB + retry + foto offline (Bloque 11e).
- Push notifications (Bloque 13).
- 3 deudas del 11ab/11b (race condition tecnico_id, kebab clipping, throttle UX) (chips spawned).
- Sidebar overlay viewport estrecho (Copilot 11ab Parte C).
- Sidebar active accent Carbon (Blue 10 + barra 2px en item activo).
- Cleanup test legacy `#1D3F8C` en AdminPanelProvider.
```
