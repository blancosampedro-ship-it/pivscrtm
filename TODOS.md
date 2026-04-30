# TODOS — Winfin PIV

Lista breve de follow-ups pendientes derivados de bloques ya ejecutados. Items operativos (no estratégicos: la estrategia vive en `docs/prompts/00-roadmap.md`).

## Pendientes Bloque 02 (auditoría prod 2026-04-30)

### Limpieza datos legacy
- [ ] **Bloque 02b** — Cleanup typo-revisions (~2 800 filas) con regex extendido y validación previa por typo pattern (`REVISON`, `MENSAUL`, `MENSUA L`, `REVISIO NMENSUAL`, etc.). Sample dirigido por cada pattern antes de extender el regex. Mismas 5 capas de seguridad del Bloque 02 Paso 10. Ver `docs/prompts/02b-cleanup-typo-revisions.md` (a crear).

### Parchear app vieja para parar contaminación nueva
- [ ] **Bloque 02c** — Parche mínimo a `winfin.es/public_html/calendar.php` (líneas 199-206) para parar inyección continua de filas contaminadas (~80/semana). Cambios:
  1. Reordenar `<select name="tipo">` para que `<option value="2">Mantenimiento</option>` aparezca PRIMERO (default visual).
  2. Validación PHP server-side en el handler `newAsignacionAveria()` que rechace `tipo=1` cuando `notas` matchea `REVISION MENSUAL` (cualquier variante).
  3. Excepción justificada a regla #1 (modificar app vieja) — requiere ADR breve `0006-parche-calendar-php.md`.

### Hallazgos schema vs ARCHITECTURE.md
- [ ] Corregir `ARCHITECTURE.md §5.1` con schema real verificado contra `INFORMATION_SCHEMA` el 2026-04-30: PKs `<tabla>_id`, columnas reales de cada tabla legacy. Lista detallada en `docs/security.md §Bloque 02 →Hallazgos schema`.
- [ ] **Bloque 02d** — Decisión arquitectónica sobre `correctivo` (la tabla solo tiene `diagnostico`, no `accion` ni `imagen` que asumía ADR-0004). Opciones: `ALTER TABLE correctivo` (excepción regla #2 con ADR) vs nueva tabla `lv_correctivo_extras (correctivo_id, accion, imagen, foto_path)`. **Bloquea Bloque 09**. Requiere ADR breve.
- [ ] **Bloque 02e** — Investigar cómo se rellena `piv.municipio` en producción (¿texto libre? ¿id de catálogo no documentado? ¿enum implícito?) y proponer validación correcta. Las copilot-instructions dicen `Rule::exists('modulo','id')` y eso es **incorrecto** (`modulo` contiene tipos de PIV/marquesina/alimentación). **Bloquea Bloque 07**.

### Operación de mantenimiento
- [ ] **2026-05-07** — Borrado del tombstone del dump SQL público:
  ```bash
  ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es \
      'rm ~/dump-borrado-bloque-02-2026-04-30.sql.tombstone && ls -la ~/ | grep dump-borrado'
  ```
  El tombstone (archivo vacío con el nombre del dump original) sirve durante 7 días como rastro auditor. A partir del 2026-05-07 ya es seguro borrarlo.

### Otros
- [ ] **Confirmar granularidad cron SiteGround Site Tools UI**: opciones del dropdown ("cada 1 min" / "cada 5 min" / etc.). Si NO ofrece "cada 1 min", actualizar `docs/decisions/0001-stack.md §Consequences negativas` y revisar diseño queue. Owner: propietario (acceso a Site Tools UI).
- [ ] **Bloque 13 (hardening webserver)** — eliminar `viejo/archivos/phpinfo.php` (info disclosure, 17 bytes, no crítico).
