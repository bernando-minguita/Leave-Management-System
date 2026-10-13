# Business rules

> Part of the LMS documentation. The stable overview, quick install and
> configuration live in [README.md](../README.md).

## Features

### Driver Dashboard

Operational availability across the fleet — a separate page from the Leave
Dashboard, reached from the shared **Dashboard** heading in the sidebar.

- **Five KPI cards** — Total Drivers, Active, On Leave, Idle and GOSI, each
  showing its share of Total Drivers at one decimal place (`420 | 92.5%`).
  Clicking a card filters the list below; clicking it again clears the filter,
  and **Total Drivers** clears everything.
- **One operational-status calculation** — `DriverDashboardService::classify()`
  decides every driver's bucket, and the cards, both charts, the branch
  summary, the table and the export all read its result, so they cannot
  disagree. Priority: outside the active employment population → *Other*; an
  open leave record → *On Leave*; then `operational_status` `vacation`,
  `sick_gosi`, `idle` and the leaving flags; `assigned` or unset → *Active*;
  a value outside the Settings lists → *Other* plus the data-quality warning.
  Employment status and operational availability stay separate throughout: an
  active employee with no truck is **Idle**, not inactive.
- **Three charts** — Operational Status by Branch (stacked bar; clicking a bar
  filters by branch *and* status), the overall distribution (donut; clicking
  a slice filters by status), and **Total Drivers By Agency** (horizontal bar,
  one bar per agency, largest first; display-only, since the page has no
  agency filter to drive). The row lays them out 5 / 4 / 3.
- **Branch Summary** — Total, the five statuses and Active % per branch,
  sortable, with row click filtering the list to that branch.
- **Driver List** — the eight columns the data source actually carries, filtered
  by status, branch and free text (badge, name, nationality), sorted and paged
  in the browser, with an empty state and Clear Filters. Clicking a row opens a
  read-only details panel; **Open full profile** hands over to the address book.
  Tractor, attachment and customer are not columns in this schema, so the panel
  says they are not tracked rather than inventing a value.
- **Two status concepts, kept apart** — the cards, both charts and the branch
  summary count the five *availability buckets* that `classify()` computes
  (Active / On Leave / Idle / GOSI / Other). The Driver List's **Operational
  Status** column and its dropdown show something else: the driver's own flag
  from **Settings → Operational Status** (`Assigned`, `Vacation`,
  `Under Termination`, `Sick / GOSI`, …) plus **Not set**, exactly as the
  address book renders it. They differ because the list only ever holds the
  active population — the bucket read "Active" for 412 of 455 drivers and told
  the reader nothing, and four of the ten configured statuses could not be
  selected at all. The two are separate filter keys (`op_status` for the cards,
  `operational_status` for the dropdown) and combine with AND, so a card and a
  dropdown can narrow together. There is no Assignment column or filter: it was
  derived from `operational_status === 'assigned'`, which no driver carried, so
  it read "Not assigned" for all 455.
- **Driver Status Override** — the stored flag does not have the last word;
  leave does. `DriverDashboardService::statusOverride()` applies five clauses
  with **no gate** (Assigned, Idle and blank are all eligible) in exactly the
  order specified, first match wins:

  1. approved Resignation whose Last Working Date has passed → **Exit**
  2. On Leave / vacation → **On Leave**
  3. ongoing Sick Leave application → **Sick / GOSI**
  4. marked Not Returned → **Exit**
  5. marked Returned → **Not Set**

  Rules 4 and 5 read the driver's **most recent** `leave_records.status`, not
  "any record ever", so an old Returned leave cannot reset a driver who is away
  right now. Two decisions were confirmed rather than assumed: **rule 2 beats
  rule 3** — as ordered, an ongoing sick leave cannot reach rule 3, so it reads
  On Leave and rule 3 remains a fallback for a sick application with no open
  record; and **rule 5 is literal**, so a Returned driver reads the em dash even
  when a real status is stored (the one place this list and the Address Book
  now disagree). Live: 408 Not Set, 26 On Leave, 9 Idle, 6 Under Termination,
  5 Training, 1 Sick / GOSI — rules 1 and 4 match nobody today but are both
  unit-tested. Only the **displayed** status changes: the stored column, the KPI
  buckets and both charts are untouched, and because the filter matches what the
  column *shows*, an overridden driver is found under the option its row names
  and drops out of **Not set**.
- **Export** — CSV or Excel of the list exactly as filtered (`drivers.export`).
- **Data-quality banner** — a driver whose status is outside the Settings lists
  is counted under *Other / Unavailable* and reported, never dropped.

### Leave Dashboard

- **Toolbar carries the range and the report only** — the range filter leads
  because it decides what every figure below shows, and **Build Summary
  Report** stays (PDF, its own `reports.export` right). The *New Leave* and
  *Expected Returns* buttons are gone: both have their own screen in the
  sidebar, and repeating them here was two more ways to leave a page whose
  whole job is showing the numbers.
- **Nine KPI cards** — Total Active Drivers, On Leave vs Total Drivers,
  Currently On Leave, Returning This Week, Leave Starting This Week, Overdue
  Returns, Expected Returns, Resignation Applications, Vacation Applications.
  Cards refresh over AJAX without a full page reload.
  - **Vacation Applications counts every leave type but the departures** —
    the register holds Annual Vacation, Sick, Emergency, Unpaid, Umrah and
    more, and the card asks how many vacation requests are in the pipeline,
    not how many *Annual Vacation* requests. Resignation, Terminated and
    Absconded are the three names that mean the driver is leaving, and they
    are the only ones excluded (matched on the leave type name, so a renamed
    or added type keeps working). The month × branch grid and its drill-down
    use the same predicate, so a cell and its list always agree.
  - **Expected Returns leaves out trips that have not started** — a record
    with status `planned` has no arrival to expect: the driver has not gone
    away yet. Planned rows belong to *Leave Starting*, not to this count.
  - **Every card's list uses the card's own window.** The nine drill-downs
    read `Dashboard::kpiDrivers()`, and the two week cards draw from the
    *same* rolling seven days (`today + 6`) the card, the THIS WEEK strip
    and the Leave Dashboard all use — so a card saying "4 returning" can
    never open a modal showing none. It previously computed the window as
    `today + (ISO weekday − 1)`, the ISO formula mirrored, which collapses
    to a single day on a Monday.
- **Four live panels** — Currently On Leave, Returning Today, Returning This
  Week and Overdue Returns, each with row-level links to the underlying record.
- **Three summary tables** (Expected Returns / Resignation / Vacation) built as
  a month × branch grid, with row totals, column totals, a `-` column for rows
  with no branch assigned, and branch-name tooltips — so the grid always foots.
- **Stacked bar modal** — each summary card has an icon that opens a stacked
  bar chart of the same data, base-to-top Dammam → Riyadh → Jeddah → any other
  branches, with a month-total tooltip and axis headroom so the tallest bar
  never touches the top of the plot.
- **Row-click drill-down** — every month row in the three summary tables opens
  a modal listing the drivers behind that row (badge, name, branch, date,
  **application status**, **leave status**), sorted chronologically by the
  date the series buckets on — expected return date for Expected Returns,
  last working date for the two application series. The two status columns
  name the two axes the registers keep separate: *Application Status* is the
  approval decision (pending / approved / rejected; an Expected Returns
  record with no filing behind it says so with an em dash) and *Leave
  Status* is the leave progress (not started / planned / on leave / returned
  / …), resolved through the same `hasPlannedTrip()` predicate the Leave
  Applications register uses. The list comes from
  `GET /api/dashboard/summary-drivers` and **pages at 10 rows** behind the
  shared drill pager — while `total` (and the heading above the table) still
  equals the number in the cell it came from, for the whole month. The modal
  carries an **Excel** button that appends `&export=xls` to that same query
  string, so the file is always built from the same series and month the
  reader is looking at — but it downloads the **whole month**, not the open
  page, so the download foots with the cell too. It goes out through the same
  `export_xls()` writer as every other export, is gated on `reports.export` —
  the same right as every other Excel button — ships disabled until a list
  has actually loaded, and is omitted entirely for a role without that right.
- **Four Chart.js charts** — Current Leave by Branch, Leave by Agency, an
  overall leave trend and an Applications & Arrivals trend.
- **Adjustable date range** in the page header (defaults to three months back
  and three months forward) that drives every card, chart and summary table at
  once.

### Leave and vacation records

- Full lifecycle: create → planned → on leave → returned, plus cancel and
  delete.
- **Status is derived, never typed**: `planned`, `on_leave`, `returned` and
  `overdue` are computed from the dates; `cancelled` and `not_returned` are
  the only manual, terminal values.
- **Not Returned** — management confirms the driver is not coming back. The
  leave closes with **no arrival date**, and four facts are recorded with it:
  reason, the date of the decision, who took it, and remarks. It survives
  status re-computation and is reported separately, with the reason.
- **Current Leave** list with sortable columns, status/branch/agency filters and
  a Mark Returned action.
- **Leave Records** — the complete history, sortable on every column including
  Last Working. Every row carries a **Duration** column (leave start → expected
  arrival): a count, never a balance, and `—` whenever either date is missing.
- **Actual Arrival Date is editable** — on **Edit Leave Record**, for the Admin
  and HR roles that hold `leave.edit`, and **only while the record is already
  Returned** (the field edits an existing date; closing a trip is still *Mark
  Returned*, which audits as `driver-returned`). A blank keeps the stored date,
  an arrival can never predate the leave start, and a crafted POST cannot turn
  an on-leave record into a returned one — the model ignores a posted arrival
  date on a record that is not back yet.
- **Calendar** — FullCalendar month view fed from the JSON API.
- **Returns board** — expected returns for the next seven days plus a separate
  overdue list showing days late. **"Returning By Month" lists only trips that
  have started**: a `planned` record is a driver who has not gone away yet, so
  there is no return to make — and the month picker offers only months that
  really do have somebody due back, so it can never open on an empty card.
- Overlap checking prevents double-booking a driver for the same window.

### Leave applications (separate register)

- A standalone application register with its own approval decision:
  `pending` → `approved` / `rejected` / `cancelled`.
- A **second, independent axis** for where the leave itself has got to:
  `not_started`, `on_leave`, `returned`, `not_returned`.
- Approve / Reject / Cancel / Edit / Delete row actions, and a read-only
  **detail page** reachable from a View action on every row.
- Checkbox filters, sortable columns (including Last Working) and CSV/XLS export.
  The list also carries a **Duration** column (last working date → expected
  arrival, `—` when either is missing) and a **Date Created** column — when the
  application was actually filed, as opposed to the business date it chose —
  immediately before Actions, in the list and in the export alike (the CSV
  header is 16 fields).
- Two auxiliary columns follow Date Created on this register **and** on **Leave
  Records**, and travel with both exports: **Last Updated** (rendered through
  the same `fmt_date_time` helper as Date Created) and **Created By** (who filed
  it, from a `LEFT JOIN users`). Both ship **hidden by default** — the server
  renders them `display:none` so nothing flashes before the script runs, and
  `table-prefs.js` adopts that default into the reader's stored preference on a
  first visit only, so ticking them in the Columns menu then sticks across
  visits. They are *appended*, never inserted: saved widths and hidden choices
  are keyed by column position, so a column in the middle would silently
  re-point them. `updated_at` carries `ON UPDATE CURRENT_TIMESTAMP`, so the
  timestamp moves on every write. **Both columns are sortable**: their headers
  are `sort_header()` calls handed the inline `display:none`, so a column can be
  hidden *and* clickable at once (a plain `<th>` could not have carried the sort
  link). The sort keys live in each model's `SORTABLE` map, which both
  controllers read — one whitelist, no second gate — and **Created By sorts on
  the joined `cu.full_name`**, not an unjoined alias, so a record whose creator
  was deleted still sorts (it lands with the NULLs).
- **Resignation, Terminated and Absconded carry no Expected Return Date** —
  those three leave types mean the driver does not come back, so both the
  New / Edit **Application** form *and* the New / Edit **Leave / Vacation**
  form drop the three questions that only describe a return journey —
  **Ticket Date**, **Expected Return Date** and **Duration (info only)** —
  while one of them is selected, and disable the inputs so nothing at all is
  posted for them. `LeaveApplication::expectedArrivalFor()` drops the value
  server side as well, so a date picked before the switch cannot survive the
  round trip. `LeaveController::validate()` (and the API's) demands an
  expected return only from a type that actually comes back and **fails
  closed** for anything it does not recognise — without that the hidden field
  would have made those records unsavable. Absconded was originally left out:
  round 6 item 2 named only the two, treating it as a population exit rather
  than a date to suppress. It was folded in here, which is also why
  `leave_records.expected_arrival_date` is now nullable — see
  [Database changes](DEPLOYMENT.md#database-changes). The form's
  **Duration (info only)** field shows the same span as the list column.
  The submit validator **skips the inputs leave.js disables** (matching what
  the browser does with them): walking every `[required]` field counted the
  emptied Expected Return as missing and blocked the save, while the toast
  blamed the driver picker, which was fine. The toast now names the field
  that is actually wrong.
- **A blank Leave Start Date defaults to the day after the Last Working Date**
  — the driver works that day, so leave begins the next. Applied *before*
  validation, so `required()` sees a value rather than rejecting the blank,
  and the overlap guard, the payload and the audit entry all read the same
  date. On the web form's create/edit/save/update **and** the API's
  create/update, plus the form prefill so the field arrives already filled. It
  never overwrites a date you typed, and with no last working date it invents
  nothing — the field stays empty and `required()` says so honestly. The `+1`
  is parsed as a calendar day, not `86400` seconds, so DST cannot shift it.
- The **Date field filter defaults to Last Working Date** — the date the
  register is actually about — with Application date and Expected return still
  available as tick-box alternatives.
- **Linked to the leave register** (one application → at most one leave record):
  a record stores which application it came from
  (`leave_records.leave_application_id`, unique + foreign key), and the
  application **follows that record**: its **Leave Status** through a single
  mapping — `planned → not started`, `on_leave`/`overdue → on leave`,
  `returned → returned`, `not_returned → not returned`, `cancelled` (or the
  record being deleted) → `not started` — and its **Expected Return Date**,
  which the record replaces with its own on link and re-states on every edit
  or transition (the date the application was filed with is overwritten; the
  Last Working Date stays as filed). An application with **no** record is
  never derived from either: it keeps whatever was chosen on it, default
  `not started`.
- **Apply New Leave** appears on every approved application whose leave has
  `not started` — it opens the leave form prefilled from the application and
  links the record to it on save. Once a record exists the menu links to that
  record instead, and the application's decision (approve/reject/cancel/delete)
  is locked so nothing can change underneath a live leave. **Requirement 9 beats
  it**: while the driver is deactivated the action is withdrawn from both the
  list and the application's own page, because `save()` would refuse the POST
  anyway — offering an action the server rejects is worse than not offering it.
  The New Leave screen now turns such a driver away at the door as well (it
  used to render and only fail on submit), so a bookmarked `?driver_id=` or
  `?application_id=` URL opens nothing.
- **A new leave cannot be created without an approved application.** Every entry
  point still opens the same form — *New Leave / Vacation* in the sidebar, *Add
  Leave* on a driver, the row actions on Leave Records — but on **create** the
  application is a **required** field: `LeaveController::validateLink()` fails a
  blank `leave_application_id` (with the same rule on
  `POST /api/leave/create`), leave.js refuses the click and names the field, and
  the form carries the picker marked `*` with *"No approved application, no leave
  record."* The picker only ever lists approved, not-started, unlinked
  applications for the driver chosen, so the one thing it cannot do is offer a
  bad link. **Editing stays permissive**: a legacy record that predates the rule
  is still correctable and its link may be cleared.
  The seven fields the application owns come across with it — **Leave Type,
  Branch, Last Working Date, Expected Return Date, Destination Country, Reason
  and Remarks** — prefilled from the filing (Branch is the application's own
  snapshot, which is why the picker sets it outright rather than only filling an
  empty select) and still **editable** before saving: the link is mandatory, the
  values are the starting point.
- **An approved departure reads `Closed` once its last working day has passed**
  — a fifth leave-progress value, beside `not_started` / `on_leave` /
  `returned` / `not_returned`. Resignation,
  Terminated and Absconded applications never produce a leave record for a
  driver who has already left (Requirement 9 keeps deactivated drivers off that
  path), so nothing else ever moved them off *Not Started* — the row then read
  as pending forever, for a window that had shut days ago. It is **derived from
  the dates, never typed**: `LeaveApplication::closeExpiredNoReturn()` runs from
  `Status::sync()` on every page load, the same housekeeping that reconciles
  records, writing only where the stored value disagrees — so there is no
  backfill and no call site to remember. Moving the last working day back into
  the future returns the row to *Not Started*; approval is required both ways,
  so the **dates** never decide a rejected or cancelled filing — that is the
  second sweep's rule, and keeping the two apart is what lets a departure
  rejected today and re-approved tomorrow stay *Closed* when its last working
  day is already behind it; and an application a **leave record** owns is left
  alone, because `RECORD_STATUS_MAP` is the single source of truth there.
  **Behaviour change:**
  a non-`not_started` application is refused by `linkError()` and dropped from
  the application picker, so a window that has shut can no longer be turned
  into a trip. `leave_status` ships in `schema.sql` and is applied on the live server.
  A missing or NULL value no longer masquerades as a status:
  `status_badge('')` now renders the em dash used everywhere else for "nothing
  here", rather than the grey pill with a glyph and no text it used to draw.
  The sweep tolerates the same blank in both directions — a row holding the
  ENUM's empty member is treated as *Not Started*, so it repairs itself instead
  of staying blank for ever.
- **A rejected or cancelled application reads `Closed` too** — the other way a
  filing ends without a leave ever happening. It used to stay on *Not Started*,
  which claims a wait nothing is waiting for. Also **derived, never typed**:
  `LeaveApplication::closeRejectedOrCancelled()` runs from the same
  `Status::sync()` sweep, so every rejected and cancelled row already on file
  repairs itself with no backfill, and decisions taken later hold themselves to
  it. **It has to reopen as well as close** — `status()` accepts all four
  decisions from any state, so an administrator can reject a filing and then put
  it back to pending or approved; without the reverse branch a re-approved
  application would be refused by `linkError()` for a leave that is very much
  still open. That branch carries `NOT (<the date rule's own close condition>)`,
  so the two sweeps can never undo each other: re-approving an ordinary leave
  reopens it, re-approving a resignation whose last working day has passed does
  not. `Pending` stays *Not Started* — it is the only one of the four still
  being waited on — and an application a **leave record** owns is left alone, as
  everywhere else.
- The **Leave / New Leave form carries an application search**: approved,
  not-started, unlinked applications for the selected driver, prefilled into
  only the fields you have left empty (same rules enforced server-side and on
  `POST /api/leave/create`, so a stale tab cannot link someone else's
  application or one already in use). It is **required on create** — the seven
  fields it owns (leave type, branch, last working date, expected return date,
  destination country, reason, remarks) come from the filing, and a submit with
  nothing linked is stopped in the browser *and* by `validateLink()`. The
  rejection message is rendered **only when the server actually rejects the
  link**: it used to sit permanently behind a `hidden` attribute, which
  `.d-block` outranks in Bootstrap (equal specificity, both `!important`,
  utilities loaded after the reboot), so it showed on every open of the form.
  The browser-side refusal has its own element (`#applicationRequired`) that
  leave.js adds `.d-block` to rather than fighting that rule again.

- **Destination Country is a dropdown, not a text box**, on both the New Leave
  Application form and the New / Edit Leave form — driven by the
  `destination_countries` setting at **Settings → Application**, with **Not
  set** offered first and stored as `NULL` (drawn as `—` on every list). The
  list is deliberately *plain*: the country name is both the value and the
  label, so the Leave Records destination filter, all three exports, the
  open-application search and every row written before the field became a
  dropdown keep matching with **no backfill and no slug to translate back**.
  A destination the current list does not carry — filed before it existed, or
  trimmed since — is still offered as its own option rather than the select
  silently falling back to *Not set* and wiping it: the server renders it, and
  `fillDestination()` in `leave.js` adds it when an application is picked
  (`fillIfEmpty()` would have dropped it, which is right for a leave type and
  wrong for a country). The label reads **Destination Country** on every
  screen, sort header, detail page, export and validation message that used
  to say *Destination*.

### Address book (drivers)

- Search, filter, paginate, view profile and per-driver leave history.
- Two timestamp columns at the end of the table, immediately before Actions
  (the same position Leave Records and Leave Applications use): **Date
  Created** and **Last Updated**, both sortable and both rendered through
  `fmt_date_time`. They stay separate on purpose — one answers *is this record
  new?*, the other *has anybody touched it since?*, and a single column can
  only ever answer one of them. A NULL reads as an em dash rather than
  `0000-00-00`.
- The filter bar carries **Branch, Agency, Nationality, Employment Status and
  Operational Status**; Operational Status filters on the stored slug against
  the Settings list, so the same value the column stores is what the dropdown
  matches.
- Add, edit, **activate / deactivate** and delete drivers. The row **"+" now
  opens a Leave Application** (every leave starts with one) — for active drivers
  only — and deactivated drivers get neither that button nor the form, or the
  New Leave screen, or the API (`leave can only be recorded for active
  drivers`), so a bookmarked URL cannot bypass rule 9.
- **Deactivation asks for the Last Working Date** in a modal (`drivers/
  {id}/deactivate` refuses to run without one): the last day the driver
  actually worked, not before their joining date, and echoed back in the
  confirmation flash. Reactivating (and setting the status back to `active` on
  the edit form) **clears** it. Stored in `drivers.last_working_date`, shown on
  the profile and the edit form (only when the status is a leaving status),
  and included in the CSV export and import.
- **The end date keeps them in the totals**: capacity, the dashboard's Active
  Drivers / On-Leave % denominator and the driver pickers count a deactivated
  driver **up to and including** their last working date and drop them the day
  after — the same exit rule an approved resignation filing has always had. A
  deactivation with no date on file behaves exactly as before the column
  existed (excluded straight away, no number moves).
- **Employment Status vs Operational Status** — two separate fields: employment
  (`drivers.status`, drives every "active driver" total) answers *does the
  person still work here*, operational (`drivers.operational_status`) answers
  *can they be on the road right now* (assigned, idle, vacation, sick…). Both
  lists are editable at **Settings → Application** as `value|Label` pairs: the
  value is what the column stores, the label what every screen shows, so
  renaming an option never rewrites stored rows. The employment list must keep
  the value `active` — the KPIs count it.
- **CSV import** with validation (both status columns checked against the
  Settings lists), and **export** of the whole address book with the status
  labels, not slugs. The import runs in three steps - upload, **preview the
  exact rows and errors**, then confirm - and writes nothing until the confirm,
  so a wrong file costs a click rather than a rebuild. It carries every column
  the bulk updates need (`mobile_number`, `branch_code`, `operational_status`
  and the rest), so a CSV can re-point a branch, set an operational status or
  fill mobiles across the fleet in one pass; `?template=1` downloads a template
  with the exact headers, offered **once** in the page toolbar beside
  *Export current list*. The **Import / Export** entry in the sidebar is an
  administrator's, because one POST rewrites every driver in the book.
- Bulk delete, guarded against foreign-key conflicts.

### Driver master-data governance

Changing *who a driver is* — badge, iqama, branch, status, dates — is governed
separately from editing the rest of the record, so a year from now there is an
answer to "who decided this, and why".

- **A change request is the normal path.** `drivers.edit` is re-labelled
  *"Request changes to driver master information"* and no longer writes master
  data: it files a single-field request carrying the old value, the new value,
  a reason of at least 5 characters and an optional effective date. The queue
  lives at `/driver-changes`, and **Change Requests** appears in the sidebar
  for anyone who can file or decide one.
- **One open request per field, enforced by the database.**
  `driver_change_requests.pending_key` is a generated column holding
  `driver:field` while the request is pending, behind a unique index — two
  people cannot both be waiting to change the same field.
- **Approval applies it in one transaction.** `DriverChangeRequest::approve()`
  takes a `FOR UPDATE` lock, re-reads the master and **refuses if it no longer
  matches the recorded old value**, so a stale request can never overwrite a
  newer edit. The update to `drivers`, the history row and the decision are
  committed together — the master row is never updated without its history.
- **Rejection and cancellation change nothing** but the request's status.
- **Direct correction is the exception, not the rule.**
  `/drivers/{id}/edit` needs `drivers.master_correct` and **refuses a governed
  change that carries no reason** of at least 5 characters. A change confined to
  ungoverned columns (remarks, the emergency-contact pair) needs none. Both
  paths write history.
- **History is append-only.** `driver_history` records who, what, from what, to
  what, why, when and through which door (`initial`, `approved_request`,
  `direct_correction`, `import`, `deactivation`, `reactivation`). Neither model
  exposes an `update()` or `delete()` path — reversing a change writes a new
  row. The **History** tab on the driver profile renders it with filters and
  needs only `drivers.history.view`.
- **Every driver starts with a baseline.** The migration stamps one *Initial
  Driver Setup* row for each governed field that already holds a value, so the
  tab is never empty. It runs only while `driver_history` is empty.
- **Rollout is gradual by design.** `drivers.master_correct` ships granted to
  Administrator *and* HR beside `drivers.change_request_approve`; revoke it from
  **Settings → Roles** whenever the business wants HR to go through
  request/approve only. No code change.
- Upgrade an existing database with the CLI tool - the phpMyAdmin SQL twin
  was removed once applied:
  `php tools/migrate-driver-governance.php` (`--dry` to preview,
  `--verify` to report, no flag to apply).

  The tool is idempotent: re-running creates nothing and re-seeds nothing.
  **Uploading the patch alone creates no tables** — until they exist,
  `/driver-changes` returns a 500 on its first SELECT.
- **Branch backfill provenance.** The baseline seed deliberately never invents
  a value, so a driver whose branch was still NULL when it ran ends up holding
  a branch afterwards with no history row explaining it. Running
  `php tools/fill-driver-branches.php --commit` **after** the migration is
  therefore followed by `php tools/record-branch-backfill.php`, which records
  what the tool assigned. Both are idempotent, and the second reports
  "nothing to do" once every branch has provenance.

### Lists sort, page and resize in place

Every list that shares the application's `pagination()` / `sort_header()` helpers —
**Leave Records, Current Leave, Leave Applications, the Address Book and the
Audit Log** — now does its three mechanical jobs in the browser:

- clicking a header reorders the rows without a request
- clicking a page link shows a different slice
- changing **Rows** (10 / 25 / 50 / 100 / 250) re-slices

None of them reloads the page, so the screen no longer jumps back to the top.
`assets/js/table.js` works on the rows the **server already rendered** — it
never re-creates a cell — which keeps PHP the single source of the markup, the
exports and the tests. Dates sort as dates and numbers as numbers, and a tie
keeps the order the server sent.

Everything still works **without JavaScript**: the headers and page links are
real URLs, and the Rows select falls back to submitting its form. Filters still
submit, because a filter changes which rows exist rather than how they are
shown, and exports are still produced by the server from those same filters.

The order the reader chose now survives all of it. Sorting writes
`?sort=<key>&dir=<asc|desc>` into the address bar with `history.replaceState`
(never `pushState` - sorting a column is not a navigation) and reads it back at
boot, so it outlives the session; a new sort also drops `page=`, because it
starts again at page 1, exactly as `sort_header()`'s links do. Every GET that
rebuilds the list then carries it: the filter forms emit `hold_sort()`'s
hidden inputs, and the links assembled by hand - status chips, export buttons -
go through `sort_params()`. Until now the sort lived only in JavaScript, so the
first Apply handed back a query string with `?sort=` stripped out, the server
fell back to its default, and `data-dd-initial` re-seeded the engine with that
default instead.

Driver capacity planning's two grids (Day-by-Day and Capacity Movement) joined
the same engine in round 9: their bespoke `mkPager` now carries the same
markers, so its page links and Rows select behave identically. **Expected
Returns** joined afterwards: all five of its boards (Today, Tomorrow, This
Week, Overdue and Returning By Month) sort in place. The four short ones carry
no pager, so they size themselves to their own row count - a default of 10
would hide rows with no page links to bring them back.

A header click toggles **ascending then descending**, exactly once per click.
The handler lives on the `<th>` alone; the anchor inside it exists for the
no-JS fallback and only cancels its own navigation. Binding the toggle to both
used to fire it twice per click, and two toggles of the same rule always land
on `desc` - ascending was unreachable with a mouse. The headers that sort this
way use `sort_header_client()`, which renders no anchor at all where the
controller does not read `?sort=` back, rather than a link that would do
nothing. The choice is still not lost: the engine writes it into the URL like
every other list, so reloading Expected Returns comes back in the same order -
one query string shared by all five boards, applied only where that board
actually renders the column.

That missing anchor had one visible side effect: every other list borrowed the
browser's own pointer from its `<a>`, so **Expected Returns alone showed an
arrow** over headers that were still perfectly clickable. `th.sortable` now
carries `cursor: pointer` in its own right, which also extends the pointer
across the whole cell rather than just the label - the cell is what `table.js`
binds.

### Column show/hide and resize

**Every** table lets the reader choose which columns they see and how wide they
are, and no table has to ask: `assets/js/table-prefs.js` boots off `table`
alone, so a grid added later joins with no markup, key, id or anchor of its
own.

A **screen** is the unit it is unified over. Expected Returns renders five
boards and Capacity four grids; a reader thinks of those as one table, so the
layout publishes the sidebar key as a meta tag and the script builds **one**
Columns control for the whole screen:

```html
<meta name="page-key" content="leave-returns">
```

Hiding *Destination* then hides it on all five boards, and dragging a header on
one board sizes that column on them all. The menu speaks **labels** — the only
thing two grids genuinely share — while storage stays **index-based and per
table** (`lms.tableprefs.<key>`), so a grid with a different column order cannot
have index 3 mean two different things. The key is the table's `data-dd-prefs`
value where one is still shipped (those four screens keep their saved layout
unchanged), otherwise derived from the screen plus the table's position on it.

A grid that rebuilds its rows — the driver dashboard, the capacity drill-down,
the dashboard summary — calls `window.ddPrefsBoot()` afterwards, which re-reads
the cells; without it a hidden column would quietly come back on the next sort.

Three screens **withdraw the control entirely**: the Leave Dashboard, the
Driver Dashboard and Capacity Planning. They are drill pages — their grids sit
behind KPI cards and inside modals — so one Columns button in their header
would list columns from several unrelated tables at once and broadcast a
change to whichever grid booted first. Each passes `no_prefs` to
`partials/page-head.php`, which renders `data-dd-prefs-none`, and `addMenu()`
returns before it builds anything. Only the *button* goes: `boot()` still runs
`refresh()`, `addResizers()` and `applyAll()`, so those grids keep their
resizers, their saved hidden columns and the server-shipped defaults — and
every other screen still offers its own control.

- A **Columns** dropdown lists the data columns as checkboxes; unticking one
  hides it. The whole row is the control, **including the tick itself**: the
  handler takes its answer from the model rather than from `cb.checked`,
  because a click on the checkbox runs the browser's pre-click activation
  (flipping it) *before* the handler runs while `preventDefault()` reverts it
  *after* the handler returns. Reading the tick therefore saw a transient
  value and re-applied what was already true — the row looked like it worked
  while clicking the box did nothing — so the tick is also re-synced once the
  click has dispatched. Every hideable header also carries a 7px drag handle
  on its right
  edge — drag to resize, double-click to reset that column — and **Reset this
  table** puts the whole grid back.
- Preferences live in `localStorage` under `lms.tableprefs.<key>` as `hidden` and
  `widths`. Nothing reaches the database and no request is made: the feature is
  one script, `assets/js/table-prefs.js`, tagged with `?v=<mtime>` so a patch
  upload cannot leave a browser running old code — and loaded by the layout from
  the **`<head>`**, not from the end of the body.
- **No flash of the wrong layout.** Hiding a column used to happen at
  `DOMContentLoaded`, by which point the browser had already painted the grid
  with every column showing, so the reader's hidden columns appeared and then
  vanished a beat later — on load, and again on every filtered query (the filter
  bar is a `GET`, so that is a full reload). The script now loads in the head and
  re-applies the preference from a `MutationObserver` as the parser hands over
  each batch of the document: an observer callback is a **microtask**, so it runs
  before the browser's rendering step and the cells are already `display:none`
  in the first frame that contains them. Only re-reads and re-applies happen
  during the parse — the Columns bar and the drag handles are still built at
  `DOMContentLoaded`, by which time the header they anchor to exists — and the
  pass is skipped outright for a screen with nothing hidden, which is most of
  them. The server still knows nothing about `localStorage`, and with JavaScript
  disabled the grid still shows every column.
- **Hiding never removes a cell** — the script only sets `display`. The row still
  holds every column, so sorting, the CSV/XLS export and the column count stay
  exactly what the server rendered.
- The menu lists **data columns only**. The bulk-select checkbox column (Leave
  Records, Address Book) and the Actions column are excluded outright and cannot
  be hidden or resized: the checkbox header has no text, which used to make it
  surface as a meaningless `Column 2`, and Actions holds the row's commands. At
  least one data column always stays visible — hiding them all brings the first
  one back rather than showing a grid of buttons with no data behind it.
- Labels are written with `textContent`, never `innerHTML`, because they come
  from the server. `labelOf()` reads `th.textContent`, which sees through the
  `<a>` that `sort_header()` wraps around a sortable label; walking only direct
  text nodes is what produced the `Column 2` names in the first place.

The dropdown is rendered **into the page header**, on the same row as the
Print/Excel buttons, rather than under the filter bar. `anchorFor()` fills the
header's `.btn-toolbar`, or — when a screen has no actions at all — `.page-head`
itself, which is `justify-content: space-between` and so still puts the control
on the title's line at the right. Address Book needs that second case: all three
of its header buttons sit behind `can('drivers.create' | 'drivers.import' |
'drivers.export')`, so a role holding none of them renders a bare header. Only a
screen with no header whatever reverts to the old position above the grid, so
the control can never disappear. In the header the menu opens inwards
(`dropdown-menu-end`) so it cannot run off the right edge, and the bar is pinned
with `flex: 0 0 auto` so it cannot shove the neighbouring buttons.

With JavaScript disabled every column shows at its natural width — which is what
the server rendered anyway.

### Reporting

- Four reports — **Leave**, **Return**, **Branch** and **Agency** — each with
  filters, an on-screen table, CSV and Excel export, and print styles. The
  Branch and Agency Excel button is built from the action URL, which already
  carries the base path — running it through `url()` a second time produced
  `/lms/lms/reports/agency` and a 404.
- **Leave and Return paginate** through the same `pagination()` every list
  uses: "Showing 1–25 of 28" counts the whole report (not the page), the links
  keep the filters already applied, and the rows-per-page select falls back to
  submitting its form without JS. The CSV/Excel export deliberately bypasses
  the window and carries **every** row, so a file can never be a silently
  truncated report. Return's **on time / late** badges are likewise counted
  across the whole report — computed in SQL over the identical WHERE — because
  badges in the heading that shrank as you paged would be describing the page
  while claiming to describe all 28.
- A printable **dashboard report**, exported as PDF in the browser via html2pdf.
- Report settings (target leave percentage, pending LWD window) are editable and
  self-heal if missing.

### Driver capacity planning

- **Day-by-day first, monthly second** — `CapacityPlanningService` walks the
  calendar one day at a time (active, on leave, available, both percentages,
  returns, leave starts, resignations, overdue) and aggregates those daily
  rows into monthly averages and peaks. It never counts applications
  submitted per month.
- **Two clearly separated KPI groups, three cards to a row** — a **Current
  Status** snapshot (Active Drivers, On Leave, On Leave %) headed with today's
  date, and a **Forecast** group headed with the selected range (average
  active, average on leave, peak on leave and %, expected returns, expected
  resignations). Averages round to **whole drivers** — no decimals on the
  cards or in the monthly table. Snapshot figures never move with the date
  range — they
  answer *today* even when the range starts after it or ends before it (a
  range of 30 Sep → 30 Nov opened on 29 Sep still shows who is away on 29
  Sep); forecast figures never show a moment-in-time number, so management
  can no longer read "Active Drivers Today" as October's capacity.
- **Forecast and Actual modes** — forecast follows the expected return (the
  expected return day counts as the return day); Actual uses the real
  arrival date where one is recorded and keeps the overdue days between the
  two visible. A leave past its expected return with no arrival recorded
  stays away and is flagged overdue — no return date is ever invented.
  **Expected Returns counts returns still to happen**: a driver whose arrival
  is already recorded has returned and is excluded (an expected return that
  happened is no longer expected), so the card, the day-by-day and monthly
  columns, and the movement table all count the same set — only past-due
  returns, which the dashboard keeps waiting for, can make the two screens
  differ. A **`planned` record is excluded from that set too**: a trip that
  has not begun has no arrival to expect, so it produces no return on its
  expected-arrival date and no return event in Capacity Movement (its
  *Leave start* still shows — the trip is on the books, it simply has not
  started). The dashboard's Expected Returns drops the same rows, so the two
  screens stay in step.
- **Scope filters** — date range, branch, agency and leave type. Branch and
  agency filter the denominator *and* the numerator; a leave type filter
  changes what "On leave" shows but never how many drivers exist, so
  Available still subtracts every driver away on any leave. Every event family
  follows that filter — leave starts, expected returns, expected leave **and
  the resignation exits** — so filtering to Annual Vacation no longer lists
  *Resignation last working date* rows beside it. The population walk is the
  deliberate exception: Active still drops for those exits, because a leave
  type filter decides what the view shows, never how many drivers exist.
- **Planned leave counts as unavailable capacity** — a `planned` record is
  subtracted from Available and added to On leave from its leave start date
  through the day before its expected return, exactly like a leave that has
  started (the trip is planned, the absence is real); it appears in the
  drill-down with a **Planned** chip and in Capacity Movement, while today's
  cards stay untouched until the trip actually begins. Cancelled and Not
  Returned records stay out, as always. The Forecast Data Quality panel states
  how many planned trip-days fall inside the range so the dip never reads as a
  bug.
- **The population is date-aware** — a driver deactivated with a Last Working
  Date stays in *Active Drivers* through that date and leaves the head-count
  the day after (the same exit an approved resignation filing has), so a
  deactivation never erases dates the driver was still working. Their leave
  before the date keeps counting; leave after it is outside the population and
  drops out of the numerator with the denominator.
  - **Pending resignations count too** — this is a forecast, so a resignation
    that has been filed with a last working date on file is taken as one that
    will happen: `pending` and `approved` both shrink Active from the day
    after their last working date, and both appear in Capacity Movement as
    *Resignation last working date*. Only cancelled and rejected change
    nothing. The exit matches the leave type name (Resignation / Terminated /
    Absconded) and never `leave_records.last_working_date`, which is stamped
    on ordinary vacations too.
- **Capacity Movement says which branch, leave type and status** — every row
  carries **Branch** (the snapshot on the row, never the driver's current
  branch), **Leave Type** and **Status** next to Date, Event, Driver, Badge and
  Impact: a record status for starts and returns (`planned`, `on_leave`, …) and
  the approval decision for filings (`pending`, `approved`, …), rendered as the
  same badge the rest of the app uses.
- **Daily chart with a series selector** — the Daily Trend card keeps one
  trend format while a dropdown switches between **On-Leave %** (default,
  titled *Daily Driver On-Leave %*), **Expected Returns / Returns**
  (titled *Daily Expected Returns / Returns*) and **Expected Leave**
  (titled *Daily Expected Leave* — the same figures as the Expected Leave
  column, so the movement and the trend can never disagree). The card title,
  Y-axis label, tooltip wording and scale follow the selection; the date range
  and forecast-mode indicators never move with it.
  Hovering the **On-Leave %** line reports **both** the share and the
  headcount behind it — *6.19% of drivers on leave* on one line, *28 drivers
  on leave* on the next — because a percentage with no fleet size anywhere on
  the screen could be any number of people. The count ships in the same
  payload as the percentage (`CAPACITY_DATA.onLeave`), is pluralised, and the
  tooltip falls back to the share alone if a payload ever arrives without it.
- **Capacity Movement, day-by-day table and monthly summary** — the two
  tables page independently: Capacity Movement at the standard 10 / 25 / 50 /
  100 / 250 rows, the day-by-day table **defaults to 10 rows** like the
  movement list (25 and 31 stay available, and 31 puts a whole month
  on one page); the daily table shows one month at a time with every calendar
  day present; each day opens the drivers behind it, linked to their profiles.
- **Expected Leave** — all three of those views (and their CSV / Excel
  exports) also carry applications that are *expected to leave*, dated the
  **day after their last working date** (44 of the 62 records in the data
  start exactly then, so the event keeps its day and simply becomes "Leave
  start" once the application becomes a record). **Included:** approval status
  **pending**, and **approved whose leave status is Not Started** — with
  **resignations counted like any other leave type**. **Excluded: only
  cancelled and rejected** approval statuses — plus an approved whose leave
  status is *not* Not Started (the leave began or ended, so its record tells
  the story), plus any trip that already exists as a leave record (linked, or
  the legacy unlinked pair covering the same dates), so one trip never appears
  twice. **One application, one movement row:** Capacity Movement raises
  *Expected Leave* only where its **+1 Expected** impact is actually
  projected. An application with no expected return - Resignation and
  Terminated, whose arrival `LeaveApplication` drops - can never be placed,
  and that departure is already in Movement as *Resignation last working
  date* **-1 Active**, so no second row is raised beside it (14 of the 44
  Expected Leave rows in the data used to say this twice). The day-by-day
  column and the monthly sum still count those departures: they report what
  is due to leave on a day, not a change in the population. Branch, agency
  and leave-type filters apply, so Movement carries the
  three families side by side: **expected return/arrival, expected leave and
  resignations**. The impact reads **+1 Expected** for the event itself; from
  that day on the trip is then **projected as away** through the day before
  its expected return (*always project*): On Leave, Available, the day rows,
  the trend chart, the monthly summary and the forecast cards all include
  pending absences, while **Active never moves for an expectation** and an
  application without an expected return cannot be placed (the data-quality
  panel states the projected trip-days).
- **A day's figures do not depend on where the range starts.** Leave records are
  loaded on *overlap* — a trip that began before the window but runs through it
  still counts — and expectations are loaded by the same rule. They used to be
  matched on their **start** day instead, so moving the range start changed a
  day sitting inside both ranges: 1–31 October and 5–31 October each reported
  5 October differently (28 drivers / 6.18% against 27 / 5.96%) because one
  pending trip had begun on 2 October. Capacity Movement still raises a *start*
  event only when that start falls inside the range, so loosening the fetch
  cannot add a row dated before the window.
- **Reconciles with the dashboard by construction** — the dashboard counts a
  driver as away until the return is *recorded*, this forecast counts the
  expected return day as the return day. The "Dashboard comparison"
  data-quality line states both numbers: dashboard = this page's leave
  records + unconfirmed returns (projected expectations are stripped from
  that comparison and stated in their own data-quality line). "Leave Starts"
  is the same figure as the dashboard's
  leave trend (trips beginning), so range figures foot as well. Expected
  Returns counts only returns still to come — the card footer simply reads
  *Expected return from leave* — and the "Dashboard arrivals comparison"
  line spells out both numbers: the dashboard is a *return not yet
  recorded* worklist (it keeps past-due rows and drops confirmed ones),
  while this card excludes drivers who already returned and shows misses
  as Overdue instead of as a return. Expected Resignations matches
  "Resignation Applications" exactly: both count every filing that is not
  cancelled (pending and approved combined, dated on the last working date),
  and the card footer foots its number as *Total Applications · approved ·
  pending*.
- **Leave Slots to N%** — the monthly summary answers "how many more
  approvals can be granted this month?" in one column. It is that share of
  the month's *average active* fleet rounded **down** (a fraction of a driver
  cannot be approved — the same rule as the dashboard's leave-policy card)
  minus the month's **peak** on-leave count rather than its average, so the
  figure still holds on the month's busiest day instead of only on an average
  one, and floored at 0. A month at or past its ceiling reads 0 and is
  coloured red. The percentage is the `leave_percentage` setting, so the
  header and both exports name whatever is configured instead of hard-coding
  7 (e.g. *Leave Slots to 7%*); with no ceiling set the column still renders,
  labelled *Leave Slots* and showing an em dash — nothing is reported as
  limited until a limit exists. It reads the same setting as the dashboard
  report's *approvals of headroom*, so the two can never quote different
  ceilings for the same day.
- **Forecast Data Quality** — real counts of invalid ranges, applications
  without an expected return, closed records, leave on drivers outside the
  head-count, overlapping leave, missing branch/agency, out-of-window joining
  dates and the planned trip-days counted as unavailable. Zero reads
  "No issues", never an invented warning. Shown to the **Administrator role
  only**, even when the page itself has been granted to someone else.
- **Percentages read at two decimals** — every percentage on the dashboard,
  the capacity cards, the day-by-day and monthly tables and the trend chart is
  computed at two decimals and printed trimmed:
  **5.75%** stays 5.75%, **5.70%** reads 5.7%, a whole number stays 45%. One
  helper (`pct_label()` in PHP, `pctText()` in the chart) formats all of them,
  so the chart and the card above it can never disagree about the same day.
- **Excel on every table** — the day-by-day grid (its **CSV button was
  dropped** as redundant - the Excel download covers it), the monthly summary,
  and now the whole **Capacity Movement** list with Date, Event, Driver,
  Badge, Branch, Leave Type, Status and Impact. Same filters as the screen,
  print layout, RBAC on `reports.view` — no new permission row, no schema
  change.
- **Rows-per-page sits next to the page links** — the movement selector moved
  out of the card header to immediately after its pagination numbers, where it
  acts (changing it restarts at page 1).
- **The drill-down pages, and only ever one date** — the eye on a day-by-day
  row opens *Drivers on leave (date)* for **that date alone**, never for the
  whole selected range, and pages through it inside the modal with the same
  *Page 1 of 3 · 24 row(s)* wording the other drill modals use, a `#` index
  column, and a sub-heading carrying the date and driver count. The API slices
  server-side, so the browser never downloads rows it will not draw — while
  the **Excel** button beside Close deliberately downloads the **whole day**.
- **One status palette for the page and the modal** — the chips in *Drivers on
  leave* are built from `status_badge_class()`, the same helper Capacity
  Movement's `status_badge()` reads, so a driver recorded as `on_leave` is the
  same blue in both places (it used to be blue on the page and amber in the
  modal). They carry the same rounded pill and glyph as well, and the dialog
  is `modal-xl`, so seven columns of dates plus Status fit without wrapping
  the chip onto a second line.

### Settings

- Company profile, date format, page title, return window.
- Maintenance of **leave types**, **branches** and **agencies**.
- The three configurable **option lists**, under **Settings → Application**:
  Employment Status and Operational Status as `value|Label` pairs, and
  **Destination Country** as a plain comma-separated list of country names.
  All three are textareas with the worked example underneath, validated whole
  (a bad edit is refused rather than half-saved) and seeded by the screen
  itself with `INSERT IGNORE`, so an install that received the patch files
  without running a migration still gets an editable field — and never
  overwrites an admin's tuned list. Destination Country must stay under 500
  characters, because `settings.setting_value` is `VARCHAR(500)` and the save
  truncates there.
- **Users, roles and permissions** administration.
- **Backup and restore**, sample/demo data reset and removal.
- **Audit log** of every recorded action, under **Settings → Audit Log**
  (gated on `audit.view` — Administrators and HR; Operations and Viewer get a
  403) — it answers *who changed this, when, and what it said
  before and after* for every register in the system:

  | What changed | Action(s) | Table |
  |---|---|---|
  | Leave application created / edited / decided / deleted | `leave-application-created`, `-updated`, `-status`, `-deleted` | `leave_applications` |
  | Leave record created / edited | `leave-created`, `leave-updated` | `leave_records` |
  | Marked returned | `driver-returned` — status, arrival date **and the return remarks** | `leave_records` |
  | Cancelled | `leave-cancelled` — with the reason | `leave_records` |
  | Not returned | `leave-not-returned` — reason, date, who confirmed, remarks | `leave_records` |
  | Closed status re-opened | `leave-status-changed` — before/after, remarks travel with it | `leave_records` |
  | Driver added / edited / activated / deactivated / imported / bulk-deleted | `driver-created`, `driver-updated`, `driver-activated`, `driver-deactivated`, `drivers-imported`, `bulk-delete` | `drivers` |

  Filter by **Table**, **Action** or **From/To**, or search on action, table or
  username; every row shows the time, the account, the record id and the
  before/after JSON. **Current Leave** has no log of its own — it is a view
  over `leave_records`, so its history is the `leave_records` rows above. The
  notes themselves live on the record (`return_remarks`,
  `not_returned_reason` / `_remarks` / `_date` / `_by`, `cancelled_reason`)
  and are shown on the record's detail page.

---

## Leave status model

`App\Core\Status` is the single source of truth. A record's status is **derived
from its dates plus the actual arrival date**, never invented by the user.

| Status | Meaning | How it arises |
|---|---|---|
| `planned` | Leave starts in the future | `leave_start > today` |
| `on_leave` | Away, expected back on schedule | start ≤ today, expected ≥ today, no arrival |
| `returned` | Driver is back | `actual_arrival_date` is set |
| `overdue` | Past expected return, not yet marked returned | `expected < today` and no arrival |
| `cancelled` | **Manual** — record cancelled | Set by a user |
| `not_returned` | **Manual, terminal** — management closed the case as a non-return | Set by a user with a reason, a date, who confirmed it and remarks; closes the leave with no arrival date |

Important properties:

- **`cancelled` and `not_returned` are terminal.** `Status::sync()` never
  overwrites them, and they are excluded from every "still out there" count —
  nobody is coming back, so they are not returning, overdue or expected.
- **No cron job.** `Status::sync()` runs one indexed `UPDATE` on page load,
  which is what makes midnight rollover, overdue detection and the KPI cards
  correct by themselves.
- All comparisons use the application time zone (`Asia/Riyadh`).

Leave **applications** carry two independent axes:

| Axis | Values |
|---|---|
| Approval decision (`status`) | `pending`, `approved`, `rejected`, `cancelled` |
| Leave progress (`leave_status`) | `not_started`, `on_leave`, `returned`, `not_returned`, `closed` |

### One record, three counting rules

The same leave is counted three different ways, on purpose — a label should
say which rule a number uses:

- **Operational (dashboards and registers)** — the derived status wins. A
  driver is *On Leave* from `leave_start ≤ today` while the expected return is
  `≥ today`, and becomes *Overdue* the day after the expected return passes
  until an arrival is recorded. The expected-return day itself still counts as
  on leave: someone expected back today who has not been marked returned is
  still counted as away — not yet returned, not yet overdue.
- **Forecast (Capacity Planning)** — planned absences are projected forward by
  their expected dates so management sees tomorrow's capacity rather than
  today's worklist; `cancelled` and `not_returned` are terminal and are never
  projected.
- **Historical / actual** — the recorded `actual_arrival_date` anchors the
  return; closed statuses stay excluded from every live count.

Reconciliation example: a driver is expected back today and no arrival has
been recorded → status `on_leave`, counted in *Currently On Leave*, still
listed in Expected Returns — until the return is marked, or midnight rolls the
status to *Overdue*.

### Exits vs absences

Two different events, never mixed in one KPI:

- **Absence** — the driver is away but still employed: planned / on-leave /
  overdue / returned records consume capacity over their date span.
- **Employment exit** — Resignation, Terminated and Absconded applications
  deliberately carry **no expected return date** (the form hides it and the
  save skips it; `LeaveApplication::NO_RETURN_PATTERNS` covers all three), so
  they can never be projected as an ordinary absence. They reduce the active
  population from the day after the last working date and appear in the monthly
  **departures** count: one headcount exit plus one departure event — never
  on-leave days, never counted twice.

### Closing a leave as Not Returned

The two registers now share the same term — `not_returned` — because they
describe the same thing. The operational reading is deliberate: the system does
**not** claim the driver will never return, only that management has closed the
overdue case as a non-return.

```
Overdue
   ↓  management confirms the driver will not return
Not Returned
```

Closing a leave always records four facts together:

| Column | Meaning |
|---|---|
| `not_returned_reason` | One of: **Resigned**, **Terminated**, **Transferred**, **Did not return from vacation**, **Other** |
| `not_returned_date` | The date the decision was taken (never in the future, never before the leave started) |
| `not_returned_by` | The signed-in user — taken from the session, never from the request |
| `not_returned_remarks` | The notes that make it auditable |

All four are enforced in `LeaveRecord::validateNotReturned()` on both the web
route and `POST /api/leave/not-returned`, so the modal cannot be skipped by
calling the endpoint directly. The reason is a **closed list** rather than free
text precisely so "why did drivers not come back this quarter?" answers with a
count instead of fifty spellings of "resigned". `not_returned_reason` is
indexed and shown as its own column on the Leave Report and its exports.

> **Schema note:** the Not Returned closure columns, FK and index ship in
> `schema.sql` for fresh installs and are already applied on the live server.
> The one-time `migrate-not-returned.sql` upgrade file was removed as
> redundant; see [Database changes](DEPLOYMENT.md#database-changes).

**Seeing the closure.** The leave record's own page shows all four facts in
the status banner - when it was closed, for which reason, the remarks, and
**who confirmed it** (resolved to the user's name, not a raw id).

**Correcting a mistaken closure.** Because `Status::sync()` deliberately never
touches a closed record, nothing else in the app can ever move one again. An
administrator therefore gets a **Change Status** action - in the Leave Records
action menu and on the record's own page - gated by `settings.system`, the
permission administrators hold by default (so no new permission row is
needed):

| Target | What the modal asks for |
|---|---|
| **Returned** | the actual arrival date (defaults to today) |
| **On leave** (leave extended) | optionally a new expected return date; *required* when the old one has passed |
| **Planned** (trip never started) | nothing - refused unless the dates say so |

The server only accepts a status the dates will still derive on the next page
load - otherwise `sync()` would silently flip it back - and it names the date
in the way. Reopening clears the closure facts that no longer apply (they are
kept in the audit log under `leave-status-changed`), so a returned record can
never still claim the driver "Resigned".

---

## Reporting and export

- **CSV (UTF-8 with BOM)** — the address book, leave lists, applications and
  every report.
- **Excel (`.xls`)** — every screen that offers CSV also offers Excel: the
  address book, leave lists, applications, the capacity tables, the driver
  dashboard and all reports. SpreadsheetML (Excel 2003 XML), never a renamed
  HTML table, so Excel opens it with no format or extension warning.

  Every sheet is laid out for reading rather than dumped raw:

  | Row | Contents |
  |---|---|
  | 1 | report title — 16pt bold, merged across every column |
  | 2 | `Exported 02 Oct 2026, 21:09` |
  | 3 | column headings — bold, filled, centred, wrapped |
  | 4+ | the data, bordered |

  Columns are fitted to their own content (clamped to a 61–425px range, about
  8–60 characters), header and data cells carry a full four-sided border, and
  gridlines are off so those borders are the only rules on the page.

  The fit is computed while the file is written rather than left to Excel's
  `ss:AutoFitWidth`, which SpreadsheetML does not apply on open — and the
  measurement deliberately skips the title row, so a long report name cannot
  blow the first column open. Widths are summed as **Calibri glyph advances in
  pixels** (then converted to the points `ss:Width` asks for, at 0.75pt per
  pixel), because a character count answers the wrong question: 37 capitals
  need 349px while a 19-character timestamp needs only 128px, so counting
  characters clips long names and wastes space on dates.
- **JSON** — `?export=json` on the leave lists (not exposed as a button).
- Exports always reflect the **current filter set**, so a download matches what
  is on screen.
- **Print** — print-friendly styles on all four reports (save as PDF from the
  browser's print dialog).
- **PDF** — the **dashboard summary only**, generated client-side by
  html2pdf.js. The four reports do not produce a PDF themselves.
- Everything is **streamed straight to the browser**; nothing is written to
  `storage/exports/` (that directory is created but unused).

---

