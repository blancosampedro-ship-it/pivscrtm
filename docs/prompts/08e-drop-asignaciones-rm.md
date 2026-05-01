# Bloque 08e — Drop AsignacionesRelationManager (HasManyThrough incompatible) + enrich AveriasRM

> Copia el bloque BEGIN PROMPT … END PROMPT en Copilot. ~25-35 min.

---

## Causa

Smoke real `/admin/pivs/4` (View page con tabs) → crash al cargar tab Asignaciones:

```
TypeError: Cannot use "::class" on null
vendor/filament/tables/src/Table/Concerns/HasRecords.php:76
```

**Root cause**: Filament 3 RelationManager **NO soporta** `HasManyThrough`. La doc oficial (https://filamentphp.com/docs/3.x/panels/resources/relation-managers) solo lista HasMany, HasOne, BelongsToMany, MorphMany, MorphOne, MorphToMany. Bloque 08d basó AsignacionesRelationManager en `Piv::asignaciones() HasManyThrough` — incompatible. Filament tries `$relationship?->getRelated()::class` y devuelve null → crash.

CI no lo cazó porque los tests de RelationManager se reescribieron (Bloque 08d Fase 6) por flakiness Livewire 3 + Filament 3 a verificación Eloquent-only. La carga real de la tabla nunca se ejerció en CI.

## Decisión

**Drop AsignacionesRelationManager**. La info clave (tipo correctivo/revisión) ya está visible en `AveriasRelationManager` vía columna "Tipo" con badge cromático que lee `$record->asignacion?->tipo`. Lo perdido (horario, status independiente, group-by) es marginal:

- **Horario** → añadir columna nueva al AveriasRelationManager.
- **Status asignación** → añadir columna toggleable.
- **Group-by tipo** → no aplica al panel-level (cada avería ya tiene su tipo en columna).

**Mantener `Piv::asignaciones()` HasManyThrough** en el modelo: sigue siendo útil para queries programáticas (`$piv->asignaciones()->count()`, exportes Bloque 10, KPIs dashboard). No solo lo usa Filament.

**Pattern preventivo** en `.github/copilot-instructions.md`: documentar que RelationManagers requieren HasMany/BelongsToMany/MorphMany, no HasManyThrough.

## Definition of Done

1. `PivResource::getRelations()` solo devuelve `[AveriasRelationManager::class]`.
2. AsignacionesRelationManager.php DROPPED (archivo borrado — no merece coexistir disabled).
3. `AveriasRelationManager` enriquecido con 2 columnas nuevas:
   - "Horario" toggleable: `getStateUsing(fn ($record) => ... $record->asignacion?->hora_inicial ...)`.
   - "Status asig." toggleable.
4. ADR (o entry log DESIGN.md §11) documenta la limitación + el workaround.
5. `.github/copilot-instructions.md` con pattern preventivo: "Filament RelationManager NO soporta HasManyThrough — usar HasMany / BelongsToMany / MorphMany".
6. Tests:
   - Update test `piv_view_page_renders_with_relation_manager_tabs` — sigue verde con un solo tab.
   - DROP test `asignaciones_relation_manager_shows_only_this_pivs_asignaciones_via_averias`.
   - DROP test sidebar visibility relacionado a Asignacion (ya cubierto).
   - KEEP `piv_has_asignaciones_through_averias_relation` — la HasManyThrough sigue funcionando a nivel modelo.
7. Smoke real post-merge: `/admin/pivs/4` carga sin crash, ve tab "Histórico de averías" con info de tipo asignación + horario por fila.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md
- docs/prompts/08e-drop-asignaciones-rm.md (este archivo)
- app/Filament/Resources/PivResource.php (getRelations actual)
- app/Filament/Resources/PivResource/RelationManagers/AveriasRelationManager.php (a enriquecer)
- app/Filament/Resources/PivResource/RelationManagers/AsignacionesRelationManager.php (a borrar)
- DESIGN.md §10.4 (a actualizar)

Tu tarea: drop AsignacionesRelationManager + enriquecer AveriasRelationManager con horario y status asignación.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Branch

```bash
git status --short          # esperado: solo este prompt
git checkout -b bloque-08e-drop-asignaciones-rm
```

PARA: "Branch creada. ¿Procedo a Fase 1 (drop AsignacionesRelationManager + getRelations)?"

## FASE 1 — Drop AsignacionesRelationManager

```bash
rm app/Filament/Resources/PivResource/RelationManagers/AsignacionesRelationManager.php
```

Edita `app/Filament/Resources/PivResource.php`. Localiza `getRelations()` y reduce el array:

```php
public static function getRelations(): array
{
    return [
        \App\Filament\Resources\PivResource\RelationManagers\AveriasRelationManager::class,
        // AsignacionesRelationManager dropped: HasManyThrough no soportado por Filament 3
        // RelationManager (issue conocido docs oficial). Info de asignación accesible via
        // columnas tipo/horario/status en AveriasRelationManager. Ver Bloque 08e.
    ];
}
```

PARA: "Fase 1 completa: AsignacionesRelationManager dropped + getRelations limpiado. ¿Procedo a Fase 2 (enriquecer AveriasRM)?"

## FASE 2 — Enriquecer AveriasRelationManager con horario + status asig

Lee `app/Filament/Resources/PivResource/RelationManagers/AveriasRelationManager.php`. Localiza el `->columns([])` del método `table()`. Después de la columna "Tipo" (la que tiene `getStateUsing` con `asignacion?->tipo`), añade:

```php
                Tables\Columns\TextColumn::make('asignacion_horario')
                    ->label('Horario')
                    ->getStateUsing(fn (Averia $record) => $record->asignacion?->hora_inicial && $record->asignacion?->hora_final
                        ? sprintf('%02d–%02d h', $record->asignacion->hora_inicial, $record->asignacion->hora_final)
                        : '—')
                    ->extraAttributes(['data-mono' => true])
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('asignacion_status')
                    ->label('Status asig.')
                    ->getStateUsing(fn (Averia $record) => $record->asignacion?->status ?? '—')
                    ->badge()
                    ->extraAttributes(['data-mono' => true])
                    ->toggleable(isToggledHiddenByDefault: true),
```

`toggleable(isToggledHiddenByDefault: true)` mantiene la tabla limpia por defecto pero permite al admin habilitarlas con un click cuando quiera ver más detalle. Mantiene la densidad del Bloque 07d sin sobrecargar.

Asegúrate de que `Averia` está en `use` block del top.

PARA: "Fase 2 completa: AveriasRM con 2 columnas opcionales nuevas. ¿Procedo a Fase 3 (DESIGN.md + copilot-instructions)?"

## FASE 3 — DESIGN.md §10.4 + copilot-instructions pattern preventivo

### 3a — DESIGN.md §10.4

Lee `DESIGN.md`. Localiza `### 10.4 Parent-child IA: averías y asignaciones se consultan desde el panel`. Reemplaza el contenido de la sección por:

```markdown
### 10.4 Parent-child IA: averías se consultan desde el panel

Las averías NO viven como entries top-level del menú primario. Pertenecen al panel afectado y se consultan vía RelationManager tab en la View page del panel:

- `/admin/pivs` → click panel → View page con tabs:
  1. **Detalles** — infolist con foto + 5 secciones (Bloque 07d).
  2. **Histórico de averías** — RelationManager tabla densa filtrada al panel. Columna "Tipo" muestra el tipo de asignación asociada (Correctivo/Revisión/Sin asignar) con badge cromático regla #11. Columnas "Horario" y "Status asig." disponibles via toggle.

Justificación: cada avería pertenece a un panel — la trazabilidad operativa exige verlas en contexto del panel, no en abstracto. Reportes cross-panel viven en Bloque 10 Dashboard.

**Asignaciones**: NO tienen tab propio porque `Piv::asignaciones()` es `HasManyThrough` (asignacion vía averia.piv_id) y Filament 3 RelationManager no soporta HasManyThrough. La info clave de la asignación (tipo, horario, status) se expone como columnas dentro del tab Averías. Si en el futuro se requiere vista enfocada de asignaciones, reincorporar como página standalone (no RelationManager).

Implementación: Filament 3 RelationManagers (`PivResource::getRelations()`). AveriaResource y AsignacionResource quedan accesibles por URL pero sin entrada en sidebar (`shouldRegisterNavigation = false`). Bloque 10 reincorporará una entrada "Reportes" para vistas agregadas.

Inspiración: la app vieja `winfin.es/paneles.php?action=edit&id=N#tabs-3` ya usaba tabs en la edit del panel — el patrón parent-child está validado por años de uso operativo.
```

### 3b — Entry log §11

Añade entrada nueva en §11 Decisions Log:

```markdown
| 2026-05-02 | **AsignacionesRelationManager dropped**. Filament 3 RelationManager no soporta `HasManyThrough` (limitación documentada). Info de asignación se mantiene visible vía columnas tipo/horario/status en AveriasRelationManager. `Piv::asignaciones()` HasManyThrough sigue para queries programáticas y Bloque 10 reportes. | Bloque 08d intentó implementarlo, smoke real reveló crash "Cannot use ::class on null". Bloque 08e drop + enriquece AveriasRM. Si futuro Filament añade soporte HasManyThrough o se necesita vista enfocada, reincorporar como página standalone. |
```

### 3c — copilot-instructions pattern preventivo

Edita `.github/copilot-instructions.md`. Localiza la sección "Convenciones de código" (donde están los patterns Bloque 08b/08c). Añade DESPUÉS:

```markdown
- **Filament RelationManager + HasManyThrough**: Filament 3 RelationManager NO soporta `HasManyThrough`. Soporta SOLO `HasMany`, `HasOne`, `BelongsToMany`, `MorphMany`, `MorphOne`, `MorphToMany`. Si la relación parent-child requiere atravesar tablas (`Piv → Averia → Asignacion`), no usar HasManyThrough como base de RelationManager — exponer la info derivada como columnas en el RM directo (HasMany), o crear página standalone si la vista enfocada vale el esfuerzo. Síntoma del bug: `TypeError: Cannot use ::class on null` en `vendor/filament/tables/src/Table/Concerns/HasRecords.php:76`. Ver Bloque 08e.
```

PARA: "Fase 3 completa: DESIGN.md + copilot-instructions actualizados. ¿Procedo a Fase 4 (tests)?"

## FASE 4 — Update tests

Lee `tests/Feature/Filament/PivRelationManagersTest.php`. Cambios:

1. **DROP** el test `asignaciones_relation_manager_shows_only_this_pivs_asignaciones_via_averias` (entero).
2. **DROP** el test `asignacion_resource_not_in_admin_sidebar_navigation` si existe en este archivo (debería estar OK porque AsignacionResource sigue con shouldRegisterNavigation=false — verificar).
3. **KEEP** los tests:
   - `piv_view_page_renders_with_relation_manager_tabs` (verifica que View page renderiza con UN tab Averías ahora, no dos).
   - `averias_relation_manager_shows_only_this_pivs_averias`.
   - `averia_resource_not_in_admin_sidebar_navigation`.
   - `averia_resource_url_still_accessible_directly`.
   - `piv_has_asignaciones_through_averias_relation` (la HasManyThrough sigue funcionando a nivel modelo — no se borró del Piv).

Verifica con:

```bash
./vendor/bin/pest tests/Feature/Filament/PivRelationManagersTest.php --colors=never --compact 2>&1 | tail -10
```

5-6 tests verdes esperados (lo que quede tras drop).

Suite total:

```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
```

PARA: "Fase 4 completa: tests reduced + suite verde. ¿Procedo a Fase 5 (commits + PR)?"

## FASE 5 — Pint + commits + PR

```bash
./vendor/bin/pint --test 2>&1 | tail -3
npm run build 2>&1 | tail -3
```

Stage explícito:

1. `docs: add Bloque 08e prompt + DESIGN.md §10.4 update + copilot-instructions HasManyThrough warning` — `docs/prompts/08e-drop-asignaciones-rm.md` + `DESIGN.md` + `.github/copilot-instructions.md`.
2. `fix(filament): drop AsignacionesRelationManager (HasManyThrough not supported)` — borrado del archivo `AsignacionesRelationManager.php` + edit `PivResource.php` (getRelations array reducido).
3. `feat(filament): enrich AveriasRelationManager with horario + status asig columns` — `AveriasRelationManager.php`.
4. `test: drop AsignacionesRM tests + verify single-tab ViewPiv renders` — `tests/Feature/Filament/PivRelationManagersTest.php`.

Push + PR:

```bash
git push -u origin bloque-08e-drop-asignaciones-rm
gh pr create --base main --head bloque-08e-drop-asignaciones-rm \
  --title "Bloque 08e — Drop AsignacionesRelationManager (HasManyThrough no soportado)" \
  --body "$(cat <<'BODY'
## Resumen

Smoke real /admin/pivs/4 revealed crash: \`TypeError: Cannot use "::class" on null\` al cargar tab Asignaciones. Filament 3 RelationManager NO soporta HasManyThrough (limitación documentada oficial: https://filamentphp.com/docs/3.x/panels/resources/relation-managers).

Drop AsignacionesRelationManager. Info de asignación (tipo, horario, status) se expone como columnas en AveriasRelationManager — la columna "Tipo" ya estaba; "Horario" y "Status asig." se añaden como toggleable hidden por defecto.

\`Piv::asignaciones()\` HasManyThrough se mantiene en el modelo — útil para queries programáticas y Bloque 10 dashboard reportes.

## Cambios

- DROP \`AsignacionesRelationManager.php\` + entrada en \`PivResource::getRelations()\`.
- ENRICH \`AveriasRelationManager\` con 2 columnas nuevas (toggleable):
  - "Horario" — sprintf('%02d–%02d h', hora_inicial, hora_final).
  - "Status asig." — badge con asignacion.status.
- DESIGN.md §10.4 actualizada explicando por qué solo hay tab "Histórico de averías" (no Asignaciones).
- DESIGN.md §11 entry log 2026-05-02 documentando la decisión.
- copilot-instructions.md pattern preventivo: HasManyThrough no soportado por RelationManager.
- Drop test asignaciones_relation_manager_shows_only_this_pivs_asignaciones_via_averias.

## CI esperado

3/3 verde.
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-08e-drop-asignaciones-rm --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ AsignacionesRelationManager dropped.
✅ AveriasRelationManager con horario + status asig (toggleable).
✅ DESIGN.md §10.4 + entry log 2026-05-02.
✅ copilot-instructions pattern preventivo HasManyThrough.
✅ Tests reduced. Suite verde.
✅ Pint + build OK.
✅ PR #N. CI 3/3 verde.
```

NO mergees.

END PROMPT
```
