# 0004 — UX separada para revisión mensual vs avería real

- **Status**: Accepted
- **Date**: 2026-04-29

## Context

En la app vieja, los técnicos registran las **revisiones mensuales rutinarias** como **averías reales** (`asignacion.tipo=1`) escribiendo notas tipo `"REVISION MENSUAL Y OK"` en `averia.notas`. Esto **contamina los KPIs del cliente**: cuando el operador pregunta "¿cuántas averías ha tenido este panel este mes?", la respuesta incluye revisiones rutinarias que en realidad no son incidencias.

El schema ya soporta la diferencia: `asignacion.tipo` es 1 (correctivo) o 2 (revisión). El problema es **puramente de UX**: hay un solo botón ("crear asignación") y el técnico, por costumbre, lo usa siempre como tipo=1.

## Decision

En la nueva app, la UI del técnico tiene **dos botones distintos** y **dos flujos completamente separados**:

### Botón "Reportar avería real" → `asignacion.tipo=1`

**Mapeo de campos del formulario a las columnas reales** (corregido tras review externa 29 abr 2026 — el schema de `correctivo` NO tiene columna `notas`):

| Campo del formulario | Columna destino | Notas |
|---|---|---|
| Síntoma | `averia.notas` | El síntoma lo describe el operador al reportar la avería; el técnico lo lee, NO lo reescribe. Si el técnico necesita anotar matices, lo hace en `correctivo.diagnostico`. |
| Diagnóstico | `correctivo.diagnostico` | Lo que el técnico encontró al inspeccionar. Obligatorio, no vacío. |
| Acción | `correctivo.accion` | Lo que el técnico hizo para resolverlo. Obligatorio, no vacío. |
| Foto | `correctivo.imagen` | Path al archivo en `storage/app/public/piv-images/`. **Obligatoria.** |

- Validación: `diagnostico`, `accion`, `imagen` no vacíos.
- **Cero plantilla en `notas`**: el campo `averia.notas` ya está escrito por el operador, el técnico no lo modifica.

### Botón "Registrar revisión mensual" → `asignacion.tipo=2`
- Form `Revision` con **checklist estructurado**: aspecto, funcionamiento, audio, fecha_hora, precisión paso, actuación (cada uno con dropdown OK/KO/N/A según `modulo` tipos 9-14).
- Cada dropdown se almacena en su columna correspondiente de `revision`.
- `revision.notas` es **opcional** y **nunca se rellena automáticamente** con "REVISION MENSUAL" ni equivalente.
- `revision.fecha` se rellena con `now()->format('Y-m-d H:i:s')` aunque la columna sea VARCHAR(100) legacy.
- Foto opcional, sigue mismo patrón de path en `storage/app/public/piv-images/`.

### En reportes y dashboards
- Filtrar **siempre por `asignacion.tipo`**, fuente de verdad estructural.
- **NO usar filtro de notas en queries de producción.** El antiguo filtro `notas NOT LIKE '%REVISION MENSUAL%'` propuesto como "defensa en profundidad" se descarta tras revisión externa por dos razones:
  1. **No funciona**: no atrapa variantes (`REV. MENSUAL`, `Revisión Mensual`, encoding latin1 de `Ó` → `Ã"`, espacios al inicio).
  2. **Es lento**: scan sin índice sobre 66.500 filas.
- En su lugar, **limpieza puntual one-shot** sobre los datos históricos antes del Bloque 10 del roadmap (ver detalles abajo).

### Limpieza puntual de datos históricos contaminados (parte del Bloque 02)

**SQL exploratorio** (read-only) para inventariar variantes:

```sql
SELECT
  CASE
    WHEN averia.notas REGEXP '[Rr][Ee][Vv][Ii][Ss][IiÍí][OoÓó][Nn][[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]' THEN 'rev_mensual'
    WHEN averia.notas REGEXP '[Rr][Ee][Vv]\\.?[[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]' THEN 'rev_mensual_abrev'
    WHEN averia.notas REGEXP '[Mm][Ee][Nn][Ss][Uu][Aa][Ll][[:space:]]*[Yy][[:space:]]*[Oo][Kk]' THEN 'mensual_y_ok'
    ELSE 'otra'
  END AS variante,
  COUNT(*) AS apariciones
FROM averia
JOIN asignacion ON asignacion.averia_id = averia.id
WHERE asignacion.tipo = 1
GROUP BY variante;
```

**Acción manual** tras revisar el resultado con el cliente:

1. Backup confirmado de `asignacion` (sha256 documentado).
2. `UPDATE asignacion SET tipo = 2 WHERE id IN (...)` — la lista de IDs sale del SELECT confirmado.
3. Documentar el `UPDATE` ejecutado en `docs/security.md` como excepción justificada al ADR-0002 (no modifica schema, solo contenido).
4. Re-correr KPIs en dashboard ad-hoc para verificar que el ratio `tipo=1`/`tipo=2` cuadra con la realidad operativa del cliente.

A partir de ese momento, **`asignacion.tipo` es la única fuente de verdad** para distinguir avería real de revisión rutinaria. Cero filtro LIKE en producción.

## Considered alternatives

- **Un solo flujo con un toggle "es revisión" dentro del formulario** — descartado: el toggle se ignora por costumbre; el botón distinto en el dashboard del técnico es físicamente distinto y obliga a parar a pensar.
- **Bloquear escritura de "REVISION MENSUAL" en notas** — descartado: parche cosmético que no cambia el comportamiento subyacente; los técnicos escribirían "REV. MENSUAL" o variantes.
- **Filtrar `notas NOT LIKE '%REVISION MENSUAL%'` en producción como defensa en profundidad** — descartado tras review externa: no atrapa variantes ortográficas, no atrapa encoding artifacts, hace scan sin índice. Limpieza puntual one-shot es más sólida que filtro frágil persistente.
- **Limpiar datos históricos como precondición de la Fase 1** — descartado: bloquea el inicio del proyecto. La limpieza se hace en Bloque 02 (paralela a la configuración de BD), no antes.

## Consequences

**Positivas:**
- KPIs del cliente **limpios desde el día 1** de la nueva app.
- Reporting fiable sin tener que parsear texto libre.
- Checklist de revisión genera datos estructurados aprovechables (porcentaje de paneles con audio KO, etc.).
- El técnico tiene UI más simple: cada flujo pregunta solo lo que necesita.

**Negativas:**
- **Datos históricos quedan contaminados**. Limpieza diferida con script puntual cuando el pattern matching de notas legacy sea estable y revisado por el cliente.
- Hay que **formar a los 3 técnicos activos** en el cambio de UX (sesión corta, una vez).
- El checklist añade más clics que un cuadro de texto libre — mitigado con valores por defecto razonables (todo OK por defecto, el técnico solo cambia los KO).

---

## Ejecución 2026-04-30 (Bloque 02 Paso 10)

Cleanup one-shot ejecutado contra MySQL prod (`dbvnxblp2rzlxj` @ SiteGround).

### Resultado

- **Filas modificadas**: **46 091** (`asignacion.tipo` 1 → 2).
- **Cobertura**: 3 patterns regex MENSUAL validados con sample de 50 filas "otra" (ver detalle en `docs/security.md` §Bloque 02). Cobertura conservadora — typos como `REVISON`, `MENSAUL`, `REVISIO NMENSUAL` (~2 800 filas adicionales estimadas) se difieren a Bloque 02b para validar pattern por pattern.
- **Distribución por año**: 228 (2014) · 3 249-4 575/año (2015-2025, estable) · 1 363 (2026 parcial hasta 27 abr).

### Backups

| Tipo | Path | SHA256 | Tamaño |
|---|---|---|---|
| **CSV IDs (en repo)** | `docs/runbooks/legacy-cleanup/ids-to-update-2026-04-30-071600.txt` | `97f06eaf0991fd1c3a398fb22afd3880253027d245b2f542a74e2617f9b0a683` | 46 091 IDs + cabecera |
| **mysqldump tabla completa (en server)** | `~/backups-bloque-02/asignacion-prerollback-2026-04-30.sql` | `a09f76951b2ce195d0eb5249bc7ed2e6d61d6c571c112afb9c55cfe170604897` | 2 555 957 bytes (66 404 filas) |

### Procedimiento ejecutado (5 capas de seguridad)

1. **Capa 1** — `mysqldump --single-transaction --skip-lock-tables` de tabla `asignacion` completa, vía `--defaults-extra-file` con permisos 600 borrado tras uso.
2. **Capa 2** — Generación de IDs file via SELECT REGEXP de los 3 patterns validados, ordenado ASC por `asignacion_id`. SHA256 calculado.
3. **Capa 3** — Confirmación humana explícita con primeros 30 + últimos 10 IDs visibles + desglose por año mostrado al usuario.
4. **Capa 4** — UPDATE en transacción única, batches de 1 000 IDs (47 batches), pre-flight verifica `count(IN list AND tipo=1) == 46091`, suma de `affected_rows` debe ser exactamente 46 091 o ROLLBACK automático. Cláusula `WHERE asignacion_id IN (...) AND tipo=1` garantiza idempotencia. Resultado: COMMIT OK, 46 091 / 46 091.
5. **Capa 5** — Sample 5 IDs aleatorios post-commit verificados (`6571, 6695, 26125, 55087, 64005` → todos `tipo=2` ✅) + idempotency re-check (re-ejecutar el SELECT REGEXP devuelve **0 filas con `tipo=1`** ✅).

### Idempotency post-commit

```text
Idempotency check: rows still matching MENSUAL regex with tipo=1: 0
```

Re-ejecutar el script no modificará ninguna fila (la cláusula `AND tipo=1` filtra todo).

### Alcance NO cubierto en este pase (deferido a 02b/02c)

- **~2 800 filas con typos** (`REVISON`, `MENSAUL`, `MENSUA L`, `REVISIO NMENSUAL`, etc.). Cada pattern requiere su propio sample dirigido + validación previa antes de incluir en regex. → **Bloque 02b**.
- **Bug fuente sigue activo en `winfin.es` viejo** (`calendar.php` líneas 199-206: `<select>` con default "Averia" + sin validación cruzada). Ratio actual de re-contaminación: ~80 filas/semana. → **Bloque 02c** (parche mínimo a app vieja, excepción justificada a regla #1 con ADR breve).
