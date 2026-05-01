# 0012 — Sistema de archivado para filas `piv`

- **Status**: Accepted
- **Date**: 2026-05-01
- **Tipo**: Pattern arquitectónico, no afecta auth ni schema legacy.

## Context

Auditoría exhaustiva de la tabla `piv` (1-may-2026) reveló contaminación: ~91-101 filas en piv_id 469-559 no son paneles informativos sino registros de vehículos de un proyecto antiguo donde el usuario reusó la BD para gestión de autobuses. Operadores identificados: Soler i Sauret, Sarbus, Monbus, Autos Castellbisbal, Font, Autocorb, Mohn, Rosanbus, Tusgsal (todos catalanes). Características visuales:

| Campo | Panel real | Bus contaminante |
|---|---|---|
| `parada_cod` | numérico ("06036") | "Soler i Sauret 103" (texto libre) |
| `direccion` | rellena | vacía "" |
| `municipio` | id de modulo tipo=5 | "0" (centinela "sin asignar") |
| `industria_id`, `operador_id` | apuntan a registros válidos | NULL |

Adicionalmente, hay ~115 filas "dudosas" (parada_cod no numérico pero con dirección/municipio rellenos): hospitales, intercambiadores, terminales, variantes de panel con sufijo letrado (06692A/B/C). MAYORÍA son paneles reales con nomenclatura especial — NO contaminantes.

Necesidad: el admin (Filament `/admin/pivs`) debe poder ocultar las filas-bus sin destruirlas, con capacidad de restaurar si fuese necesario.

## Decision

**Soft-archive vía nueva tabla `lv_piv_archived`.**

### Schema

```sql
CREATE TABLE lv_piv_archived (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    piv_id              INT NOT NULL,
    archived_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_by_user_id BIGINT UNSIGNED NULL,
    reason              VARCHAR(255) NULL,
    created_at          TIMESTAMP NULL,
    updated_at          TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_piv_archived (piv_id),
    KEY idx_archived_at (archived_at),
    KEY idx_archived_by (archived_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`UNIQUE KEY uniq_piv_archived` evita doble-archivado accidental. Sin FK física a `piv` (regla coexistencia ADR-0002) ni a `lv_users` (consistencia con resto de tablas lv_*).

### Comportamiento

1. **Por defecto, listing oculta archivados**: TernaryFilter `archived` con default=blank aplica scope `notArchived()` (`whereDoesntHave('archive')`).
2. **Filter UI** con 3 estados:
   - `Activos` (default, blank) → solo no-archivados
   - `Solo archivados` (true) → solo archivados
   - `Todos` (false) → ambos, con opacity 0.6 en archivados
3. **Action archive** abre modal con campo `reason` (textarea opcional). Inserta fila en `lv_piv_archived` con `archived_by_user_id = auth()->id()`.
4. **Action unarchive** (visible solo en archivadas) confirma + borra fila de `lv_piv_archived`.
5. **Bulk archive**: selección múltiple + reason → batch insert.
6. **App vieja sin afectar**: la tabla `piv` no se modifica. Coexistencia preservada (regla #1).

### Por qué soft-archive y no DELETE legacy

- **Reversible**: descubrimientos tardíos recuperables con un click.
- **Audit trail**: quién/cuándo/por qué.
- **Sin DML legacy**: regla #2 sin invocar — solo INSERT en `lv_*` nueva.
- **Coexistencia**: app vieja no rompe.
- **Migración futura**: tras cutover Fase 7, hard-delete real es trivial (`DELETE FROM piv WHERE piv_id IN (SELECT piv_id FROM lv_piv_archived)`).

### Bulk one-shot post-merge

Tras mergear este bloque, runbook `docs/runbooks/07e-bulk-archive-bus-rows.md` ejecuta:
1. Backup prod DB.
2. SELECT inventario: piv_ids con parada_cod no-numérico + dir vacía + mun=0.
3. Confirmación humana sobre la lista.
4. INSERT batch en `lv_piv_archived` con `reason="Bus row from legacy vehicle project — bulk archive 2026-05-01 (audit ADR-0012)"`.
5. Smoke verificación count.

## Considered alternatives

- **DELETE inmediato de las filas-bus en `piv`** — descartado: viola spirit de regla #2, irreversible.
- **Migrar a `lv_vehiculos_legacy` y hard-delete de `piv`** — descartado: doble trabajo, pierde coexistencia.
- **Filtro estático por heurística (parada_cod regex)** — descartado: heurística falla en edge cases + no permite admin marcar nuevos buses si aparecen.
- **Soft-delete column en piv** — descartado: viola regla #2.
- **`whereNotIn(piv_id, [list_hardcoded])`** — descartado: lista crece sin control, sin audit.

## Consequences

**Positivas:**
- Tabla legacy intacta. App vieja sin afectar.
- Listing admin limpio sin tocar BD.
- Audit trail completo. RGPD-friendly.
- Reversible en cualquier momento.
- Patrón reutilizable para futuras tablas legacy con contaminación similar.

**Negativas:**
- Una subquery extra (`whereDoesntHave`) en cada listing del PivResource. Cost: ~1ms con índice `uniq_piv_archived`. Insignificante.
- Admin debe entender el concepto "archivado" — UI con filter de 3 estados ayuda.
- Las filas archivadas siguen visibles en app vieja (esperado, pero confuso). Aceptable hasta cutover.

**Implementación**: ver Bloque 07e (`docs/prompts/07e-piv-archive-system.md`).
