# ORBIT

Business intelligence for multi-branch organizations: import business data, analyze performance, build dashboards and reports, monitor KPIs.

> Status: **Phase 4 (analytics) complete.** Auth, multi-tenancy, data sources, the CSV/Excel import engine, the analytics engine, the executive dashboard and drill-down explorer work end to end on a seeded demo organization. The custom dashboard builder is next. See [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md).

## Demo

`php artisan migrate:fresh --seed` builds **Nexora Group**: three branches and about 18 months of sales, expenses, inventory and staff, ending on today's date. Every account's password is `password`.

| Role | Email |
|---|---|
| Owner | `owner@nexora.test` |
| Administrator | `admin@nexora.test` |
| Analyst | `analyst@nexora.test` |
| Viewer | `viewer@nexora.test` |

Imports run on the queue, so keep a worker running (`php artisan queue:listen`, or `composer dev`).

## Documentation

| Doc | Contents |
|---|---|
| [PRODUCT_REQUIREMENTS](docs/PRODUCT_REQUIREMENTS.md) | Scope, requirement IDs, non-functional requirements |
| [ARCHITECTURE](docs/ARCHITECTURE.md) | Environment assessment, stack, tenancy, import and analytics engines, decision log, risks |
| [DATABASE_DESIGN](docs/DATABASE_DESIGN.md) | ERD and table definitions |
| [ROUTES](docs/ROUTES.md) | Routes, permissions, sidebar IA |
| [DESIGN_SYSTEM](docs/DESIGN_SYSTEM.md) | Tokens, typography, validated chart palette, components |
| [IMPLEMENTATION_PLAN](docs/IMPLEMENTATION_PLAN.md) | Phased roadmap, dependencies, Definition of Done tracker |

## Local setup (Windows)

Requires PHP 8.4 (Laravel Herd's build), Composer 2, Node 24.

```powershell
# Use Herd's PHP 8.4, not an older php.exe on PATH
$env:Path = "C:\Users\<you>\.config\herd\bin\php84;C:\Users\<you>\.config\herd\bin;" + $env:Path

composer install
npm install
copy .env.example .env
php artisan key:generate
New-Item database\database.sqlite -ItemType File   # SQLite during development
php artisan migrate
composer dev        # server + queue worker + Vite
```

Open http://127.0.0.1:8000 and create an account.

## Quality gate

```powershell
composer test       # Pest feature + unit tests
composer analyse    # Larastan level 6
vendor/bin/pint --test
npm run typecheck   # vue-tsc strict
npm run lint
npm test            # Vitest
```
