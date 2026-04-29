# 0002 — Coexistencia de base de datos con la app legacy

- **Status**: Accepted
- **Date**: 2026-04-29

## Context

La app vieja (`https://winfin.es`) y la app nueva (`https://piv.winfin.es`) van a convivir durante varios meses sobre **la misma BD MySQL `dbvnxblp2rzlxj`**. Mientras dure la migración por fases:

- La app vieja debe seguir funcionando tal cual (operadores y técnicos siguen entrando ahí).
- La app nueva debe leer y, progresivamente, escribir en las mismas tablas.
- No podemos permitirnos un big-bang con migración de datos.

Las tablas legacy son: `piv`, `averia`, `asignacion`, `correctivo`, `revision`, `tecnico`, `operador`, `modulo`, `piv_imagen`, `instalador_piv`, `desinstalado_piv`, `reinstalado_piv`, `u1`, `session`.

## Decision

1. Los **modelos Eloquent** de la app nueva apuntan a las tablas legacy con `protected $table = 'piv';` (etc.) explícito. Sin pluralización automática, sin renombrados.
2. Las **tablas internas de Laravel** (users, sessions, jobs, cache, password_reset_tokens, failed_jobs, personal_access_tokens, notifications, webpush_subscriptions) se crean con **prefijo `lv_`** para no chocar con tablas legacy ni con futuros nombres del cliente.
3. **Cero `ALTER TABLE`** sobre tablas legacy en Fases 1-3. Si la app nueva necesita columnas extra (p.ej. `lv_password_migrated_at`), se guardan en una tabla `lv_*` con FK lógica al id legacy.
4. Cualquier cambio de schema legacy (Fases 4+) requiere **un ADR específico** que documente impacto en la app vieja.

## Considered alternatives

- **BD nueva + sync bidireccional** — descartado: complejidad enorme (resolver conflictos, latencia, doble fuente de verdad), riesgo alto de drift, multiplica superficie de bugs.
- **Big-bang schema migration** — descartado: rompe la app vieja desde el día 1; sin paracaídas si algo falla; obliga a tener todos los flujos nuevos listos antes de cortar.

## Consequences

**Positivas:**
- App vieja sigue funcionando intacta; cero downtime.
- Migración por módulos: un flujo nuevo se activa sin esperar al resto.
- Reversibilidad total: si la app nueva falla, los datos siguen accesibles desde la vieja.
- Tablas `lv_*` son fáciles de identificar y de borrar/restaurar.

**Negativas:**
- Los modelos Eloquent quedan más verbosos (`$table`, `$primaryKey`, `$timestamps=false` donde aplique).
- No podemos beneficiarnos de convenciones automáticas de Laravel (timestamps `created_at`/`updated_at`, naming).
- Algunos campos legacy son tipos raros (`revision.fecha` VARCHAR(100), `piv.municipio` VARCHAR con id numérico) y hay que tratarlos con accessors/mutators.
- La normalización de charset (latin1↔utf8mb4) hay que resolverla en capa de aplicación.
