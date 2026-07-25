# Runbook — Primer deploy de `main` a producción (piv.winfin.es)

> ## ✅ COMPLETADO — 1 jun 2026 ~21:15
> `main` (commit `fea60a5`) está EN PRODUCCIÓN en `https://piv.winfin.es`. Verificado:
> `/up` → 200 · `/admin` → login Filament · 404 sin stack trace (`APP_DEBUG=false`) ·
> BD `dbvnxblp2rzlxj` con **575 paneles** · 0 errores en log.
> - **Backup previo:** `~/backups-deploy/dbvnxblp2rzlxj-pre-deploy-20260601-1716.sql.gz` (2.1 MB, sha256 `603a05bf467570015ef4e710d02e94267caf82459bf598b5d780625b8e916e6d`).
> - **Migraciones:** `migrate --force` = *Nothing to migrate* (no-op, como se esperaba).
> - **Document Root:** Site Tools NO permite editar el docroot de un subdominio existente
>   (solo crear/borrar). Resuelto con **symlink** `~/www/piv.winfin.es/public_html → laravel-app/public`.
>   El placeholder quedó respaldado en `public_html.placeholder-bak` (rollback = restaurar ese dir).
> - **SSH:** clave nueva sin passphrase `~/.ssh/winfin_deploy` (la vieja `siteground_winfin` quedó
>   de-autorizada). Los comandos de abajo que citan `siteground_winfin` ya no aplican; usar `winfin_deploy`.

> **Estado de partida (auditoría 1 jun 2026):** la app NUNCA se ha desplegado. `piv.winfin.es` sirve un placeholder. La BD de prod (`dbvnxblp2rzlxj` @ SiteGround) ya tiene las 15 migraciones de `main` corridas (se ejecutaron remotamente desde local). SSH a SiteGround **caído** (key no autorizada). Objetivo: desplegar el CÓDIGO de `main` y dejar Laravel corriendo, sin tocar features nuevas (PR #53, 9 zonas, filtros, Acciones quedan FUERA).

> **Hecho de seguridad clave:** `php artisan migrate --force` en este deploy debe decir **"Nothing to migrate"** (prod ya está al día con las 15 migraciones de `main`). Si dijera otra cosa → PARAR y revisar.

---

## Datos de referencia

| Dato | Valor |
|---|---|
| Subdominio | `piv.winfin.es` |
| SSH | `ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es` |
| Document Root destino | `~/www/piv.winfin.es/laravel-app/public/` |
| Carpeta app en server | `~/www/piv.winfin.es/laravel-app/` |
| Repo (privado) | `https://github.com/blancosampedro-ship-it/pivscrtm.git` |
| Rama a desplegar | `main` (commit `fea60a5`) |
| BD prod (desde el server) | `dbvnxblp2rzlxj` vía **`localhost`** (NO la IP pública) |
| PHP server | 8.2.30 (coincide con el platform pin de composer.json) |
| Node server | 22.x · Composer: confirmar ≥ 2.4 |

---

## FASE 0 — Prerrequisitos (acción MANUAL del propietario en Site Tools)

- [ ] **0.1 Restaurar SSH.** Site Tools → Devs → SSH Keys Manager → importar/re-autorizar la clave pública `~/.ssh/siteground_winfin.pub`. **Verificar:**
  ```bash
  ssh -o BatchMode=yes -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es 'echo SSH_OK; php -v | head -1'
  ```
  Debe imprimir `SSH_OK` y PHP 8.2.x.

- [ ] **0.2 Confirmar Document Root.** Site Tools → Domain → subdominio `piv.winfin.es` → Document Root debe apuntar a `~/www/piv.winfin.es/laravel-app/public/`. Si apunta al placeholder (`.../public_html/`), **cambiarlo DESPUÉS** del paso 3 (no antes, o rompería el placeholder sin app detrás). Anotar valor actual para rollback.

- [ ] **0.3 Acceso del server al repo privado (GitHub).** El server necesita leer un repo privado. Opción recomendada: **deploy key read-only**.
  ```bash
  # en el server:
  ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-... 'ssh-keygen -t ed25519 -f ~/.ssh/github_deploy -N "" -C "siteground-deploy"; cat ~/.ssh/github_deploy.pub'
  ```
  Copiar esa pública → GitHub repo → Settings → Deploy keys → Add (solo lectura). Y en el server `~/.ssh/config`:
  ```
  Host github.com
    IdentityFile ~/.ssh/github_deploy
    IdentitiesOnly yes
  ```
  *(Alternativa rápida: clonar por HTTPS con un PAT. Menos limpio.)*

- [ ] **0.4 Confirmar versiones en el server:**
  ```bash
  ssh ... 'php -v | head -1; composer --version; node -v; npm -v; git --version'
  ```
  PHP 8.2.x · Composer ≥ 2.4 · Node ≥ 18 · Git ≥ 2.

---

## FASE 1 — Backup previo (OBLIGATORIO antes de nada)

- [ ] **1.1 Backup BD prod** (en el server, vía localhost, con `--defaults-extra-file` 600 + trap):
  ```bash
  ssh ... 'mkdir -p ~/backups-deploy && \
    cat > ~/.my.cnf.tmp <<EOF
  [client]
  user=<DB_USER>
  password=<DB_PASS>
  EOF
    chmod 600 ~/.my.cnf.tmp && \
    mysqldump --defaults-extra-file=~/.my.cnf.tmp --single-transaction --no-tablespaces --skip-lock-tables dbvnxblp2rzlxj | gzip > ~/backups-deploy/dbvnxblp2rzlxj-pre-deploy-$(date +%Y%m%d-%H%M).sql.gz && \
    rm -f ~/.my.cnf.tmp && \
    ls -lh ~/backups-deploy/'
  ```
  *(Las creds reales se obtienen del config.php legacy o del gestor; NUNCA en claro en este doc.)*
- [ ] **1.2** Confirmar que el backup pesa lo razonable (>1 MB) y anotar sha256.
- [ ] **1.3** Confirmar que el backup diario automático de SiteGround corrió hoy (Site Tools → Backups).

---

## FASE 2 — Código en el server

- [ ] **2.1 Clonar `main`** (primer deploy = clone, no pull):
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es && git clone --branch main --single-branch git@github.com:blancosampedro-ship-it/pivscrtm.git laravel-app && cd laravel-app && git log -1 --oneline'
  ```
  Debe mostrar `fea60a5`.
- [ ] **2.2 Dependencias PHP (sin dev):**
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es/laravel-app && composer install --no-dev --optimize-autoloader --no-interaction'
  ```
- [ ] **2.3 Assets front** (`public/build` está gitignored) — ⚠️ **COMPILAR EN LOCAL, NO EN EL SERVER.**
  ```bash
  # en local:
  npm run build && ls public/build/sw.js
  # subir el directorio completo (COPYFILE_DISABLE evita los ._* de macOS):
  COPYFILE_DISABLE=1 scp -r -P 18765 -i ~/.ssh/winfin_deploy public/build/* \
    u2409-puzriocmpohe@ssh.winfin.es:'~/www/piv.winfin.es/laravel-app/public/build/'
  ```
  **Por qué no en el server** (verificado 25 jul 2026): `npm run build` en SiteGround compila CSS y JS
  pero **falla al generar el service worker de la PWA** y aborta:
  ```
  Error: Unable to write the service worker file. 'Unexpected early exit...
  Unfinished hook action(s) on exit: (terser) renderChunk'
  ```
  Causa: workbox minifica el SW con `@rollup/plugin-terser`, cuyos workers no arrancan en el hosting
  compartido (`workbox-build/build/lib/bundle.js:56` — terser solo se añade si `mode === 'production'`).
  El fallo es **silencioso en apariencia**: Vite deja el build a medias, así que `public/build/sw.js` y
  `workbox-*.js` desaparecen y la PWA del técnico **pierde el modo offline** aunque el panel siga bien.
  Verificar SIEMPRE tras desplegar: `curl -s -o /dev/null -w "%{http_code}\n" https://piv.winfin.es/build/sw.js` → **200**.
- [ ] **2.4 Assets de Filament** ⚠️ **IMPRESCINDIBLE — fácil de olvidar.** `public/js/filament/` y
  `public/css/filament/` están gitignored y NO viajan con `git archive`. Sin ellos el panel renderiza
  pero **no es interactivo** (sidebar no despliega, dropdowns no abren — todos los assets dan 404):
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es/laravel-app && php artisan filament:assets'
  ```
  Verificar: `curl -s -o /dev/null -w "%{http_code}\n" https://piv.winfin.es/js/filament/filament/app.js` → **200**.
  *(Nota: Livewire en producción sirve `/livewire/livewire.min.js` (minificado); `/livewire/livewire.js`
  a secas da 404 y es NORMAL — no es un fallo.)*

---

## FASE 3 — `.env` de producción (NUNCA a Git)

- [ ] **3.1 Crear `.env` en el server** a partir de `.env.example`, editado a mano (vía `nano`/heredoc), con:
  ```
  APP_ENV=production
  APP_DEBUG=false
  APP_URL=https://piv.winfin.es
  APP_LOCALE=es
  APP_TIMEZONE=Europe/Madrid

  DB_CONNECTION=mysql
  DB_HOST=localhost          # ← localhost en el server, NO la IP pública
  DB_PORT=3306
  DB_DATABASE=dbvnxblp2rzlxj
  DB_USERNAME=<prod_user>
  DB_PASSWORD=<prod_pass>

  SESSION_DRIVER=database
  CACHE_STORE=database
  QUEUE_CONNECTION=database

  MAIL_MAILER=...            # según config real
  VAPID_PUBLIC_KEY=...       # para web push (Bloque 13, si aplica)
  VAPID_PRIVATE_KEY=...
  ```
- [ ] **3.2 APP_KEY fresca en el server** (no reusar la local):
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es/laravel-app && php artisan key:generate'
  ```
- [ ] **3.3 Symlink de storage:**
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es/laravel-app && php artisan storage:link'
  ```
- [ ] **3.4 Verificar `.env` NO trackeado:** `git -C ~/www/piv.winfin.es/laravel-app check-ignore .env` → debe imprimir `.env`.

---

## FASE 4 — Migraciones (esperado: NADA pendiente)

- [ ] **4.1 Comprobar estado primero (dry):**
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es/laravel-app && php artisan migrate:status'
  ```
  Las 15 migraciones de `main` deben salir como **`Ran`**. Si alguna sale `Pending` → PARAR y avisarme (no debería).
- [ ] **4.2 Migrar (será no-op):**
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es/laravel-app && php artisan migrate --force'
  ```
  Salida esperada: **`Nothing to migrate.`** Si intenta crear/alterar algo → **PARAR**, restaurar backup, investigar.

---

## FASE 5 — Optimizar + activar Document Root

- [ ] **5.1 Cachear config/rutas/vistas:**
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es/laravel-app && php artisan optimize'
  ```
- [x] **5.2 Apuntar el Document Root** a `~/www/piv.winfin.es/laravel-app/public/`. **Site Tools NO deja editar el docroot de un subdominio existente** (la pantalla Subdominios solo crea/borra). Método usado — **symlink** (reversible):
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es && \
    mv public_html public_html.placeholder-bak && \
    ln -sfn ~/www/piv.winfin.es/laravel-app/public public_html && \
    ls -la public_html'   # debe mostrar public_html -> .../laravel-app/public
  ```
  A partir de aquí el placeholder deja de servirse y entra Laravel. **Rollback:** `rm public_html && mv public_html.placeholder-bak public_html`.
- [ ] **5.3 Permisos** de `storage/` y `bootstrap/cache/` escribibles:
  ```bash
  ssh ... 'cd ~/www/piv.winfin.es/laravel-app && chmod -R ug+rwX storage bootstrap/cache'
  ```

---

## FASE 6 — Verificación post-deploy

- [ ] **6.1** `curl -s -o /dev/null -w "%{http_code}\n" https://piv.winfin.es/up` → **200**
- [ ] **6.2** `curl -s https://piv.winfin.es/up` → cuerpo OK (health check)
- [ ] **6.3** Navegar `https://piv.winfin.es/admin` → **login de Filament** (no 404)
- [ ] **6.4** Login con admin real (`info@winfin.es`) → entra al panel → la lista de Paneles muestra los 575 (confirma conexión a BD prod)
- [ ] **6.5** `ssh ... 'tail -50 ~/www/piv.winfin.es/laravel-app/storage/logs/laravel.log'` → sin errores nuevos
- [ ] **6.6** Confirmar `APP_DEBUG=false` (una ruta inexistente NO debe mostrar stack trace)

---

## ROLLBACK (si algo falla)

| Síntoma | Acción |
|---|---|
| Laravel da 500 / blanco | Revertir Document Root al placeholder anterior (Site Tools, valor anotado en 0.2). El placeholder vuelve, cero daño. |
| `.env` mal / no conecta BD | Corregir `.env` en server + `php artisan config:clear && php artisan optimize`. |
| Migración corrió algo inesperado | `php artisan migrate:rollback` Y/O restaurar `~/backups-deploy/...sql.gz` (Fase 1). |
| Quiero deshacer todo | `mv ~/www/piv.winfin.es/laravel-app ~/laravel-app-rollback-$(date +%s)` + Document Root al placeholder. La BD **no se tocó** (migrate fue no-op), así que no hay nada que restaurar salvo que se ejecutara algo en 4.2. |

**Clave:** como `migrate` es no-op en este deploy, el rollback es casi 100% a nivel de código/Document Root. La BD queda intacta.

---

## Lo que este deploy NO incluye (siguientes PRs, por separado)

1. PR reporte periódico (#53) — su migración `create_lv_reporte_periodico_table` se correrá en SU deploy.
2. PR 9 zonas oficiales (seeder + catálogo) — hoy solo en BD del clon.
3. PR filtros trabajo diario.
4. PR columna Acciones.
5. PR fusión técnicos duplicados.

Cada uno: rama propia → PR → CI verde → merge → `git pull` en server → `composer install` → `migrate --force` → `optimize` → verificación.
