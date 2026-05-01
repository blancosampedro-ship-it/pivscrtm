# Bloque 08c — Fix Infolist crashes con relaciones null

> Mini-prompt. Copia el bloque BEGIN PROMPT … END PROMPT en Copilot. ~25-30 min.

---

## Causa

Smoke real `/admin/averias` reveló crash al abrir slideOver de avería sin `asignacion` asociada (~70 averías de 66.485 no tienen asignación):

```
Exception: Infolist has no [record()] or [state()] set.
PHP 8.4.8 / Laravel 12.58 / Filament 3
```

**Root cause**: Filament 3 `TextEntry::make('relation.field')->badge()` falla cuando `relation` es null. El dot-notation devuelve null como state, y el rendering de badge no aplica el `placeholder()` antes de exigir state válido. El error se propaga aunque el resto del infolist esté OK.

**Tests no lo cazaron** porque las factories siempre crean asignaciones para sus averías. En prod hay 69 averías huérfanas que el test no simula.

## Fix

Reemplazar **todas** las dot-notation `TextEntry::make('rel.field')` con relación que pueda ser null por **state callback explícito** que null-coalesce:

```php
// Antes (frágil):
Infolists\Components\TextEntry::make('asignacion.tipo')
    ->badge()
    ->formatStateUsing(fn ($state) => match ((int) $state) { 1 => 'Correctivo', 2 => 'Revisión', default => '—' })
    ->color(fn ($state) => match ((int) $state) { 1 => 'danger', 2 => 'success', default => 'gray' }),

// Después (defensive):
Infolists\Components\TextEntry::make('asignacion_tipo_label')  // <- nombre cambiado para evitar auto-resolve por dot-notation
    ->label('Tipo')
    ->badge()
    ->state(fn ($record) => match ((int) ($record->asignacion?->tipo ?? 0)) {
        1 => 'Correctivo',
        2 => 'Revisión rutinaria',
        0 => 'Sin asignar',
        default => 'Indefinido',
    })
    ->color(fn ($record) => match ((int) ($record->asignacion?->tipo ?? 0)) {
        1 => 'danger',
        2 => 'success',
        default => 'gray',
    }),
```

**Pattern preventivo**: documentar en `.github/copilot-instructions.md` que `TextEntry` con relación potencialmente null debe usar `->state(closure)` en lugar de dot-notation.

## Definition of Done

1. **AveriaResource infolist**: `asignacion.tipo`, `tecnico.nombre_completo`, `operador.razon_social`, `piv.parada_cod`, `piv.direccion`, `piv.municipioModulo.nombre`, `piv.operadorPrincipal.razon_social`, `notas` — todos con `->state(closure)` defensivo o verificación que sus relaciones nunca son null.
2. **AsignacionResource infolist**: similar tratamiento para `averia.*`, `averia.piv.*`, `averia.piv.municipioModulo.nombre`, `tecnico.nombre_completo`, `correctivo.estado_final`, `revision.id`.
3. **Test nuevo** que cubre el caso real:
   - `averia_view_action_renders_when_asignacion_is_null`
   - `asignacion_view_action_renders_when_correctivo_is_null` (revisión rutinaria sin correctivo)
4. Pattern preventivo en `.github/copilot-instructions.md`.
5. CI 3/3 verde.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- docs/prompts/08c-fix-infolist-null-relations.md (este archivo, sección Causa + Fix + DoD)
- app/Filament/Resources/AveriaResource.php (infolist actual con dot-notation)
- app/Filament/Resources/AsignacionResource.php (infolist actual con dot-notation)

Tu tarea: hacer defensivos los infolists de Averia y Asignacion contra relaciones null. Pattern preventivo en copilot-instructions.

## FASE 0 — Branch

```bash
git status --short          # esperado: solo este prompt
git checkout -b bloque-08c-fix-infolist-null-relations
```

PARA: "Branch creada. ¿Procedo a Fase 1 (AveriaResource defensive infolist)?"

## FASE 1 — AveriaResource: infolist defensive

Lee `app/Filament/Resources/AveriaResource.php`. Localiza el método `infolist()`. Reemplaza el cuerpo entero (manteniendo la firma):

```php
public static function infolist(Infolist $infolist): Infolist
{
    return $infolist->schema([
        Infolists\Components\Section::make('Avería')
            ->columns(2)
            ->schema([
                Infolists\Components\TextEntry::make('averia_id')
                    ->label('ID')
                    ->extraAttributes(['data-mono' => true]),
                Infolists\Components\TextEntry::make('fecha')
                    ->dateTime('d M Y · H:i')
                    ->extraAttributes(['data-mono' => true])
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('status')
                    ->badge()
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('asignacion_tipo_label')
                    ->label('Tipo de asignación')
                    ->badge()
                    ->state(fn ($record) => match ((int) ($record->asignacion?->tipo ?? 0)) {
                        1 => 'Correctivo',
                        2 => 'Revisión rutinaria',
                        default => 'Sin asignación',
                    })
                    ->color(fn ($record) => match ((int) ($record->asignacion?->tipo ?? 0)) {
                        1 => 'danger',
                        2 => 'success',
                        default => 'gray',
                    }),
            ]),

        Infolists\Components\Section::make('Panel afectado')
            ->columns(2)
            ->schema([
                Infolists\Components\TextEntry::make('piv_parada')
                    ->label('Parada')
                    ->extraAttributes(['data-mono' => true])
                    ->state(fn ($record) => $record->piv ? mb_strtoupper(trim((string) $record->piv->parada_cod)) : null)
                    ->placeholder('— Sin panel asociado —'),
                Infolists\Components\TextEntry::make('piv_direccion')
                    ->label('Dirección')
                    ->state(fn ($record) => $record->piv?->direccion)
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('piv_municipio')
                    ->label('Municipio')
                    ->state(fn ($record) => $record->piv?->municipioModulo?->nombre)
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('piv_operador_panel')
                    ->label('Operador del panel')
                    ->state(fn ($record) => $record->piv?->operadorPrincipal?->razon_social)
                    ->placeholder('—'),
            ]),

        Infolists\Components\Section::make('Participantes')
            ->columns(2)
            ->schema([
                Infolists\Components\TextEntry::make('operador_reporta')
                    ->label('Operador reporta')
                    ->state(fn ($record) => $record->operador?->razon_social)
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('tecnico_asignado')
                    ->label('Técnico asignado')
                    ->state(fn ($record) => $record->tecnico?->nombre_completo)
                    ->placeholder('—'),
            ]),

        Infolists\Components\Section::make('Notas')
            ->schema([
                Infolists\Components\TextEntry::make('notas')
                    ->hiddenLabel()
                    ->placeholder('— Sin notas —')
                    ->columnSpanFull(),
            ]),
    ]);
}
```

PARA: "Fase 1 completa: AveriaResource infolist defensivo. ¿Procedo a Fase 2 (AsignacionResource)?"

## FASE 2 — AsignacionResource: infolist defensive

Lee `app/Filament/Resources/AsignacionResource.php`. Reemplaza el método `infolist()`:

```php
public static function infolist(Infolist $infolist): Infolist
{
    return $infolist->schema([
        Infolists\Components\Section::make('Asignación')
            ->columns(3)
            ->schema([
                Infolists\Components\TextEntry::make('asignacion_id')
                    ->label('ID')
                    ->extraAttributes(['data-mono' => true]),
                Infolists\Components\TextEntry::make('fecha')
                    ->date('d M Y')
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('tipo_label')
                    ->label('Tipo')
                    ->badge()
                    ->state(fn ($record) => match ((int) $record->tipo) {
                        1 => 'Correctivo',
                        2 => 'Revisión rutinaria',
                        default => 'Indefinido',
                    })
                    ->color(fn ($record) => match ((int) $record->tipo) {
                        1 => 'danger',
                        2 => 'success',
                        default => 'gray',
                    }),
                Infolists\Components\TextEntry::make('horario')
                    ->label('Horario')
                    ->state(fn ($record) => $record->hora_inicial && $record->hora_final
                        ? sprintf('%02d–%02d h', $record->hora_inicial, $record->hora_final)
                        : null)
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('tecnico_nombre')
                    ->label('Técnico')
                    ->state(fn ($record) => $record->tecnico?->nombre_completo)
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('status')
                    ->badge()
                    ->placeholder('—'),
            ]),

        Infolists\Components\Section::make('Avería origen')
            ->schema([
                Infolists\Components\TextEntry::make('averia_id')
                    ->label('Avería')
                    ->state(fn ($record) => $record->averia?->averia_id ? '#'.$record->averia->averia_id : null)
                    ->extraAttributes(['data-mono' => true])
                    ->placeholder('— Sin avería —'),
                Infolists\Components\TextEntry::make('averia_fecha')
                    ->label('Fecha avería')
                    ->state(fn ($record) => $record->averia?->fecha)
                    ->dateTime('d M Y · H:i')
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('averia_notas')
                    ->label('Notas avería')
                    ->state(fn ($record) => $record->averia?->notas)
                    ->columnSpanFull()
                    ->placeholder('—'),
            ]),

        Infolists\Components\Section::make('Panel afectado')
            ->columns(2)
            ->schema([
                Infolists\Components\TextEntry::make('piv_parada')
                    ->label('Parada')
                    ->state(fn ($record) => $record->averia?->piv ? mb_strtoupper(trim((string) $record->averia->piv->parada_cod)) : null)
                    ->extraAttributes(['data-mono' => true])
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('piv_municipio')
                    ->label('Municipio')
                    ->state(fn ($record) => $record->averia?->piv?->municipioModulo?->nombre)
                    ->placeholder('—'),
            ]),

        Infolists\Components\Section::make('Cierre')
            ->description('Form de cierre llegará en Bloque 09 — aquí solo readonly de lo existente')
            ->schema([
                Infolists\Components\TextEntry::make('correctivo_estado')
                    ->label('Estado final correctivo')
                    ->state(fn ($record) => $record->correctivo?->estado_final)
                    ->placeholder('— Sin cerrar —'),
                Infolists\Components\TextEntry::make('revision_cerrada')
                    ->label('Revisión cerrada')
                    ->state(fn ($record) => $record->revision !== null ? 'Sí (id #'.$record->revision->revision_id.')' : 'No')
                    ->placeholder('No'),
            ]),
    ]);
}
```

PARA: "Fase 2 completa: AsignacionResource infolist defensivo. ¿Procedo a Fase 3 (tests + pattern doc)?"

## FASE 3 — Tests + pattern preventivo

### 3a — Tests nuevos

Añade a `tests/Feature/Filament/AveriaResourceTest.php`:

```php
it('averia_view_action_renders_when_asignacion_is_null', function () {
    $piv = Piv::factory()->create(['piv_id' => 99500]);
    $averia = Averia::factory()->create([
        'averia_id' => 99500,
        'piv_id' => 99500,
        'tecnico_id' => null,    // sin técnico
    ]);
    // Importante: NO creamos asignación.

    Livewire::test(\App\Filament\Resources\AveriaResource\Pages\ListAverias::class)
        ->callTableAction('view', $averia->averia_id)
        ->assertSuccessful();
});
```

Añade a `tests/Feature/Filament/AsignacionResourceTest.php`:

```php
it('asignacion_view_action_renders_when_cierre_is_null', function () {
    $piv = Piv::factory()->create(['piv_id' => 99600]);
    $av = Averia::factory()->create(['averia_id' => 99600, 'piv_id' => 99600]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 99600,
        'averia_id' => 99600,
        'tipo' => 2,    // revisión sin correctivo asociado
        'tecnico_id' => null,
    ]);

    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->callTableAction('view', $asig->asignacion_id)
        ->assertSuccessful();
});
```

### 3b — Pattern preventivo

Edita `.github/copilot-instructions.md`. Localiza la sección "Convenciones de código" (donde añadiste el slug pattern en Bloque 08b). Añade DESPUÉS:

```markdown
- **Filament Infolist con relaciones nullable**: NUNCA usar dot-notation (`TextEntry::make('relation.field')`) cuando `relation` puede ser null en algún registro de prod. Filament 3 falla con "Infolist has no record() or state() set" cuando `badge()` recibe state null vía dot-notation, y `placeholder()` no rescata el rendering. Usar SIEMPRE state callback explícito: `TextEntry::make('field_label')->state(fn ($record) => $record->relation?->field)->placeholder('—')`. Aplica también a `getStateUsing()`. Ver Bloque 08c (caso real: avería sin asignación → infolist crash).
```

### 3c — Test rapidez

```bash
./vendor/bin/pest tests/Feature/Filament/ --colors=never --compact 2>&1 | tail -10
```

Suite Filament total esperada: 14+ tests verdes (12 previos + 2 nuevos).

PARA: "Fase 3 completa: tests + pattern. ¿Procedo a Fase 4 (commits + PR)?"

## FASE 4 — Commits + PR

```bash
./vendor/bin/pint --test 2>&1 | tail -3
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
npm run build 2>&1 | tail -3
```

Stage explícito:

```bash
git add docs/prompts/08c-fix-infolist-null-relations.md
git add app/Filament/Resources/AveriaResource.php
git add app/Filament/Resources/AsignacionResource.php
git add tests/Feature/Filament/AveriaResourceTest.php
git add tests/Feature/Filament/AsignacionResourceTest.php
git add .github/copilot-instructions.md
git commit -m "fix(filament): defensive infolist for nullable relations (Bloque 08c)"
git push -u origin bloque-08c-fix-infolist-null-relations

gh pr create --base main --head bloque-08c-fix-infolist-null-relations \
  --title "Bloque 08c — Defensive infolist para relaciones nullable" \
  --body "Smoke real reveló crash en /admin/averias al abrir slideOver de averías sin asignación (~70 de 66485). Filament 3 \`TextEntry::make('rel.field')->badge()\` falla con state null vía dot-notation. Fix: state callbacks explícitos con null-coalescing operator. Pattern preventivo añadido a copilot-instructions.md. 2 tests nuevos cubren el caso (averia sin asignación + asignación sin cierre)."

sleep 8
PR_NUM=$(gh pr list --head bloque-08c-fix-infolist-null-relations --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ AveriaResource + AsignacionResource infolists con state() callback defensive.
✅ 2 tests nuevos (avería sin asignación + asignación sin cierre).
✅ Pattern preventivo en copilot-instructions.md.
✅ Suite verde. PR #N. CI 3/3.
```

NO mergees.

END PROMPT
```
