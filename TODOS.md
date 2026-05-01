# TODOS — Winfin PIV

Lista breve de follow-ups pendientes derivados de bloques ya ejecutados. Items operativos (no estratégicos: la estrategia vive en `docs/prompts/00-roadmap.md`).

## Pendientes Bloque 02 (auditoría prod 2026-04-30)

### Limpieza datos legacy
- [ ] **Bloque 02b** — Cleanup typo-revisions (~2 800 filas) con regex extendido y validación previa por typo pattern (`REVISON`, `MENSAUL`, `MENSUA L`, `REVISIO NMENSUAL`, etc.). Sample dirigido por cada pattern antes de extender el regex. Mismas 5 capas de seguridad del Bloque 02 Paso 10. Ver `docs/prompts/02b-cleanup-typo-revisions.md` (a crear).

### Parchear app vieja para parar contaminación nueva
- [ ] **Bloque 02c** — Parche mínimo a `winfin.es/public_html/calendar.php` (líneas 199-206) para parar inyección continua de filas contaminadas (~80/semana). Cambios:
  1. Reordenar `<select name="tipo">` para que `<option value="2">Mantenimiento</option>` aparezca PRIMERO (default visual).
  2. Validación PHP server-side en el handler `newAsignacionAveria()` que rechace `tipo=1` cuando `notas` matchea `REVISION MENSUAL` (cualquier variante).
  3. Excepción justificada a regla #1 (modificar app vieja) — requiere ADR `0009-parche-calendar-php.md` (números 0006/0007/0008 ocupados por schema alignment).

### Hallazgos schema vs ARCHITECTURE.md ✅ resueltos en pre-Bloque 03 schema alignment (PR #2 si aprobado)
- [x] ~~Corregir `ARCHITECTURE.md §5.1` con schema real~~ → hecho en commit `docs: replace ARCHITECTURE §5.1-5.3 with verified schema from prod`.
- [x] ~~**Bloque 02d** — Decisión arquitectónica sobre `correctivo`~~ → resuelto en **ADR-0006** (reuso de `recambios`/`estado_final`/`tiempo` legacy + nueva tabla `lv_correctivo_imagen` para fotos del cierre).
- [x] ~~**Bloque 02e** — Investigar cómo se rellena `piv.municipio`~~ → resuelto en **ADR-0007** (apunta a `modulo` con `tipo=5`; centinela `"0"` permitido para "sin asignar"; validación closure custom).
- [x] ~~Documentar nombres reales auth columns~~ → resuelto en **ADR-0008** (`tecnico.clave`, `operador.clave`, `u1.password`; `u1.user_id` PK excepción).

### Hallazgos schema nuevos (descubiertos en pre-Bloque 03)
- [ ] **Bloque 02f** — Decisión + implementación geocoding (lat/lng) para los 575 paneles. ARCHITECTURE.md asumía coordenadas en `piv` pero **no existen las columnas**. Bloquea PWA operador (Bloque 12) si requiere mapa visual. Opciones en roadmap entrada 02f.
- [ ] Confirmar empíricamente qué columnas de `piv` (`tipo_piv`, `tipo_marquesina`, `tipo_alimentacion`) son referencias lógicas a `modulo` tipos 2/3/4 vs texto libre. Son varchar(255), no int — sospecha: texto libre con valores que coinciden parcialmente con nombres de modulo. Sin urgencia, captura datos reales para Bloque 07 form fields.
- [ ] Confirmar si `modulo` tipo=6 ("En Rev.", "OK", "Retirada") es el catálogo legacy de `piv.status` (tinyint). Sin urgencia.
- [ ] Documentar significado de `piv.status` (tinyint) y `piv.status2` (tinyint, default 1) — ¿son redundantes? ¿uno es lifecycle y otro snapshot? Pendiente investigación cuando llegue Bloque 10 dashboard.

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

## Pendientes Bloque 07 (smoke + audit encoding 2026-05-01)

### Datos legacy con corrupción real (no fix por cast)
Audit completo (~700 filas en piv/modulo/operador/tecnico) tras Bloque 07c (cast WINDOWS-1252) detectó **3 filas con corrupción real**: caracteres incorrectos almacenados literalmente en BD desde la app vieja, no resoluble por encoding fix.

- [ ] **`piv.piv_id=18`** — `direccion` contiene "ESTACI**î**N FF." → debería ser "ESTACI**Ó**N FF.".
- [ ] **`piv.piv_id=50`** — `direccion` contiene "DIRECCI**î**N MADRID" → debería ser "DIRECCI**Ó**N MADRID".
- [ ] **`piv.piv_id=112`** — `direccion` contiene "CARMEN MART**ê**N GAYTE" → debería ser "CARMEN MART**Í**N GAYTE".

Recomendación: aprovechar el cleanup masivo de Bloque 02b (typo-revisions) para incluir estas 3 fix puntuales en la misma pasada con backup + ADR. **No bloqueante** — direcciones siguen siendo legibles para el operario humano.

Falso positivo descartado (correcto en su idioma): `operador.operador_id=41` razon_social "Transports Urbans i Serveis Generals, Societat An**ò**nima Laboral" — catalán correcto.
