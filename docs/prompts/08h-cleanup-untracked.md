# Bloque 08h — Cleanup working tree (gitignore Filament assets + commit audit trails)

> Mini-prompt. Copia el bloque BEGIN PROMPT … END PROMPT en Copilot. ~10 min.

---

## Objetivo

Despejar working tree de los untracked acumulados durante la cadena Bloques 07e/08:

1. **Filament published assets** (`public/css/filament/`, `public/js/filament/`) — son output de `php artisan filament:upgrade`, regenerables; no deben vivir en git. Añadir a `.gitignore`.
2. **Audit trails del Bloque 07e bulk archive** (`docs/runbooks/legacy-cleanup/bus-archive-ids-*.txt`) — registros sha256-stamped del operativo prod de archivado de 91 filas-bus. Commitear como evidencia histórica.

## Definition of Done

1. `.gitignore` con 2 líneas nuevas para Filament assets.
2. 2 audit trails committeadas en `docs/runbooks/legacy-cleanup/`.
3. `git status` working tree clean tras el commit.
4. Tests siguen verde (no debería romper nada).
5. PR creado, CI 3/3 verde.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md
- docs/prompts/08h-cleanup-untracked.md (este archivo)
- .gitignore (a actualizar)

Tu tarea: cleanup working tree. Añadir Filament assets a .gitignore + committear audit trails del Bloque 07e.

## FASE 0 — Pre-flight + branch

```bash
git status --short
# Esperado ver:
# - public/css/filament/ y public/js/filament/ (untracked, a gitignorear)
# - docs/runbooks/legacy-cleanup/bus-archive-ids-*.txt (untracked, a commitear)
# - public/css/, public/js/ (parents — solo gitignorear el sub-path filament/)
# - este prompt (untracked, a commitear)

git checkout -b bloque-08h-cleanup-untracked
```

PARA: "Branch creada. ¿Procedo a Fase 1 (.gitignore)?"

## FASE 1 — Actualizar .gitignore

Lee `.gitignore`. Añade al final (o en la sección apropiada si tiene secciones como "Build artifacts"):

```
# Filament published assets (regenerados por `php artisan filament:upgrade`).
# No deben vivir en git — son output, no source.
/public/css/filament
/public/js/filament
```

Verifica que `git status --short` ya NO muestra los untracked de filament:

```bash
git status --short | grep -E "filament" || echo "OK — filament assets ignorados"
```

PARA: "Fase 1 completa: Filament assets gitignorados. ¿Procedo a Fase 2 (commitear audit trails)?"

## FASE 2 — Stage + commitear audit trails + prompt + .gitignore

Stage explícito:

```bash
git add .gitignore
git add docs/prompts/08h-cleanup-untracked.md
git add docs/runbooks/legacy-cleanup/bus-archive-ids-20260501-112708.txt
git add docs/runbooks/legacy-cleanup/bus-archive-ids-confirmed-only-20260501-113147.txt

git status --short
```

Esperado: solo los archivos staged (con `A` o `M`). NO debe quedar ningún `??` salvo posibles sub-dirs vacíos.

Commit:

```bash
git commit -m "chore: gitignore Filament published assets + commit Bloque 07e audit trails"
```

PARA: "Fase 2 completa: commit hecho. ¿Procedo a Fase 3 (push + PR)?"

## FASE 3 — Push + PR

```bash
git push -u origin bloque-08h-cleanup-untracked

gh pr create --base main --head bloque-08h-cleanup-untracked \
  --title "Bloque 08h — Cleanup: gitignore Filament assets + Bloque 07e audit trails" \
  --body "$(cat <<'BODY'
## Resumen

Cleanup mecánico del working tree tras la cadena Bloques 07e/08:

1. **Filament published assets** \`public/css/filament/\` y \`public/js/filament/\` añadidos al \`.gitignore\`. Regenerables vía \`php artisan filament:upgrade\`, no deben vivir en git.
2. **Audit trails Bloque 07e bulk archive** committeados:
   - \`bus-archive-ids-20260501-112708.txt\` (115 candidatos detectados por heurística).
   - \`bus-archive-ids-confirmed-only-20260501-113147.txt\` (91 buses confirmados sha256 \`08d6df71...\`, ejecutados en prod).
3. Prompt 08h del propio cleanup.

## Verificación

- \`git status --short\` working tree clean tras commit.
- Tests existentes siguen verde (cleanup no toca código de app).

## CI esperado

3/3 verde.
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-08h-cleanup-untracked --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ .gitignore con Filament assets ignorados.
✅ 2 audit trails committeadas como evidencia histórica del Bloque 07e.
✅ Working tree clean.
✅ PR #N. CI 3/3 verde.
```

NO mergees.

END PROMPT
```
