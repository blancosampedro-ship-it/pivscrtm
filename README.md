# Winfin PIV

[![CI](https://github.com/blancosampedro-ship-it/pivscrtm/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/blancosampedro-ship-it/pivscrtm/actions/workflows/ci.yml)

> CMMS de gestión de paneles de información al viajero (PIVs) en marquesinas — versión moderna en Laravel 12 + Filament 3.

---

## Tabla de contenidos

- [Arquitectura](ARCHITECTURE.md)
- [Decisiones arquitectónicas (ADRs)](docs/decisions/)
- [Roadmap de prompts](docs/prompts/00-roadmap.md)
- [Política de seguridad y RGPD](docs/security.md)

---

## Stack

| Capa | Tecnología | Versión |
|------|------------|---------|
| Lenguaje | PHP | 8.2.30 |
| Framework | Laravel | 12 |
| Admin/Operador UI | Filament | 3.2 |
| Técnico UI (mobile PWA) | Livewire 3 + Volt + Tailwind | 3 |
| Auth | Laravel Fortify (sin Breeze) | — |
| Base de datos | MySQL (SiteGround) | 8.4.6 |
| Notificaciones | Mailable + `laravel-notification-channels/webpush` (VAPID) | — |
| Storage | Filesystem local (`storage/app/public/piv-images/`) | — |
| Queue | Driver `database` (cron de SiteGround → `schedule:run`) | — |
| Tests | Pest | 4 (4.6.x) |
| Build front | Vite + Tailwind 3 + Alpine | — |
| Composer | Composer | 2.7+ |
| Node | Node / npm | 22.22.0 LTS / 10.9.4 |
| Locale / TZ | `es` / `Europe/Madrid` | — |

---

## Roles

- **Admin** (legacy rol 1): control total del sistema desde el panel Filament.
- **Técnico** (legacy rol 2): consulta sus asignaciones del día y cierra averías o revisiones mensuales desde la PWA móvil con foto.
- **Operador** (legacy rol 3): cliente final; ve sus paneles asignados, reporta averías y consulta histórico desde la web.

---

## Setup local

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run dev
php artisan serve
```

> Requiere acceso a la BD `dbvnxblp2rzlxj` en SiteGround (whitelisting de IP en Site Tools → MySQL → Remote MySQL).

---

## Despliegue

Pipeline manual sin downtime de la app vieja:

```
Mac (dev) → git push → GitHub (pivscrtm) → SSH SiteGround
        → git pull
        → composer install --no-dev --optimize-autoloader
        → php artisan migrate --force
        → php artisan optimize
```

Document Root del subdominio `piv.winfin.es`: `~/www/piv.winfin.es/laravel-app/public/`.

---

## Documentación interna

- [ARCHITECTURE.md](ARCHITECTURE.md) — visión completa del sistema y modelo de dominio.
- [docs/decisions/](docs/decisions/) — ADRs (stack, coexistencia BD, auth, UX revisión vs avería).
- [docs/security.md](docs/security.md) — RGPD, exports, secretos, incidentes pendientes.
- [docs/prompts/](docs/prompts/) — roadmap de bloques de trabajo con Copilot.

---

## Licencia

Propietaria — © Winfin Systems S.L. Todos los derechos reservados.
