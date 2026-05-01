# Bloque 08b — Hotfix slug AsignacionResource

> Mini-prompt de 5 minutos. Copia el bloque BEGIN PROMPT … END PROMPT en Copilot.

## Causa

Filament pluraliza modelos vía pluralizador inglés. `Asignacion` → `asignacions` (slug incorrecto). El menú del sidebar genera `/admin/asignacions` → 404. La URL correcta es `/admin/asignaciones`.

## Fix

Añadir `protected static ?string $slug = 'asignaciones';` al `AsignacionResource`. Override explícito del pluralizador.

## Pattern preventivo

Todos los Resources futuros con nombre en español deben declarar `$slug` y `$pluralModelLabel` explícitamente para evitar la regla de pluralización inglesa. Documentar en `.github/copilot-instructions.md`.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- docs/prompts/08b-fix-asignacion-slug.md (este archivo)
- app/Filament/Resources/AsignacionResource.php (resource con el slug roto)

Tu tarea: hotfix 1-línea + actualizar copilot-instructions con el pattern preventivo.

## FASE 0 — Branch

```bash
git status --short    # esperado: solo este prompt
git checkout -b bloque-08b-fix-asignacion-slug
```

## FASE 1 — Añadir slug explícito

Edita `app/Filament/Resources/AsignacionResource.php`. Localiza la zona de `protected static` properties (cerca de `$navigationSort`) y añade:

```php
    protected static ?string $slug = 'asignaciones';
```

Verifica con tinker:

```bash
php artisan tinker --execute='echo \App\Filament\Resources\AsignacionResource::getSlug() . PHP_EOL;'
```

Esperado: `asignaciones` (no `asignacions`).

## FASE 2 — Documentar pattern preventivo

Edita `.github/copilot-instructions.md`. Localiza la sección "Convenciones de código". Añade al final un nuevo bullet:

```markdown
- **Filament Resources con nombre en español**: declarar SIEMPRE `protected static ?string $slug` y `protected static ?string $pluralModelLabel` explícitos. Filament pluraliza por regla inglesa (`Asignacion` → `asignacions` ❌); sin override, las URLs salen incorrectas y el menú genera 404. Ver Bloque 08b. Ejemplo: `Asignacion` → `slug='asignaciones'`, `pluralModelLabel='asignaciones'`.
```

## FASE 3 — Test sanidad

```bash
./vendor/bin/pest tests/Feature/Filament/AsignacionResourceTest.php --colors=never --compact 2>&1 | tail -5
```

Tests del Bloque 08 deben seguir verde.

## FASE 4 — Commit + PR

Stage explícito:

```bash
git add docs/prompts/08b-fix-asignacion-slug.md
git add app/Filament/Resources/AsignacionResource.php
git add .github/copilot-instructions.md
git commit -m "fix(filament): set explicit slug=asignaciones (avoid English pluralizer bug)"
git push -u origin bloque-08b-fix-asignacion-slug

gh pr create --base main --head bloque-08b-fix-asignacion-slug \
  --title "Bloque 08b — Hotfix slug AsignacionResource (asignaciones)" \
  --body "Filament pluralizer (English-based) genera Asignacion → asignacions (incorrecto). Menú sidebar daba 404. Fix: \`\$slug = 'asignaciones'\` explícito + pattern preventivo en copilot-instructions.md para futuros Resources en español."
```

## REPORTE

```
✅ slug=asignaciones override.
✅ Pattern preventivo en copilot-instructions.md.
✅ Tests verdes.
✅ PR #N: [URL]. CI 3/3 verde.
```

NO mergees.

END PROMPT
```
