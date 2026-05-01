# Bloque 07b — Fix dirección del cast `Latin1String` (ADR-0011)

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~25-35 min.

---

## Objetivo

Corregir el cast `App\Casts\Latin1String` cuyas direcciones `get()` y `set()` están **invertidas**. Esto se descubrió en el smoke real post-Bloque 07: el panel admin muestra municipios y direcciones con mojibake como `"AlcalÃÂ¡ de Henares"` (debería ser `"Alcalá de Henares"`).

**Causa raíz**: la BD legacy guarda texto **doble-encoded** (PHP 2014 escribió bytes UTF-8 en columnas `latin1` sin transcoding). Al leerlos vía conexión `utf8mb4` actual de Laravel, MySQL transcodifica de `latin1` columnar a `utf8mb4` connection: cada byte UTF-8 originalmente almacenado se interpreta como char latin1 y se re-codifica como UTF-8 → mojibake `c3 a1` (`á` original) acaba siendo `c3 83 c2 a1` (`Ã¡`).

El cast actual hace `mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1')` en `get()` (interpreta input como latin1 y produce utf8). Eso EMPEORA el mojibake porque el input ya es utf8 doblemente-encoded, no latin1 plano.

La dirección correcta es la inversa: `mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8')` en `get()` (= `utf8_decode`). Verificado experimentalmente con el municipio Alcalá de Henares (modulo_id=45):

```
raw bytes BD (después de utf8mb4 connection transcoding): c3 83 c2 a1 = "Ã¡"
cast actual:        AlcalÃÂ¡ de Henares    (más mojibake)
cast invertido:     Alcalá de Henares       ✓
```

Para escrituras, la dirección invertida también es correcta:
```
admin teclea "á" (utf8 c3 a1) -> cast set (utf8_encode) -> c3 83 c2 a1
-> via utf8mb4 connection -> MySQL latin1 column transcoding -> c3 a1 stored
-> mismo patrón que legacy. Continuidad con app vieja preservada.
```

## Definition of Done

1. ADR-0011 nuevo en `docs/decisions/0011-latin1-cast-direction-fix.md` documentando el bug, el patrón doble-encoded de la BD legacy, la solución (flip simétrico) y los límites (no resuelve el caso teórico de filas correctamente almacenadas single-byte latin1 — no se ha visto ninguna en prod).
2. `app/Casts/Latin1String.php` con `get()` y `set()` swapeados.
3. `tests/Unit/Casts/Latin1StringTest.php` actualizado:
   - Roundtrip tests siguen pasando (set+get = identity siempre, con cualquier dirección).
   - El test `reverses pre-existing mojibake on get` se reescribe usando bytes reales del patrón doble-encoded de prod (4 bytes `c3 83 c2 a1` para `á`), no el sintético previo.
   - Test nuevo `set_produces_legacy_compatible_bytes` verifica que tras `set()`, los bytes resultantes son los que MySQL almacenará como `c3 a1` en columna latin1 (mismo patrón que la app vieja).
4. Los demás tests (95 actuales) siguen verdes.
5. `pint --test`, `pest`, `npm run build` verdes.
6. PR creado, CI 3/3 verde.
7. **Post-merge**: smoke real en `/admin/piv` → municipios y direcciones se ven correctos (sin mojibake).

---

## Decisiones documentadas en ADR-0011

- **Por qué no se hace refactor a dos conexiones** (legacy charset=latin1 + lv charset=utf8mb4): es la solución arquitectónicamente más limpia pero requiere modificar 12+ modelos. Diferida a un bloque futuro si aparecen casos donde el flip simétrico no funcione (p. ej. caracteres fuera de latin1 como kanji).
- **Por qué no se normaliza la BD primero**: arreglar las 575 filas Piv + 103 Modulo + 41 Operador + 65 Tecnico requiere migración de datos que afecta también a la app vieja en producción. Riesgo > beneficio mientras la app vieja sigue corriendo.
- **Por qué solo Spanish chars**: el dominio del producto es B2B España. No hay caracteres asiáticos ni cirílicos en el dataset. Latin1 cubre completamente á, é, í, ó, ú, ñ, Á, É, etc.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (convenciones)
- CLAUDE.md (división trabajo)
- docs/prompts/07b-fix-latin1-cast-direction.md (este archivo)
- app/Casts/Latin1String.php (código actual del cast)
- tests/Unit/Casts/Latin1StringTest.php (tests existentes)

Tu tarea: invertir las direcciones del cast Latin1String + actualizar tests + ADR-0011.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd
git branch --show-current        # main
git rev-parse HEAD               # debe ser d517de4 (post Bloque 07)
git status --short               # vacío
./vendor/bin/pest --colors=never --compact 2>&1 | tail -3
```

Los 95 tests deben estar verdes.

```bash
git checkout -b bloque-07b-fix-latin1-cast-direction
```

PARA: "Branch creada. ¿Procedo a Fase 1 (ADR-0011)?"

## FASE 1 — Escribir ADR-0011

Crea `docs/decisions/0011-latin1-cast-direction-fix.md`:

```markdown
# 0011 — Corrección de dirección del cast `Latin1String`

- **Status**: Accepted
- **Date**: 2026-05-01
- **Amends**: ADR-0002 (database coexistence). El cast sigue siendo necesario; solo la dirección se corrige.

## Context

El cast `App\Casts\Latin1String` (Bloque 03) intenta resolver mojibake en columnas de tablas legacy con charset físico latin1, leídas vía conexión Laravel utf8mb4. La implementación inicial asumía que los bytes del lado MySQL llegan a PHP como latin1 sin transcodificar — incorrecto porque la conexión utf8mb4 SÍ transcodifica.

Smoke real en Bloque 07 (1 may 2026) reveló mojibake severo en el panel admin:
- `Modulo::find(45)->nombre` mostraba `"AlcalÃÂ¡ de Henares"` (debería ser `"Alcalá de Henares"`).
- `Piv::find(8)->direccion` mostraba `"PZA. CERVANTES, ESQ. C/ SANTA ÃÅ¡RSULA"` (debería tener `"ÚRSULA"`).

Diagnóstico de bytes:
```
raw bytes (vía utf8mb4 connection) para Alcalá: 41 6c 63 61 6c c3 83 c2 a1 20 ...
                                                          └── "Ã¡" en utf8 ──┘
cast actual aplicado: AlcalÃÂ¡ de Henares       (más mojibake — peor)
cast con dirección invertida: Alcalá de Henares  ✓
```

La explicación: la BD legacy tiene texto **doble-encoded**. PHP 2014 escribía bytes UTF-8 directamente en columnas latin1 sin transcoding. Stored: `c3 a1` (los 2 bytes utf8 de `á` interpretados como 2 chars latin1). Al leer vía utf8mb4 connection: MySQL transcodifica cada char latin1 (`c3` = "Ã", `a1` = "¡") a su equivalente utf8 (`c3 83`, `c2 a1`). PHP recibe los 4 bytes y los muestra como `Ã¡`.

Para revertir, el cast debe hacer `mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8')` (= `utf8_decode`) en `get()`, no `mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1')`. Las direcciones del cast estaban literalmente invertidas.

Las roundtrip tests del Bloque 03 pasaban porque `set` + `get` son funciones inversas en cualquier dirección, así que `set(get(x)) == x`. Solo el test `reverses pre-existing mojibake on get` simulaba un escenario semi-realista, pero el input sintético usado (`mb_convert_encoding('Móstoles', 'ISO-8859-1', 'UTF-8')` = 1 byte por char) NO coincide con el patrón real de prod (2-bytes-utf8 stored as 2-chars-latin1, transcoded to 4-bytes-utf8 by connection).

## Decision

Swapear las direcciones del cast `Latin1String`:

```php
// get() — lectura: bytes utf8 doblemente-encoded recibidos via utf8mb4 connection -> string utf8 limpio
public function get($model, $key, $value, $attributes): ?string
{
    if ($value === null) return null;
    return mb_convert_encoding((string) $value, 'ISO-8859-1', 'UTF-8');  // antes: 'UTF-8', 'ISO-8859-1'
}

// set() — escritura: string utf8 limpio -> bytes utf8 que MySQL almacenará como latin1 conservando el patrón legacy
public function set($model, $key, $value, $attributes): ?string
{
    if ($value === null) return null;
    return mb_convert_encoding((string) $value, 'UTF-8', 'ISO-8859-1');  // antes: 'ISO-8859-1', 'UTF-8'
}
```

### Verificación de simetría

Lectura del prod data:
- BD almacena `c3 a1` (latin1 col) — patrón legacy.
- Connection utf8mb4 transcodifica → 4 bytes `c3 83 c2 a1`.
- Cast nuevo `get()` → 2 bytes `c3 a1`.
- Browser/PHP interpreta como utf8 → `á` ✓

Escritura desde formulario admin:
- Admin teclea `á` (utf8 `c3 a1`).
- Cast nuevo `set()` → 4 bytes `c3 83 c2 a1`.
- Connection utf8mb4 → MySQL transcodifica latin1 col → 2 bytes `c3 a1`.
- BD almacena `c3 a1` ✓ (mismo patrón que la app vieja escribe → continuidad)

## Considered alternatives

- **Refactor a dos conexiones (legacy charset=latin1 + lv charset=utf8mb4)** — solución arquitectónicamente más limpia. Requiere modificar 12+ modelos para añadir `protected $connection = 'legacy'`. Diferida a un bloque futuro si aparecen casos donde el flip simétrico no funcione (p. ej. caracteres fuera del rango latin1 como kanji o cirílico). Para B2B España no aplica hoy.
- **Normalización de la BD legacy** (script que convierte todas las filas latin1-double-encoded a single-byte latin1 limpio) — descartada: requiere migración masiva en prod mientras la app vieja sigue corriendo. La app vieja también escribe el mismo patrón doble-encoded; tras normalizar lo volvería a romper. Solo factible tras el cutover (Fase 7).
- **Set connection charset=latin1** (1 línea en config) — descartada: rompe lectura de tablas `lv_*` que sí están en utf8mb4. Cualquier nombre con acento en `lv_users.name` (técnicos lazy-creados) sale corrupto.

## Consequences

**Positivas:**
- Mojibake desaparece en lecturas. Admin ve municipios, direcciones, nombres correctos.
- Escrituras desde admin producen el mismo patrón que la app vieja (continuidad bidireccional).
- 12 modelos legacy ya tienen el cast aplicado (Piv, Modulo, Operador, Tecnico, Revision, Averia, Correctivo, DesinstaladoPiv, ReinstaladoPiv) — todos se benefician sin tocar.

**Negativas:**
- Si en algún momento aparecieran filas correctamente almacenadas single-byte latin1 (ej. byte `e1` para `á` en lugar de `c3 a1`), el flip las rompería al leer. Hasta ahora no se ha encontrado ninguna en los datasets explorados. Si aparece, se gestiona caso por caso y/o se acelera el refactor a dos conexiones.

**Implementación**:
- Solo cambia `App\Casts\Latin1String` (2 líneas).
- Tests roundtrip siguen verdes.
- Test `reverses pre-existing mojibake on get` se reescribe con input real de prod (4 bytes utf8 doblemente encoded).
- Test nuevo `set_produces_legacy_compatible_bytes` verifica que el output de `set()` matchea el patrón legacy.
```

PARA: "Fase 1 completa: ADR-0011 escrito. ¿Procedo a Fase 2 (flip cast)?"

## FASE 2 — Flip dirección de Latin1String

Lee `app/Casts/Latin1String.php`. Localiza los métodos `get()` y `set()`.

En `get()`, cambia:
```php
return mb_convert_encoding((string) $value, 'UTF-8', 'ISO-8859-1');
```
por:
```php
// Lectura: prod tiene texto doblemente encoded (PHP 2014 escribió bytes utf8
// en columna latin1 sin transcoding; la conexión utf8mb4 los retransform a 4
// bytes "Ã¡"). utf8_decode los devuelve a 2 bytes válidos utf8 "á". ADR-0011.
return mb_convert_encoding((string) $value, 'ISO-8859-1', 'UTF-8');
```

En `set()`, cambia:
```php
return mb_convert_encoding((string) $value, 'ISO-8859-1', 'UTF-8');
```
por:
```php
// Escritura: utf8 entrante "á" (c3 a1) -> 4 bytes "Ã¡" (c3 83 c2 a1). MySQL
// transcodifica de utf8mb4 connection a latin1 column -> 2 bytes (c3 a1)
// almacenados. Mismo patrón que la app vieja escribe. ADR-0011.
return mb_convert_encoding((string) $value, 'UTF-8', 'ISO-8859-1');
```

Actualiza también el docblock de la clase (encima de `class Latin1String implements...`) para reflejar la nueva semántica.

PARA: "Fase 2 completa: cast invertido. ¿Procedo a Fase 3 (tests)?"

## FASE 3 — Actualizar tests del cast

Lee `tests/Unit/Casts/Latin1StringTest.php`. Reemplaza el test `reverses pre-existing mojibake on get`:

```php
it('reverses prod-style double-encoded mojibake on get', function () {
    // Input simula los bytes que MySQL devuelve via conexión utf8mb4 cuando lee
    // una columna latin1 que tiene texto doblemente encoded (patrón real
    // observado en producción 1 may 2026, ADR-0011).
    //
    // BD prod: bytes utf8 (c3 a1 = "á") almacenados como 2 chars latin1.
    // Connection transcoding: cada char latin1 -> utf8 -> 4 bytes c3 83 c2 a1.
    // PHP recibe esos 4 bytes y muestra "Ã¡" si no se aplica cast.

    $prodBytes = "Alcal\xc3\x83\xc2\xa1 de Henares";  // hex c3 83 c2 a1 = "Ã¡"

    expect($this->cast->get($this->model, 'col', $prodBytes, []))
        ->toBe('Alcalá de Henares');
});
```

Añade un test nuevo:

```php
it('set produces legacy-compatible bytes for storage', function () {
    // Tras set(), los bytes deben ser tales que MySQL los transcoda a utf8mb4
    // connection -> latin1 column como `c3 a1` (mismo patrón legacy app vieja).

    $cleaned = $this->cast->set($this->model, 'col', 'Alcalá de Henares', []);

    // Verifica que el output de set tiene 4 bytes en la posición de "á",
    // mismos que la app vieja produce (c3 83 c2 a1).
    expect(substr($cleaned, 5, 4))->toBe("\xc3\x83\xc2\xa1");
});
```

Corre los tests del cast:
```bash
./vendor/bin/pest tests/Unit/Casts/Latin1StringTest.php --colors=never --compact 2>&1 | tail -15
```

7 tests verdes esperados (los 6 originales + 1 nuevo). Si falla algún roundtrip, AVISA — sería bug de la swap.

Después corre TODA la suite:
```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -15
```

96 tests verdes esperados (95 + 1 nuevo). Si algún test de modelo falla por la nueva dirección del cast, revisar — los factories pueden estar generando datos que ahora se transforman distinto. Si aparece algún fallo, AVISA con el error exacto antes de tocar nada más.

PARA: "Fase 3 completa: tests verdes. ¿Procedo a Fase 4 (pint + commits + PR)?"

## FASE 4 — pint + commits + PR

```bash
./vendor/bin/pint --test 2>&1 | tail -5
npm run build 2>&1 | tail -3
```

Si pint pide cambios, corre `./vendor/bin/pint` y commitea como `style:` aparte.

Stage por archivo:

1. `docs: add Bloque 07b prompt + ADR-0011 (latin1 cast direction fix)` — `docs/prompts/07b-fix-latin1-cast-direction.md` + `docs/decisions/0011-latin1-cast-direction-fix.md`.
2. `fix(casts): invert Latin1String get/set direction (ADR-0011)` — `app/Casts/Latin1String.php`.
3. `test: rewrite Latin1String mojibake test with real prod-pattern bytes` — `tests/Unit/Casts/Latin1StringTest.php`.

Push + PR:

```bash
git push -u origin bloque-07b-fix-latin1-cast-direction
gh pr create \
  --base main \
  --head bloque-07b-fix-latin1-cast-direction \
  --title "Bloque 07b — Fix dirección invertida en cast Latin1String (ADR-0011)" \
  --body "$(cat <<'BODY'
## Resumen

Corrige el cast `App\Casts\Latin1String` cuyas direcciones `get()`/`set()` estaban literalmente invertidas. Smoke real en Bloque 07 reveló mojibake severo en el panel admin (`AlcalÃÂ¡ de Henares` en lugar de `Alcalá de Henares`). ADR-0011 documenta la causa raíz (datos prod doblemente encoded) y la solución (flip simétrico).

## Cambio

- `Latin1String::get()`: ahora hace `utf8_decode` (utf8 → latin1). Antes hacía utf8 ← latin1 (al revés).
- `Latin1String::set()`: ahora hace `utf8_encode` (latin1 → utf8). Antes hacía latin1 ← utf8 (al revés).

Ambas direcciones siguen siendo simétricamente inversas, así que los roundtrip tests siguen verdes.

## Verificación

Diagnosticado experimentalmente vs BD prod (modulo_id=45 = "Alcalá de Henares"):

| | Resultado |
|---|---|
| Cast actual | `AlcalÃÂ¡ de Henares` (más mojibake) |
| Cast invertido | `Alcalá de Henares` ✓ |

Para escrituras: utf8 input → cast → 4 bytes utf8mb4 → MySQL latin1 col stores 2 bytes (mismo patrón legacy app vieja). Continuidad bidireccional preservada.

## Tests

- 6 tests existentes siguen verdes (roundtrip, null, ASCII).
- Test `reverses pre-existing mojibake on get` reescrito para usar el patrón real de prod (4 bytes utf8 doublemente encoded).
- Test nuevo `set_produces_legacy_compatible_bytes` verifica que el output de set respeta el patrón legacy.

## Post-merge

Smoke real en `/admin/piv` → tabla con municipios y direcciones legibles sin mojibake.

## CI esperado

3/3 jobs verde (PHP 8.2, PHP 8.3, Vite build).
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-07b-fix-latin1-cast-direction --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ Qué he hecho:
   - ADR-0011 documenta el bug de dirección + por qué el flip simétrico funciona.
   - Latin1String::get() y set() invertidos. Comentarios inline citando ADR.
   - Test `reverses pre-existing mojibake on get` reescrito con bytes reales prod.
   - Test nuevo `set_produces_legacy_compatible_bytes`.
   - 96 tests verdes (suite total).
   - Pint clean. Build OK.
   - 3 commits Conventional Commits.
   - PR #N: [URL].
   - CI 3/3 verde.

⏳ Qué falta:
   - (Manual, post-merge) Smoke real en /admin/piv: municipios y direcciones se ven correctos sin mojibake.
   - Bloque 08 — Resources Averia + Asignacion.

❓ Qué necesito del usuario:
   - Confirmar PR.
   - Mergear (Rebase and merge sugerido).
   - Tras merge, smoke en navegador.
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 07b

1. Smoke real en `/admin/piv` — municipios "Alcalá de Henares", direcciones con tildes, todo legible.
2. Bloque 08 — Resources de `Averia` + `Asignacion`.
