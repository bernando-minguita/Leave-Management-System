# Architecture

> Part of the LMS documentation. The stable overview, quick install and
> configuration live in [README.md](../README.md).

## Project structure

```
lms/
├── index.php              Front controller — every clean URL lands here
├── api.php                JSON API gateway (/api/<area>/<action>)
├── login.php, logout.php  Thin redirects into the router
├── setup.php              One-time web installer (deletes itself)
├── .htaccess              Clean URLs, API rewrite, blocks /app /tools /storage
├── app/
│   ├── bootstrap.php      Autoloader, config, error handler, session
│   ├── config/config.php  Database + application constants
│   ├── controllers/       8 controllers (HTTP entry points)
│   ├── models/            8 models (SQL + domain rules)
│   ├── views/             Templates, layouts and partials
│   ├── core/              Router, View, Database, Validator, Status
│   ├── middleware/        auth, permissions, route-permissions
│   ├── helpers/           functions, security, audit
│   └── api/               API route map
├── assets/
│   ├── css/               Application styles
│   ├── js/                app, table, table-prefs, driver-dashboard,
│   │                      dashboard, dashboard-report, capacity, leave, calendar
│   ├── images/
│   └── vendor/            bootstrap, bootstrap-icons, chartjs,
│                          fullcalendar, html2pdf, qrcode
├── database/
│   ├── schema.sql         Tables, indexes, foreign keys
│   ├── seed.sql           Settings, leave types, branches, agencies
│   ├── seed-drivers.sql   Demo drivers (all fictional)
│   └── seed-leave.sql     Demo leave records
├── storage/               logs, exports, cache  (never served over HTTP)
└── tools/                 CLI utilities and the test suite  (blocked from HTTP)
```

---

## Architecture

### Request flow

```
browser  ──►  .htaccess  ──►  index.php (web)  or  api.php (JSON)
                                 │
                                 ├─ require_login()
                                 ├─ require_route_permission()   ← app/middleware/route-permissions.php
                                 ├─ Router::dispatch()
                                 └─ Controller → Model → View::render()
```

- **Routing** — routes are declared in `index.php`. `{id}` segments are
  matched numerically. Literal segments (`new`, `export`) are registered
  before parameterised ones so they always win.
- **Permissions** — `app/middleware/route-permissions.php` maps
  `"GET /leave-applications/{id}"` to a permission key. Resolution is an exact
  hash lookup on the normalised path, so a shorter pattern can never leak a
  permission meant for a longer route.
- **Views** — plain PHP templates under `app/views/`, rendered through
  `layouts/app.php`, with reusable partials for page headers, filters, KPI
  cards, row actions and modals.

### Models own the SQL

Controllers never write SQL. Models expose `paginate()`, `all()`, `find()`,
`create()`, `update()` and `delete()`, and every value is bound through a PDO
prepared statement. Column names come from allow-lists (`SORTABLE`,
`DATE_FIELDS`), never from user input.

---

## Data model

### Core tables (`database/schema.sql`)

| Table | Holds |
|---|---|
| `users` | Login accounts, profile, status |
| `drivers` | Badge, name, iqama, branch, agency, position, mobile, active flag, **last working date (deactivation end date)** |
| `leave_records` | The leave register: dates, destination, status, actual arrival, **which application it came from** |
| `leave_applications` | The application register with approval + leave status, **mirrored from its linked record** |
| `branches` | Branch master (Dammam, Riyadh, Jeddah, Khobar, Other) |
| `agencies` | Agency master |
| `leave_types` | Leave/vacation types |
| `settings` | Key/value runtime configuration |
| `audit_logs` | Who did what, when |
| `driver_change_requests` | One field's proposed master change: old/new value, reason, who filed it, who decided it, status. `pending_key` keeps it to **one open request per field** |
| `driver_history` | Append-only record of how a driver's master data came to look like this — who, what, from what, to what, why, and through which door |

### Security tables (`tools/migrate-rbac.php`)

| Table | Holds |
|---|---|
| `roles` | `slug`, `name`, `description`, `is_system` |
| `permissions` | `permission_key`, `label`, `group_name` |
| `role_permissions` | Role ↔ permission grants |
| `user_roles` | User ↔ role assignments |

Account-security tables (TOTP secrets, recovery codes, rate-limit counters) are
built by the same installer path (a manual install runs `tools/migrate-account.php`). There is **no self-service password reset** —
recovery is administrator-assisted (`tools/create-admin.php`, or the installer's
`--admin` flag); see [Known gaps](SECURITY.md#known-gaps).

> The RBAC tables are **not** in `schema.sql` — always run
> `tools/migrate-rbac.php` (or the web installer) on a fresh database.

---

## JSON API

`api.php` is a thin gateway: `/api/<area>/<action>`.

```bash
GET  /api/leave/calendar?start=2026-09-01&end=2026-09-30
POST /api/leave/mark-returned
GET  /api/dashboard/kpis
GET  /api/drivers/search?q=170
```

- Uses the **same permission map** as web routes (`require_route_permission('api', …)`),
  so an endpoint cannot drift out of sync with the UI.
- Every non-`GET` request is CSRF-checked by the gateway before dispatch.
- Responses are always `{ok: bool, message: string, …}`.

Endpoints are declared in `app/api/routes.php`:

| Area | Actions |
|---|---|
| `drivers` | `search`, `detail`, `deactivate`, `sync-simledger`, `sync-simledger-all` — the sync pair stays hidden and refuses until `SIMLEDGER_API_TOKEN` is configured |
| `leave` | `create`, `update`, `mark-returned`, `cancel`, `not-returned`, `view`, `calendar`, `overlap-check`, `open-applications`, `change-status` |
| `dashboard` | `kpis`, `panel`, `summary-drivers`, `kpi-drivers` |
| `settings` | `save`, `lookup` |
| `reports` | `rows` |
| `capacity` | `drivers` |

---

