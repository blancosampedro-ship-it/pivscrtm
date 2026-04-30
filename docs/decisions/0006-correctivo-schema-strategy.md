# 0006 — Estrategia de uso de la tabla `correctivo` legacy

- **Status**: Accepted
- **Date**: 2026-04-30
- **Supersedes (parcialmente)**: ADR-0004 §"Botón Reportar avería real" — el field mapping documentado allí asumía columnas (`accion`, `imagen`) que no existen en la BD real.

## Context

ADR-0004 (UX separada para revisión vs avería) asumía que la tabla `correctivo` tenía columnas `diagnostico`, `accion`, `imagen` (entre otras) y planteaba el mapeo:

| Campo del formulario | Columna destino |
|---|---|
| Síntoma | `averia.notas` |
| Diagnóstico | `correctivo.diagnostico` |
| Acción | `correctivo.accion` |
| Foto | `correctivo.imagen` |

Tras conectar a producción y consultar `INFORMATION_SCHEMA` (Bloque 02, 2026-04-30), el schema **real** de `correctivo` es muy distinto:

| Columna | Tipo | Uso real (samples 2026-04-30) |
|---|---|---|
| `correctivo_id` | int PK | id |
| `tecnico_id` | int | quién cerró |
| `asignacion_id` | int | FK a la asignación |
| `tiempo` | varchar(45) | horas decimales tipo "0.5", "1.25" |
| `contrato` | tinyint | flag de facturación |
| `facturar_horas` | tinyint | flag de facturación |
| `facturar_desplazamiento` | tinyint | flag de facturación |
| `facturar_recambios` | tinyint | flag de facturación |
| `recambios` | varchar(255) | texto libre — "qué se cambió/hizo". Ej: "NO", "SD DE NEX", "CABLE PN256DC17 DE MONTES" |
| `diagnostico` | varchar(255) | texto libre. Ej: "REVISION POR INCDENCIA Y OK", "SE PONE LA SD YSIM DE NEX Y OK" |
| `estado_final` | varchar(100) | resultado conciso. Ej: "OK" |

**No existen** `accion`, `fecha`, `imagen`. La realidad es **más rica de lo asumido** (campos de facturación, tiempo en horas), no más pobre.

## Decision

**Reusar los campos legacy existentes en lugar de añadir columnas o crear tablas extras.** Mapeo del formulario de cierre tipo=1 a las columnas reales:

| Campo del formulario nuevo | Columna destino | Fuente / responsabilidad |
|---|---|---|
| Síntoma | `averia.notas` | Ya existente, escrito por el operador al reportar — el técnico NO lo modifica. |
| Diagnóstico | `correctivo.diagnostico` | Lo que el técnico encontró al inspeccionar. Obligatorio. |
| Acción / Recambio | `correctivo.recambios` | Qué hizo el técnico para resolverlo (cambio de SD/SIM, cable, etc.) o "NO" si no hubo recambio. Obligatorio. |
| Estado final | `correctivo.estado_final` | "OK" / "OK pendiente reposición" / "PDTE de cliente". Default "OK". |
| Tiempo dedicado | `correctivo.tiempo` | Horas decimales. Obligatorio. |
| Foto del cierre | `lv_correctivo_imagen.url` (tabla NUEVA, ver abajo) | Path al archivo en `storage/app/public/piv-images/correctivo/`. **Obligatoria.** |
| Facturación | `correctivo.contrato`, `facturar_horas`, `facturar_desplazamiento`, `facturar_recambios` | Flags de admin (no expuestos al técnico en su PWA). Default según contrato del operador. |

### Tabla nueva `lv_correctivo_imagen`

Las fotos asociadas al cierre de un correctivo NO encajan en `piv_imagen` (que tiene FK a `piv_id`, no a `correctivo_id` — son fotos del panel en general, no del cierre concreto). Solución: tabla `lv_*` nueva que cumple la regla de coexistencia (ADR-0002).

```sql
CREATE TABLE lv_correctivo_imagen (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    correctivo_id   INT NOT NULL,
    url             VARCHAR(500) NOT NULL,
    posicion        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_correctivo_id (correctivo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- Sin FK física hacia `correctivo` (regla de coexistencia: no añadir constraints a tablas legacy ni que apunten a ellas; la integridad se valida en aplicación).
- `posicion` permite múltiples fotos por cierre con orden definido.

## Considered alternatives

- **`ALTER TABLE correctivo ADD accion VARCHAR, ADD imagen VARCHAR`** — descartado: viola regla #2 (no modificar schema de tablas legacy sin ADR específico, y aun así sería peor opción que reusar `recambios` que YA cumple la función). Doble columna semánticamente solapada.
- **Concatenar diagnóstico + acción en `correctivo.diagnostico`** con plantilla "Síntoma: X; Diagnóstico: Y; Acción: Z" — descartado: serializar texto en un solo campo es exactamente lo que estamos huyendo (regla #5 "no serializar input"). `recambios` está disponible como columna separada, úsala.
- **Tabla `lv_correctivo_extras (correctivo_id, accion, imagen)`** — descartado: duplicaría info ya almacenable en `correctivo.recambios`. Solo necesitamos extras para fotos, no para texto.
- **Reutilizar `piv_imagen` para fotos de cierre** con un nuevo campo opcional `correctivo_id` — descartado: `piv_imagen.correctivo_id` requeriría ALTER TABLE legacy (regla #2). Mejor tabla `lv_*` nueva.

## Consequences

**Positivas:**
- Cero ALTER sobre tablas legacy (regla #2 respetada limpiamente).
- Aprovecha riqueza ya existente: campos de facturación, tiempo, recambios. La nueva app puede empezar a producir reporting más detallado desde el día 1 que la app vieja.
- `diagnostico` y `recambios` siguen el mismo patrón de uso que la app vieja, así que históricos legibles cruzados (la nueva escribe en los mismos campos donde la vieja ya escribió 65.901 filas).
- `lv_correctivo_imagen` es independiente, fácil de purgar/migrar en el cutover de Fase 7 si se decide centralizar.

**Negativas:**
- ADR-0004 §"Botón Reportar avería real" requería corrección documental (hecha en este mismo PR vía rewrite de §5.1).
- El campo `recambios` lleva 12+ años acumulando texto en formato heterogéneo: "NO", "SD DE NEX", listas separadas por comas… La nueva app debería estandarizar el formato (¿lista de items? ¿booleano "hubo recambio"+texto?) — discusión para Bloque 09.
- Fotos en tabla separada implica join extra en queries de listado de correctivos. Mitigación: eager loading en Filament Resource.

**Implementación**:
- Bloque 04 (`lv_*` migrations) añade la migración `create_lv_correctivo_imagen_table`.
- Bloque 09 (Filament action cierre) usa el mapeo de arriba en su FormRequest.
- Tests obligatorios actualizados (ya documentados en `.github/copilot-instructions.md`):
  - `tipo_1_writes_correctivo_columns_correctly` (verifica mapeo: diagnóstico→diagnostico, acción→recambios, tiempo→tiempo, estado→estado_final).
  - `tipo_1_creates_lv_correctivo_imagen_row` (verifica que la foto va a la tabla nueva con FK lógica al correctivo).
  - `tipo_1_does_not_modify_averia_notas` (averia.notas escrita por operador, técnico no la toca).
