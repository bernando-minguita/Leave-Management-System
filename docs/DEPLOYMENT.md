# Deployment and patching

> Part of the LMS documentation. The stable overview, quick install and
> configuration live in [README.md](../README.md).

## Deployment and patching

Production hosts are often managed **without command-line access**, so changes
are shipped as zip patches applied through the file manager.

### What a patch contains

Full files at their real paths — not diffs:

```
app/views/dashboard/index.php
assets/js/dashboard.js
tools/dashboard-stacked-test.php
...
```

Extract at the project root, overwriting. Because each entry is the complete
file, patches are idempotent and order-independent: applying two patches that
touch different files is safe, and re-applying a patch is a no-op.

### Before shipping

*Build side — this section runs where the repository, PHP CLI and the test
suite live (the development machine), not on the live server.*

1. `php tools/lint.php` — must report 0 errors.
2. Run the relevant test files.
3. Rebuild and re-verify the archive so nothing ships that did not verify.

### After applying

Re-run at least the smoke test and any test covering the touched area.

### Release checklist

Steps 1 and 4–8 run **on the target** — the live server, which has no command
line, so everything needed there must already be inside the zip. Step 3 runs
**on the build side** (the development machine: git, PHP CLI and a restored
copy of production); step 2 is just reading the package name.

1. **Back up first** — a database dump (Settings → System → Backup) and a copy
   of the application files.
2. **Note the current version** — the patch filename is the version reference;
   a schema-version table does not exist yet (recorded gap).
3. **Pre-checks on a restored copy** — lint, `php tools/rbac-verify.php`, and
   the [release gate](TESTING.md#release-gate) suites for the touched area.
4. **Apply the patch** — upload the zip, extract over the project root.
5. **Run the SQL and make the config edits this change requires.** Nothing in
   the zip executes on its own: SQL runs by hand in phpMyAdmin and
   `app/config/config.php` is edited directly on the server. What each
   particular change needs is recorded in its commit message.
6. **Delete `setup.php`** if it still exists on the server: it self-deletes
   after a successful install, but an aborted install leaves it behind.
7. **Post-checks** — sign in, dashboard KPIs foot, one create → approve →
   return cycle, one export, and `rbac-verify` again if roles changed.
8. **Rollback** — re-upload the pre-deploy file copy; restore the database
   dump if a schema change was applied.

### Database changes

`database/schema.sql` is the single source of truth for structure: a fresh
install reads it directly (`php tools/install.php`), and every schema change
ships folded into it. For a database that already exists, the same change
ships as a **new** `database/migrate-*.sql` file inside the patch - written to
run safely whether the column already exists or not - and is applied once
through phpMyAdmin, since the host has no CLI.

Changes already applied to the live server had their one-time migration files
removed as redundant; this table records what each did:

| Former migration | What it did |
|---|---|
| `migrate-not-returned.sql` | Not Returned closure columns |
| `migrate-driver-statuses.sql` | `drivers.status` ENUM -> VARCHAR, adds `drivers.operational_status`, inserts the two Settings option lists |
| `migrate-capacity-permission.sql` | Adds `reports.capacity` and grants it to the Administrator role only |
| `migrate-leave-records-nullable.sql` | `leave_records.expected_arrival_date` becomes optional (NULL) so a Resignation / Terminated / Absconded record can be saved without an Expected Return Date |
| `migrate-leave-closed.sql` | `leave_applications.leave_status` gains `closed`, the derived value for an approved departure whose last working day has passed |
| `migrate-leave-status.sql` | Creates `leave_applications.leave_status` when the table predates it, or widens it to five members when it does not |
| `migrate-driver-lwd.sql` | Adds `drivers.last_working_date` + its index (the deactivation end date); PHP twin: `tools/migrate-driver-lwd.php` |
| `migrate-leave-application-link.sql` | Adds `leave_records.leave_application_id`, its unique key (one application -> one record) and the foreign key; PHP twin: `tools/migrate-leave-application-link.php` |

---

