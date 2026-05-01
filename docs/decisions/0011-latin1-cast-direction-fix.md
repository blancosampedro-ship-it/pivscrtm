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
// get(): bytes utf8 doblemente-encoded -> utf8 limpio
return mb_convert_encoding((string) $value, 'ISO-8859-1', 'UTF-8');

// set(): utf8 limpio -> bytes utf8 que MySQL almacenará como latin1 con patrón legacy
return mb_convert_encoding((string) $value, 'UTF-8', 'ISO-8859-1');
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
- Connection utf8mb4 → MySQL transcodifica a latin1 col → 2 bytes `c3 a1`.
- BD almacena `c3 a1` ✓ (mismo patrón que la app vieja escribe — continuidad)

## Considered alternatives

- **Refactor a dos conexiones (legacy charset=latin1 + lv charset=utf8mb4)** — solución arquitectónicamente más limpia. Requiere modificar 12+ modelos para añadir `protected $connection = 'legacy'`. Diferida a un bloque futuro si aparecen casos donde el flip simétrico no funcione (p. ej. caracteres fuera del rango latin1 como kanji o cirílico). Para B2B España no aplica hoy.
- **Normalización de la BD legacy** (script que convierte todas las filas latin1-double-encoded a single-byte latin1 limpio) — descartada: requiere migración masiva en prod mientras la app vieja sigue corriendo. La app vieja también escribe el mismo patrón doble-encoded; tras normalizar lo volvería a romper. Solo factible tras el cutover (Fase 7).
- **Set connection charset=latin1** (1 línea en config) — descartada: rompe lectura de tablas `lv_*` que sí están en utf8mb4. Cualquier nombre con acento en `lv_users.name` (técnicos lazy-creados) sale corrupto.

## Consequences

**Positivas:**
- Mojibake desaparece en lecturas. Admin ve municipios, direcciones, nombres correctos.
- Escrituras desde admin producen el mismo patrón que la app vieja (continuidad bidireccional).
- 9 modelos legacy con el cast aplicado se benefician sin tocar (Piv, Modulo, Operador, Tecnico, Revision, Averia, Correctivo, DesinstaladoPiv, ReinstaladoPiv).

**Negativas:**
- Si en algún momento aparecieran filas correctamente almacenadas single-byte latin1 (ej. byte `e1` para `á` en lugar de `c3 a1`), el flip las rompería al leer. Hasta ahora no se ha encontrado ninguna en los datasets explorados. Si aparece, se gestiona caso por caso y/o se acelera el refactor a dos conexiones.
