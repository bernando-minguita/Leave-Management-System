# Testing

> Part of the LMS documentation. The stable overview, quick install and
> configuration live in [README.md](../README.md).

## Testing

The suite lives in `tools/` and runs against a live server — every test logs in
over HTTP and asserts on the rendered page.

Every suite that signs in as admin takes its credentials from `LMS_TEST_USER` /
`LMS_TEST_PASS` (PowerShell: `$env:LMS_TEST_PASS = '…'`; bash:
`export LMS_TEST_PASS=…`) and falls back to `admin` / `admin123`, the installer
default. Set the variables whenever the installation uses a rotated password.
Suites that mint their own fixture users are unaffected.

### Release gate

What must be true before any patch reaches the server:

| Check | Command | Required outcome |
|---|---|---|
| Syntax | `php tools/lint.php` | 0 errors |
| RBAC reconciliation | `php tools/rbac-verify.php` | 0 unexpected grants — or each one explicitly accepted |
| Leave workflow | `php tools/workflow-test.php` | all steps pass |
| Linking + statuses | `php tools/leave-link-test.php` and `php tools/leave-status-test.php` | 0 failed |
| Security | `php tools/security-test.php` | 0 failed |
| Exports | `php tools/export-format-test.php` | 0 failed — proves no truncation and the CSV-injection guard |
| Credentials | `LMS_TEST_PASS` set before the run | the sign-in step is not what fails |
| Restored copy | the gate re-run against a **restored copy of production** | every failure explained or fixed |

Exit codes are non-zero on any failure, so the gate can script a build.

```bash
php tools/lint.php                       # parse-check every PHP file
php tools/capacity-plan-test.php         # capacity forecast + dashboard reconciliation
php tools/dashboard-stacked-test.php     # summary tables, stacked modal, row drill-down
php tools/driver-status-test.php         # employment/operational status split
php tools/driver-governance-test.php     # change requests, approval, master history
php tools/driver-lwd-test.php            # last working date + date-aware head-count
php tools/leave-link-test.php            # leave record <-> application linking
php tools/application-view-test.php      # View action + detail page across all roles
php tools/round6-test.php                # round 6: arrival date, duration, vacations
php tools/driver-dashboard-test.php      # driver dashboard: classification + footing
php tools/security-test.php              # open redirect, response headers, cache, rate limiter
php tools/table-engine-test.php          # in-place tables: sort, page, index, no-return fields
php tools/table-prefs-test.php           # Date Created, column show/hide, resize, header placement
php tools/rbac-test.php
php tools/role-ui-test.php
php tools/workflow-test.php
php tools/smoke-test.php
```

| Test | What it protects | Last run |
|---|---|---|
| `lint.php` | Every PHP file parses | **148 files, 0 errors** |
| `table-engine-test.php` | Sort column mapping, **one toggle per click**, index renumbering, lazy pager, no-return fields **on both forms (the New Leave / Vacation screen marks Ticket Date, Expected Return and Duration, and leave.js covers all three names)**, Expected Returns sortable boards, **the pointer cursor on a sortable header that renders no anchor**, Address Book table floor, **the sort surviving a query (the URL writes and reads it, every filter form re-sends it, chips and exports carry it)** | **89 passed** |
| `table-prefs-test.php` | Date Created column + CSV, column show/hide and resize, **utility columns kept out of the menu**, **the Columns control lands in the page header (and in `.page-head` when there is no toolbar)**, **every table joins automatically with no per-table opt-in**, **one control per screen driven by `page-key`, changes broadcast by label, storage kept per table**, **Last Updated + Created By on both registers — shipped hidden, adopted once, and sortable end-to-end**, **a transient list can opt out with `data-dd-skip` (the drill-down modal does, so its per-card header stays under its own control)**, **the tick itself toggles — the handler reads the model, not `cb.checked`, because pre-click activation and the `preventDefault()` revert would otherwise cancel each other out**, **the three KPI screens withdraw the whole control (`data-dd-prefs-none`) while keeping resizers, saved columns and server defaults**, **and the script loads from the `<head>` and re-applies the saved layout from a `MutationObserver` while the page is still parsing, so the reader's hidden columns are never painted first and then hidden** | **136 passed** |
| `driver-dashboard-test.php` | Operational-status rules, KPI/chart/table reconciliation, filters and export, agency chart, **the dropdown comes from Settings (10 statuses + Not set), the flag filter, the column draws the flag, Assignment is gone, and the Driver Status Override (no gate, five clauses, full list order, literal Returned reset, live counts, filter/export agreement)** | **193 passed** |
| `security-test.php` | **Open redirect (`safe_redirect_target()` vectors + the attack replayed end to end through `?to=`), response security headers, CSP's safe subset, `Cache-Control: no-store` on pages and exports, and the rate limiter counting both sides of an insert race** | **47 passed** |
| `rbac-test.php` | Roles, permissions, route map, **admin-only Import / Export in the sidebar** | **96 passed, 7 failed** |
| `role-ui-test.php` | What each role actually sees in the UI | **55 passed** |
| `workflow-test.php` | End-to-end leave lifecycle, including the Last Working Date prompt on deactivation and the table floors (41 steps) | **41 passed** |
| `dashboard-stacked-test.php` | Summary grid foots, stacked modal, row drill-down + footing, axis headroom, row spans, **the modal's Excel export**, **the card drill's whole-list download**, **the pager that cannot show another card's page count**, **Total Active Drivers drops its empty Date column**, **one footer shape shared by every drill modal: pager first as prev / label / next, actions grouped right**, **the drill's two status columns (Application Status / Leave Status), month paging and the six-heading Excel export** | **143 passed** |
| `smoke-test.php` | All main screens return 200 | **36 passed** |
| `application-view-test.php` | View/detail for admin, HR, Operations and Viewer + the list's action floor | **49 passed, 1 failed** |
| `leave-status-test.php` | Derived status rules, Not Returned closure fields, **the return remark reaches the audit trail**, **section E: an approved departure reads Closed once its last working day has passed — closed, reopened, the record-owned one untouched, and the shut window refused by `linkError()`** — plus **a blank status renders an em dash, never a phantom pill, and the sweep repairs such a row instead of walking past it**, **section F: a rejected or cancelled filing reads Closed, Pending stays Not Started, and the reopen branch gives it back on any of the four decisions without ever undoing a Closed the dates are entitled to** | **105 passed** |
| `current-sort-test.php` | Current-leave sorting and filters | **22 passed** |
| `dashboard-table-test.php` | Summary table structure + the drill modal's 7-column header (Application Status + Leave Status) / `#` column | **26 passed** |
| `driver-delete-test.php` | Foreign-key-safe driver deletion | **11 passed** |
| `driver-governance-test.php` | Change requests + approval + master history: the route map per role, the whitelist, one pending request per field, reason gates, approval applying to `drivers` with an `approved_request` history row, reject/cancel writing nothing, direct correction refused without a reason, append-only models | **98 passed** |
| `driver-status-test.php` | Employment/operational status split, option lists, `active` invariant, single template link, **and the option-list safety rules: a value drivers still hold cannot be removed (the save names it and writes nothing), a stale value the list no longer carries survives an edit while a NEW driver may not use one, and the screen shows per-option usage** | **57 passed** |
| `leave-link-test.php` | Leave record ↔ application link: schema, link guards, status follow-through, unlink, **and the rule that a NEW leave demands one — `validateLink()` refuses a blank link on create while an edit stays permissive, all seven fields carried across from the application (leave type, branch, last working date, expected return, destination, reason, remarks), the same refusal on `POST /api/leave/create`, the picker marked required, and leave.js guarding only the create screen** | **90 passed** |
| `driver-lwd-test.php` | Last Working Date: column, date-aware head-count, KPI rule, export/import, screen wiring | **32 passed** |
| `capacity-plan-test.php` | Capacity forecast: day boundaries, resignations, filters, monthly aggregation, dashboard reconciliation, **planned leave as unavailable capacity**, **date-aware deactivation**, **Expected Leave**, **planned trips excluded from Expected Returns**, **pending resignations as exits**, **movement Branch/Leave Type/Status**, `reports.capacity` gate, **leave-type filter covers the exit rows**, **drill-down badges share Capacity Movement's palette**, **Leave Slots to N% (ceiling minus peak)**, **tooltip shows the headcount behind the on-leave %**, **the On Leave card's View All reuses the day-row modal**, **Expected Returns and Expected Resignations open a modal on this page instead of navigating away**, **all three drill modals share one footer shape**, **Drivers on leave gains a # column, a sub-heading and a whole-day Excel export** | **296 passed, 1 failed** |
| `round6-test.php` | Round 6: actual arrival date editable on a Returned record (model + screen), no expected return on **Resignation / Terminated / Absconded** (including that a no-return record is still *savable* once the field is hidden, and that the New Leave / Vacation screen marks all three of Ticket Date, Expected Return and Duration), **the blank start defaulting to last working date + 1**, **Requirement 9 withdrawing Apply New Leave and the New Leave screen from a deactivated driver**, Duration column/field, Vacation Applications counting every leave type but the departures, `pct_label()` at two decimals, **Returning By Month excluding planned trips** | **90 passed** |
| `export-format-test.php` | CSV injection guard, SpreadsheetML, **column widths fitted from Calibri glyph advances in pixels, then emitted in points**, **every real export string fits the column Excel would give it**, **title / timestamp / bordered rows and gridlines off in every sheet**, 400 on unknown export, **single base path in the summary reports' links** | **147 passed** |
| `destination-test.php` | **Destination Country**: the shipped list and its `parsePlainList()` rules (`value\|Label` pairs refused, duplicates, >120 characters), the `destination_countries` row Settings seeds and validates, **both forms rendering a `<select>` whose first option is Not set**, a stored value the list does not carry still getting its own option (server-rendered *and* `leave.js`'s `fillDestination()`), and the rename holding on all nine display surfaces, three export headers and two validation messages, **plus pasted lists (line breaks and tabs split, control characters refused) and the removal rule for a country leave rows still use — allowed, reported, and the rows keep their value** | **60 passed** |

Notes:

- **Nine assertions fail on a production-restored database**, spread over three
  tests: `rbac-test.php` (7), `application-view-test.php` (1) and
  `capacity-plan-test.php` (1). Every one is a grant mismatch — the tests assert
  the permission policy in the code against the grants the live database
  actually holds. HR is missing `drivers.import`, `import` and `audit.view`;
  Operations holds driver CSV export but not `leave.create`; `reports.capacity`
  is not held by the Administrator role alone; and a role that should be able to
  edit an application is refused for want of `leave.edit`. The application code
  is not wrong in any of these — reconciling them is a decision about role data.
  `tools/rbac-verify.php` prints this matrix read-only; run it before and after
  any role edit.
- Tests create throwaway users (`zui_*`, `zav_*`, `r6_*`, `zgov_*`) and remove them on
  success; every fixture row is named after its test (`CAP FIX *`, `R6 *`, `ZZ-GOV-*`)
  and is also cleaned up on shutdown.
- Every admin-sign-in suite now reads `LMS_TEST_PASS` — no test file carries a
  literal password any more. A wrong or unset credential still fails at the
  sign-in step and every assertion downstream of it, so a suite can look
  broken when only the credential is. `security-test.php` never has this
  problem: it mints its own throwaway `zsec_*` account and removes it on
  shutdown, so it never touches the shared admin bucket or its
  5-per-15-minutes rate limit.
- Exit code is non-zero on any failure, so they can gate a build.

### Test helpers

```bash
php tools/clear-logs.php          # empty storage/logs
php tools/rebuild-sample-leave.php # regenerate demo leave data
php tools/remove-sample-data.php   # strip demo rows
php tools/remove-test-data.php     # strip test rows
```

---

