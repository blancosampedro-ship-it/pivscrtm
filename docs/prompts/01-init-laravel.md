# Bloque 01 — Inicializar Laravel 12 + Pest + Tailwind + Vite + git

> **Cómo se usa este archivo:** copia el bloque `BEGIN PROMPT` … `END PROMPT` que está al final, pégalo entero en VS Code Copilot Chat con modo Agent activado. Copilot lo ejecuta paso a paso en la carpeta `~/Documents/winfin-piv/`. Tú vigilas y confirmas las acciones destructivas si las pide.

---

## Objetivo del bloque

Dejar `~/Documents/winfin-piv/` con:

- Laravel 12 instalado (composer create-project sobre carpeta no vacía — workaround documentado).
- Pest 3 instalado en lugar de PHPUnit.
- Tailwind 3 + Vite con tokens de DESIGN.md ya aplicados (azul cobalto `#1D3F8C`, Instrument Serif, General Sans desde Bunny Fonts RGPD-friendly).
- Ruta `/up` health check funcional.
- `.env` local generado a partir de `.env.example` con `APP_KEY` fresco. El `.env` NO se commitea.
- Toda la documentación pre-existente intacta (ARCHITECTURE.md, DESIGN.md, ADRs 0001-0005, CLAUDE.md, README.md, .github/copilot-instructions.md, docs/security.md, docs/prompts/00-roadmap.md, docs/prompts/01-init-laravel.md, .env.example, .gitignore).
- `git init` con rama `main`, primer commit con toda la docu + Laravel base, y push a `https://github.com/blancosampedro-ship-it/pivscrtm.git`.

**Definition of Done de este bloque:**
1. `php artisan serve` arranca sin errores.
2. `curl -s http://127.0.0.1:8000/up` devuelve `200`.
3. `npm run build` compila sin errores.
4. `./vendor/bin/pest` corre con al menos 1 test pasando (el smoke test que crea Pest por defecto).
5. `git log --oneline` muestra exactamente 1 commit.
6. Repo `pivscrtm` en GitHub tiene contenido (no vacío).

---

## Riesgos y mitigaciones

- **Carpeta no vacía**: `composer create-project laravel/laravel . --prefer-dist` falla. Workaround: instalar en `/tmp/laravel-fresh/` y luego `rsync --ignore-existing` para no machacar nuestra documentación.
- **Conflicto entre `.gitignore` de Laravel y el nuestro**: el nuestro es superset, lo conservamos.
- **Conflicto entre `README.md` de Laravel y el nuestro**: el nuestro es Winfin-específico, lo conservamos.
- **`.env` con la API key de OpenAI no debe commitarse**: ya está en `.gitignore`. Verificar.
- **Rama por defecto de git**: forzamos `main` explícito (no depender de la config global del usuario).
- **Composer < 2.7**: si ocurre, `composer self-update` antes. Conservamos las versiones del Mac local; SiteGround se actualizará en Bloque 15.

---

## Diff esperado tras el bloque (vista panorámica)

```
~/Documents/winfin-piv/
├── .env                       (NUEVO, NO commiteado, copia de .env.example + APP_KEY)
├── .env.example               (sin cambios — el nuestro, con datos Winfin)
├── .gitignore                 (sin cambios — el nuestro)
├── .github/copilot-instructions.md   (sin cambios)
├── ARCHITECTURE.md            (sin cambios)
├── CLAUDE.md                  (sin cambios)
├── DESIGN.md                  (sin cambios)
├── README.md                  (sin cambios — el nuestro)
├── app/                       (NUEVO — Laravel default)
├── bootstrap/                 (NUEVO — Laravel default)
├── composer.json              (NUEVO — Laravel default + Pest dev deps)
├── composer.lock              (NUEVO)
├── config/                    (NUEVO — Laravel default)
├── database/                  (NUEVO — Laravel default)
├── docs/
│   ├── decisions/             (sin cambios — ADRs 0001-0005)
│   ├── prompts/               (sin cambios — 00-roadmap.md, 01-init-laravel.md)
│   └── security.md            (sin cambios)
├── package.json               (NUEVO — Laravel default + Tailwind config)
├── package-lock.json          (NUEVO)
├── phpunit.xml                (NUEVO — usado por Pest)
├── public/                    (NUEVO — Laravel default)
├── resources/
│   ├── css/app.css            (MODIFICADO — tokens de DESIGN.md)
│   └── views/welcome.blade.php (DEFAULT Laravel; lo dejamos por ahora)
├── routes/
│   └── web.php                (MODIFICADO — añade Route::get('/up'))
├── storage/                   (NUEVO — Laravel default)
├── tailwind.config.js         (MODIFICADO — paleta cobalto + fonts custom)
├── tests/                     (NUEVO — Pest)
└── vite.config.js             (sin tocar SW de PWA todavía — eso es Bloque 11)
```

---

## El prompt para Copilot

Lo siguiente es un único prompt copy-paste. Pégalo en VS Code Copilot Chat (modo Agent) con la carpeta `~/Documents/winfin-piv/` abierta.

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero `.github/copilot-instructions.md`, `CLAUDE.md` y `DESIGN.md` para entender el contexto, las restricciones inviolables y el sistema visual.

Tu tarea: ejecutar el Bloque 01 del roadmap (`docs/prompts/01-init-laravel.md`). Inicializa Laravel 12 + Pest + Tailwind + Vite en esta carpeta, configura los tokens de DESIGN.md, añade la ruta /up, hace git init con rama main, primer commit con toda la documentación + Laravel base, y push a github.com/blancosampedro-ship-it/pivscrtm.git.

Sigue estos pasos en orden. Tras cada paso destructivo, muéstrame el resultado y espera confirmación antes del siguiente.

## Paso 0 — Verificación de entorno

Verifica que el Mac tiene las versiones requeridas. Si alguna falla, AVISA y para:

```
php --version          # >= 8.2
composer --version     # >= 2.7 (si no, sugiere `composer self-update`)
node --version         # >= 22 LTS
npm --version          # >= 10
git --version          # >= 2.30
```

Verifica que estamos en la carpeta correcta:

```
pwd                    # debe ser /Users/winfin/Documents/winfin-piv
ls                     # debe listar ARCHITECTURE.md, DESIGN.md, README.md, etc.
```

Si la carpeta no es la correcta, AVISA y para. No improvises.

## Paso 1 — Snapshot de seguridad

Antes de tocar nada, lista los archivos que ya existen para que sepamos qué proteger:

```
find . -maxdepth 2 -type f -not -path './.git/*' | sort > /tmp/winfin-pre-laravel-files.txt
cat /tmp/winfin-pre-laravel-files.txt
```

Esa lista representa "lo que NO se puede perder". La conservaremos para verificación final.

## Paso 2 — Instalar Laravel en carpeta temporal

```bash
rm -rf /tmp/laravel-fresh
composer create-project laravel/laravel /tmp/laravel-fresh --prefer-dist --no-interaction
```

Verifica que `/tmp/laravel-fresh/composer.json` declara `"laravel/framework": "^12.0"` (o similar). Si Composer instaló una versión distinta de Laravel, AVISA.

## Paso 3 — Copiar Laravel sobre nuestra carpeta sin machacar nuestros archivos

```bash
rsync -av --ignore-existing /tmp/laravel-fresh/ ./
rsync -av --ignore-existing /tmp/laravel-fresh/.[!.]* ./   # archivos ocultos (.editorconfig, etc.)
rm -rf /tmp/laravel-fresh
```

`--ignore-existing` significa: si un archivo ya existe en destino, NO se sobreescribe. Esto protege nuestro README.md, .gitignore, .env.example, ARCHITECTURE.md, etc.

Tras el rsync, lista lo que hay:

```bash
ls -la
```

Verifica que ves: el `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `tests/`, `composer.json`, `package.json`, `vite.config.js`, etc. de Laravel, Y SIGUE estando ARCHITECTURE.md, DESIGN.md, README.md, CLAUDE.md, .env.example, .gitignore, .github/, docs/.

Si algún archivo nuestro fue borrado o sobrescrito, PARA y AVISA.

## Paso 4 — Generar `.env` local con APP_KEY (NO commiteado)

```bash
cp .env.example .env
php artisan key:generate
```

Verifica que `.env` se creó con `APP_KEY=base64:...` y que `.env` está listado en `.gitignore` (line: `.env`).

```bash
grep -E '^\.env$' .gitignore
```

## Paso 5 — Reemplazar PHPUnit por Pest 3

```bash
composer remove --dev phpunit/phpunit --no-interaction || true
composer require --dev pestphp/pest pestphp/pest-plugin-laravel --with-all-dependencies --no-interaction
./vendor/bin/pest --init
```

`--init` crea `tests/Pest.php` y un test ejemplo `tests/Feature/ExampleTest.php`. Verifica que existen.

Corre los tests para confirmar que Pest funciona:

```bash
./vendor/bin/pest
```

Debe pasar el smoke test default. Si falla, PARA y AVISA.

## Paso 6 — Configurar Tailwind con tokens de DESIGN.md

Abre `tailwind.config.js`. Si Laravel 12 no lo trae por defecto (depende de la versión instalada), créalo. Reemplaza su contenido completo por:

```js
/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    "./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
    "./vendor/filament/**/*.blade.php",
  ],
  theme: {
    extend: {
      // --- Tokens de DESIGN.md — única fuente de verdad ---
      colors: {
        // Azul cobalto Winfin (señalética pública española, NO bright SaaS blue)
        primary: {
          50:  "#E6EBF5",
          100: "#C7D1E8",
          200: "#9FB1D5",
          300: "#7791C2",
          400: "#4F71AF",
          500: "#1D3F8C",   // base
          600: "#163070",
          700: "#102358",
          800: "#0B1A40",
          900: "#06122B",
        },
        // Fondos cálidos (no blanco hospital)
        canvas: {
          base:    "#FAFAF7",
          surface: "#FFFFFF",
          subtle:  "#F2F2EC",
        },
        // Tipografía
        ink: {
          DEFAULT: "#0F1115",
          muted:   "#5A6068",
          faint:   "#8A8F96",
        },
        // Bordes
        line: {
          DEFAULT: "#E5E5E0",
          strong:  "#C9C9C2",
        },
        // Semánticos profundos (NO lima, NO bright red)
        success: { DEFAULT: "#0F766E", soft: "#E6F2F1" },
        warning: { DEFAULT: "#B45309", soft: "#FBEFD9" },
        error:   { DEFAULT: "#B91C1C", soft: "#FBE6E6" },
      },
      fontFamily: {
        // Editorial serif para titulares y KPIs grandes
        serif: ['"Instrument Serif"', "ui-serif", "Georgia", "serif"],
        // Humanist sans para body, UI, tablas, formularios
        sans:  ['"General Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
      },
      borderRadius: {
        sm:  "4px",
        DEFAULT: "6px",
        md:  "6px",
        lg:  "8px",
        xl:  "12px",
      },
      // Modular scale 1.250
      fontSize: {
        xs:   "12px",
        sm:   "14px",
        base: "16px",
        lg:   "20px",
        xl:   "25px",
        "2xl": "31px",
        "3xl": "39px",
        "4xl": "49px",
        "5xl": "61px",
      },
      transitionDuration: {
        micro: "150ms",
        short: "200ms",
        med:   "300ms",
      },
    },
  },
  plugins: [
    require("@tailwindcss/forms"),
  ],
};
```

Si `@tailwindcss/forms` no está instalado:

```bash
npm install -D @tailwindcss/forms
```

## Paso 7 — Configurar `resources/css/app.css` con fuentes Bunny

Reemplaza el contenido de `resources/css/app.css` por:

```css
/* Fuentes vía Bunny Fonts (mirror RGPD-friendly de Google Fonts).
   Justificación: tribunales europeos han fallado contra fonts.googleapis.com
   por enviar IPs a Google. Bunny no traquea. Ver DESIGN.md §3 — Carga de fuentes. */
@import url("https://fonts.bunny.net/css?family=instrument-serif:400,400i&display=swap");
@import url("https://api.fontshare.com/v2/css?f[]=general-sans@400,500,600,700&display=swap");

@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
  html {
    font-family: theme("fontFamily.sans");
    background: theme("colors.canvas.base");
    color: theme("colors.ink.DEFAULT");
  }
  /* Tabular nums activos por defecto en datos */
  .tabular,
  .data-cell,
  [class*="kpi"] {
    font-variant-numeric: tabular-nums;
  }
}
```

## Paso 8 — Añadir ruta `/up` (health check)

Edita `routes/web.php`. Si Laravel 12 ya trae una ruta `/up` por default (algunas versiones la incluyen), verifica que existe. Si no, añade al final del archivo:

```php
// Health check para deploy canary y monitorización post-deploy.
Route::get('/up', function () {
    return response('OK', 200)->header('Content-Type', 'text/plain');
});
```

## Paso 9 — Ajustar `.gitignore` (añadir `.phpunit.cache` si no está)

```bash
grep -q '\.phpunit\.cache' .gitignore || echo '.phpunit.cache' >> .gitignore
```

(Laravel 12 puede generar este directorio; nuestro `.gitignore` original no lo cubría.)

## Paso 10 — Compilar assets para verificar que la cadena Vite/Tailwind funciona

```bash
npm install
npm run build
```

Verifica que `public/build/` contiene archivos `app-*.css` y `app-*.js`. Si falla, PARA y AVISA con el error.

## Paso 11 — Smoke test final

Arranca el servidor en background:

```bash
php artisan serve > /tmp/winfin-serve.log 2>&1 &
SERVER_PID=$!
sleep 2
```

Prueba ambos endpoints:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/        # esperado: 200
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/up      # esperado: 200
curl -s http://127.0.0.1:8000/up                                       # esperado: "OK"
```

Para el servidor:

```bash
kill $SERVER_PID
```

Corre los tests Pest una vez más:

```bash
./vendor/bin/pest
```

Si TODO pasa, sigue. Si algo falla, PARA y AVISA con los logs.

## Paso 12 — `git init`, primer commit, push

Verifica que `.env` NO se va a commitear:

```bash
git check-ignore .env       # debe imprimir ".env"
```

Si imprime vacío (lo que significa que NO está ignorado), PARA y AVISA.

Inicializa el repo con rama `main`:

```bash
git init -b main
```

Configura el remoto:

```bash
git remote add origin https://github.com/blancosampedro-ship-it/pivscrtm.git
```

Stage todo (el .gitignore protege .env, vendor, node_modules):

```bash
git add .
git status
```

Verifica visualmente que NO aparece `.env`, `vendor/`, `node_modules/` ni `public/build/` en la lista de archivos a commitear. Si alguno aparece, PARA y AVISA.

Primer commit (mensaje en inglés, ≤72 chars en subject, body en español):

```bash
git commit -m "chore: bootstrap Laravel 12 + Pest + Tailwind with full docs

Inicializa el proyecto Winfin PIV con Laravel 12, Pest 3, Tailwind 3 y
Vite. Aplica el sistema visual de DESIGN.md (azul cobalto #1D3F8C,
Instrument Serif y General Sans vía Bunny Fonts) en tailwind.config.js
y resources/css/app.css. Añade ruta /up health check para canary.

Incluye toda la documentación arquitectónica pre-existente: ARCHITECTURE,
DESIGN, ADRs 0001-0005, CLAUDE, README, copilot-instructions, security,
roadmap (20 bloques) y 01-init-laravel."
```

Push:

```bash
git push -u origin main
```

Si pide credenciales, espera al usuario. Si el push falla por "remote rechazado", AVISA y para — probablemente el repo no está vacío como esperábamos.

## Paso 13 — Verificación final post-push

Lista el commit:

```bash
git log --oneline
```

Debe mostrar EXACTAMENTE 1 commit con el mensaje del paso 12.

Verifica que la documentación pre-existente sigue presente y commiteada:

```bash
git ls-files | grep -E '^(ARCHITECTURE|DESIGN|README|CLAUDE)\.md$'
git ls-files | grep -E '^docs/decisions/000[1-5]-.*\.md$'
git ls-files | grep -E '^\.github/copilot-instructions\.md$'
git ls-files | grep -E '^docs/prompts/(00-roadmap|01-init-laravel)\.md$'
```

Cada uno de los `grep` debe encontrar su archivo. Si alguno no aparece, AVISA.

Verifica que los archivos sensibles NO están commiteados:

```bash
git ls-files | grep -E '^\.env$' && echo "ERROR: .env commiteado" || echo "OK: .env no commiteado"
```

## Reporte final

Cuando termines, dame este resumen exacto:

```
✅ Qué he hecho:
   - Laravel 12.x.x instalado.
   - Pest 3.x.x instalado, PHPUnit removido.
   - Tailwind 3.x con tokens de DESIGN.md aplicados.
   - resources/css/app.css cargando Instrument Serif + General Sans desde Bunny.
   - Ruta /up health check añadida y verificada (200 OK, body "OK").
   - npm run build pasa.
   - ./vendor/bin/pest pasa N tests.
   - git init -b main + 1 commit + push a origin/main.
   - Tamaño del commit: N archivos, N líneas insertadas.
⏳ Qué falta:
   - Bloque 01b (GitHub Actions CI).
   - Bloque 02 (env + DB + verificación cron + borrado dump SQL público + cleanup REVISION MENSUAL).
❓ Qué necesito del usuario:
   - Confirmar que el repo en https://github.com/blancosampedro-ship-it/pivscrtm muestra el primer commit.
   - Si falló algo, leer los logs en /tmp/winfin-serve.log o el output de Composer/npm.
```

END PROMPT
```

---

## Lo que viene tras este bloque

- **Bloque 01b** — GitHub Actions CI (Pint + Pest en cada push). 30 min de setup, blinda regresiones desde el día 1.
- **Bloque 02** — `.env` apuntando a SiteGround Remote MySQL + whitelisting IP + verificación SQL emails duplicados (ADR-0005 §4) + verificación cron real + borrado dump SQL público + cleanup one-shot REVISION MENSUAL.
- **Bloque 03** — Eloquent models para las 14 tablas legacy con accessors/mutators de charset latin1↔utf8mb4.
