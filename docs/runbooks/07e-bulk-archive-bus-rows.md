# Runbook 07e — Bulk archive bus rows post-merge

> Se ejecuta DESPUÉS de mergear PR del Bloque 07e. Lo hace Claude Code o el usuario
> vía tinker/script. Toca BD producción (insertando en `lv_piv_archived`, NO toca `piv`).

## Prerequisitos

- PR Bloque 07e mergeado en `main`.
- Local sincronizado (`git pull`).
- Migrate aplicado a prod: `php artisan migrate` (crea `lv_piv_archived` en SiteGround).
- Backup fresco de la BD prod (mysqldump < 24h).

## Pasos

### 1. Backup prod

Mismo patrón que Bloque 05:

```bash
MYSQLDUMP=/usr/local/opt/mysql-client@8.4/bin/mysqldump
TS=$(date +%Y%m%d-%H%M%S)
BACKUP=~/Documents/winfin-piv-backups-locales/prod-pre-bloque-07e-$TS.sql
TMP=$(mktemp); chmod 600 $TMP
DB_HOST=$(grep '^DB_HOST=' .env | cut -d= -f2-)
DB_USER=$(grep '^DB_USERNAME=' .env | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' .env | cut -d= -f2-)
cat > $TMP <<EOF
[client]
host=$DB_HOST
user=$DB_USER
password=$DB_PASS
EOF
$MYSQLDUMP --defaults-extra-file=$TMP --single-transaction --quick --no-tablespaces --skip-lock-tables "$DB_NAME" > $BACKUP
shasum -a 256 $BACKUP
rm $TMP
```

### 2. Aplicar migration a prod

```bash
php artisan migrate --pretend       # ver SQL
# Confirmar: solo CREATE TABLE lv_piv_archived
php artisan migrate
php artisan migrate:status | grep lv_piv_archived
```

### 3. Generar lista de piv_ids candidatos a archivar

```bash
php artisan tinker --execute='
$candidates = \DB::table("piv")
    ->whereRaw("REGEXP_REPLACE(parada_cod, \"[[:space:]]\", \"\") NOT REGEXP \"^[0-9]+[A-Z]?(\\\\([a-zA-Z ]+\\\\))?$\" OR parada_cod IS NULL OR parada_cod = \"\"")
    ->where(function($q){ $q->whereNull("direccion")->orWhere("direccion", ""); })
    ->where(function($q){ $q->whereNull("municipio")->orWhere("municipio", "")->orWhere("municipio", "0"); })
    ->orderBy("piv_id")
    ->select("piv_id", "parada_cod")
    ->get();
echo "TOTAL: " . count($candidates) . PHP_EOL;
foreach ($candidates as $r) echo $r->piv_id . " " . trim($r->parada_cod) . PHP_EOL;
'
```

Guarda la lista en `docs/runbooks/legacy-cleanup/bus-archive-ids-$(date +%Y%m%d).txt` con sha256 al final del archivo.

### 4. Confirmación humana sobre la lista

Revisa los IDs visualmente. Si alguno parece sospechoso, quítalo del archivo. Esperado: ~91-101 IDs en rango contiguo 469-559.

### 5. Bulk insert en lv_piv_archived

```bash
php artisan tinker --execute='
$ids = file("docs/runbooks/legacy-cleanup/bus-archive-ids-YYYYMMDD.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$ids = array_filter(array_map(fn($l) => (int) explode(" ", $l)[0], $ids));
echo "A archivar: " . count($ids) . PHP_EOL;
$reason = "Bus row from legacy vehicle project — bulk archive 2026-05-01 (audit ADR-0012)";
$inserted = 0;
foreach ($ids as $piv_id) {
    \App\Models\LvPivArchived::create([
        "piv_id" => $piv_id,
        "archived_at" => now(),
        "archived_by_user_id" => 1,
        "reason" => $reason,
    ]);
    $inserted++;
}
echo "INSERTED: $inserted" . PHP_EOL;
echo "TOTAL en lv_piv_archived: " . \App\Models\LvPivArchived::count() . PHP_EOL;
'
```

### 6. Smoke verificación

```bash
php artisan tinker --execute='
echo "Paneles activos (default scope): " . \App\Models\Piv::notArchived()->count() . PHP_EOL;
echo "Archivados: " . \App\Models\Piv::onlyArchived()->count() . PHP_EOL;
echo "Total piv tabla legacy: " . \DB::table("piv")->count() . " (sin cambios)" . PHP_EOL;
'
```

Esperado:
- Activos: ~474 (575 - ~101)
- Archivados: ~101
- Total piv: 575 (sin cambios)

### 7. Smoke navegador

`php artisan serve` → `/admin/pivs` → la lista ya no muestra Soler i Sauret, Sarbus, Monbus, etc. Filter "Solo archivados" los muestra todos.

### 8. Documentar resultado

Apunta en este runbook:
- Fecha ejecución.
- Hash del backup pre-deploy.
- Cuenta exacta de filas archivadas.
- piv_ids inesperados que quedaron sin archivar.
