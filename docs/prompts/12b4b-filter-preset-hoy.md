# Bloque 12b.4b — Mini-PR ajuste UX filter preset "Hoy" en `LvRevisionPendienteResource`

## Contexto

Tras Bloque 12b.4 (PR #37) + smoke real prod (4-5 may), feedback UX del usuario:

> "No todos los paneles se revisan todos los días, esto tiene que estar sujeto a los
> planificados en el día."

La tabla actual muestra las 484 filas del mes. El admin instintivamente quiere ver
solo lo que requiere acción HOY. **Decisión cerrada con el usuario — opción A**:
filter preset por defecto activo que aísla las filas relevantes del día. Admin puede
quitarlo y ver las 484 si quiere.

**Out of scope**: distribución automática mensual (overkill — opción C rechazada),
calendario operacional drag-drop (Bloque 12b.5 separado — opción B no se adelanta).

## Decisión cerrada con el usuario antes del prompt

Mensaje literal del usuario:

> Decisión: opción A.
> Después del smoke, abrir mini-PR de ajuste UX:
> - Default filter/preset "Hoy".
> - Mostrar fecha_planificada = today.
> - Incluir carry overs prioritarios anteriores aún pendientes.
> - Admin puede quitar el filtro y ver las 484 pendientes del mes.
> - No implementar distribución automática mensual.
> - No adelantar calendario 12b.5.

## Lógica exacta del filter "Hoy" (cuando ACTIVO)

Filter activo (default true) muestra UNION de:

1. **Filas con `fecha_planificada == today`** (independientemente del status):
   - `requiere_visita` programadas para hoy, ya promocionadas o no.
   - Cualquier otro status que tenga fecha_planificada=today (caso edge improbable).

2. **Carry overs pendientes** (`carry_over_origen_id IS NOT NULL AND status = pendiente`):
   - Filas que arrastran del mes anterior y aún no se han decidido.
   - Tienen prioridad operativa: el admin debería resolverlas antes que el resto.

Filter desactivado: muestra todo (filtrado solo por el filter `mes` que ya existe — default mes actual).

## Restricciones inviolables

- **NO modificar schema**, ningún modelo, ningún service. Solo cambios en
  `LvRevisionPendienteResource.php` + tests.
- **NO tocar las 4 actions ni el bulk ni el header action**. Siguen funcionando igual.
- **NO romper los 21 tests del Resource ya en main** (Bloque 12b.4 PR #37). Suite
  actual 288 verde — debe terminar ≥291 verde tras este mini-PR (~3 tests nuevos).
- **PHP 8.2 floor**, sin paquetes nuevos.
- **CI 3/3 verde**.
- **Pint clean**.
- DESIGN.md Carbon: el filter sigue el patrón visual de los filtros existentes (no es
  pestaña ni navigation — es un Filter normal con `default()`).

## Plan de cambios

### Step 1 — Añadir filter `solo_hoy` en `LvRevisionPendienteResource::table()`

Editar `app/Filament/Resources/LvRevisionPendienteResource.php` solo en la sección
`->filters([...])`. **Posición sugerida**: ANTES del filter `mes` actual (que tiene
`->default()` con mes y año), porque visualmente "Solo hoy + carry" es la primera
opción que el admin debe ver/manipular.

Añadir el filter:

```php
Tables\Filters\Filter::make('solo_hoy')
    ->label('Solo hoy + carry overs')
    ->default()
    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
        $today = \Carbon\CarbonImmutable::now('Europe/Madrid')->toDateString();
        $q->whereDate('fecha_planificada', $today)
          ->orWhere(function (Builder $sub): void {
              $sub->whereNotNull('carry_over_origen_id')
                  ->where('status', LvRevisionPendiente::STATUS_PENDIENTE);
          });
    })),
```

**Notas**:
- `->default()` activa el filter automáticamente al cargar la página.
- El query usa `where(function ($q) {...})` para envolver el OR en paréntesis y NO
  romper la AND con el filter `mes` que ya existe (filtro por `periodo_year` y
  `periodo_month`).
- `today` en Europe/Madrid garantiza que el filter respete el huso horario (la app
  default es UTC).
- Al estar dentro de `where(fn $q ...)` envolvente, el SQL final genera:
  `WHERE periodo_year=2026 AND periodo_month=5 AND (DATE(fecha_planificada)='2026-05-05' OR (carry_over_origen_id IS NOT NULL AND status='pendiente'))`.
- Admin desactiva el filter desde el panel de filtros de Filament (el icon de filtro
  en el header de la tabla, o el toggle individual del filter).

**Importante**: el orden en el array `->filters([...])` SOLO afecta a la posición
visual en el panel de filtros. El SQL aplica todos los filtros activos con AND.

### Step 2 — Tests Pest

Añadir a `tests/Feature/Filament/LvRevisionPendienteResourceTest.php` (extender el
archivo existente del Bloque 12b.4):

```php
it('filter solo_hoy default activo muestra filas requiere_visita today + carry overs pendientes', function () {
    $now = CarbonImmutable::now('Europe/Madrid');

    // Setup: 4 filas en el mes actual con escenarios distintos.
    $pivToday = Piv::factory()->create();
    $pivFuture = Piv::factory()->create();
    $pivCarry = Piv::factory()->create();
    $pivOtra = Piv::factory()->create();

    // 1. requiereVisita today → DEBE aparecer.
    $rowToday = LvRevisionPendiente::factory()->for($pivToday, 'piv')->requiereVisita()->create([
        'periodo_year' => $now->year, 'periodo_month' => $now->month,
        'fecha_planificada' => $now->toDateString(),
    ]);

    // 2. requiereVisita futura → NO debe aparecer.
    $rowFuture = LvRevisionPendiente::factory()->for($pivFuture, 'piv')->requiereVisita()->create([
        'periodo_year' => $now->year, 'periodo_month' => $now->month,
        'fecha_planificada' => $now->addDays(3)->toDateString(),
    ]);

    // 3. pendiente con carry_over → DEBE aparecer.
    $previousMonth = $now->subMonth();
    $rowCarryOrigen = LvRevisionPendiente::factory()->for($pivCarry, 'piv')->pendiente()->create([
        'periodo_year' => $previousMonth->year, 'periodo_month' => $previousMonth->month,
    ]);
    $rowCarry = LvRevisionPendiente::factory()->for($pivCarry, 'piv')->pendiente()->create([
        'periodo_year' => $now->year, 'periodo_month' => $now->month,
        'carry_over_origen_id' => $rowCarryOrigen->id,
    ]);

    // 4. pendiente sin carry → NO debe aparecer.
    $rowOtra = LvRevisionPendiente::factory()->for($pivOtra, 'piv')->pendiente()->create([
        'periodo_year' => $now->year, 'periodo_month' => $now->month,
        'carry_over_origen_id' => null,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->assertCanSeeTableRecords([$rowToday, $rowCarry])
        ->assertCanNotSeeTableRecords([$rowFuture, $rowOtra]);
});

it('filter solo_hoy desactivado muestra todas las del mes', function () {
    $now = CarbonImmutable::now('Europe/Madrid');

    $pivA = Piv::factory()->create();
    $pivB = Piv::factory()->create();

    $rowA = LvRevisionPendiente::factory()->for($pivA, 'piv')->pendiente()->create([
        'periodo_year' => $now->year, 'periodo_month' => $now->month,
        'carry_over_origen_id' => null,
    ]);
    $rowB = LvRevisionPendiente::factory()->for($pivB, 'piv')->requiereVisita()->create([
        'periodo_year' => $now->year, 'periodo_month' => $now->month,
        'fecha_planificada' => $now->addDays(3)->toDateString(),
    ]);

    // Con filter solo_hoy desactivado, ambas deben verse.
    Livewire::test(ListLvRevisionPendientes::class)
        ->removeTableFilter('solo_hoy')
        ->assertCanSeeTableRecords([$rowA, $rowB]);
});

it('filter solo_hoy incluye carry over verificada_remoto solo si tiene fecha_planificada today', function () {
    // Edge case: carry over que admin ya decidió como verificada_remoto NO debe aparecer
    // (porque la condición carry_over requiere status=pendiente).
    $now = CarbonImmutable::now('Europe/Madrid');
    $piv = Piv::factory()->create();

    $rowOrigen = LvRevisionPendiente::factory()->for($piv, 'piv')->pendiente()->create([
        'periodo_year' => $now->subMonth()->year, 'periodo_month' => $now->subMonth()->month,
    ]);
    $rowVerificada = LvRevisionPendiente::factory()->for($piv, 'piv')->verificadaRemoto()->create([
        'periodo_year' => $now->year, 'periodo_month' => $now->month,
        'carry_over_origen_id' => $rowOrigen->id,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->assertCanNotSeeTableRecords([$rowVerificada]);
});
```

**Notas sobre los tests**:
- 3 tests nuevos son suficientes. Cubren: filter ON con escenarios mixtos, filter OFF
  ve todo, edge case carry over ya decidido.
- Usar `removeTableFilter('solo_hoy')` para simular admin desactivándolo.
- Si Filament 3 no expone `removeTableFilter`, usar `filterTable('solo_hoy', null)`
  o equivalente. Adaptar al API real verificando contra los tests existentes
  (`'mes'` filter ya está en el Resource).

### Step 3 — Smoke local

```bash
php artisan test tests/Feature/Filament/LvRevisionPendienteResourceTest.php
./vendor/bin/pint --test app/Filament/Resources/LvRevisionPendienteResource.php tests/Feature/Filament/LvRevisionPendienteResourceTest.php
```

Esperado: tests verde + Pint clean.

**NO ejecutar** `php artisan migrate` ni `php artisan tinker` que escriba a tablas
reales — `.env` LOCAL apunta a SiteGround prod (lección Bloque 12b.3).

## DoD

- [ ] Filter `solo_hoy` añadido en `LvRevisionPendienteResource::table()->filters([...])` con `->default()`, label "Solo hoy + carry overs", query con OR de `fecha_planificada=today` o `(carry_over_origen_id NOT NULL AND status=pendiente)`, todo en `where(fn $q ...)` envolvente.
- [ ] Carbon timezone Europe/Madrid usado para resolver "today".
- [ ] 3 tests Pest nuevos: filter ON shows only relevant, filter OFF shows all, edge case carry over decidido no aparece.
- [ ] Suite total 288 → ≥291 verde.
- [ ] CI 3/3 verde.
- [ ] Pint clean.
- [ ] Smoke local: tests específicos verde + Pint clean.

## Smoke real obligatorio post-merge (~10 min)

Sesión dedicada tras merge:

1. Login admin `info@winfin.es` en `http://127.0.0.1:8000/admin/login`.
2. Sidebar "Planificación" → "Decisiones del día".
3. **Esperado**: tabla muestra **0 filas inicialmente** (las 484 son pendientes sin carry over y sin fecha_planificada). Filter "Solo hoy + carry overs" badge debe estar visible y activo.
4. Verificar que el panel de filtros muestra "Solo hoy + carry overs" como filter aplicado por default.
5. Click en el icon de filtros → desactivar `solo_hoy` → tabla muestra las 484 filas. Re-activar → vuelve a 0.
6. Con filter activo: action `Requiere visita` en una fila pendiente con fecha=today.
   - Pero antes de la action no la vemos porque el filter la oculta. Para hacer la action: desactivar filter momentáneamente, marcar requiereVisita today, re-activar filter, verificar que ahora la fila SÍ aparece (1 fila).
7. Action `Excepción` en otra fila (la que sea pendiente, requiere desactivar filter primero) con notas obligatorias. Re-activar filter → la excepcion NO aparece (no satisface la lógica). ✓
8. Action `Revertir` en la fila excepción → vuelve a pendiente. ✓
9. Cleanup: revertir la fila requiereVisita today (si no se promocionó vía `Promover ahora`, action Revertir está disponible). Si se promocionó → cleanup manual SQL como en smoke 12b.4.
10. Estado final: 484 pendientes mayo intactas.

## Riesgos y decisiones diferidas

1. **Filter API exact**: Filament 3 evoluciona. Si `->query(fn (Builder $q) ...)` con
   nesting funciona como en el código existente (filter `mes`), igual aplica aquí.
   Si Copilot detecta diferencia por versión, ajustar.
2. **Carry over decidido**: la lógica `carry_over_origen_id NOT NULL AND status=pendiente`
   excluye carry overs que ya admin decidió (verificada_remoto, etc.). Decisión correcta:
   esos ya no requieren acción. Test cubre.
3. **Admin con UI distinta tras merge**: si admin estaba acostumbrado a ver las 484
   directamente, se sorprenderá al ver tabla "vacía". Es feature, no bug — el badge
   nav sigue mostrando 484 y el panel de filtros muestra claramente "Solo hoy + carry
   overs" activo. Si esto genera fricción, mini-PR posterior añade ayuda visual
   ("Tabla filtrada — desactiva 'Solo hoy + carry overs' para ver todas").
4. **Filtro de mes default**: sigue activo (default mes actual). La intersección con
   `solo_hoy` significa: "Solo del mes actual, hoy o carry over". Coherente.

## REPORTE FINAL (formato esperado)

```
## Bloque 12b.4b — REPORTE FINAL

### Estado
- Branch: bloque-12b4b-filter-preset-hoy
- Commits: N
- Tests: 288 → 291+ verde (3 nuevos).
- CI: 3/3 verde sobre HEAD <hash>
- Pint: clean
- Smoke local: tests específicos verde.

### Decisiones aplicadas
- Filter solo_hoy con default ON.
- Lógica: today OR (carry over AND pendiente).
- Sin tocar otras partes del Resource.

### Pivots respecto al prompt
- (si los hubo, listar y justificar)
```

---

## Aplicación checklist obligatoria

| Sección | Aplicado | Cómo |
|---|---|---|
| 1. Compatibilidad framework | ✓ | Filter custom Filament 3 con `->default()->query(...)`, igual patrón que el filter `mes` ya en el Resource. Cero quirks nuevos. |
| 2. Inferir de app vieja | N/A | Feature nueva 100%. |
| 3. Smoke real obligatorio | ✓ | Smoke real post-merge documentado paso a paso (~10 min). Aprovecha para validar visualmente Excepción + Revertir saltadas en fase C del smoke 12b.4. |
| 4. Test pivots = banderazo rojo | ✓ | Tests usan `Livewire::test()->assertCanSeeTableRecords()` y `removeTableFilter()` reales, no mocks. Si Copilot pivota a tests del modelo, banderazo. |
| 5. Datos prod-shaped | ✓ | Tests cubren 4 escenarios (today, futura, carry pendiente, no-carry pendiente, carry decidido). Coincide con la diversidad real prod. |
