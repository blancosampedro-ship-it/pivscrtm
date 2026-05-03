# Smoke real combinado Bloques 11a + 11ab

**Cuándo correrlo:** justo antes de mergear PR #25 (11a) y PR #26 (11ab).
**Branch local:** `bloque-11ab-admin-tecnicos` (contiene 11a + 11ab combinados via merge `e89ff0b`).
**Pre-requisito:** server local arrancado en `http://127.0.0.1:8000`, admin (`info@winfin.es`) ya con sesión Filament viva.

## Pre-smoke gates obligatorios

Antes de arrancar la Parte A, confirmar estos 3 puntos. Si alguno falla, parar y resolver antes de continuar.

### Gate 1 — auth.providers.users.model = App\Models\User

```bash
php artisan config:show auth.providers.users.model
```
- [ ] Output: `App\Models\User`. Si apunta a otro modelo (legacy `U1`, etc.), `LegacyHashGuard::login()` autentica contra una tabla que no es `lv_users` y la migración lazy bcrypt nunca persiste. Bloque 06 lo dejó bien — esto es un doble check pre-flight.

### Gate 2 — lv_password_migrated_at marcador para paso 6

Reforzado: el paso 6 (login PWA OK) DEBE devolver un timestamp no-null en `lv_users.lv_password_migrated_at`. Si queda null tras login OK, la migración lazy de SHA1 → bcrypt falló silenciosamente y los siguientes logins seguirán cayendo en el camino legacy. Hacer la assert explícita en el paso 6, no implícita.

### Gate 3 — tecnico_id max+1 race condition

Issue conocido (ver "Hallazgos colaterales" al final). NO bloquea este smoke siempre que solo haya UN admin operando durante la Parte A. Si en algún momento del smoke se observa colisión de PK al crear el técnico smoke, parar y abrir mini-bloque de fix antes de seguir. Si no aparece, registrar como deuda post-merge.

- [ ] Confirmado: solo un admin operando durante el smoke.

## Test fixtures

Datos del técnico que se crea durante la Parte A. Reusar **estos exactos** para que las búsquedas en logs/BD sean consistentes:

```
Nombre:    Smoke Test Once Once
Usuario:   smoke11
Email:     test.smoke11@winfin.local
DNI:       12345678Z
Teléfono:  600000011
NSS:       111111111111
CCC:       1111111
Password:  <SMOKE_PASS>
Status:    Activo
```

> **Credenciales de smoke**: los valores reales de `<SMOKE_PASS>` y `<SMOKE_PASS_ROTATED>` viven en `docs/runbooks/.smoke-credentials.local.md`, **gitignored**. Cuando ejecutes el smoke, sustituye los placeholders por los valores reales en tu navegador. Si rotas la password, actualiza el `.local.md` y NO los commits a este repo.

Tras la Parte C, este técnico queda en prod con `status=0`. Aceptable (legacy no tiene delete físico — es la decisión del bloque).

---

## Parte A — Admin desktop (TecnicoResource)

### 1. Sidebar grupo "Personas" + badge

Navegar a `/admin`. Verificar:

- [ ] Sidebar tiene grupo nuevo **"Personas"** entre "Operaciones" y "Activos".
- [ ] Item **"Técnicos"** con icono `user-group`.
- [ ] **Badge verde** a la derecha con un número (count técnicos activos en legacy). Si en prod todos los técnicos están con `status=1`, el número será > 0.

**Verificación BD (terminal local):**
```bash
php artisan tinker --execute="echo App\Models\Tecnico::where('status', 1)->count();"
```
El número debe coincidir con el badge.

### 2. List page `/admin/tecnicos`

Click en "Técnicos". Verificar:

- [ ] Tabla con columnas: ID (mono), Nombre, Usuario (mono), Email, **Asignac. abiertas** (badge gris/warning/danger según count), Status (badge "Activo"/"Inactivo").
- [ ] Filtro ternario "Status" arriba con opciones Todos / Solo activos / Solo inactivos. Al elegir "Solo inactivos" la tabla queda con menos (o ninguna) fila.
- [ ] Buscador funciona contra Nombre / Usuario / Email.
- [ ] Acción de fila = **kebab vertical** (icono 3 puntos). NO botones expandidos. Al hacer click muestra: Ver detalle, Editar, Desactivar (si activo) ó Activar (si inactivo).
- [ ] Click "Ver detalle" → **slideOver** (no modal full-page) con 3 secciones: Identidad, Contacto, Documentación. Estos campos sí son visibles aquí (regla #3 RGPD aplica solo a exports al operador, no al admin interno).

Volver al filtro "Todos" o "Solo activos" antes del paso 3.

### 3. Create flow

Click "**Nuevo técnico**" (botón header). Verificar form:

- [ ] 4 secciones: Identidad (2 cols) / Contacto (2 cols) / Documentación (3 cols) / Acceso (2 cols).
- [ ] Campos `usuario`, `dni`, `telefono`, `n_seguridad_social`, `ccc`, `carnet_conducir` con `data-mono` (tipografía monoespaciada).
- [ ] Toggle "Activo" arriba a la derecha de la sección Acceso, con default ON.
- [ ] Password tiene icono ojo "revelar".

Rellenar con los fixtures de arriba. Click "Crear". Esperado:

- [ ] Notification verde "Técnico creado".
- [ ] Redirección a `/admin/tecnicos/{id}/edit` (Filament default).

**Verificación BD:**
```bash
php artisan tinker --execute="
\$t = App\Models\Tecnico::where('email', 'test.smoke11@winfin.local')->first();
echo 'tecnico_id: ' . \$t->tecnico_id . PHP_EOL;
echo 'nombre_completo: ' . \$t->nombre_completo . PHP_EOL;
echo 'usuario: ' . \$t->usuario . PHP_EOL;
echo 'status: ' . \$t->status . PHP_EOL;
echo 'clave: ' . \$t->clave . PHP_EOL;
echo 'sha1(<SMOKE_PASS>): ' . sha1('<SMOKE_PASS>') . PHP_EOL;
echo 'match: ' . (\$t->clave === sha1('<SMOKE_PASS>') ? 'OK' : 'FAIL') . PHP_EOL;
"
```

- [ ] `tecnico_id` = un número (ojo: ver "Hallazgo race-condition" al final de este doc).
- [ ] `clave` = `sha1('<SMOKE_PASS>')` (40 chars hex). **NUNCA** debe ser bcrypt (`$2y$...`).
- [ ] `lv_users` **NO** debe tener fila para este técnico todavía — la migración a bcrypt es lazy en primer login PWA.
```bash
php artisan tinker --execute="
\$t = App\Models\Tecnico::where('email', 'test.smoke11@winfin.local')->first();
\$u = App\Models\User::where('legacy_kind', 'tecnico')->where('legacy_id', \$t->tecnico_id)->first();
echo \$u ? 'lv_users existe (FAIL — debería ser null pre-login)' : 'lv_users null OK';
"
```

### 4. Edit flow — password preservation

Estando en `/admin/tecnicos/{id}/edit` del técnico recién creado:

- [ ] El campo "Cambiar contraseña" está vacío (NO precargado con el hash).
- [ ] Cambiar `nombre_completo` a `Smoke Test Once Once Editado` y dejar password VACÍO. Click "Guardar".
- [ ] Notification "Datos guardados".

**Verificación BD:** la `clave` debe ser **idéntica** a antes (no se sobrescribió):
```bash
php artisan tinker --execute="
\$t = App\Models\Tecnico::where('email', 'test.smoke11@winfin.local')->first();
echo 'clave (debería seguir siendo sha1 <SMOKE_PASS>): ' . \$t->clave . PHP_EOL;
echo 'match: ' . (\$t->clave === sha1('<SMOKE_PASS>') ? 'OK' : 'FAIL — sobreescribió!') . PHP_EOL;
echo 'nombre: ' . \$t->nombre_completo . PHP_EOL;
"
```

- [ ] Volver a editar, esta vez **sí** poner nueva password `<SMOKE_PASS_ROTATED>`. Guardar.
- [ ] BD: `clave` ahora = `sha1('<SMOKE_PASS_ROTATED>')`.
- [ ] Volver a poner la password original `<SMOKE_PASS>` para que la Parte B funcione con los fixtures.

```bash
php artisan tinker --execute="
\$t = App\Models\Tecnico::where('email', 'test.smoke11@winfin.local')->first();
echo (\$t->clave === sha1('<SMOKE_PASS>') ? 'OK — listo para Parte B' : 'FAIL'). PHP_EOL;
"
```

### 5. SlideOver ViewAction (RGPD interno OK)

Volver a `/admin/tecnicos`. Kebab → "Ver detalle" sobre el técnico de smoke:

- [ ] SlideOver aparece desde la derecha.
- [ ] Sección Identidad muestra: ID, Nombre, Usuario (mono), Email, DNI (mono), Status (badge).
- [ ] Sección Contacto muestra Teléfono y Dirección (— si vacío).
- [ ] Sección Documentación muestra NSS, CCC, Carnet (mono).
- [ ] Cerrar slideOver con X o overlay click.

---

## Parte B — PWA técnico

### 6. Login OK desde móvil simulado

Abrir Safari en una **ventana privada** (sin sesión admin). Desktop también vale, pero dimensionar a 390×844 (iPhone 13) en DevTools para ver el shell mobile-first.

Navegar a `http://127.0.0.1:8000/tecnico/login`. Verificar:

- [ ] Header con wordmark **"Winfin <em>PIV</em>"** (la "f" en Instrument Serif italic, el resto en General Sans regular). NO sidebar.
- [ ] Form "Acceso técnico" centrado, con Email + Contraseña, ambos con `border-bottom-only` (estilo Carbon, no border completo).
- [ ] Botón "Entrar" full-width, ≥ 44px alto (tap-target).
- [ ] Sin link "registrarse" ni "olvidé contraseña" (no implementados — admin gestiona altas).

Login con `test.smoke11@winfin.local` / `<SMOKE_PASS>`. Esperado:

- [ ] Redirección a `/tecnico` (dashboard).

**Verificación BD post-login (la migración lazy bcrypt ya ocurrió):**
```bash
php artisan tinker --execute="
\$t = App\Models\Tecnico::where('email', 'test.smoke11@winfin.local')->first();
\$u = App\Models\User::where('legacy_kind', 'tecnico')->where('legacy_id', \$t->tecnico_id)->first();
echo 'lv_users existe: ' . (\$u ? 'SÍ' : 'NO — FAIL') . PHP_EOL;
echo 'lv_users.password (bcrypt): ' . substr(\$u?->password ?? '(null)', 0, 10) . '...' . PHP_EOL;
echo 'lv_password_migrated_at: ' . (\$u?->lv_password_migrated_at ?? '(null)') . PHP_EOL;
echo 'tecnico.clave (sigue SHA1): ' . \$t->clave . PHP_EOL;
"
```

- [ ] `lv_users` ahora **sí** tiene fila para este técnico.
- [ ] `lv_users.password` empieza por `$2y$` (bcrypt).
- [ ] **GATE 2 OBLIGATORIO** — `lv_password_migrated_at` **no es null** y trae un timestamp del momento del login. Si null aquí, el flow de migración lazy está roto: parar smoke y diagnosticar `LegacyHashGuard::login()` antes de seguir. Sin esto, todos los logins futuros seguirían rehashing → race conditions y rendimiento degradado.
- [ ] `tecnico.clave` **sigue siendo** SHA1 (regla legacy: nunca tocar).

### 7. Login con password mala + rate limit

Logout (botón en header del dashboard, ver paso 9). Volver a `/tecnico/login`.

Intentar login con `test.smoke11@winfin.local` / `mala`:

- [ ] Mensaje de error inline: **"Credenciales no válidas."** debajo del input de email.
- [ ] No redirección, sigue en `/tecnico/login`.

Repetir 4 veces más (5 fallos consecutivos en menos de 60s):

- [ ] En el 6º intento, mensaje: **"Demasiados intentos. Espera X segundos."** (mensaje exacto del trans `auth.throttle`).
- [ ] Espera 60s o reinicia rate limiter manualmente:
  ```bash
  php artisan cache:clear
  ```

Login correcto con la password buena para continuar.

### 8. Dashboard "Mis asignaciones abiertas"

Estando en `/tecnico` con sesión técnico:

**Caso A — vacío (esperado por defecto):**
El técnico de smoke no tiene asignaciones legacy.

- [ ] Texto centrado: **"No tienes asignaciones abiertas ahora mismo."**
- [ ] No hay cards.

**Caso B — con datos (opcional, pero recomendable para validar el visual stripe regla #11):**

Si quieres ver las cards renderizadas con stripe color, asignar manualmente una asignación abierta del legacy a este técnico:

```bash
php artisan tinker --execute="
\$t = App\Models\Tecnico::where('email', 'test.smoke11@winfin.local')->first();
// Buscar una asignación abierta cualquiera y reasignarla temporalmente
\$a = App\Models\Asignacion::where('status', 1)->first();
if (\$a) {
    echo 'Asignación encontrada: ' . \$a->asignacion_id . ' (tipo=' . \$a->tipo . ', tecnico_id=' . \$a->tecnico_id . ')' . PHP_EOL;
    echo 'BACKUP tecnico_id original: ' . \$a->tecnico_id . PHP_EOL;
    \$a->update(['tecnico_id' => \$t->tecnico_id]);
    echo 'Reasignada al smoke: ' . \$t->tecnico_id . PHP_EOL;
    echo 'AL TERMINAR PARTE B PASO 8 RESTAURAR CON: \$a->update([tecnico_id => <id_original>]);' . PHP_EOL;
} else {
    echo 'No hay asignaciones abiertas en prod. Skip caso B.';
}
"
```

Refrescar `/tecnico`:

- [ ] Card aparece con:
  - Stripe lateral izquierdo de **4px**, color **rojo** (`border-error`) si `tipo=1` (correctivo) ó **verde** (`border-success`) si `tipo=2` (revisión).
  - Kicker en mayúsculas con tracking: "AVERÍA REAL" ó "REVISIÓN MENSUAL".
  - Línea principal: `Panel #XXX · {parada_cod}` (ID padded a 3 dígitos).
  - Dirección del panel debajo en gris.
  - Subtítulo: "Hay un fallo. Crear parte correctivo." ó "Todo OK. Checklist mensual rutinario."

**OBLIGATORIO restaurar la asignación a su técnico original** (el id que printeó el comando arriba como BACKUP):

```bash
php artisan tinker --execute="
\$a = App\Models\Asignacion::find(<asignacion_id>);
\$a->update(['tecnico_id' => <tecnico_id_original>]);
echo 'Restaurado.';
"
```

Si saltaste el caso B, el dashboard sigue en estado vacío y todo OK.

### 9. Logout

En el header del dashboard:

- [ ] Botón con icono SVG (puerta/flecha) arriba a la derecha. Click.
- [ ] Form POST a `/tecnico/logout` (no GET, no link).
- [ ] Redirección a `/tecnico/login`.
- [ ] Si intentas volver a `/tecnico` directamente → redirige a login (sesión limpia).

---

## Parte C — Activate / deactivate boundary

### 10. Admin desactiva → técnico bloqueado

Volver a la ventana del admin. `/admin/tecnicos`. Kebab del técnico smoke → "Desactivar":

- [ ] Modal de confirmación con texto: "No podrá entrar a la PWA. Sus asignaciones e histórico se conservan."
- [ ] Confirmar. Notification warning "Técnico desactivado".
- [ ] La fila ahora muestra Status = "Inactivo" (badge gris).
- [ ] El kebab de esa fila ya no muestra "Desactivar" sino **"Activar"**.

**BD:**
```bash
php artisan tinker --execute="
\$t = App\Models\Tecnico::where('email', 'test.smoke11@winfin.local')->first();
echo 'status: ' . \$t->status . ' (esperado 0)';
"
```

En la ventana PWA (privada), intentar login de nuevo con `test.smoke11@winfin.local` / `<SMOKE_PASS>`:

- [ ] Mensaje de error: **"Cuenta de técnico inactiva. Contacta con admin."**
- [ ] Sigue en `/tecnico/login`, sin sesión.

### 11. Admin reactiva → técnico vuelve a entrar

Admin: kebab → "Activar". Modal "Reactivar técnico" → Confirmar. Notification success.

- [ ] Status vuelve a "Activo" (badge verde).
- [ ] PWA: login con las mismas credenciales → redirige a `/tecnico` correctamente.

### 12. Sesión viva interrumpida por desactivación

Estando logueado en PWA (post paso 11), volver al admin:

- [ ] Desactivar técnico smoke (paso 10 again).

Sin cerrar sesión PWA, intentar **navegar dentro** del área técnico (refresh `/tecnico`, click cualquier link):

- [ ] El middleware `EnsureTecnico` detecta `tecnico.status === 0` y:
  - Hace logout del usuario.
  - Invalida sesión.
  - Redirige a `/tecnico/login` con error "Cuenta de técnico inactiva."
- [ ] No queda sesión viva con un técnico inactivo. Crítico — sin esto un técnico despedido seguiría operando hasta que su cookie expire.

---

## Cleanup post-smoke

```bash
# El técnico smoke queda con status=0 en prod (no delete físico).
# OPCIONAL: si quieres eliminarlo del todo (no afecta historial porque
# nunca creó datos):
php artisan tinker --execute="
\$t = App\Models\Tecnico::where('email', 'test.smoke11@winfin.local')->first();
\$u = App\Models\User::where('legacy_kind', 'tecnico')->where('legacy_id', \$t->tecnico_id)->first();
\$u?->delete();  // borra fila lv_users (lazy migration creada en paso 6)
\$t->delete();   // borra fila tecnico legacy
echo 'Smoke técnico eliminado.';
"
```

Verificar suite local intacta antes de mergear:

```bash
./vendor/bin/pest --parallel
```

Esperado: **169 tests verde** (suite combinada 11a + 11ab tras merge).

---

## Si los 12 puntos OK

1. Mergear PR #25 (Bloque 11a) primero. Estrategia: **Rebase and merge**.
2. Mergear PR #26 (Bloque 11ab). GitHub auto-resuelve duplicados via merge commit base.
3. `git checkout main && git pull && git branch -d bloque-11a-pwa-tecnico-shell bloque-11ab-admin-tecnicos`.
4. Actualizar memoria `status.md` con el cierre.
5. Arrancar Bloque 11b (cierre flow PWA + AsignacionCierreService extract + foto upload + PWA full SW).

## Si algo falla

- Crear mini-bloque `bloque-11ab-fix` con prompt específico para Copilot.
- NO mergear nada hasta verde 12/12.

---

## Hallazgos colaterales detectados durante la reconstrucción del checklist

Estos NO bloquean el smoke, pero quedan registrados para futuro:

1. **Race condition potencial en `tecnico_id` autoassign** (`CreateTecnico::mutateFormDataBeforeCreate`): el handler hace `$data['tecnico_id'] ??= ((int) Tecnico::max('tecnico_id')) + 1;`. En MySQL prod la columna es auto_increment, y este código **siempre** computa max+1 manualmente (porque `tecnico_id` no viaja en el form, así que `??=` siempre dispara). Dos admins creando técnicos simultáneamente pueden colisionar en el mismo PK → segundo fallaría con duplicate key. Mitigación: en prod hay un solo admin operando a la vez, riesgo aceptable. Fix limpio futuro: solo computar manualmente si `DB::connection()->getDriverName() === 'sqlite'`. **A registrar como issue post-merge.**

2. **`config/auth.php` `providers.users` apunta al modelo correcto?** Verificar en post-smoke que `providers.users.model = App\Models\User::class` y no a algún legacy. (Bloque 06 lo dejó bien, doble check con `php artisan config:show auth.providers.users.model`.)

3. **`lv_password_migrated_at`** ya está en el `$fillable` y el guard lo escribe en migración lazy — comportamiento estándar Bloque 06. Solo verificar campo en BD post paso 6.
