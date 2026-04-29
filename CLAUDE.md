# CLAUDE.md — Instrucciones para Claude Code en Winfin PIV

> Estas instrucciones son para **Claude Code** (este asistente, en esta carpeta). Para
> instrucciones de **VS Code Copilot** ver [.github/copilot-instructions.md](.github/copilot-instructions.md).

---

## Contexto del proyecto

Reescritura moderna del CMMS Winfin PIV. Documentación de referencia:

- [ARCHITECTURE.md](ARCHITECTURE.md) — visión técnica completa, modelo de dominio, flujos.
- [DESIGN.md](DESIGN.md) — sistema visual (única fuente de verdad para UI).
- [docs/decisions/](docs/decisions/) — ADRs 0001-0004.
- [docs/security.md](docs/security.md) — política RGPD + secretos + incidentes.
- [docs/prompts/00-roadmap.md](docs/prompts/00-roadmap.md) — roadmap de bloques para Copilot.
- [.github/copilot-instructions.md](.github/copilot-instructions.md) — reglas que aplica Copilot al generar código (las 11 restricciones, convenciones, DoD).

---

## División de trabajo (importante)

- **VS Code Copilot** escribe el código de la app (Laravel, Filament, Livewire, tests, migrations).
- **Claude Code** (yo) hace planning, design, code review, security review, deploy. Genera prompts para Copilot. **No escribe código de la app directamente.**

Más detalle en la memoria persistente del proyecto (cargada automáticamente al abrir esta carpeta).

---

## Sistema de diseño

Antes de cualquier sugerencia visual o de UI, **leer [DESIGN.md](DESIGN.md)**. Todas las
decisiones de fuentes, colores, espaciado y dirección estética están definidas allí. No
desviarse sin aprobación explícita del usuario y, en caso de cambio, dejar entrada en el
log de decisiones al final de DESIGN.md.

En modo QA o code review, marcar como issue cualquier código que no respete DESIGN.md
(p. ej. uso de Inter/Roboto, gradientes, `blue-600` Tailwind, iconos en círculos coloreados).

---

## Restricciones inviolables (resumen)

Las 11 reglas completas viven en [.github/copilot-instructions.md](.github/copilot-instructions.md). Las que más afectan a Claude Code:

1. **No romper la app vieja** en https://winfin.es.
2. **No modificar schema legacy** sin ADR.
3. **NUNCA exportar campos RGPD del técnico** (DNI, NSS, CCC, teléfono, dirección, email) al cliente.
4. **NUNCA SHA1 sin sal** — ver ADR-0003 para la migración lazy.
5. **SQL solo Eloquent / Query Builder** — nunca raw queries con concatenación.
6. **Producción es solo-lectura por defecto.** Cualquier `UPDATE`/`DELETE`/`ALTER` en BD producción requiere ADR + backup + confirmación explícita.
7. **Nunca commitear `.env` ni keys** en chat ni en archivos del repo.

---

## Workflow típico

1. Usuario pide funcionalidad o módulo nuevo.
2. Claude Code planifica (puede usar `/plan-eng-review`, `/plan-design-review`, `/autoplan`).
3. Claude Code prepara prompt copy-paste para Copilot (en `docs/prompts/0X-modulo.md`).
4. Usuario pega el prompt en VS Code Copilot Chat (modo Agent).
5. Copilot genera código + tests + commit.
6. Usuario vuelve a Claude Code, lanza `/qa-only` o `/security-review`.
7. Si OK: `/ship` → `/land-and-deploy` → `/canary`.

---

## Skill routing

When the user's request matches an available skill, invoke it via the Skill tool. When in doubt, invoke the skill.

Key routing rules:
- Product ideas/brainstorming → invoke /office-hours
- Strategy/scope → invoke /plan-ceo-review
- Architecture → invoke /plan-eng-review
- Design system/plan review → invoke /design-consultation or /plan-design-review
- Full review pipeline → invoke /autoplan
- Bugs/errors → invoke /investigate
- QA/testing site behavior → invoke /qa or /qa-only
- Code review/diff check → invoke /review
- Visual polish → invoke /design-review
- Ship/deploy/PR → invoke /ship or /land-and-deploy
- Save progress → invoke /context-save
- Resume context → invoke /context-restore
