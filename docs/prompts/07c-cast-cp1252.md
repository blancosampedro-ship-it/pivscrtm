# Bloque 07c — Cast `Latin1String` usa Windows-1252 en lugar de ISO-8859-1

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~15-25 min.

---

## Objetivo

Después del fix de dirección del cast (Bloque 07b, ADR-0011), el smoke real reveló que algunas columnas con caracteres como `Ú`, `š` siguen mostrando mojibake (`�?RSULA` en lugar de `ÚRSULA`). Causa: MySQL para `charset=latin1` usa internamente Windows-1252 (cp1252), no ISO-8859-1 puro. ISO-8859-1 deja undefined el rango `0x80-0x9F`; Windows-1252 lo rellena con `š` (`0x9A`), `€` (`0x80`), etc.

Diagnóstico contra prod (1 may 2026):

| Columna BD | Raw bytes | Cast actual (ISO-8859-1) | Cast con WINDOWS-1252 |
|---|---|---|---|
| `modulo.nombre` (Alcalá) | `c3 83 c2 a1` | `Alcalá de Henares` ✓ | `Alcalá de Henares` ✓ |
| `piv.direccion` (Úrsula) | `c3 83 c5 a1` | `�?RSULA` ✗ | `ÚRSULA` ✓ |

La `Ú` original (`c3 9a` en utf8) se almacenó como 2 chars latin1. MySQL leyó byte `9a` y, según la convención `latin1 ≡ cp1252`, lo mapeó a `š` (utf8 `c5 a1`). Para revertir necesitamos cp1252, no ISO-8859-1 puro.

ADR-0011 amendado (no nuevo ADR — la decisión es la misma, solo la encoding source cambia).

## Definition of Done

1. ADR-0011 actualizado con un postscript "Refinement (Bloque 07c)" explicando la diferencia ISO-8859-1 vs Windows-1252.
2. `app/Casts/Latin1String.php` con ambos `mb_convert_encoding` usando `'WINDOWS-1252'` en lugar de `'ISO-8859-1'`.
3. Tests `Latin1StringTest`:
   - Test `set produces legacy-compatible bytes for storage` sigue verde (los chars básicos producen mismos bytes en cp1252 e ISO-8859-1).
   - Test nuevo `handles_uppercase_u_with_acute` cubre el caso real de prod (`Ú` doble-encoded).
4. Suite total verde.
5. PR + CI verde.
6. Smoke real: `php artisan tinker --execute='echo \App\Models\Piv::find(8)->direccion'` debe mostrar `URSULA` (con `Ú` legible).

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (convenciones)
- CLAUDE.md (división trabajo)
- docs/decisions/0011-latin1-cast-direction-fix.md (ADR a amendar)
- docs/prompts/07c-cast-cp1252.md (este archivo)
- app/Casts/Latin1String.php (código actual)

Tu tarea: cambiar la encoding source del cast de ISO-8859-1 a WINDOWS-1252 + actualizar tests + amendar ADR-0011.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
git branch --show-current        # main
git rev-parse HEAD               # debe ser 27861cd (post Bloque 07b)
git status --short               # esperado: solo `M TODOS.md` (Claude actualizó el TODO de 3 piv corruptos)
./vendor/bin/pest --colors=never --compact 2>&1 | tail -3
```

96 tests verdes esperados. Si `git status` muestra cambios distintos de `TODOS.md`, AVISA.

```bash
git checkout -b bloque-07c-cast-cp1252
```

PARA: "Branch creada. ¿Procedo a Fase 1 (cast)?"

## FASE 1 — Actualizar Latin1String

Lee `app/Casts/Latin1String.php`. En `get()` y `set()`, sustituye `'ISO-8859-1'` por `'WINDOWS-1252'`. Resultado:

```php
public function get(Model $model, string $key, mixed $value, array $attributes): ?string
{
    if ($value === null) {
        return null;
    }

    return mb_convert_encoding((string) $value, 'WINDOWS-1252', 'UTF-8');
}

public function set(Model $model, string $key, mixed $value, array $attributes): ?string
{
    if ($value === null) {
        return null;
    }

    return mb_convert_encoding((string) $value, 'UTF-8', 'WINDOWS-1252');
}
```

Actualiza el comentario de `get()` para mencionar Windows-1252:

```php
/**
 * Lectura: prod tiene texto doblemente encoded. La conexión utf8mb4 entrega
 * bytes que originalmente eran utf8 almacenados como cp1252 (que MySQL usa
 * internamente para `charset=latin1`). Ver ADR-0011 + postscript Bloque 07c.
 */
```

PARA: "Fase 1 completa: cast usa WINDOWS-1252. ¿Procedo a Fase 2 (tests)?"

## FASE 2 — Actualizar tests

Lee `tests/Unit/Casts/Latin1StringTest.php`.

**Roundtrip tests existentes** (Móstoles, ñoño, ÁÉÍÓÚÑ, ASCII): siguen verdes porque `set+get` es inverso simétrico independiente de la encoding source.

**Test `set produces legacy-compatible bytes`**: sigue verde porque `á` está en el rango compartido latin1 ∩ cp1252.

**Test `reverses prod-style double-encoded mojibake on get`**: sigue verde por la misma razón.

**Añade test nuevo** que cubre el caso real de Ú (que ISO-8859-1 no resolvía):

```php
it('handles uppercase U with acute through cp1252 encoding', function () {
    // Patrón real de prod (Bloque 07c): Ú legacy almacenada como utf8 c3 9a
    // en columna latin1; MySQL transcodifica a utf8mb4 connection mapeando
    // byte 9a vía Windows-1252 (-> š) y entrega 4 bytes c3 83 c5 a1.
    //
    // ISO-8859-1 no cubre el byte 9a -> la conversión devolvía replacement char.
    // Windows-1252 sí -> se obtiene Ú original.

    $prodBytes = "PZA. SANTA \xc3\x83\xc5\xa1RSULA";  // hex c3 83 c5 a1 = "Ãš"

    expect($this->cast->get($this->model, 'col', $prodBytes, []))
        ->toBe('PZA. SANTA ÚRSULA');
});
```

Corre:
```bash
./vendor/bin/pest tests/Unit/Casts/Latin1StringTest.php --colors=never --compact 2>&1 | tail -10
```

8 tests verdes esperados (7 anteriores + 1 nuevo). Suite total:
```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -10
```

97 tests verdes. Si rompe algún roundtrip, AVISA (sería un bug de mb_convert_encoding con cp1252 en este PHP).

PARA: "Fase 2 completa: tests verdes. ¿Procedo a Fase 3 (ADR amend + PR)?"

## FASE 3 — Amendar ADR-0011 + commits + PR

Edita `docs/decisions/0011-latin1-cast-direction-fix.md`. Al final del archivo, antes del último `}`, añade un postscript:

```markdown
---

## Postscript — Refinement Bloque 07c (1 may 2026, mismo día)

Smoke real post-merge reveló que el flip simétrico (Bloque 07b) resolvía la mayoría de mojibake pero rompía el caso `Ú` (y por extensión cualquier carácter cuya forma cp1252 caiga en `0x80-0x9F`, rango undefined en ISO-8859-1).

Datos reales:

| Columna | Raw bytes | ISO-8859-1 fix | WINDOWS-1252 fix |
|---|---|---|---|
| `modulo.nombre` (Alcalá) | `c3 83 c2 a1` | `Alcalá` ✓ | `Alcalá` ✓ |
| `piv.direccion` (URSULA) | `c3 83 c5 a1` | `�?RSULA` ✗ | `ÚRSULA` ✓ |

Causa: MySQL trata `charset=latin1` internamente como Windows-1252 (extiende ISO-8859-1 rellenando `0x80-0x9F`). Cuando una `Ú` original (utf8 `c3 9a`) se almacenó como 2 chars latin1, MySQL transcodifica byte `9a` → `š` (`U+0161`) → utf8 `c5 a1`. ISO-8859-1 no contiene `š` y el `mb_convert_encoding` substituye por `?`. Windows-1252 sí, así que reverse correcta.

**Decisión refinada**: sustituir `ISO-8859-1` por `WINDOWS-1252` en ambas direcciones del cast. Los caracteres del castellano básico (á, é, í, ó, ú, ñ, etc.) producen los mismos bytes en ambas encodings, así que ningún test roundtrip rompe. Solo cambia el comportamiento para caracteres en `0x80-0x9F` que ahora se mapean correctamente.

**Bloque relacionado**: `docs/prompts/07c-cast-cp1252.md`.
```

Stage por archivo:

1. `docs: add Bloque 07c prompt + amend ADR-0011 (cp1252) + capture 3 piv corrupt rows TODO` — `docs/prompts/07c-cast-cp1252.md` + `docs/decisions/0011-latin1-cast-direction-fix.md` + `TODOS.md`.
2. `fix(casts): use WINDOWS-1252 instead of ISO-8859-1 (ADR-0011 §postscript)` — `app/Casts/Latin1String.php`.
3. `test: cover uppercase U-acute prod pattern (cp1252 0x9a)` — `tests/Unit/Casts/Latin1StringTest.php`.

Push + PR:

```bash
git push -u origin bloque-07c-cast-cp1252
gh pr create \
  --base main \
  --head bloque-07c-cast-cp1252 \
  --title "Bloque 07c — Cast Latin1String usa WINDOWS-1252 (ADR-0011 postscript)" \
  --body "$(cat <<'BODY'
## Resumen

Sustituye `ISO-8859-1` por `WINDOWS-1252` en `App\Casts\Latin1String`. Smoke real post-Bloque 07b reveló que Ú (y caracteres en rango cp1252 0x80-0x9F) se rompían: MySQL trata `charset=latin1` como Windows-1252, no como ISO-8859-1 puro.

ADR-0011 amendado con postscript Bloque 07c (no nuevo ADR — la decisión arquitectónica es la misma, solo la encoding source cambia).

## Verificación

| Columna | Raw bytes | Antes | Ahora |
|---|---|---|---|
| `modulo.nombre` (Alcalá) | `c3 83 c2 a1` | `Alcalá` ✓ | `Alcalá` ✓ |
| `piv.direccion` (URSULA) | `c3 83 c5 a1` | `�?RSULA` ✗ | `ÚRSULA` ✓ |

## Tests

- 7 tests existentes siguen verdes (chars Spanish básicos producen mismos bytes en cp1252 e ISO-8859-1).
- Test nuevo `handles_uppercase_u_with_acute` cubre el patrón real de prod.
- 97 tests / NNN assertions verde.

## Post-merge

Smoke real en `/admin/piv` debe mostrar direcciones con `Ú` legibles.

## CI esperado

3/3 jobs verde.
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-07c-cast-cp1252 --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ Qué he hecho:
   - Latin1String usa WINDOWS-1252 (cp1252 cubre 0x80-0x9F que ISO-8859-1 no).
   - ADR-0011 amendado con postscript Bloque 07c.
   - Test nuevo handles_uppercase_u_with_acute cubre el patrón Ú real de prod.
   - 97 tests verdes.
   - Pint + build OK.
   - 3 commits Conventional Commits.
   - PR #N: [URL].
   - CI 3/3 verde.

⏳ Qué falta:
   - (Manual, post-merge) Smoke real con Ú/Ó/ñ en navegador.
   - Bloque 08 — Resources Averia + Asignacion.

❓ Qué necesito del usuario:
   - Confirmar PR.
   - Mergear (Rebase and merge).
   - Smoke real.
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 07c

1. Smoke real en `/admin/piv` con la fila `piv_id=8` (PZA. CERVANTES, ESQ. C/ SANTA ÚRSULA TER) — debe ser legible.
2. Si todavía aparece mojibake en algún campo extra (chars muy raros, p. ej. `€` o caracteres asiáticos), capturar como TODO. El dominio es B2B España así que improbable.
3. Bloque 08 — Resources Averia + Asignacion.
