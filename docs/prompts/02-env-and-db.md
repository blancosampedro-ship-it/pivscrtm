# Bloque 02 — `.env` real + 4 tareas críticas en producción

> **Cómo se usa este archivo:** lee primero la sección **"Antes de pegar el prompt"** y haz los 3 pasos manuales. Luego copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). El bloque entero te llevará ~30-45 min, con varias pausas donde Copilot pide tu OK antes de actuar sobre producción.

---

## Por qué este bloque es distinto a 01 y 01b

**Por primera vez tocamos producción.** Específicamente:

- Conectamos el Mac local al MySQL real de SiteGround (lectura).
- Borramos un archivo del servidor de la app vieja (el dump SQL público — incidente RGPD).
- Verificamos cron real SiteGround (granularidad — afecta a decisiones de ADR-0001).
- Ejecutamos un `UPDATE` ad-hoc sobre la tabla `asignacion` legacy para limpiar el bug histórico de "REVISION MENSUAL".

Cualquier error puede romper la app vieja (regla #1). Por eso este bloque tiene **3 puntos de confirmación explícita** donde Copilot PARA y espera tu OK antes de continuar.

**Definition of Done de este bloque:**
1. `.env` LOCAL apunta a SiteGround MySQL con credenciales reales (NO commiteado).
2. `php artisan tinker --execute="DB::connection()->getPdo();"` conecta sin error.
3. `SHOW TABLES;` desde Eloquent lista las 14 tablas legacy esperadas.
4. `phpunit.xml` verifica que tests usan SQLite memory, NO prod (defensa contra accidentes).
5. **Dump SQL público borrado**: `curl -I https://winfin.es/serv19h17935_winfin_2025-04-25_07-53-24.sql` devuelve `404`.
6. **Cron real verificado**: granularidad documentada en ADR-0001 (1-min o el límite real que enforce SiteGround).
7. **Emails duplicados verificados** (ADR-0005 §4): SQL ejecutado, resultado documentado.
8. **REVISION MENSUAL contaminado limpiado** (o lista pendiente de revisión, según decisión en el momento): `UPDATE asignacion SET tipo=2 WHERE id IN (...)` ejecutado tras OK explícito.
9. Commits con conventional commits, push verde en CI.

---

## Antes de pegar el prompt — 3 pasos manuales tuyos

### Paso A — Whitelistear tu IP en SiteGround Remote MySQL

1. Saca tu IP pública actual:
   ```bash
   curl -s ifconfig.me; echo
   ```
   Apúntala (formato `xx.xx.xx.xx`).

2. Abre https://my.siteground.com/ → **Sites → winfin.es → Site Tools**.

3. En el menú izquierdo: **Site → MySQL → Remote**.

4. En "Add new IP" pega tu IP. Comment: `Mac local desarrollo Winfin PIV`. Guarda.

5. **En la misma pantalla, anota el hostname remoto MySQL.** Usualmente algo como `xxx.siteground.biz` o un IP. Está en algún sitio visible de la sección Remote MySQL — texto tipo "Connect using hostname: ...". **Lo necesitarás cuando Copilot te lo pregunte.**

### Paso B — Tener a mano

- Passphrase de la SSH key `siteground_winfin` (no la pegues aquí; la introducirás cuando ssh-add la pida).
- Hostname remoto MySQL del Paso A.5.

### Paso C — Verificar acceso a `config.php` de la app vieja

```bash
ls "/Users/winfin/Documents/LENOVO1/WINFIN PIVS/winfinpiv/config.php"
```

Si el archivo existe, perfecto. Si no, **avísame antes de pegar el prompt** — Copilot no podrá leer las creds de BD automáticamente y tendríamos que cambiar de plan.

Cuando hayas hecho A, B y C, pega el prompt.

---

## Riesgos y mitigaciones

- **Credenciales BD en chat:** Copilot lee `config.php` legacy sin mostrar las creds en stdout, y las escribe directamente a `.env` (que está gitignored). Cero exposición en log.
- **Conexión MySQL falla:** primera causa probable = whitelisting no propagado. SiteGround tarda hasta 60s en aplicar la entrada nueva. Mitigación: reintentar cada 30s hasta 3 min antes de declarar fallo.
- **Borrado del dump:** acción irreversible en prod. Mitigación: (a) ya tienes backup local con sha256 documentado en memoria desde 28 abr, (b) el archivo en SSH se mueve primero a `~/dump-borrado-bloque-02-<fecha>.sql.tombstone` antes de `rm` definitivo, así si te das cuenta de algo en los próximos 7 días, está recuperable; (c) SiteGround daily backup retiene 30 días.
- **UPDATE de REVISION MENSUAL:** acción modificadora sobre tabla legacy. ADR-0002 prohíbe ALTER, pero NO prohíbe UPDATE de contenido — esto se documenta como excepción justificada al ADR-0002 en `docs/security.md` y como nota en ADR-0004. Mitigación: backup específico de `asignacion` antes del UPDATE (CSV con todas las filas a modificar) + transacción + verificación post-update.
- **Cron de prueba se queda colgado:** si Copilot añade un cron de prueba via SSH y falla a retirarlo, queda escribiendo a `~/cron-test-bloque-02.log` indefinidamente. Mitigación: el cron de prueba tiene un comentario `# TEMP-BLOQUE-02-FECHA` que lo identifica para retirada manual si hace falta.
- **`.env` se commitea por accidente:** Mitigación pre-existente (`.env` en `.gitignore` desde Bloque 01). Verificación adicional con `git check-ignore .env` antes de cualquier `git add`.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero `.github/copilot-instructions.md`, `CLAUDE.md`, `docs/security.md`, `docs/decisions/0001-stack.md`, `docs/decisions/0002-database-coexistence.md`, `docs/decisions/0004-revision-vs-averia-ux.md` y `docs/decisions/0005-user-unification.md`.

Tu tarea: ejecutar el Bloque 02. Configurar `.env` local con credenciales SiteGround reales, verificar conectividad y las 14 tablas legacy, ejecutar las 4 tareas críticas (borrar dump SQL público, verificar cron real, verificar emails duplicados, cleanup REVISION MENSUAL contaminado), commitear y pushear.

ATENCIÓN — Acciones destructivas en producción
=================================================
Este bloque tiene TRES puntos donde PARAS y esperas confirmación EXPLÍCITA del usuario en chat antes de actuar:
  - PASO 6: borrado del dump SQL público.
  - PASO 9: ejecución del UPDATE asignacion para limpieza REVISION MENSUAL.
  - PASO 12: commit + push final.

NUNCA actúes sobre prod sin "sí" explícito en chat. NUNCA imprimas el contenido de `config.php` legacy en stdout. NUNCA escribas DB_PASSWORD ni APP_KEY en mensajes que vayan al chat.

## Paso 0 — Pre-flight check

Verifica el estado del repo local:

```bash
pwd                         # /Users/winfin/Documents/winfin-piv
git branch --show-current   # main
git status --short          # vacío (working tree clean)
git log --oneline | head -3 # últimos commits son los de Bloque 01b
```

Si algo no encaja, AVISA y para.

Carga la SSH key en ssh-agent (te pedirá passphrase UNA vez):

```bash
ssh-add -l 2>&1 | head -3
```

Si dice "The agent has no identities", carga la key:

```bash
ssh-add ~/.ssh/siteground_winfin
```

Te pedirá passphrase. Tras introducirla:

```bash
ssh-add -l
```

Debe mostrar la huella de la key. Si NO la muestra, AVISA y para — algo del flujo SSH no funciona.

Verifica conectividad SSH al server:

```bash
ssh -p 18765 -o BatchMode=yes -o ConnectTimeout=10 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es 'echo "SSH OK"; pwd; uname -a' 2>&1 | head -5
```

Si no responde "SSH OK", AVISA y para — la SSH key no se cargó correctamente o el agente expiró.

Verifica acceso al `config.php` de la app vieja:

```bash
ls "/Users/winfin/Documents/LENOVO1/WINFIN PIVS/winfinpiv/config.php"
```

Si no existe, AVISA y para — necesitamos ese archivo para extraer las creds BD.

## Paso 1 — Pedir hostname MySQL remoto al usuario

El usuario debe haber whitelisteado su IP en SiteGround Site Tools y anotado el hostname remoto MySQL. Pregúntaselo:

> "Bloque 02 listo para arrancar. Pega el hostname remoto MySQL que viste en SiteGround Site Tools → Site → MySQL → Remote (formato típico: `xxx.siteground.biz` o IP). NO me pegues credenciales — solo el hostname."

ESPERA respuesta del usuario. Cuando responda, guarda el hostname en una variable interna (no la commitees, no la imprimas innecesariamente). Llamémosla `$DB_HOST_REMOTE`.

## Paso 2 — Extraer credenciales BD del config.php legacy SIN imprimirlas

El archivo `config.php` legacy contiene constantes tipo:
```php
$db_host = 'localhost';
$db_user = '...';
$db_pass = '...';
$db_name = 'dbvnxblp2rzlxj';
```

Extrae las 4 con grep + sed, escribe a un archivo temporal con permisos 600, NUNCA muestres valores en stdout:

```bash
TMP_CREDS=$(mktemp)
chmod 600 "$TMP_CREDS"
grep -E '\$db_(user|pass|name)' "/Users/winfin/Documents/LENOVO1/WINFIN PIVS/winfinpiv/config.php" > "$TMP_CREDS"
echo "TMP_CREDS=$TMP_CREDS"
echo "Líneas extraídas: $(wc -l < "$TMP_CREDS")  (esperado: 3)"
```

NO `cat $TMP_CREDS` ni hagas echo de los valores. Solo confirma número de líneas.

Si el grep no extrae 3 líneas, AVISA — el formato del config.php es distinto y hay que adaptar el sed.

## Paso 3 — Escribir `.env` LOCAL con credenciales reales

Lee del temporal y reescribe `.env` (LOCAL, gitignored). El campo `DB_HOST` usa el hostname remoto que pegó el usuario en Paso 1, NO `localhost` del config.php legacy (eso es desde el server, nosotros conectamos remoto).

```bash
# Construye el bloque DB_* a partir del temporal
DB_USER=$(grep '\$db_user' "$TMP_CREDS" | sed -E "s/.*=\s*['\"]([^'\"]+)['\"].*/\1/")
DB_PASS=$(grep '\$db_pass' "$TMP_CREDS" | sed -E "s/.*=\s*['\"]([^'\"]+)['\"].*/\1/")
DB_NAME=$(grep '\$db_name' "$TMP_CREDS" | sed -E "s/.*=\s*['\"]([^'\"]+)['\"].*/\1/")

# Confirma sin imprimir valores
echo "DB_USERNAME extraído: longitud $(echo -n "$DB_USER" | wc -c | tr -d ' ')  (esperado: > 5)"
echo "DB_PASSWORD extraído: longitud $(echo -n "$DB_PASS" | wc -c | tr -d ' ')  (esperado: > 8)"
echo "DB_DATABASE extraído: $DB_NAME  (esperado: dbvnxblp2rzlxj)"
```

Si alguno está vacío o el DB_DATABASE no es `dbvnxblp2rzlxj`, AVISA y para.

Reemplaza las líneas del .env. Asegúrate de que SOLO modificas las DB_*, SESSION_DRIVER y CACHE_STORE — el resto del .env (APP_KEY, etc.) queda intacto:

```bash
# Backup del .env actual antes de modificar
cp .env .env.bak

# Reemplazos
sed -i.tmp \
  -e "s|^DB_HOST=.*|DB_HOST=$DB_HOST_REMOTE|" \
  -e "s|^DB_USERNAME=.*|DB_USERNAME=$DB_USER|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" \
  -e "s|^DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" \
  -e "s|^SESSION_DRIVER=.*|SESSION_DRIVER=database|" \
  -e "s|^CACHE_STORE=.*|CACHE_STORE=database|" \
  .env

rm -f .env.tmp
```

Limpia el temporal:

```bash
rm -f "$TMP_CREDS"
```

Verifica .env todavía gitignored:

```bash
git check-ignore .env
# debe imprimir: .env
```

Si NO imprime `.env`, ALARMA — el .env podría commitearse. PARA y AVISA.

## Paso 4 — Probar conexión y listar 14 tablas

Reintenta hasta 3 veces (el whitelisting puede tardar hasta 60s en propagar):

```bash
for i in 1 2 3; do
  echo "Intento $i..."
  if php artisan tinker --execute="DB::connection()->getPdo(); echo 'CONEXIÓN OK';" 2>&1 | grep -q "CONEXIÓN OK"; then
    echo "OK en intento $i"
    break
  fi
  echo "Falló intento $i, esperando 30s..."
  sleep 30
done
```

Si los 3 intentos fallan, AVISA con el output del último intento. Causas probables:
- Whitelisting de IP no aplicado todavía → usuario verifica en SiteGround.
- Hostname incorrecto → usuario revisa lo que pegó en Paso 1.
- Password con caracteres especiales que sed no escapó bien → revisar el .env (sin imprimir el valor; usar `head -c 20 .env | sed 's/[A-Za-z0-9]/x/g'` o similar).

Si conecta, lista las tablas legacy:

```bash
php artisan tinker --execute="
\$tables = collect(DB::select('SHOW TABLES'))->flatten()->map(fn(\$o) => array_values((array)\$o)[0])->sort()->values();
\$expected = collect(['piv','averia','asignacion','correctivo','revision','tecnico','operador','modulo','piv_imagen','instalador_piv','desinstalado_piv','reinstalado_piv','u1','session']);
echo 'Total tablas en BD: '.\$tables->count().PHP_EOL;
echo 'Legacy esperadas presentes: '.\$tables->intersect(\$expected)->count().' / '.\$expected->count().PHP_EOL;
\$missing = \$expected->diff(\$tables);
echo 'Faltan: '.(\$missing->isEmpty() ? 'ninguna' : \$missing->implode(', ')).PHP_EOL;
\$extra = \$tables->diff(\$expected);
echo 'Adicionales (Laravel lv_* o legacy no documentadas): '.\$extra->count().PHP_EOL;
\$extra->each(fn(\$t) => print('  - '.\$t.PHP_EOL));
" 2>&1
```

Esperado:
- 14 / 14 legacy presentes.
- 0 faltan.
- Adicionales: cualquier `lv_*` (vacío en este momento) o tablas legacy no documentadas que aparezcan deben listarse para revisar.

## Paso 5 — Verificar que `phpunit.xml` NO conecta a producción en tests

Crítico: si `phpunit.xml` no override la conexión BD, los tests Pest podrían correr contra prod y hacer cosas raras (escribir, truncar, etc.).

```bash
grep -A 1 -E '(DB_CONNECTION|DB_DATABASE)' phpunit.xml | head -10
```

Esperado: ver `<env name="DB_CONNECTION" value="sqlite"/>` y `<env name="DB_DATABASE" value=":memory:"/>`. Si NO están, el archivo necesita esos overrides:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Si tienes que añadirlos, edítalos en la sección `<php>` de `phpunit.xml`. Tras el cambio, corre los tests para confirmar que siguen pasando con SQLite memory:

```bash
./vendor/bin/pest --colors=always --compact
```

## Paso 6 — BORRAR DUMP SQL PÚBLICO (CONFIRMACIÓN EXPLÍCITA)

Esta es la primera acción destructiva en producción. PARA y pide confirmación explícita.

Primero, verifica que el dump sigue accesible:

```bash
curl -sI https://winfin.es/serv19h17935_winfin_2025-04-25_07-53-24.sql 2>&1 | head -3
```

Debe devolver `HTTP/2 200`. Si ya está 404, el archivo ya no está; informa al usuario y salta al Paso 7.

Si está 200, calcula el sha256 remoto (vía SSH al server, sin descargar):

```bash
ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es \
  'shasum -a 256 ~/www/winfin.es/public_html/serv19h17935_winfin_2025-04-25_07-53-24.sql' 2>&1
```

Compara con el sha256 documentado en memoria persistente del usuario:
`144c2c634a2c3f95569fb0ef2afd1c72361838cfc04a93006f1d7819d6deff8e`

Si NO coincide, AVISA — el archivo en producción es distinto al que el usuario tiene como backup local. NO borres sin confirmar.

Si coincide:

PARA. Pregunta al usuario en chat:

> "Listo para borrar el dump SQL público de producción. SHA256 confirmado coincide con tu backup local del 28 abr. Voy a:
>
> 1. Mover el archivo a `~/dump-borrado-bloque-02-2026-04-30.sql.tombstone` (rename, no rm — recuperable durante 7 días).
> 2. Verificar `curl -I` devuelve 404 desde fuera.
> 3. Programar `rm` definitivo del tombstone para `+7 días` en un comentario en docs/security.md (recordatorio manual).
>
> ¿Procedo? (responder 'sí, borra')"

ESPERA respuesta. Solo si la respuesta es exactamente "sí, borra" o equivalente claro, ejecuta:

```bash
ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es \
  'mv ~/www/winfin.es/public_html/serv19h17935_winfin_2025-04-25_07-53-24.sql ~/dump-borrado-bloque-02-'$(date +%Y-%m-%d)'.sql.tombstone && echo MOVED_OK || echo MOVE_FAILED'
```

Verifica el 404:

```bash
sleep 2
curl -sI https://winfin.es/serv19h17935_winfin_2025-04-25_07-53-24.sql 2>&1 | head -1
# debe imprimir: HTTP/2 404
```

Si NO es 404, AVISA — el archivo todavía es accesible (cache CDN, otra ruta, etc.). Investigar antes de declarar done.

## Paso 7 — Verificar cron real SiteGround (granularidad)

ADR-0001 asume `* * * * * php artisan schedule:run`. El subagente externo flag que SiteGround GoGeek puede enforzar mínimo 15 min en Site Tools UI. Verificamos por SSH crontab.

Lista crontab actual:

```bash
ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es 'crontab -l 2>/dev/null || echo "EMPTY_CRONTAB"' 2>&1
```

Documenta el output (si hay crons existentes de la app vieja, NO los toques).

Añade cron de prueba minutal (sin tocar los existentes):

```bash
ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es \
  '(crontab -l 2>/dev/null; echo "* * * * * date \"+%Y-%m-%dT%H:%M:%S\" >> ~/cron-test-bloque-02.log  # TEMP-BLOQUE-02") | crontab -'
```

Confirma que se añadió:

```bash
ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es 'crontab -l | grep TEMP-BLOQUE-02' 2>&1
```

Espera 3 minutos para que dispare 2-3 veces:

```bash
echo "Esperando 3 minutos para verificar granularidad del cron..."
sleep 180
```

Lee el log:

```bash
ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es 'cat ~/cron-test-bloque-02.log 2>&1; echo "---wc---"; wc -l ~/cron-test-bloque-02.log 2>&1' 2>&1
```

Interpreta el resultado:
- Si hay 2-3 líneas con timestamps espaciados por 1 minuto → **granularidad 1-min funciona vía SSH crontab**. Documenta como OK.
- Si hay 1 línea o 0 → granularidad mayor (15 min, etc.) o el cron no arrancó. Espera 5 min más y reintenta. Si sigue raro → granularidad no es 1-min.
- Si hay timestamps espaciados de forma irregular → reportar el patrón observado.

Retira el cron de prueba:

```bash
ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es \
  'crontab -l | grep -v TEMP-BLOQUE-02 | crontab -'
```

Confirma:

```bash
ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es 'crontab -l 2>/dev/null | grep TEMP-BLOQUE-02 && echo "ALERTA: cron de prueba todavía presente" || echo "OK: cron de prueba retirado"'
```

Borra el log:

```bash
ssh -p 18765 -i ~/.ssh/siteground_winfin u2409-puzriocmpohe@ssh.winfin.es 'rm -f ~/cron-test-bloque-02.log && echo OK'
```

Captura el resultado de granularidad para documentar después en ADR-0001.

## Paso 8 — Verificar emails duplicados entre `u1` / `tecnico` / `operador` (read-only)

Query de ADR-0005 §4:

```bash
php artisan tinker --execute="
\$rows = DB::select('
  SELECT email, COUNT(*) AS apariciones, GROUP_CONCAT(origen) AS donde
  FROM (
    SELECT email, \"admin\" AS origen FROM u1 WHERE email IS NOT NULL AND email <> \"\"
    UNION ALL
    SELECT email, \"tecnico\" AS origen FROM tecnico WHERE email IS NOT NULL AND email <> \"\"
    UNION ALL
    SELECT email, \"operador\" AS origen FROM operador WHERE email IS NOT NULL AND email <> \"\"
  ) t
  GROUP BY email
  HAVING apariciones > 1
');
echo 'Emails duplicados: '.count(\$rows).PHP_EOL;
foreach (\$rows as \$r) {
  echo '  - '.\$r->email.' aparece en: '.\$r->donde.PHP_EOL;
}
" 2>&1
```

Captura el output. Resultados posibles:
- **0 duplicados** → ADR-0005 §3 (role-hint por subdominio) funciona limpio. Documenta y sigue.
- **N duplicados** → muestra la lista. El plan original de ADR-0005 sigue vigente porque cada login es por subdominio (`/admin/login`, `/tecnico/login`, `/operador/login`), pero documenta los emails colisionantes para revisión manual cuando lleguemos a Bloque 06.

## Paso 9 — Inventory de "REVISION MENSUAL" en `asignacion` (read-only) — CONFIRMACIÓN EXPLÍCITA

Esta query inventaria las filas contaminadas según las regex de ADR-0004:

```bash
php artisan tinker --execute="
\$rows = DB::select('
  SELECT
    CASE
      WHEN averia.notas REGEXP \"[Rr][Ee][Vv][Ii][Ss][IiÍí][OoÓó][Nn][[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\" THEN \"rev_mensual\"
      WHEN averia.notas REGEXP \"[Rr][Ee][Vv]\\\\.?[[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\" THEN \"rev_mensual_abrev\"
      WHEN averia.notas REGEXP \"[Mm][Ee][Nn][Ss][Uu][Aa][Ll][[:space:]]*[Yy][[:space:]]*[Oo][Kk]\" THEN \"mensual_y_ok\"
      ELSE \"otra\"
    END AS variante,
    COUNT(*) AS apariciones
  FROM averia
  JOIN asignacion ON asignacion.averia_id = averia.id
  WHERE asignacion.tipo = 1
  GROUP BY variante
  ORDER BY apariciones DESC
');
echo 'Inventory variantes en asignacion.tipo=1:'.PHP_EOL;
foreach (\$rows as \$r) {
  echo '  - '.\$r->variante.': '.\$r->apariciones.' filas'.PHP_EOL;
}
" 2>&1
```

Captura el resultado. Saca también una muestra de 20 filas concretas para revisión visual:

```bash
php artisan tinker --execute="
\$sample = DB::select('
  SELECT asignacion.id AS asignacion_id, averia.id AS averia_id, LEFT(averia.notas, 80) AS notas_preview, averia.fecha
  FROM averia
  JOIN asignacion ON asignacion.averia_id = averia.id
  WHERE asignacion.tipo = 1
    AND (
      averia.notas REGEXP \"[Rr][Ee][Vv][Ii][Ss][IiÍí][OoÓó][Nn][[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\"
      OR averia.notas REGEXP \"[Rr][Ee][Vv]\\\\.?[[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\"
      OR averia.notas REGEXP \"[Mm][Ee][Nn][Ss][Uu][Aa][Ll][[:space:]]*[Yy][[:space:]]*[Oo][Kk]\"
    )
  ORDER BY averia.fecha DESC
  LIMIT 20
');
foreach (\$sample as \$s) {
  echo \$s->asignacion_id.' | '.\$s->fecha.' | '.\$s->notas_preview.PHP_EOL;
}
" 2>&1
```

PARA. Muestra al usuario el inventory + sample y pregunta:

> "Inventory completado. He encontrado N filas en asignacion con tipo=1 cuyo averia.notas tiene patrón REVISION MENSUAL.
>
> Variantes:
> [pegar tabla del inventory]
>
> Muestra de 20 filas más recientes:
> [pegar lista de id | fecha | notas_preview]
>
> Hay tres opciones:
>
> A) Ejecutar UPDATE: cambiar `asignacion.tipo` de 1 a 2 para todas las filas que matchean los patrones, dentro de transacción + backup CSV previo. El UPDATE es idempotente (correrlo dos veces no afecta porque la segunda vez no hay filas con tipo=1 que matcheen).
>
> B) Reducir el filtro: si las muestras revelan algún falso positivo (avería real cuya nota menciona 'revisión mensual' por casualidad), ajustamos los regex y volvemos a inventarear.
>
> C) Saltar por ahora: documentar el inventory y diferir el UPDATE a otra sesión donde lo consultes con el cliente.
>
> ¿A, B o C?"

ESPERA respuesta.

## Paso 10 — UPDATE asignacion para REVISION MENSUAL (solo si usuario eligió A)

Si la respuesta es A:

Crea backup CSV de las filas afectadas ANTES del UPDATE:

```bash
mkdir -p docs/runbooks/legacy-cleanup
BACKUP_CSV="docs/runbooks/legacy-cleanup/asignacion-revision-mensual-$(date +%Y%m%d-%H%M%S).csv"

php artisan tinker --execute="
\$rows = DB::select('
  SELECT asignacion.id AS asignacion_id, asignacion.averia_id, asignacion.tecnico_id, asignacion.tipo AS tipo_antes, asignacion.fecha AS asignacion_fecha, asignacion.status, averia.fecha AS averia_fecha, averia.notas
  FROM asignacion
  JOIN averia ON averia.id = asignacion.averia_id
  WHERE asignacion.tipo = 1
    AND (
      averia.notas REGEXP \"[Rr][Ee][Vv][Ii][Ss][IiÍí][OoÓó][Nn][[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\"
      OR averia.notas REGEXP \"[Rr][Ee][Vv]\\\\.?[[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\"
      OR averia.notas REGEXP \"[Mm][Ee][Nn][Ss][Uu][Aa][Ll][[:space:]]*[Yy][[:space:]]*[Oo][Kk]\"
    )
');
\$fp = fopen('$BACKUP_CSV', 'w');
fputcsv(\$fp, ['asignacion_id','averia_id','tecnico_id','tipo_antes','asignacion_fecha','status','averia_fecha','notas']);
foreach (\$rows as \$r) fputcsv(\$fp, (array)\$r);
fclose(\$fp);
echo 'Backup escrito: $BACKUP_CSV con '.count(\$rows).' filas'.PHP_EOL;
" 2>&1

echo "SHA256 backup:"
shasum -a 256 "$BACKUP_CSV"
```

Ejecuta el UPDATE en transacción:

```bash
php artisan tinker --execute="
DB::beginTransaction();
try {
  \$affected = DB::update('
    UPDATE asignacion
    JOIN averia ON averia.id = asignacion.averia_id
    SET asignacion.tipo = 2
    WHERE asignacion.tipo = 1
      AND (
        averia.notas REGEXP \"[Rr][Ee][Vv][Ii][Ss][IiÍí][OoÓó][Nn][[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\"
        OR averia.notas REGEXP \"[Rr][Ee][Vv]\\\\.?[[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\"
        OR averia.notas REGEXP \"[Mm][Ee][Nn][Ss][Uu][Aa][Ll][[:space:]]*[Yy][[:space:]]*[Oo][Kk]\"
      )
  ');
  echo 'Filas modificadas: '.\$affected.PHP_EOL;
  DB::commit();
  echo 'COMMIT OK'.PHP_EOL;
} catch (Exception \$e) {
  DB::rollBack();
  echo 'ROLLBACK: '.\$e->getMessage().PHP_EOL;
}
" 2>&1
```

Verifica idempotencia (re-ejecutar la inventory query del Paso 9 debe devolver 0 filas):

```bash
php artisan tinker --execute="
\$count = DB::select('
  SELECT COUNT(*) AS c
  FROM asignacion
  JOIN averia ON averia.id = asignacion.averia_id
  WHERE asignacion.tipo = 1
    AND (
      averia.notas REGEXP \"[Rr][Ee][Vv][Ii][Ss][IiÍí][OoÓó][Nn][[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\"
      OR averia.notas REGEXP \"[Rr][Ee][Vv]\\\\.?[[:space:]]+[Mm][Ee][Nn][Ss][Uu][Aa][Ll]\"
      OR averia.notas REGEXP \"[Mm][Ee][Nn][Ss][Uu][Aa][Ll][[:space:]]*[Yy][[:space:]]*[Oo][Kk]\"
    )
')[0]->c;
echo 'Filas restantes con patrón REVISION MENSUAL en tipo=1: '.\$count.PHP_EOL;
echo (\$count == 0 ? 'IDEMPOTENTE OK' : 'ALERTA: hay residuo').PHP_EOL;
" 2>&1
```

Si dice IDEMPOTENTE OK, sigue. Si hay residuo, AVISA — alguna variante regex no atrapa todo.

## Paso 11 — Documentar resultados

Edita `docs/security.md` para registrar:

1. Borrado del dump SQL público (Paso 6): fecha, sha256 antes del borrado, verificación 404, recordatorio para `rm` del tombstone en `+7 días`.
2. Verificación de cron real (Paso 7): granularidad observada (1-min, 15-min, otro). Si NO es 1-min, edita también ADR-0001 sección Consequences negativas.
3. Inventario de emails duplicados (Paso 8): cuántos, lista resumen.
4. (Si aplicó) UPDATE REVISION MENSUAL (Paso 10): número de filas afectadas, path del CSV de backup, sha256 del CSV.

Edita ADR-0004 sección "Limpieza puntual de datos históricos contaminados" para añadir un bloque "Ejecución" con: fecha, conteos resultantes, link al CSV de backup.

Si el cron real es 15-min en lugar de 1-min, edita ADR-0001 §Consequences negativas con una nueva línea documentando el hallazgo y su impacto en jobs (60s drift → 15-min drift en todos los schedulers).

## Paso 12 — Commits + push (CONFIRMACIÓN EXPLÍCITA antes del push)

Verifica que el .env NO se commitea:

```bash
git status
git check-ignore .env
```

Stage los cambios de docs:

```bash
git add docs/security.md docs/decisions/0004-revision-vs-averia-ux.md
git status
```

Si hubo cambios en ADR-0001 o phpunit.xml, también:

```bash
git add docs/decisions/0001-stack.md phpunit.xml 2>/dev/null
```

Si hay un CSV en docs/runbooks/legacy-cleanup/, también:

```bash
git add docs/runbooks/legacy-cleanup/
```

Verifica visualmente que NO está stageado .env, .env.bak, vendor/, node_modules/, /tmp/*, ni los archivos temporales del Paso 2.

Limpia residuos:

```bash
rm -f .env.bak
```

Commit principal:

```bash
git commit -m "chore(env): wire .env to SiteGround MySQL + verify legacy schema" -m "Bloque 02 ejecutado:

- .env LOCAL configurado con creds reales SiteGround (gitignored).
- Conexion MySQL verificada via tinker; las 14 tablas legacy presentes.
- phpunit.xml verificado: tests usan SQLite memory, no prod.

Las tareas criticas y sus resultados se documentan en commits siguientes
para granularidad de revision."
```

Si hubo borrado de dump + verificación cron + inventario emails:

```bash
git commit --allow-empty -m "docs: record SiteGround prod state after Bloque 02 verifications" -m "Documenta en docs/security.md y ADRs:

- Dump SQL publico borrado (sha256 verificado, 404 confirmado).
- Granularidad cron real SiteGround GoGeek: [1-min OK | 15-min — flagged en ADR-0001].
- Emails duplicados u1/tecnico/operador: [N duplicados | ninguno].
- (Si aplico) Cleanup REVISION MENSUAL: N filas asignacion.tipo 1->2 con CSV de backup."
```

Si ejecutó UPDATE REVISION MENSUAL:

```bash
git commit --allow-empty -m "fix(legacy): clean up REVISION MENSUAL contamination in asignacion" -m "UPDATE one-shot sobre asignacion.tipo de 1 a 2 para N filas cuyo
averia.notas matchea los patrones de REVISION MENSUAL documentados
en ADR-0004. Backup CSV en docs/runbooks/legacy-cleanup/.

Excepcion justificada al ADR-0002 (modifica contenido, no schema).
Idempotencia verificada: re-ejecutar la query devuelve 0 filas."
```

PARA. Muestra al usuario:

```bash
git log --oneline | head -5
```

Y pregunta:

> "Listo para pushear los commits de Bloque 02 a origin/main. CI va a correr en GitHub Actions y debe quedar verde sobre todos. ¿Procedo? (responder 'sí, push')"

ESPERA respuesta. Si "sí, push":

```bash
git push origin main
```

Espera CI:

```bash
sleep 10
RUN_ID=$(gh run list --workflow=ci.yml --limit 1 --json databaseId --jq '.[0].databaseId')
gh run watch $RUN_ID --exit-status
```

Si verde, sigue al reporte. Si rojo, captura logs y AVISA.

## Reporte final

Cuando todo verde, dame este resumen:

```
✅ Qué he hecho:
   - .env LOCAL configurado: DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE,
     SESSION_DRIVER=database, CACHE_STORE=database. Verificado gitignored.
   - Conexion MySQL OK. 14 tablas legacy presentes (lista: ...).
   - phpunit.xml verificado SQLite memory.
   - Dump SQL publico:
       - sha256 antes: [hash]
       - movido a tombstone: ~/dump-borrado-bloque-02-AAAA-MM-DD.sql.tombstone
       - curl -I devuelve: 404 ✓
       - tombstone para rm definitivo en: 2026-05-07 (anotado en docs/security.md)
   - Cron real SiteGround: [1-min | 15-min | otro]. Documentado en [security.md / ADR-0001].
   - Emails duplicados: [N duplicados | ninguno]. Lista en docs/security.md.
   - REVISION MENSUAL cleanup: [opcion elegida | filas N | CSV backup path]
   - Commits pusheados: [hashes].
   - CI run [RUN_ID]: success / 3 jobs verdes.

⏳ Qué falta:
   - Bloque 03 — Eloquent models para las 14 tablas legacy con accessors/
     mutators de charset latin1<->utf8mb4.

❓ Qué necesito del usuario:
   - Confirmar visualmente https://winfin.es/serv19h17935_winfin_2025-04-25_07-53-24.sql
     en el navegador devuelve 404 / pagina de error.
   - Verificar pestaña Actions del repo: ultimo run en verde.
   - Anotar en agenda recordatorio para borrar tombstone del dump
     en 7 dias (2026-05-07): comando ssh ... rm ~/dump-borrado-bloque-02-*.sql.tombstone
   - (Si aplico) revisar el CSV de backup REVISION MENSUAL en
     docs/runbooks/legacy-cleanup/ por si quieres archivarlo aparte.
```

END PROMPT
```

---

## Después de Bloque 02

- **Bloque 03** — Eloquent models para las 14 tablas legacy con accessors/mutators de charset latin1↔utf8mb4. Lectura puramente: nada que escribir todavía.
- **Bloque 04** — Migrations para tablas internas Laravel con prefijo `lv_`. Incluye `lv_users` con schema de ADR-0005.
- **Bloque 05** — `composer require filament/filament` + custom theme con tokens de DESIGN.md + crear primer admin user vía tinker (insert manual en `lv_users` apuntando a `u1.id`).

Bloque 02 es la barrera más alta del proyecto. Pasado este, los siguientes son sustancialmente más mecánicos.
