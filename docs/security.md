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
