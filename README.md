# LMS

**Driver Leave & Vacation Management**

A web application for tracking driver leave, vacations, expected returns and
overdue drivers across branches and agencies — with role-based access control,
reporting and an auditable trail of every change.

Drivers are recorded with a badge number, branch, agency and leave window. As
soon as a leave window is entered the system derives whether that driver is
planned, currently away, returned or overdue — no cron job, no manual status
chasing. A dashboard, four reports and CSV/XLS exports keep supervisors and
management current.

---

## Table of contents

**In this file** - [Overview](#overview) · [Key features](#key-features) ·
[Tech stack](#tech-stack) · [Requirements](#requirements) ·
[Installation](#installation) · [Configuration](#configuration) ·
[Module reference](#module-reference) · [Troubleshooting](#troubleshooting) ·
[Licence and credits](#licence-and-credits)

**Documentation** (depth lives here):

- [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) - feature behaviour, leave
  lifecycle, statuses, counting rules, capacity, reporting and export
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) - project structure, request
  flow, data model, JSON API
- [docs/SECURITY.md](docs/SECURITY.md) - security controls, production
  checklist, known gaps, roles and permissions
- [docs/TESTING.md](docs/TESTING.md) - release gate, suites, helpers
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) - patches, release checklist,
  database changes, rollback

---

## Overview

LMS answers one operational question reliably: **where are my drivers,
and when are they coming back?**

Drivers are recorded with a badge number, branch, agency and leave window. As
soon as a leave window is entered the system derives whether that driver is
planned, currently away, returned or overdue — no cron job, no manual status
chasing. A dashboard, four reports and CSV/XLS exports keep supervisors and
management current.

It runs on a plain LAMP stack with **no Composer dependencies and no build
step**, and is operated from a single centrally managed server. The
installation steps below are for maintainers setting up a development copy or
re-deploying — end users only ever sign in.

---

## Key features

- **Leave lifecycle** - derived statuses (planned, on leave, returned,
  overdue, cancelled, not returned) with applications, approvals and a
  one-to-one link to leave records.
- **Driver register** - badge/IQAMA master data, CSV import, change requests
  with append-only history, deactivation with a last working date.
- **Dashboards** - leave and driver dashboards: KPI cards, drill-down modals
  with two status axes and paging, charts.
- **Capacity planning** - day-by-day forecast, expected leave, departures,
  Leave Slots, Excel export.
- **Reports** - four filterable reports with CSV/XLS export and print; the
  dashboard summary exports as PDF in the browser.
- **Access control** - session RBAC (four shipped roles), route-permission
  map, TOTP two-factor, recovery codes, rate limiting, full audit trail.
- **Operational extras** - FullCalendar leave calendar, JSON API gateway,
  demo-data tooling, ZIP-patch deployment.

## Tech stack

| Layer | Choice |
|---|---|
| Language | PHP 8.0+ (`declare(strict_types=1)` throughout) |
| Database | MySQL 8.x / MariaDB — PDO with prepared statements |
| Framework | Custom front-controller MVC — **no Composer packages** |
| Front end | Bootstrap 5.3, Bootstrap Icons |
| Charts | Chart.js |
| Calendar | FullCalendar |
| PDF | html2pdf |
| Other | QR code generator, TOTP two-factor auth |
| Server | Apache with `mod_rewrite` (developed on XAMPP) |

Vendored libraries live in `assets/vendor/` — nothing is fetched at runtime.

---

## Requirements

- PHP **8.0** or newer with `pdo_mysql`, `curl`, `mbstring`, `openssl`
- MySQL **8.x** (or a compatible MariaDB)
- Apache with `mod_rewrite` enabled
- HTTPS strongly recommended in production

---

## Installation

> For maintainers only. There is no public download and no supported
> third-party hosting: the system runs as one centrally operated instance, so
> anything below is either a local development copy or a re-deployment of that
> instance. The instance address is intentionally not documented.

### Option A — Web installer (recommended)

1. Place the project on the target server at the path you intend to serve
   (the rewrite base must match — see below).
2. Open:

   ```
   https://<host>/<path>/setup.php?key=<printed-key>
   ```

3. The locked screen prints the current setup key — no secret lives in the
   documentation. Enter the database name, user and password. The installer
   creates the
   database, applies the schema and seeds, builds the RBAC and account-security
   tables, creates the administrator account and writes
   `app/config/config.php`.
4. **The installer deletes itself on success.** If you abort, delete it
   manually.

> `setup.php` takes no destructive action and never prints a password back. It
> exists because `tools/install.php` is CLI-only on purpose (it can drop a
> database) and `.htaccess` blocks `/tools/` from HTTP.

### Option B — Command line

```bash
mysql -u root -p -e "CREATE DATABASE lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php tools/install.php
php tools/migrate-rbac.php      # roles, permissions, user_roles
php tools/migrate-account.php   # 2FA, recovery codes, rate limits
```

`tools/install.php` can **drop** a database — read it before you run it. It
creates the schema, the default admin and the seeds only; the two migrations
above build RBAC and account-security exactly as `setup.php` does inline.

### Option C — Manual

```bash
mysql -u root -p lms < database/schema.sql
mysql -u root -p lms < database/seed.sql
php tools/migrate-rbac.php      # roles, permissions, user_roles
php tools/migrate-account.php   # 2FA, recovery codes, rate limits
php tools/create-admin.php      # first administrator
```

Optional demo data (everything below is fictional):

```bash
mysql -u root -p lms < database/seed-drivers.sql
mysql -u root -p lms < database/seed-leave.sql
```

All demo drivers are fictional. `seed-drivers.sql` inserts 50 placeholder
drivers (badges `B-001` … `B-050`): the names are invented, the IQAMA numbers
are a made-up straight run (`3451123456` … `3451123505`) that deliberately starts with 3, because real IQAMAs start with 1 or 2; the mobile, alternate
and emergency-contact numbers are sequential dummies (`0551000001` …), and
every row carries `SAMPLE DATA` in its remarks. `seed-leave.sql` attaches its
demo leave records only to those same `B-0xx` badges, so no real person is
named or identified anywhere in the demo set. Remove it all before real use
with `php tools/remove-sample-data.php`.

When installation finishes, sign in at `/login`.

---

## Configuration

All settings live in **`app/config/config.php`** (the installer writes it for
you).

| Constant | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database connection |
| `APP_NAME`, `APP_TAGLINE` | Branding shown in the UI and on reports |
| `APP_TIMEZONE` | Defaults to `Asia/Riyadh` (UTC+3, no DST) |
| `APP_DEBUG` | Verbose errors — **never enable in production** |
| `APP_KEY` | Key that encrypts TOTP secrets at rest (AES-256-GCM). **Set `APP_KEY` in the environment in production**; the built-in fallback is a development default only |
| `MIN_PASSWORD_LENGTH` | Default 8, overridable via the `MIN_PASSWORD_LENGTH` env var |
| `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY` | Google reCAPTCHA for login, read from the environment and **never from source**. Unset by default in development; **both are required in production** — see [reCAPTCHA behaviour](#recaptcha-behaviour) |
| `DEFAULT_PER_PAGE` | Default pagination size (users can switch to 25/50/100/250) |

Runtime-editable settings (company name, date format, page title, return
window, target leave percentage, pending LWD window) are stored in the
`settings` table and managed from **Settings → System**.

### reCAPTCHA behaviour

Whether the login form is challenged is decided by `recaptcha_enabled()` in
`app/helpers/security.php`. The rules are explicit:

| Host | Keys configured | reCAPTCHA |
|---|---|---|
| `localhost` | either | **disabled** |
| `127.0.0.1` | either | **disabled** |
| `::1` / `[::1]` | either | **disabled** |
| `*.localhost` (RFC 6761 loopback TLD) | either | **disabled** |
| anything else | both set | **enabled** |
| anything else | either missing | disabled — **a misconfiguration in production** |

Points worth being precise about:

- **The loopback list above is exhaustive.** `is_local_host()` does not inspect
  IP address ranges at all. A server on `192.168.x.x`, `172.16.x.x`, `10.x.x.x`
  or any other private/reserved address is treated as **production** and
  reCAPTCHA stays on. An earlier version of this check did switch reCAPTCHA off
  for private addresses, which would have silently removed the protection from
  an intranet deployment — being on an internal network is a topology fact, not
  a development environment.
- **There is no automatic fallback.** Production must set **both**
  `RECAPTCHA_SITE_KEY` and `RECAPTCHA_SECRET_KEY`. If either is missing the
  challenge is skipped entirely, so confirm them immediately after deploying.
- The **secret** key never leaves the server; the browser only ever sees the
  site key.

### Deploying to a different folder

`.htaccess` sets `RewriteBase /lms/`. If you deploy into another folder,
change it:

```apache
RewriteBase /your-path/
```

---

## Module reference

| Route | Page | Notes |
|---|---|---|
| `/` · `/dashboard` | Leave Dashboard | KPI cards, panels, summary tables, charts |
| `/driver-dashboard` | Driver Dashboard | Operational availability: KPIs, branch/distribution charts, branch summary, filterable list |
| `/login` · `/login/2fa` · `/logout` | Authentication | Only routes reachable without a session |
| `/profile` | My Profile | Name, email, password, two-factor setup |
| `/drivers` | Address book | Search, export, CSV import |
| `/drivers/{id}` | Driver profile | Details + leave history |
| `/drivers/{id}/history` | Driver History | How this record came to look like this (`drivers.history.view`) |
| `/drivers/{id}/edit` | Edit driver | Direct correction; needs `drivers.master_correct` and a reason |
| `/driver-changes` | Change Requests | The queue: file, follow and decide master-data changes |
| `/driver-changes/new` · `/driver-changes/{id}` | Request / detail | Raise a request, then approve, reject or cancel it |
| `/leave/current` | Current leave | Drivers away right now |
| `/leave/records` | Leave records | Full history, sortable |
| `/leave/calendar` | Calendar | FullCalendar month view |
| `/leave/returns` | Returns | Next 7 days + overdue list |
| `/leave/new` · `/leave/{id}` | Leave form / detail | Create, edit, mark returned |
| `/leave-applications` | Applications | Separate approval register |
| `/leave-applications/{id}` | Application detail | Read-only View target |
| `/capacity-planning` | Driver capacity planning | Day-by-day forecast, monthly summary, movement (with Excel export), drill-down (paged, one date) |
| `/reports/leave` | Leave report | Filters, CSV/XLS export, print |
| `/reports/return` | Return report | |
| `/reports/branch` | Branch report | |
| `/reports/agency` | Agency report | |
| `/settings` | Settings hub | |
| `/settings/leave-types` · `/settings/branches` · `/settings/agencies` | Lookups | |
| `/settings/users` | Users | Account administration (formerly the *User Accounts* tab on System) |
| `/settings/system` | System | Backup, restore, demo data |
| `/settings/roles` · `/settings/permissions` | RBAC | |
| `/settings/audit` | Audit log | |

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Blank page or 500 | Check `storage/logs/app-YYYY-MM-DD.log`. Set `APP_DEBUG` to `true` **temporarily** |
| 403 on a page you expect to open | Your role lacks the route's permission — see Settings → Permissions |
| 404 on every clean URL | `mod_rewrite` off, or `RewriteBase` in `.htaccess` does not match the deploy folder |
| Overdue / returning counts look wrong | Status is derived on page load; confirm `APP_TIMEZONE` is correct |
| Login always rejected on localhost | Expected — reCAPTCHA is off on loopback only; check credentials and account status instead |
| Empty Actions column on a list | The role lacks `leave.view` for that register; View is tied to the same permission that opens the page |
| RBAC tables missing after a manual install | Run `php tools/migrate-rbac.php` |
| Changes not visible in the browser | Dashboard scripts are cache-busted with `?v=mtime`; force-refresh (Ctrl+F5) |
| Chart canvas has no height | `.chart-box` is sized by `assets/js/dashboard.js` on load — confirm that file loaded |

### Logs

```
storage/logs/app-YYYY-MM-DD.log   application log (fatal + exceptions)
storage/logs/php-error.log        PHP-level errors
```

`.htaccess` blocks HTTP access to `storage/` and `tools/`.

---

## Licence and credits

**Internal and proprietary.** This software, its source and this documentation
are for use by the operating organisation only. Do not redistribute, publish
or release them to third parties. The full copyright notice lives in [`LICENSE`](LICENSE) at the repository root.

Third-party libraries are vendored under `assets/vendor/` and remain under
their own licences:

- [Bootstrap](https://getbootstrap.com/)
- [Bootstrap Icons](https://icons.getbootstrap.com/)
- [Chart.js](https://www.chartjs.org/)
- [FullCalendar](https://fullcalendar.io/)
- [html2pdf](https://github.com/spipu/html2pdf)





