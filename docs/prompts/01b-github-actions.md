# Bloque 01b — GitHub Actions CI (Pint + Pest + Vite build)

> **Cómo se usa este archivo:** copia el bloque `BEGIN PROMPT` … `END PROMPT` que está al final, pégalo en VS Code Copilot Chat con modo Agent activado en `~/Documents/winfin-piv/`. Tarda ~10-15 min en total (la mayoría es Composer/npm install dentro del runner GitHub).

---

## Objetivo del bloque

Dejar funcionando un workflow CI en `.github/workflows/ci.yml` que en cada `push` a `main` y cada `pull_request` corre:

1. **PHP matrix sobre 8.2 y 8.3** (NO 8.4 — el Mac local corre 8.4.8 pero producción es 8.2.30; cualquier syntax 8.4-only debe romper CI antes de mergearse).
2. `./vendor/bin/pint --test` — verifica formato del código (dry run, no modifica).
3. `./vendor/bin/pest` — corre los tests con SQLite in-memory.
4. **Vite build job separado** — `npm run build` para verificar que la cadena Tailwind/PostCSS compila sin errores.

**Por qué este bloque va antes que el 02:**

El Mac local del usuario corre **PHP 8.4.8** mientras producción SiteGround corre **PHP 8.2.30**. Cualquier código que Copilot escriba con sintaxis 8.3+ (asymmetric visibility, lazy ghost objects, `#[\Override]` con cierta semántica nueva) arranca local pero peta en deploy. **Sin CI, este riesgo está abierto en cada commit.** Con CI: cualquier syntax newer falla en GitHub Actions y bloquea el merge.

**Definition of Done de este bloque:**
1. `.github/workflows/ci.yml` existe y es YAML válido (sin `actionlint`-equivalente disponible local, validamos sintaxis YAML básica).
2. Push a GitHub dispara el workflow.
3. Los **3 jobs pasan en verde** (php-tests sobre 8.2, php-tests sobre 8.3, frontend-build).
4. Commit conventional `chore: add GitHub Actions CI workflow (Pint + Pest + Vite)`.
5. README.md actualizado con badge de CI status (opcional pero recomendado para visibilidad).

---

## Riesgos y mitigaciones

- **Tests Pest que requieren BD**: el smoke test default no toca BD, pero Laravel 12 puede tener un `phpunit.xml` que asume MySQL. Mitigación: env vars explícitas en CI (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `SESSION_DRIVER=array`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`).
- **Composer install lento la primera vez**: caché Composer + caché npm reduce de ~3 min a ~30s en runs subsecuentes. Cache key incluye `php-version` para que cada matrix entry tenga su caché.
- **Workflow no se ejecuta en el primer push**: GitHub Actions corre el workflow tal cual existe en el commit que lo introduce. Es decir, el commit que añade `ci.yml` SÍ dispara el workflow contra él mismo. No hay que preocuparse por "el primer commit no tiene CI".
- **Permisos `gh`**: ya configurado en Bloque 01 vía `gh auth setup-git`. Sin acción adicional.
- **Pint a la primera puede fallar** si Laravel 12 generó código con formato inconsistente (poco probable pero posible). Mitigación: si falla, correr `./vendor/bin/pint` (sin `--test`) localmente y commitear el formateo en un commit aparte ANTES de añadir el workflow.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero `.github/copilot-instructions.md`, `CLAUDE.md` y este archivo (`docs/prompts/01b-github-actions.md`).

Tu tarea: implementar el Bloque 01b — añadir GitHub Actions CI con tres jobs (php-tests matrix 8.2+8.3, frontend-build) que corren Pint, Pest y Vite build en cada push a main y pull request a main.

Sigue estos pasos en orden. Tras pasos críticos, muéstrame el resultado y espera confirmación antes del siguiente.

## Paso 0 — Pre-flight check

Verifica el estado del repo:

```bash
pwd                                 # /Users/winfin/Documents/winfin-piv
git branch --show-current           # main
git log --oneline | head -5         # debe mostrar al menos 2 commits del Bloque 01
git remote -v                       # origin = blancosampedro-ship-it/pivscrtm
git status                          # clean working tree
```

Si el working tree no está limpio o no estamos en `main`, AVISA y para.

Verifica que `gh` CLI está autenticado contra `blancosampedro-ship-it`:

```bash
gh auth status 2>&1 | grep -E 'Logged in|account'
```

Si no, AVISA y para.

## Paso 1 — Pint preflight: formatear código antes del workflow

Antes de añadir el CI, corre Pint localmente para que el repo esté en estado limpio. Si Pint detecta cambios, los commiteamos PRIMERO en su propio commit conventional, ANTES del workflow. Razón: si el primer run del CI falla por Pint, es ruido innecesario.

```bash
./vendor/bin/pint --test 2>&1 | tail -10
```

Si Pint dice "All files are correctly formatted" → no hay nada que commitear, sigue al Paso 2.

Si Pint reporta archivos a corregir:

```bash
./vendor/bin/pint 2>&1 | tail -10
git diff --stat
git add -u
git commit -m "style: apply Pint formatting baseline"
```

**No commitees archivos no relacionados con Pint en este commit.** Solo `git add -u` (modificados, no nuevos).

## Paso 2 — Crear `.github/workflows/ci.yml`

Crea el directorio si no existe:

```bash
mkdir -p .github/workflows
```

Escribe el archivo `.github/workflows/ci.yml` con EXACTAMENTE este contenido:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]
  workflow_dispatch:

permissions:
  contents: read

concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  php-tests:
    name: PHP ${{ matrix.php }} — Pint + Pest
    runs-on: ubuntu-24.04
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3']
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP ${{ matrix.php }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: dom, curl, libxml, mbstring, zip, pcntl, bcmath, intl, fileinfo, sqlite3, pdo_sqlite
          coverage: none
          tools: composer:v2

      - name: Get composer cache directory
        id: composer-cache
        run: echo "dir=$(composer config cache-files-dir)" >> $GITHUB_OUTPUT

      - name: Cache composer dependencies
        uses: actions/cache@v4
        with:
          path: ${{ steps.composer-cache.outputs.dir }}
          key: composer-php${{ matrix.php }}-${{ hashFiles('**/composer.lock') }}
          restore-keys: composer-php${{ matrix.php }}-

      - name: Install PHP dependencies
        run: composer install --prefer-dist --no-interaction --no-progress --no-scripts

      - name: Run package discovery
        run: php artisan package:discover --ansi

      - name: Bootstrap environment
        run: |
          cp .env.example .env
          php artisan key:generate --ansi

      - name: Pint (code style — dry run)
        run: ./vendor/bin/pint --test

      - name: Pest (unit + feature tests)
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'
          SESSION_DRIVER: array
          CACHE_STORE: array
          QUEUE_CONNECTION: sync
          MAIL_MAILER: array
        run: ./vendor/bin/pest --colors=always --compact

  frontend-build:
    name: Vite build (Tailwind + PostCSS)
    runs-on: ubuntu-24.04
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup Node 22
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: 'npm'

      - name: Install npm dependencies
        run: npm ci

      - name: Build assets
        run: npm run build

      - name: Verify build output
        run: |
          test -d public/build/assets || { echo "::error::public/build/assets missing"; exit 1; }
          ls public/build/assets/ | grep -E '\.(css|js)$' > /dev/null || { echo "::error::no compiled assets in public/build/assets/"; exit 1; }
          echo "Build OK — assets present:"
          ls -la public/build/assets/
```

Verifica que el archivo se escribió correctamente:

```bash
cat .github/workflows/ci.yml | head -20
ls -la .github/workflows/
```

## Paso 3 — Validación YAML local

Sin `actionlint` instalado, valida YAML básico con Python (que el Mac trae):

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml')); print('YAML OK')"
```

Si falla, AVISA y para — el archivo tiene error de sintaxis.

## Paso 4 — README.md badge (opcional pero recomendado)

Edita `README.md` y añade un badge debajo del título principal. Busca la línea `# Winfin PIV` y la línea `> CMMS de gestión...` justo debajo. Inserta entre ellas un badge:

```markdown
# Winfin PIV

[![CI](https://github.com/blancosampedro-ship-it/pivscrtm/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/blancosampedro-ship-it/pivscrtm/actions/workflows/ci.yml)

> CMMS de gestión de paneles de información al viajero (PIVs) en marquesinas — versión moderna en Laravel 12 + Filament 3.
```

El badge no será verde hasta que el primer run del workflow termine. Es esperable.

## Paso 5 — Smoke test local de la cadena CI

Antes de pushear, verifica que los comandos del workflow corren limpios localmente:

```bash
echo "--- pint --test ---"
./vendor/bin/pint --test

echo "--- pest con env de CI ---"
DB_CONNECTION=sqlite DB_DATABASE=':memory:' SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync MAIL_MAILER=array ./vendor/bin/pest --colors=always --compact

echo "--- npm run build ---"
npm run build
```

Los tres deben pasar. Si alguno falla local, va a fallar en CI también — PARA y AVISA antes de pushear.

## Paso 6 — Commit + push

```bash
git status
git add .github/workflows/ci.yml README.md
git status
```

Verifica que el stage solo contiene `ci.yml` y `README.md`. Si hay algo más, AVISA y para.

```bash
git commit -m "chore: add GitHub Actions CI workflow (Pint + Pest + Vite)" -m "Anade .github/workflows/ci.yml con 3 jobs:

- php-tests matrix 8.2 + 8.3 (NO 8.4 — prod SiteGround es 8.2 y cualquier
  syntax 8.3+/8.4-only que escape el Mac local 8.4.8 falla aqui antes de
  mergear).
- pint --test para verificar formato.
- pest con SQLite in-memory + drivers array para no depender de servicios.
- frontend-build separado: npm ci + npm run build + verificacion de
  artefactos en public/build/assets/.

Concurrency: cancela runs anteriores del mismo branch al pushear nuevo
commit. Permissions: contents read (no write — el CI no toca el repo).

Cierra el riesgo PHP 8.2 vs 8.4 que quedo abierto tras Bloque 01.

Anade tambien badge de CI status en README.md."

git push origin main
```

## Paso 7 — Esperar al workflow y verificar

Tras el push, GitHub Actions arranca el workflow. Espera a que termine:

```bash
echo "Esperando 5 segundos para que GitHub registre el run..."
sleep 5

echo "--- runs recientes ---"
gh run list --workflow=ci.yml --limit 3

echo "--- siguiendo el run en vivo ---"
gh run watch --exit-status
```

`gh run watch --exit-status` se queda esperando hasta que el run termine y devuelve exit code 0 si verde, distinto de 0 si rojo. **Esto puede tardar 3-7 min** (composer install + pest + npm ci + build).

Si `gh run watch` devuelve verde:

```bash
gh run list --workflow=ci.yml --limit 1
```

Confirmar que aparece `completed  success`.

Si rojo: capturar logs de los jobs fallidos:

```bash
RUN_ID=$(gh run list --workflow=ci.yml --limit 1 --json databaseId --jq '.[0].databaseId')
echo "Run fallido: $RUN_ID"
gh run view $RUN_ID --log-failed | tail -100
```

PARA y AVISA con los logs. NO intentes "arreglar y repushear" sin confirmación.

## Paso 8 — Reporte final

Cuando todo verde, dame este resumen exacto:

```
✅ Qué he hecho:
   - .github/workflows/ci.yml creado con 3 jobs.
   - Matrix PHP: 8.2 + 8.3 (NO 8.4, proteccion contra drift Mac local vs prod).
   - Pint --test + Pest (SQLite memory) + Vite build verificados.
   - YAML validado localmente con yaml.safe_load.
   - Smoke test local pasado: pint OK, pest OK, npm run build OK.
   - Commit: chore: add GitHub Actions CI workflow (Pint + Pest + Vite).
   - Push exitoso. RUN_ID del primer run: [N].
   - Workflow termino en VERDE (3/3 jobs success).
   - Tiempo total del run: [duracion].
   - Badge anadido a README.md.
   - (si fue necesario) Commit previo "style: apply Pint formatting baseline".

⏳ Qué falta:
   - Bloque 02 — env real SiteGround + 4 tareas criticas (borrar dump SQL,
     verificar emails duplicados, verificar cron, cleanup REVISION MENSUAL).

❓ Qué necesito del usuario:
   - Confirmar que la pestana "Actions" del repo en GitHub muestra el run
     con check verde en el ultimo commit.
   - Confirmar que el badge en README.md (en GitHub web) renderiza verde.
```

END PROMPT
```

---

## Lo que verifica este bloque

| Cosa | Cómo | Frecuencia |
|---|---|---|
| Sintaxis PHP 8.2-compatible | `php-tests` matrix run on 8.2 | Cada push y PR |
| Forward-compat 8.3 | `php-tests` matrix run on 8.3 | Cada push y PR |
| Formato del código | `./vendor/bin/pint --test` | Cada push y PR |
| Tests verde | `./vendor/bin/pest` | Cada push y PR |
| Tailwind/PostCSS compilan | `npm run build` | Cada push y PR |
| Assets renderizan | Verificación de `public/build/assets/*.css|js` | Cada push y PR |

**Lo que NO verifica este bloque** (deferido a otros):
- Coverage de tests (defer).
- PHPStan / Larastan static analysis (defer; lo añadiremos cuando hagamos el primer modelo Eloquent en Bloque 03).
- Conexión real a SiteGround MySQL (Bloque 02 lo configura, no lo testea aquí).
- Lighthouse performance / accessibility (defer hasta tener UI real, post-Bloque 11).
- Security scan (defer; podemos añadir `composer audit` en una iteración futura).

## Después de este bloque

- **Bloque 02** — `.env` real apuntando a SiteGround Remote MySQL + las 4 tareas críticas (borrar dump SQL público AHORA, verificar emails duplicados ADR-0005 §4, verificar cron real SiteGround, cleanup one-shot REVISION MENSUAL).
- **Bloque 03** — Eloquent models para las 14 tablas legacy con accessors/mutators de charset latin1↔utf8mb4.
