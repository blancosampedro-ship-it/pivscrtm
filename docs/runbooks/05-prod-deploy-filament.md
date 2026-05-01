# Runbook 05 — Deploy Filament a producción + primer admin

> Se ejecuta DESPUÉS de mergear PR del Bloque 05. Lo hace Claude Code o el usuario
> vía SSH; NO lo hace Copilot. Toca BD producción.

## Prerequisitos

- PR Bloque 05 mergeado en `main`.
- Backup reciente de la BD prod (mysqldump < 24h). Si no hay, crear uno antes.
- Confirmación humana explícita (la migración crea tablas nuevas en prod, no toca legacy).

## Pasos

### 1. Backup fresh de la BD prod

```bash
ssh u1234@server.siteground.com
cd ~/private/backups
mysqldump --defaults-extra-file=~/.my.cnf \
    --single-transaction --quick --no-tablespaces \
    dbvnxblp2rzlxj > prod-pre-bloque-05-$(date +%Y%m%d-%H%M%S).sql
gzip prod-pre-bloque-05-*.sql
ls -lh prod-pre-bloque-05-*.sql.gz
```

### 2. Verificar que las migrations lv_* no tocan tablas legacy

Localmente (Mac), antes de ejecutar nada en prod:

```bash
cd ~/Documents/winfin-piv
git pull
ls database/migrations/
# Esperado: 0001_01_01_000000_create_users_table.php (lv_users)
#           0001_01_01_000001_create_cache_table.php (lv_cache)
#           0001_01_01_000002_create_jobs_table.php (lv_jobs)
#           2026_05_01_000000_create_lv_correctivo_imagen_table.php
```

Las 4 migrations crean exclusivamente tablas con prefijo `lv_*`. Sin `ALTER` ni `DROP` sobre legacy.

### 3. Ejecutar migrate contra prod

Con `.env` LOCAL apuntando a SiteGround MySQL:

```bash
php artisan migrate --pretend           # dry-run, ver SQL
# Confirmar visualmente que solo hay CREATE TABLE lv_*

php artisan migrate                      # ejecutar
php artisan migrate:status               # confirmar las 4 marcadas como Ran
```

### 4. Verificar tablas en prod

```bash
mysql --defaults-extra-file=~/.my.cnf -e \
    "SHOW TABLES FROM dbvnxblp2rzlxj LIKE 'lv\_%';"
```

Esperado: 9 tablas (`lv_users`, `lv_password_reset_tokens`, `lv_sessions`,
`lv_cache`, `lv_cache_locks`, `lv_jobs`, `lv_job_batches`, `lv_failed_jobs`,
`lv_correctivo_imagen`).

### 5. Crear primer admin vía tinker

```bash
php artisan tinker
```

Dentro:

```php
$u1 = \App\Models\U1::first();
echo "u1.user_id = {$u1->user_id}, email = {$u1->email}, username = {$u1->username}\n";

$admin = \App\Models\User::create([
    'legacy_kind'              => 'admin',
    'legacy_id'                => $u1->user_id,
    'email'                    => $u1->email,                          // o el que prefiera el usuario
    'name'                     => $u1->username,                       // ajustable
    'password'                 => '<PASSWORD-NUEVO-CHOSEN>',           // bcrypt automático por cast 'hashed'
    'legacy_password_sha1'     => null,
    'lv_password_migrated_at'  => now(),
    'email_verified_at'        => now(),
]);
echo "lv_users.id = {$admin->id}\n";
exit
```

NOTAS:
- El password lo elige el admin en este momento; NO se reutiliza el SHA1 de `u1.password`.
  Por eso `legacy_password_sha1=null` y `lv_password_migrated_at=now()`: este admin ya está
  "post-migración" desde día uno. Bloque 06 no tocará a este usuario.
- Si se cambia de email respecto a `u1.email`, perfecto — el lookup de Bloque 06 será por
  `(legacy_kind, legacy_id)`, no por email.

### 6. Verificar login

Mac → navegador → `https://piv.winfin.es/admin/login`.

Login con email + password elegidos. Esperado: dashboard Filament con tema cobalto + Instrument Serif visibles.

Si falla:
- 419 CSRF mismatch → `php artisan optimize:clear` en prod.
- 500 → `tail -50 storage/logs/laravel.log`.
- Login redirige a sí mismo → password no quedó bcrypt; verificar `User::first()->password` empieza por `$2y$`.

### 7. Cerrar runbook

Apuntar en este archivo la fecha de ejecución y el hash del backup pre-deploy.
Actualizar `memory/status.md` con el resultado.
