# Hotfix 02 — Tablas: densidad Carbon real + acciones mínimas, adiós columna sticky

> **ESTADO: EJECUTADO.** Implementado directamente por Claude Code (petición explícita del
> usuario, 19 jul 2026) en la rama `fix/tablas-densidad-sin-sticky` — no hizo falta pasar
> este prompt a Copilot. Se conserva como registro de la spec. Cambios adicionales sobre lo
> especificado: sidebar a 220px (DESIGN.md ya lo exigía; Filament default era 320px) y
> Notas oculta por defecto. Suite completa verde (472 tests).

> **Origen:** revisión visual en producción (19 jul 2026) de Reportes → Averías. La columna
> Acciones sticky (introducida en `2ce9dc0`, PR #67) se lee como un bloque pegado a la derecha:
> el badge Estado queda cortado ("Bloque…") **en reposo**, sin scroll previo del usuario, y las
> filas miden ~110px cuando DESIGN.md §Spacing exige 36–40px (densidad productive). Decisión
> aprobada por el usuario: **eliminar el sticky en vez de decorarlo** — hacer que la tabla quepa,
> reducir Acciones a la mínima expresión, y borrar el CSS del parche. Esto revierte
> deliberadamente el enfoque de #67.

> **Cómo se usa:** copia el bloque `BEGIN PROMPT … END PROMPT`, pégalo en VS Code Copilot Chat
> (modo Agent), carpeta `~/Documents/winfin-piv/` abierta. Rama propia, PR aparte (NO tocar
> `main` directamente, NO `git add .`).

---

## Causa raíz

Tres problemas que se suman:

1. **La tabla desborda horizontalmente incluso en pantalla ancha.** Con solo 8 columnas visibles
   debería caber entera en 1280px (breakpoint Carbon Large). Al desbordar, la celda sticky tapa
   permanentemente el badge Estado. Una columna fija que oculta contenido nada más cargar siempre
   se lee como parche.

2. **La celda sticky va disfrazada de slab permanente** (`resources/css/filament/admin/theme.css`,
   bloque líneas ~130–184 + equivalente dark ~275–285): fondo opaco + hairline + degradado pintados
   *siempre*, haya o no contenido debajo.

3. **Las filas miden ~3× la spec.** El `.fi-ta-row { height: 40px }` (línea ~102) es inerte: en un
   `<tr>`, `height` actúa como mínimo y el padding interno de las celdas de Filament gana. Algo más
   está inflando las filas hasta ~110px — hay que diagnosticarlo en el DOM real.

## Fix

Patrón Carbon DataTable de verdad: la fila entera es clicable (abre el detalle), las acciones se
compactan en un overflow menu `⋮`, la tabla cabe sin scroll horizontal, y el CSS sticky se borra
entero. Sin desborde no hay nada que fijar; en ventanas estrechas las acciones simplemente hacen
scroll con el resto de la tabla (como GitHub). La solución más elegante es la que no se ve.

---

```text
BEGIN PROMPT

Eres el agente Copilot de Winfin PIV. Lee `.github/copilot-instructions.md`, `CLAUDE.md` y `DESIGN.md` antes de empezar.

CONTEXTO: la columna Acciones sticky de las tablas (commit 2ce9dc0, PR #67) se ve como un bloque pegado: en Reportes → Averías el badge Estado queda cortado en reposo, y las filas miden ~110px cuando DESIGN.md exige 36–40px (densidad productive Carbon). Decisión de diseño aprobada: eliminar el enfoque sticky (revertir deliberadamente esa parte de #67) y sustituirlo por el patrón Carbon: tabla que cabe sin overflow + fila clicable + overflow menu. PRIORIDAD ABSOLUTA: no romper nada — cambios visuales/UX quirúrgicos, cero cambios de schema, cero cambios funcionales fuera de lo especificado.

TAREA: en una rama nueva `fix/tablas-densidad-sin-sticky`, tres frentes:

FRENTE 1 — CSS: borrar el bloque sticky entero (`resources/css/filament/admin/theme.css`):
- Eliminar completo el bloque "Columna Acciones — sticky con borde suave" (líneas ~130–184): position sticky, shrink-to-fit, degradado ::before, fondos calcados de fila (`bg-gray-50`), centrado, y el `::after` con la etiqueta "Acciones" (la columna de un overflow menu no lleva etiqueta en Carbon — queda sin header, es lo deseado).
- Eliminar también el bloque equivalente de dark mode (líneas ~275–285: degradado dark + fondos `#1F1F1F`).
- NO tocar el resto del theme (inputs bottom-border, type scale, tokens).

FRENTE 2 — Densidad Carbon real (36–40px por fila):
- Primero DIAGNOSTICA en el DOM compilado (usa el arnés visual que se usó en #67: DOM de Filament + CSS compilado con `npm run build`) por qué las filas de Averías miden ~110px. El `.fi-ta-row { height: 40px }` actual (línea ~102) es inerte — height en <tr> es un mínimo. Identifica qué elemento lleva el padding vertical real (en Filament v3 típicamente el wrapper `.fi-ta-col-wrp` con `py-4`, o la propia `.fi-ta-cell`) y qué más infla la celda.
- Sustituye la regla inerte por compactación real del padding vertical de ese elemento, hasta que una fila de una línea (texto 14px o badge de 24px) mida 36–40px medidos en el DOM. No uses `!important` salvo que Tailwind lo haga inevitable; selector específico del theme, no inline.
- Las filas con contenido multilínea legítimo (columna Notas con ->wrap(), visible al togglearla) pueden crecer — el objetivo 36–40px aplica a filas de una línea.
- El objetivo colateral clave: con densidad compacta y los limit() existentes, la tabla de Averías (8 columnas visibles: ID, Fecha, Parada, Municipio, Operador, Técnico, Tipo, Estado + acciones) debe caber SIN overflow horizontal a 1280px de viewport. Si tras la densidad aún desborda a 1280px, ajusta limit() de Municipio/Operador/Técnico en `app/Filament/Resources/AveriaResource.php` (p. ej. limit(18)) hasta que quepa — sin ocultar columnas.

FRENTE 3 — Acciones mínimas en Averías (`app/Filament/Resources/AveriaResource.php`, método table()):
- Sustituir las dos acciones actuales (ViewAction iconButton ojo + EditAction iconButton lápiz) por UN solo `Tables\Actions\ActionGroup::make([...])` (kebab ⋮) que contenga la ViewAction existente (slideOver, modalWidth 2xl, mismo infolist — no cambiar nada de su configuración interna) y la EditAction existente.
- Añadir `->recordAction('view')` al $table para que el clic en la fila abra el slide-over de Ver. Lo esperado en Filament v3 es que funcione con la acción dentro del ActionGroup (las acciones agrupadas se resuelven por nombre) — verifícalo en el navegador. SOLO si en la versión instalada no montara desde el grupo: deja ViewAction como acción suelta visible junto al kebab (ojo + ⋮). NO uses `->hidden()` como truco (las acciones hidden no se montan).
- Comprueba que el clic en el botón kebab NO dispara además la acción de fila (Filament ya hace stopPropagation en los triggers de acción — verifícalo en el navegador).
- NO tocar las acciones de las otras 12 tablas con ->actions() en este PR (PivResource, AsignacionResource, LvAveriaIccaResource, ActivityResource, etc.) — adoptarán el patrón kebab en un PR posterior si procede. El cambio de CSS (frentes 1–2) sí les afecta globalmente y es intencionado: sus acciones dejan de ser sticky y sus filas se compactan.

RESTRICCIONES (recordatorio de las inviolables):
- Cero cambios de schema, cero cambios en queries/funcionalidad — esto es CSS + configuración de tabla de UN resource.
- Producción intocable; la app vieja winfin.es ni se mira.
- No commitear .env ni artefactos del arnés visual.

VERIFICACIÓN OBLIGATORIA (con el arnés visual, viewport 1280px Y ~1440px, light Y dark):
1. Averías: sin overflow horizontal a 1280px; badge Estado SIEMPRE visible entero; filas de una línea 36–40px medidas en DOM; striped y hover intactos; sin hairline ni degradado fantasma a la derecha.
2. Clic en fila → abre slide-over Ver con el infolist correcto. Kebab ⋮ → Ver y Editar funcionan (Editar navega a la página de edición).
3. Spot-check de al menos: Paneles PIV, Asignaciones, Averías ICCA (botones con texto Ver/Borrar) y Actividad — filas compactas, nada cortado, acciones usables; si alguna tabla ancha desborda, sus acciones hacen scroll con la tabla (comportamiento aceptado, NO reintroducir sticky).
4. Suite de tests completa verde (`php artisan test`). Si algún test asserta las acciones de la tabla de Averías (ojo/lápiz sueltos), actualízalo al nuevo patrón y explícalo en el PR.
5. `npm run build` sin errores y con el CSS del theme regenerado.

DOCUMENTACIÓN:
- Añade una entrada al Decisions Log al final de `DESIGN.md` (§11), fecha 19 jul 2026: patrón de tablas admin = fila clicable + overflow menu ⋮, sin columna de acciones sticky (revierte el enfoque de #67); densidad productive real 36–40px aplicada vía padding de celda.

Commit estilo del repo, p. ej.: `fix(ux): tablas con densidad Carbon real y acciones fila-clicable + kebab — se elimina la columna sticky`. Rama `fix/tablas-densidad-sin-sticky`, PR aparte contra main. En la descripción del PR: capturas antes/después (1280px, light y dark) y la medición de altura de fila.

END PROMPT
```
