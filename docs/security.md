# Seguridad y RGPD — Winfin PIV

## Datos RGPD por tabla

### `tecnico` — categoría: **personal sensible**
Campos sensibles: `dni`, `n_seguridad_social`, `ccc`, `telefono`, `direccion`, `email`.
- **Retención**: vida del contrato + 5 años fiscales.
- **Acceso**: solo admin.
- **Export al cliente**: ❌ NUNCA. Solo `nombre_completo`.

### `operador` — categoría: **datos profesionales**
Campos: `cif`, `responsable`, `email`, `domicilio`.
- **Acceso**: admin + el propio operador (su ficha).
- **Export**: solo a admin o al propio operador.

### `u1` (admins) — categoría: **credenciales**
Campos: `username`, `email`, `password` (bcrypt en la app nueva, SHA1 en la vieja hasta migración).
- **Acceso**: solo admin.

### `averia.notas` y `revision.notas` — categoría: **observaciones**
- Pueden contener observaciones del operador o del técnico.
- **No exponer fuera del cliente correspondiente** (filtrar por `piv.operador_id` del solicitante).

---

## Política de exports

- ❌ **NUNCA** campos RGPD del técnico (`dni`, `n_seguridad_social`, `ccc`, `telefono`, `direccion`, `email`) en CSV/PDF/email que vayan al **operador o externos**.
- ✅ Solo `tecnico.nombre_completo` es exportable hacia operador.
- Cualquier export pasa por una capa explícita (`TecnicoExportTransformer`) que filtra campos. Tests Pest validan que las columnas RGPD nunca aparecen.

---

## Política de logs

- ❌ **Nunca** loggear contraseñas, tokens, ni el header `Authorization`.
- ❌ **Nunca** loggear cuerpo completo de requests en endpoints de auth.
- ✅ Mascarar en `lv_logs` (y en `storage/logs/laravel.log`): cualquier campo cuyo nombre contenga `password`, `token`, `secret`, `key`, `vapid`.
- Middleware `MaskSensitiveLogs` aplicado globalmente.

---

## Secretos

Solo viven en el `.env` del servidor (nunca en repo):

- `APP_KEY`
- `DB_PASSWORD`
- `MAIL_PASSWORD`
- `VAPID_PRIVATE_KEY`

La passphrase del backup cifrado `.7z` vive **solo en el gestor de contraseñas del propietario** (no en `.env`, no en repo, no en email).

---

## Backups

- **Diario automático**: dump completo en SiteGround (incluido en plan GoGeek), retención 30 días.
- **Mensual manual**: backup cifrado con `7z + AES-256` subido a Dropbox del propietario. Passphrase **conocida por el propietario Y por la firma legal de Winfin Systems** (segundo holder requerido — single point of failure removed tras review externa).
- **Restore runbook**: `docs/runbooks/restore.md` documenta paso a paso el procedimiento (comandos SSH, decryption, import, verificación). Se crea en el Bloque 15b del roadmap.
- **Primera prueba de restore real**: obligatoria antes de marcar Bloque 15b como done. NO diferir 6 meses.
- **Restore drill recurrente**: cada 6 meses, ejecutar el runbook completo en staging y documentar tiempos reales (RTO observado vs objetivo 4h).

---

## Incidentes pendientes documentados

### 🔴 Dump SQL público (RGPD — severidad crítica)
- **URL**: `https://winfin.es/serv19h17935_winfin_2025-04-25_07-53-24.sql`
- **Problema**: descargable por cualquiera. Contiene **contraseñas SHA1 sin sal y datos personales completos** de técnicos y operadores. Combinado con la ausencia de rate limit en la app vieja, los SHA1 se pueden rainbow-table-ear offline y luego replayar online sin freno contra la nueva.
- **Estado**: **adelantado al Bloque 02 del roadmap** tras review externa (29 abr 2026). NO esperar a Fase 7. Comando exacto vive en `docs/prompts/02-env-and-db.md` (cuando se prepare).
- **Verificación**: tras borrado, `curl -I https://winfin.es/serv19h17935_winfin_2025-04-25_07-53-24.sql` debe devolver `404`. Documentar la fecha y hash del dump local de respaldo (que sí mantenemos cifrado en Dropbox del propietario).

### 🟡 Refactor a medias en variables de sesión (app vieja)
- **Problema**: `header.php` lee `$_SESSION['user_level']` mientras `login.php` escribe `$_SESSION['userId']` → menú no se renderiza correctamente.
- **Estado**: **no se arregla en la app vieja**. Se reemplaza con la nueva (Fortify + Filament).

### 🟡 `unserialize()` sobre cookie `admin_settings` (app vieja)
- **Problema**: vector RCE clásico en `functions.php`.
- **Estado**: **no se arregla en la app vieja**. Vivirá hasta el cutover de Fase 7. Mientras tanto, no añadir features que dependan de esa cookie.

---

## Checklist de seguridad pre-deploy

Antes de cada `git pull` en producción:

- [ ] `APP_DEBUG=false` en `.env` del servidor.
- [ ] `APP_ENV=production`.
- [ ] `php artisan config:cache` ejecutado.
- [ ] Sin archivos `.env*` ni `*.key` ni dumps SQL en `public/`.
- [ ] CSRF activo (no se ha desactivado en `VerifyCsrfToken::$except` salvo justificación documentada).
- [ ] Tests Pest pasan.
- [ ] No hay `dd()` ni `var_dump()` en el diff.

---

## Bloque 02 — Estado verificado en producción (2026-04-30)

Auditoría ejecutada al cablear `.env` contra MySQL prod (SiteGround GoGeek). Todo lo que sigue está verificado contra la BD viva, no inferido del código.

### Schema legacy
- **14 tablas presentes**, 0 ausentes, 0 extras inesperadas: `piv`, `averia`, `asignacion`, `tecnico`, `operador`, `modulo`, `piv_imagen`, `correctivo`, `revision`, `instalador_piv`, `desinstalado_piv`, `reinstalado_piv`, `u1`, `session`.

#### Hallazgos schema vs ARCHITECTURE.md (3 desviaciones que requieren follow-up)

Los siguientes puntos NO estaban en `ARCHITECTURE.md §5.1` o estaban incorrectos. Verificados contra `INFORMATION_SCHEMA` el 2026-04-30. Cada uno tiene su entrada en `TODOS.md`.

**(a) PKs son `<tabla>_id`, NO `id`.**
- `piv.piv_id`, `averia.averia_id`, `asignacion.asignacion_id`, `tecnico.tecnico_id`, `operador.operador_id`, `modulo.modulo_id`, `correctivo.correctivo_id`, `revision.revision_id`, `piv_imagen.piv_imagen_id`, `instalador_piv.instalador_piv_id`, `desinstalado_piv.desinstalado_piv_id`, `reinstalado_piv.reinstalado_piv_id`, `u1.u1_id`. Solo `session` no sigue convención (PK textual del session id).
- **Impacto Bloque 03**: cada modelo Eloquent debe declarar `protected $primaryKey = 'piv_id'` etc. NO confiar en la convención `id` por defecto de Eloquent.
- **Tests obligatorios afectados**: cualquiera que asuma `Model::find($id)` funciona out-of-the-box sin override de `$primaryKey` debe ajustarse.

**(b) `piv.municipio` es `varchar(255)` LIBRE, no FK lógica a `modulo`.**
- Las copilot-instructions actuales incluyen literalmente `'municipio' => ['required', Rule::exists('modulo', 'id')]` para FormRequests. **Esa regla es incorrecta** — `modulo` contiene tipos de PIV/marquesina/alimentación, NO municipios. El campo `piv.municipio` se rellena con texto libre desde el formulario CRUD de PIVs en la app vieja.
- **Impacto Bloque 07 (PivResource)**: la validación correcta requiere primero entender cómo se rellena el campo en la realidad (¿texto libre? ¿id de algún catálogo no documentado? ¿enum implícito?). → bloqueante para Bloque 07. Investigación: **Bloque 02e** en `TODOS.md`.

**(c) Tabla `correctivo` solo tiene `diagnostico`, NO `accion` ni `imagen`.**
- ADR-0004 §"Botón Reportar avería real" mapea formulario nuevo a tres columnas: `diagnostico`, `accion`, `imagen`. **Solo `diagnostico` existe.**
- Esto invalida por segunda vez el plan de Bloque 09 (la primera fue en review externa del 29 abr cuando se descartó `correctivo.notas`).
- **Decisión pendiente**: o `ALTER TABLE correctivo ADD accion TEXT, ADD imagen VARCHAR(500)` (excepción a regla #2 — requiere ADR breve), o crear tabla nueva `lv_correctivo_extras (correctivo_id PK, accion TEXT, imagen VARCHAR(500))` (sin tocar legacy, regla #2 respetada). → bloqueante para Bloque 09. Decisión: **Bloque 02d** en `TODOS.md`.

### Conexión MySQL
- Host externo: `34.175.189.6:3306` (usado desde Laravel local + futura PWA).
- Host interno (desde el propio shell SiteGround para mysqldump/scripts): **`localhost`** únicamente. La IP pública rechaza conexión desde el propio server (`Access denied for user@34.175.189.6`).
- `--defaults-extra-file` con `chmod 600` y `rm -f` post-uso es el patrón obligatorio para cualquier sesión MySQL ad-hoc en server (regla #6).

### Emails duplicados (relevante para Bloque 06 unificación de identidades)
- **Cross-tabla** (mismo email en >1 de tecnico/operador/u1): **1 caso** → `info@winfin.es` aparece en `tecnico` Y `operador`. Implicación Bloque 06: el guard de login debe mostrar selector "¿como técnico o como operador?" cuando hay colisión, NO inferir rol del email.
- **Within `tecnico`**: 17 emails con 2-5 filas cada uno (técnicos dados de alta varias veces tras bajas, p. ej. `dmartin@winfin.es×5`, `gcastro@winfin.es×5`). Implicación Bloque 06: lookup de `lv_users` por `(legacy_kind, legacy_id)`, **nunca por email** (ver ADR-0003 punto 2 + test obligatorio `lookup_canonical_by_legacy_kind_legacy_id`). Estrategia para resolver el "técnico vivo" cuando hay duplicados: el más reciente con `status=1` (activo).
- **Within `operador`**: 1 anomalía detectada (`Cevesa` no tiene formato email) — flag para limpieza de datos posterior, no bloqueante.
- **Within `u1`**: 0 duplicados.

### Cron SiteGround
- **SiteGround GoGeek NO expone el binario `crontab` por SSH.** La gestión de cron es exclusivamente vía Site Tools UI.
- Crons legacy activos heredados: `cron/asignarMantenimiento.php` (escribe `tipo=2` correctamente), `cron/enviarAsignaciones.php` (solo emails, no INSERTs).
- **Granularidad mínima del scheduler UI**: ⚠️ **PENDIENTE confirmación dropdown UI** por el propietario. Si NO ofrece "cada 1 minuto", impacta diseño de la nueva app (queue driver `database` + `schedule:run` cada minuto, ADR-0001). Si la mínima es 5 min: degradación aceptable para notificaciones webpush + sin impacto en mantenimiento programado mensual; queue jobs corren con latencia 0-5 min en lugar de 0-60s. Si es ≥15 min: **bloqueante** → reabrir ADR-0001 §"Consequences negativas" y evaluar Supervisor o cambio de hosting.

### Dump SQL público (incidente RGPD crítico — RESUELTO)
- **URL**: `https://winfin.es/serv19h17935_winfin_2025-04-25_07-53-24.sql`
- **Estado**: ✅ **borrado del filesystem el 2026-04-30** (movido a tombstone, ver abajo).
- **SHA256 del dump original** (archivo confirmado, antes del borrado): `cf54085563170b5d3924ec21a2f027edcf58e9b424ecce35e56e2cea97be5011` · **11 556 410 bytes**.
- **Backup local cifrado**: `~/Documents/winfin-piv-backups-locales/serv19h17935_winfin_2025-04-25_07-53-24.sql` (mismo sha256, fuera del repo, fuera del servidor público).
- **Tombstone en server**: `~/dump-borrado-bloque-02-2026-04-30.sql.tombstone` (archivo vacío con mismo nombre extendido para que cualquier intento de descargar deje rastro y no se confunda con re-creación accidental).
- **Recordatorio rm definitivo del tombstone**: `2026-05-07` (apuntado en `TODOS.md` raíz del repo).
- **Verificación post-borrado**: `curl -I https://winfin.es/...sql` devuelve `HTTP/2 403`. NOTA: SiteGround WAF bloquea TODOS los `*.sql` con 403 independientemente de existencia, lo cual es **defensa en profundidad correcta** (file deleted + WAF blocks family). El 403 vs 404 esperado en el roadmap original NO es un fallo: es una capa adicional. Documentado para que cualquier auditor futuro no se confunda.

### Otros archivos sensibles expuestos detectados (sweep parcial)
- `viejo/archivos/phpinfo.php` (17 bytes) — flag para sweep posterior. NO crítico (solo expone versión PHP), pero pertenece a categoría "info disclosure" → eliminar en Bloque 13 (hardening webserver).

### REVISION MENSUAL — causa raíz identificada (NO arreglada en Bloque 02)
- **Bug fuente**: `winfin.es/public_html/calendar.php` líneas 199-206. El `<select name="tipo">` lista `<option value="1">Averia</option>` PRIMERO → es el default visual del navegador. El operador/admin que va a registrar una revisión mensual rutinaria desde el calendario olvida cambiar el dropdown a "Mantenimiento", y guarda con `tipo=1` mientras escribe en `notas` "REVISION MENSUAL Y OK". No hay validación server-side cruzada `tipo`/`notas`.
- **Crons NO son culpables**: `cron/asignarMantenimiento.php` escribe correctamente `tipo=2`. Verificado por lectura del código.
- **Tasa de inyección actual en app vieja**: ~80 filas/semana, ~4 000/año (cifras estables 2015-2025). En 2026 hasta hoy: 1 363 filas.
- **Parche propuesto**: añadir Bloque 02c al roadmap — modificación mínima a `calendar.php` (excepción justificada a regla #1 con ADR breve): reordenar `<option>` para que "Mantenimiento" sea el default + validación PHP server-side que rechace `tipo=1` cuando `notas` matchea `REVISION MENSUAL`. Sin esto, la BD se sigue contaminando hasta el cutover de Fase 7.
- **Cleanup ejecutado**: ver sección siguiente.

### Cleanup REVISION MENSUAL — ejecutado 2026-04-30 07:16 UTC+2
- **Filas modificadas**: **46 091** (`asignacion.tipo` 1 → 2). 0 fallos, 0 rollbacks, sample 5 random verificado, idempotency check 0 filas residuales.
- **Detalle completo + procedimiento de las 5 capas**: `docs/decisions/0004-revision-vs-averia-ux.md` §"Ejecución 2026-04-30 (Bloque 02 Paso 10)".
- **CSV backup en repo**: `docs/runbooks/legacy-cleanup/ids-to-update-2026-04-30-071600.txt` (sha256 `97f06eaf0991fd1c3a398fb22afd3880253027d245b2f542a74e2617f9b0a683`).
- **mysqldump backup en server** (NO en repo): `~/backups-bloque-02/asignacion-prerollback-2026-04-30.sql` (sha256 `a09f76951b2ce195d0eb5249bc7ed2e6d61d6c571c112afb9c55cfe170604897`, 2 555 957 bytes, 66 404 filas — tabla completa pre-cleanup).
- **Excepción justificada al ADR-0002** (no modifica schema, solo contenido — corrige `tipo=1` mal aplicado a 46 091 revisiones rutinarias).
- **Restore en caso de necesidad**:
  ```bash
  ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es
  # crear ~/.winfin-mysql-defaults [client] host=localhost user=... password=... chmod 600
  mysql --defaults-extra-file=~/.winfin-mysql-defaults dbvnxblp2rzlxj < ~/backups-bloque-02/asignacion-prerollback-2026-04-30.sql
  rm -f ~/.winfin-mysql-defaults
  ```
- **No completo**: ~2 800 filas con typos (`REVISON`, `MENSAUL`, etc.) deferidas a Bloque 02b. Bug fuente sigue activo en app vieja, parche en Bloque 02c.


