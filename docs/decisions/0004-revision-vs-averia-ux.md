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
