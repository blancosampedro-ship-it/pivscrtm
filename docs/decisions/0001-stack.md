# 0001 — Stack tecnológico

- **Status**: Accepted
- **Date**: 2026-04-29

## Context

Hay que reescribir Winfin PIV (CMMS de 575 paneles, 65 técnicos, 41 operadores, ~66.500 averías históricas) sustituyendo una app PHP procedural de 2014 con deuda técnica grave (sesiones rotas, SHA1 sin sal, `unserialize()` sobre cookie, dump SQL público, sin CSRF, sin tests). El hosting es **SiteGround GoGeek (compartido)**, no se cambia. El usuario que pilota el proyecto es no-developer y opera con asistencia de Copilot.

Restricciones de hosting:
- PHP 8.2.30, MySQL 8.4, sin workers de queue persistentes.
- Cron único disponible (`* * * * *`).
- Document root configurable por subdominio.

Restricciones de equipo:
- Una sola persona desarrollando con IA. Hay que minimizar superficie y maximizar lo que viene "out of the box".

## Decision

**Laravel 12 + Filament 3.2 + Livewire 3 (con Volt) + PWA en SiteGround GoGeek.**

- Backend y framework principal: Laravel 12.
- Admin/operador (back-office desktop): Filament 3.2.
- Técnico (móvil en campo): Livewire 3 + Volt + Tailwind 3, instalable como PWA.
- BD: la misma `dbvnxblp2rzlxj` (coexistencia, ver ADR-0002).
- Queue driver `database` + cron de SiteGround → `schedule:run` cada minuto.

## Considered alternatives

- **Next.js + Supabase** — descartado: cambia stack del cliente y exige hosting Node + Postgres, fuera del plan SiteGround actual.
- **Django** — descartado: PHP es lo que hay en el servidor; cambiar runtime obliga a renegociar hosting y romper conocimiento previo.
- **Pocketbase** — descartado: SQLite single-file no escala a coexistencia con MySQL legacy y limita reporting.
- **NocoDB / low-code** — descartado: la lógica de cierre de averías + revisiones + notificaciones push + RGPD es más rica que CRUD; los low-code se quedan cortos.
- **PHP plano modernizado** — descartado: parchear los 11 puntos de deuda (sesión, charset, criptografía, deserialización, CSRF, etc.) cuesta más que reescribir sobre Laravel y deja la base inconsistente.

## Consequences

**Positivas:**
- Filament cubre el panel admin completo en horas, no semanas.
- Livewire mantiene PHP como única fuente; sin SPA framework adicional.
- Coexistencia con la app vieja sin downtime durante la migración por fases.
- Eloquent permite mapear las 14 tablas legacy con `$table` explícito sin tocar schema.
- Pest + Laravel Pint dan calidad sin configurar nada exótico.

**Negativas:**
- Filament añade ~200 dependencias Composer; aumenta superficie de actualización.
- Queue por `database` + cron minutal tiene latencia de hasta 60s en tareas asíncronas (aceptable para emails y push, no para tiempo real).
- PWA en iOS Safari tiene limitaciones de push (mejorando, pero no idénticas a Android).
- Hay que disciplinar al equipo (1 persona + IA) para no usar Filament como muleta y meter lógica de negocio en formularios admin.
