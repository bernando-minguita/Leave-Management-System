# Security and access control

> Part of the LMS documentation. The stable overview, quick install and
> configuration live in [README.md](../README.md).

## Security

| Control | Implementation |
|---|---|
| SQL injection | PDO prepared statements everywhere; identifiers from allow-lists |
| XSS | Output escaped with `e()` in every template |
| CSRF | Token on every form (`csrf_field()` / `csrf_check()`); enforced on all mutating API calls |
| Open redirect | `safe_redirect_target()` gates `?to=` and the hidden `redirect` field before anything can reach a `Location:` header — schemes, protocol-relative `//host`, leading slashes and control-character tricks all degrade to `dashboard` |
| Security headers | Sent once from `app/bootstrap.php`, so every page, error page and export carries them: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'; object-src 'none'; base-uri 'self'`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` denying geolocation/camera/microphone/payment/usb |
| Caching | `Cache-Control: no-store` on **every** PHP response, restated on both export paths — `.htaccess` serves CSS/JS/images directly, so nothing PHP emits is worth caching and no route can be forgotten |
| Authentication | Session-based; `require_login()` before dispatch |
| Authorisation | Route → permission map applied before the controller runs |
| Two-factor | TOTP, secrets encrypted at rest with AES-256-GCM under `APP_KEY`, one-time recovery codes |
| Passwords | Hashed with `password_hash()`; minimum length configurable |
| Login abuse | Google reCAPTCHA from the environment — **disabled on loopback only**, never on a private network ([details](../README.md#recaptcha-behaviour)) — plus a database rate limiter: 5 attempts per 15-minute window per `IP | user`, cleared on success |
| Audit trail | `audit_logs` records who did what |
| Direct access | `.htaccess` returns 404 for `/app`, `/database`, `/storage`, `/tools` |
| Debug | `APP_DEBUG` off by default; errors logged to `storage/logs/` |

### Production checklist

- A unique administrator password — never the installer default.
- `setup.php` removed from the server (it self-deletes on success; an aborted
  install leaves it, and its installer key is baked into the file — the repo's
  copy has been rotated, delete any stray server copy rather than relying on
  the rotation).
- `APP_DEBUG` false; `APP_KEY` set from the environment, not the dev fallback.
- HTTPS on; reCAPTCHA keys set for any non-loopback deployment.
- `app/config/config.php` kept out of every archive — gitignored, never
  packaged in patches.
- Backups stored off the web root; a restore requires typing `RESTORE` and the
  `settings.system` right.
- Vendored JavaScript under `assets/vendor/` updated deliberately, one library
  at a time, followed by `export-format-test` and a smoke pass.

### Known gaps

Deliberate or acknowledged absences, recorded so nobody has to rediscover them:

- **No self-service password reset.** Recovery is administrator-assisted —
  `tools/create-admin.php`, or the installer's `--admin` flag. For a small
  internal deployment that is a considered trade: a reset flow adds token
  generation, delivery and account-enumeration surface, and the account count
  here does not pay for it. Related tables: `user_recovery_codes` (2FA) only.
- **No `script-src` in the Content-Security-Policy.** The policy covers
  `frame-ancestors`, `object-src` and `base-uri` — the three directives with no
  interaction with inline script. Roughly 18 views emit inline `<script>` data
  payloads and login depends on reCAPTCHA's inline bootstrap, so adding
  `script-src` must be done with the browser open, not by editing a header.
- **No HTTP→HTTPS redirect and no HSTS.** The `Secure` cookie flag is derived
  from `$_SERVER['HTTPS']`, which is correct while PHP itself terminates TLS.
  If TLS is ever terminated *in front of* PHP, that check has to learn
  `X-Forwarded-Proto` (from a trusted proxy only) and HSTS should be sent.

---

## Roles and permissions

Access is granted by **permission, never by role name**. A user's effective
permissions are the **union across every role they hold**, read from
`user_roles` → `role_permissions` → `permissions` and memoised per request.

### Permission catalogue

| Group | Keys |
|---|---|
| Dashboard | `dashboard.view`, `dashboard.drivers` |
| Address Book | `drivers.view`, `drivers.create`, `drivers.edit` *(file a change request)*, `drivers.master_correct` *(write master data directly)*, `drivers.change_request_approve`, `drivers.history.view`, `drivers.deactivate`, `drivers.delete`, `drivers.import`, `drivers.export` |
| Leave & Vacation | `leave.view`, `leave.create`, `leave.edit`, `leave.cancel`, `leave.return`, `leave.delete` |
| Reports | `reports.view`, `reports.export`, `reports.print`, `reports.capacity` |
| Settings | `settings.users`, `settings.roles`, `settings.permissions`, `settings.branches`, `settings.agencies`, `settings.leave_types`, `settings.system` |
| Audit | `audit.view` |

### Shipped roles

| Role | Name | Permissions | Intent |
|---|---|---|---|
| `admin` | Administrator | all (`*`) | Unrestricted, including users, roles and system settings |
| `hr` | HR / Personnel | 20 | Maintains drivers and leave records; files and approves driver change requests, may correct directly; no settings, roles or system config |
| `operations` | Operations | 12 | Operational visibility plus leave/return updates; read-only address book, but may **file** a driver change request |
| `viewer` | Viewer / Management | 8 | Read-only visibility for managers; can export reports and read driver history, changes nothing |

`leave.cancel` is **deliberately withheld** from Operations — grant it from
Settings → Roles if an operations user needs it.

`reports.capacity` (the Driver Capacity Planning page) is granted to the
**Administrator role only** (granted in `role_definitions()`);
every other role receives a 403 on both the page and its drill-down API.
Grant it from Settings → Permissions to widen access without a code change.

Grants are editable at **Settings → Permissions**, so an administrator can
widen or narrow any role without a code change.

> **Why every row shows a View action:** actions on list pages are gated by
> permission, so a role holding only `leave.view` would otherwise see a blank
> Actions column. View is tied to `leave.view` — the same permission that gates
> the list itself — so anyone who can reach a page always has a way in, while
> `leave.edit` / `leave.delete` remain independently enforced.

### Operation matrix

Every sensitive operation, the one permission that unlocks it, and the state a
fresh install ships with. `✓` = granted, `–` = not granted (default grants come
from `role_definitions()`; **every row can be widened or narrowed per role from
Settings → Roles / Permissions with no code change**). *Enforced where* names
the layers that refuse the request — the JSON API carries the same keys as the
web routes and controllers that write sensitive data re-check the permission
themselves, so hiding a button is never the security boundary. The last column
is the `audit_logs.action` the operation writes; `—` means the operation is not
itself logged (a page read, a download).

| Operation | Permission | Admin | HR | Ops | Viewer | Enforced where | Audited as |
|---|---|---|---|---|---|---|---|
| Leave Dashboard | `dashboard.view` | ✓ | ✓ | ✓ | ✓ | route map (`GET /dashboard`) | — |
| Driver Dashboard | `dashboard.drivers` | ✓ | ✓ | ✓ | ✓ | route map (`GET /driver-dashboard`) | — |
| View drivers, profiles, leave history | `drivers.view` | ✓ | ✓ | ✓ | ✓ | route map + API (`drivers/search`, `drivers/detail`) | — |
| Add a driver | `drivers.create` | ✓ | ✓ | – | – | route map | `driver-created` |
| File a driver change request | `drivers.edit` | ✓ | ✓ | ✓ | – | route map (the queue takes **either** this key or the approver's) | `driver-change-requested` |
| Correct master data directly | `drivers.master_correct` | ✓ | ✓ | – | – | route map **+** a second `require_permission()` in the controller; the SIMLedger sync API carries the same key | `driver-updated` (+ a Driver History row with the reason) |
| Decide change requests | `drivers.change_request_approve` | ✓ | ✓ | – | – | route map (`approve` / `reject`) | `driver-change-approved`, reviewer recorded |
| Deactivate / reactivate | `drivers.deactivate` | ✓ | ✓ | – | – | route map + API | `driver-deactivated` / `driver-activated` |
| Delete drivers | `drivers.delete` | ✓ | – | – | – | route map (single and bulk) | `driver-deleted` |
| Import the address book (CSV) | `drivers.import` | ✓ | ✓ | – | – | route map (`/drivers/import`, `/import`) | `drivers-imported` (mode + row counts) |
| Export the address book | `drivers.export` | ✓ | ✓ | – | – | route map (`GET /drivers/export`) | — |
| View leave records and applications | `leave.view` | ✓ | ✓ | ✓ | ✓ | route map + API | — |
| Create leave / an application | `leave.create` | ✓ | ✓ | ✓ | – | route map + API (`leave/create`) | `leave-created`, `leave-application-created` |
| Edit leave / an application | `leave.edit` | ✓ | ✓ | ✓ | – | route map + API (`leave/update`) | `leave-updated`, `leave-application-updated` |
| Approve / reject / reset a decision | `leave.edit` | ✓ | ✓ | ✓ | – | route map (`POST …/status`); refused outright while a record is linked | `leave-application-status` |
| Mark returned / close as Not Returned | `leave.return` | ✓ | ✓ | ✓ | – | route map + API (`mark-returned`, `not-returned`) | `driver-returned`, `leave-not-returned` |
| Cancel a leave record | `leave.cancel` | ✓ | ✓ | **–** | – | route map + API (`leave/cancel`); withheld from Operations on purpose | `leave-cancelled` |
| Delete leave records | `leave.delete` | ✓ | – | – | – | route map (single and bulk) | `leave-deleted`, `leave-bulk-deleted` |
| Reopen a closure (Change Status) | `settings.system` | ✓ | – | – | – | route map (`POST /leave/change-status`) | `leave-status-changed` |
| View the four reports | `reports.view` | ✓ | ✓ | ✓ | ✓ | route map | — |
| Export CSV / Excel (`?export=`) | `reports.export` | ✓ | ✓ | ✓ | ✓ | an explicit `require_permission()` — a query string is invisible to the route map; the capacity drill's Excel demands the same key | — |
| Driver Capacity Planning + drill | `reports.capacity` | ✓ | – | – | – | route map (page and API); the data-quality panel stays Administrator-only regardless | — |
| Manage branches / agencies / leave types | `settings.branches` / `settings.agencies` / `settings.leave_types` | ✓ | – | – | – | route map, one key per screen | screen-named actions (e.g. `agency-created`, `agency-updated`) |
| Manage users and administrator 2FA resets | `settings.users` | ✓ | – | – | – | route map (+ the two administrator 2FA actions) | `user-created`, `user-updated` |
| Manage roles and their grants | `settings.roles` / `settings.permissions` | ✓ | – | – | – | route map (either key opens the page; the save asks what its kind needs) | `role-updated` |
| System settings, backup / restore, demo data | `settings.system` | ✓ | – | – | – | route map (+ typing `RESTORE` to restore) | `settings-changed`, `database-restored`, `system-reset` |
| View the audit log | `audit.view` | ✓ | ✓ | – | – | route map | — |

The four shipped roles are seeds, not law: the matrix above is what
`role_definitions()` grants on a fresh install, and the live grants live in
`role_permissions`, readable and editable per role at Settings → Roles.

---

