# 0005 — Modelo unificado de usuarios (`lv_users`)

- **Status**: Accepted
- **Date**: 2026-04-29

## Context

La app vieja tiene **tres tablas separadas** para autenticación, una por rol:

- `u1` — admins (1 fila hoy).
- `tecnico` — técnicos (65 en BD, 3 activos).
- `operador` — operadores cliente (41 empresas).

Cada tabla tiene su propio `id` autoincremental. Los IDs **no son únicos entre tablas** (puede existir `u1.id=1`, `tecnico.id=1` y `operador.id=1` simultáneamente — son entidades distintas). Los `email` **no se garantizan únicos** entre las tres tablas (puede haber un técnico y un admin con el mismo email).

La app nueva usa Fortify + Filament + un guard custom (ver ADR-0003). Necesita un modelo `User` único que sepa de qué tabla legacy viene cada autenticación, sin alterar el schema legacy (regla de coexistencia, ADR-0002).

## Decision

### 1. Tabla `lv_users` — schema

```sql
CREATE TABLE lv_users (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    legacy_kind              ENUM('admin','tecnico','operador') NOT NULL,
    legacy_id                INT UNSIGNED NOT NULL,
    email                    VARCHAR(255) NOT NULL,
    name                     VARCHAR(255) NOT NULL,
    password                 VARCHAR(255) NULL,         -- bcrypt; NULL hasta primer login post-migración
    legacy_password_sha1     CHAR(40) NULL,             -- copia del SHA1 legacy; se borra al primer login OK
    lv_password_migrated_at  TIMESTAMP NULL,
    remember_token           VARCHAR(100) NULL,
    email_verified_at        TIMESTAMP NULL,
    created_at               TIMESTAMP NULL,
    updated_at               TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_legacy (legacy_kind, legacy_id),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `(legacy_kind, legacy_id)` es **único** y permite mapeo 1:1 con la tabla legacy de origen.
- `email` **no único** intencionadamente — admite el caso de email duplicado entre roles.
- `password` puede ser NULL hasta el primer login post-migración (lazy bcrypt, ver ADR-0003).

### 2. Estrategia de creación de filas — **lazy on first login**

**No se hace seeder one-shot ni cron de sync.** La fila `lv_users` se crea **al vuelo** en el primer login exitoso de cada usuario en la app nueva.

> **Importante (corrección 29 abr 2026):** el lookup canónico es por `(legacy_kind, legacy_id)` **tras** resolver la fila legacy, NUNCA por email en `lv_users`. Si lookáramos por email primero, un cambio de email en la app vieja después del primer login crearía una fila nueva en `lv_users` y dejaría la vieja huérfana, o `updateOrCreate` sobrescribiría silenciosamente. La fuente de verdad de la identidad es el `legacy_id` de la tabla origen.

```
[ Login attempt: email + password + role-hint ]
            │
            ▼
   ┌──────────────────────────────────┐
   │ Rate limit: ¿>5 intentos/min     │
   │ por (IP, email, role-hint)?      │
   └──────────────────────────────────┘
        │ sí                  │ no
        ▼                     ▼
   429 Too Many        ┌─────────────────────────────────┐
                       │ SIEMPRE primero: resolver legacy │
                       │   admin    → SELECT FROM u1     │
                       │   tecnico  → SELECT FROM tecnico │
                       │   operador → SELECT FROM operador│
                       │ WHERE email = ?                  │
                       └─────────────────────────────────┘
                            │ encontrado    │ no
                            ▼               ▼
                       ┌─────────────┐  fail
                       │ Lookup      │  (rate limit hit)
                       │ lv_users por│
                       │ (legacy_kind,
                       │  legacy_id) │
                       └─────────────┘
                            │
                  ┌─────────┴─────────┐
                  ▼                   ▼
              encontrado          no encontrado
                  │                   │
            bcrypt check              │
            ┌───┴────┐                │
            ok      falla             │
            │        │                │
            │        └────► SHA1 check (hash_equals)
            │                  │ ok      │ falla
            │                  ▼         ▼
            │       ┌──────────────────┐ fail
            │       │ updateOrCreate   │ (rate limit hit)
            │       │ por (legacy_kind,│
            │       │     legacy_id)   │
            │       └──────────────────┘
            │                  │
            ▼                  ▼
        Auth::login(user) ──► ✓ (rate limit clear)
```

**Ventajas vs seeder/cron:**
- Cero código de sync. Cero job. Cero desactualización entre app vieja y `lv_users`.
- El usuario que cambia password en la app vieja durante coexistencia funciona transparente: la próxima vez que entra a la nueva, validamos contra el SHA1 legacy actual de su tabla.
- Usuarios "dormidos" que nunca entran a la nueva: simplemente no tienen fila en `lv_users`. Cero overhead. Cuando entren, se crea.

### 3. Resolución del rol al login

Tres opciones de cómo el guard sabe en qué tabla buscar:

**A. Selector explícito en formulario de login** ("Entrar como admin / técnico / operador" radio).

**B. Subdominios separados:** `admin.piv.winfin.es` (Filament) / `piv.winfin.es/tecnico` (PWA técnico) / `piv.winfin.es/operador` (PWA operador). Cada flujo de login fija el `role-hint` por contexto.

**C. Resolución automática:** probar las tres tablas hasta encontrar match.

**Decisión: B** — un único subdominio `piv.winfin.es` con tres rutas de login distintas. Filament admin va a `/admin/login`, técnico a `/tecnico/login`, operador a `/operador/login`. Cada una sabe su `role-hint` por la ruta.

**Por qué B y no A:**
- Cero fricción para el usuario (no elige rol al login, el sitio lo sabe por la URL).
- Imposible login cruzado por accidente (un operador no puede meterse "como admin" sin saber la URL del admin).
- Cada PWA puede tener su manifest, su tema y su flujo independiente.

**Por qué B y no C:**
- C invita a leakage: si un técnico tiene mismo email que un operador y dispara el lookup secuencial en orden incorrecto, podría "entrar como" el rol equivocado.
- Coste de B prácticamente cero — son tres rutas Laravel.

### 4. Verificación previa: emails duplicados entre tablas

**Antes de programar el guard (Bloque 6 del roadmap), correr este SQL en producción** (read-only, vía SSH + `--defaults-extra-file`):

```sql
SELECT email, COUNT(*) AS apariciones, GROUP_CONCAT(origen) AS donde
FROM (
    SELECT email, 'admin'   AS origen FROM u1       WHERE email IS NOT NULL AND email <> ''
    UNION ALL
    SELECT email, 'tecnico' AS origen FROM tecnico  WHERE email IS NOT NULL AND email <> ''
    UNION ALL
    SELECT email, 'operador' AS origen FROM operador WHERE email IS NOT NULL AND email <> ''
) t
GROUP BY email
HAVING apariciones > 1;
```

- **Si vacío:** seguir con el plan tal cual. Login resuelto por subdominio + email.
- **Si hay duplicados:** documentar la lista y decidir caso por caso (probablemente ningún operador comparte email con técnico — probablemente alguno sí). En el peor caso, los emails colisionantes se resuelven porque cada login es por subdominio (`role-hint` separa).

## Considered alternatives

- **Tabla por rol** (`lv_admins`, `lv_tecnicos`, `lv_operadores`) — descartado: triplica código, complica Filament policies, no aporta sobre el modelo unificado con `legacy_kind`.
- **Seeder one-shot + cron sync continuo** — descartado: añade un job permanente y un punto de fallo (¿cuándo es la última vez que el cron corrió OK?). El lazy lookup elimina toda esa categoría de problema.
- **Sustituir SHA1 desde la app vieja por bcrypt antes de migrar** — descartado: requiere modificar `login.php` legacy o hacer reset masivo, ambos rotos por filosofía (no tocar app vieja).

## Consequences

**Positivas:**
- Schema simple: una tabla, un índice único compuesto, sin FK a tablas legacy (la relación es lógica vía `legacy_kind` + `legacy_id`).
- Cero job de sincronización. Cero código que mantener "en paralelo" mientras dura la coexistencia.
- Sub-dominios/rutas de login dan UX clara y aislamiento de seguridad por contexto.
- Compatible con `Spatie\LaravelPermission` o Filament policies (cada `legacy_kind` mapea 1:1 a un rol).

**Negativas:**
- En el primer login de cada usuario hay un query extra a la tabla legacy (`u1` / `tecnico` / `operador`). Ocurre **una sola vez por usuario** durante toda la vida del sistema. Insignificante.
- Si la tabla legacy cambia (p.ej. la app vieja escribe nuevo password tras la migración del usuario), la app nueva NO se entera. Aceptable porque tras el primer login en la nueva, el usuario debería migrar también su flujo de operación a la nueva. Si vuelve a la vieja y cambia ahí, pierde sync — pero entonces el siguiente login a la nueva fallará y **vuelve al lookup legacy** porque su `lv_users.password` es bcrypt obsoleto. **Detalle a manejar en el guard: si bcrypt falla, NO hacer fail directo — re-intentar lookup legacy por si la app vieja actualizó.** Esto se documenta en ADR-0003.

**Implementación en roadmap:**
- Prompt 04 (`04-lv-internal-tables.md`) crea `lv_users` con el schema de arriba.
- Prompt 06 (`06-auth-sha1-bcrypt.md`) implementa el guard con la lógica lazy completa + tests.
