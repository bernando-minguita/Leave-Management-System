<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Lookup;

/**
 * Settings: leave types, branches, agencies, system settings, users and the
 * audit trail. Everything here is admin-only.
 */
final class SettingsController
{
    public function index(array $params = []): void
    {
        // The hub itself renders nothing: send the caller to whichever
        // settings screen their permission actually opens.
        redirect(can('settings.system') ? 'settings/system' : 'settings/users');
    }

    /* ------------------------------------------------------- delete one -- */

    /**
     * Delete a single branch / agency / leave type (admin only).
     *
     * Anything still referenced is refused with a count instead of silently
     * blanking the link - a driver must never quietly lose its branch, and a
     * leave type with history is protected by the foreign key anyway.
     */
    public function destroy(array $params = []): void
    {
        csrf_check();

        $kind = (string)($_POST['kind'] ?? '');

        // Scoped by WHAT is being deleted, not by who is asking.
        require_permission($this->permissionForKind($kind) ?? 'settings.system');

        $specs = [
            'branch' => [
                'table' => 'branches', 'noun' => 'Branch', 'label' => 'branch_name',
                'refs'  => ['drivers', 'branch_id', 'drivers'],
            ],
            'agency' => [
                'table' => 'agencies', 'noun' => 'Agency', 'label' => 'agency_name',
                'refs'  => ['drivers', 'agency_id', 'drivers'],
            ],
            'leave_type' => [
                'table' => 'leave_types', 'noun' => 'Leave type', 'label' => 'name',
                'refs'  => ['leave_records', 'leave_type_id', 'leave records'],
            ],
        ];

        if (!isset($specs[$kind])) {
            flash_set('danger', 'Unknown record.');
            redirect('settings/system');
            return;
        }

        $spec   = $specs[$kind];
        $id     = (int)($_POST['id'] ?? 0);
        $return = $this->safeReturn((string)($_POST['return_to'] ?? ''), 'settings/' . $kind);

        $row = Database::one('SELECT * FROM `' . $spec['table'] . '` WHERE id = ?', [$id]);

        if ($row === null) {
            flash_set('warning', 'That ' . strtolower($spec['noun']) . ' no longer exists.');
            redirect($return);
            return;
        }

        [$usedTable, $usedColumn, $usedNoun] = $spec['refs'];

        $inUse = (int)Database::scalar(
            'SELECT COUNT(*) FROM `' . $usedTable . '` WHERE `' . $usedColumn . '` = ?',
            [$id]
        );

        if ($inUse > 0) {
            flash_set(
                'danger',
                $spec['noun'] . ' "' . $row[$spec['label']] . '" is used by ' . $inUse . ' '
                . $usedNoun . ' and cannot be deleted. Reassign them first.'
            );
            redirect($return);
            return;
        }

        Database::run('DELETE FROM `' . $spec['table'] . '` WHERE id = ?', [$id]);

        audit_log($kind . '-deleted', $spec['table'], $id, $row, null);

        flash_set('success', $spec['noun'] . ' "' . $row[$spec['label']] . '" deleted.');
        redirect($return);
    }

    /**
     * Only accept an internal path as a return target (guards against an
     * absolute URL being posted in return_to).
     */
    private function safeReturn(string $path, string $fallback): string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//')) {
            return $fallback;
        }

        return $path;
    }

    /* ----------------------------------------------- delete sample data -- */

    /**
     * Admin: delete every row tagged "SAMPLE DATA" and leave the system empty.
     *
     * POST /settings/remove-sample-data - the UI equivalent of
     * tools/remove-sample-data.php. Unlike resetDemo() it does NOT re-seed, so
     * it is the button to press before entering your own drivers.
     *
     * Needs admin rights, a CSRF token and the typed word "DELETE".
     */
    public function removeSampleData(): void
    {
        require_permission('settings.system');
        csrf_check();

        if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'DELETE') {
            flash_set('danger', 'Type DELETE to confirm removing the sample data.');
            redirect('settings/system');
            return;
        }

        try {
            $removed = $this->purgeSampleData();
        } catch (\Throwable $ex) {
            log_fatal('remove sample data failed: ' . $ex->getMessage());

            flash_set('danger', 'Could not remove the sample data. (' . $ex->getMessage() . ')');
            redirect('settings/system');
            return;
        }

        audit_log('sample-data-removed', 'drivers', null, $removed, ['source' => 'admin-panel']);

        flash_set(
            'success',
            'Sample data removed: ' . $removed['leave_records'] . ' leave records, '
            . $removed['leave_applications'] . ' leave applications, '
            . $removed['drivers'] . ' drivers and ' . $removed['agencies']
            . ' agencies. Nothing was re-seeded.'
        );

        redirect('settings/system');
    }

    /**
     * Delete the SAMPLE DATA rows, in foreign-key order.
     *
     * Only drivers, their leave records/applications and the agencies are
     * tagged, so those are the rows that go. Branches, leave types and
     * application settings are reference data the system still needs and are
     * deliberately kept.
     *
     * @return array<string, int> table => rows deleted
     */
    private function purgeSampleData(): array
    {
        $sampleDriverIds = array_map(
            static fn (array $row): int => (int)$row['id'],
            Database::all("SELECT id FROM drivers WHERE remarks LIKE '%SAMPLE DATA%'")
        );

        $in      = $sampleDriverIds === [] ? '0' : implode(',', $sampleDriverIds);
        $removed = [
            'leave_records' => 0, 'leave_applications' => 0,
            'drivers' => 0, 'agencies' => 0,
        ];

        // Both leave registers first: their driver FKs are ON DELETE RESTRICT.
        $removed['leave_records'] = Database::exec(
            "DELETE FROM leave_records WHERE driver_id IN ($in) OR remarks LIKE '%SAMPLE DATA%'"
        );

        if ($sampleDriverIds !== []) {
            $removed['leave_applications'] = Database::exec(
                "DELETE FROM leave_applications WHERE driver_id IN ($in)"
            );
            $removed['drivers'] = Database::exec("DELETE FROM drivers WHERE id IN ($in)");
        }

        // drivers.agency_id is ON DELETE SET NULL, so a driver you added
        // yourself simply loses the link instead of blocking the delete.
        $removed['agencies'] = Database::exec(
            "DELETE FROM agencies WHERE remarks LIKE '%SAMPLE DATA%'"
        );

        return $removed;
    }

    /* ------------------------------------------------- restore demo data -- */

    /**
     * Admin: wipe every seeded table and re-apply the sample-data files.
     *
     * POST /settings/reset-demo - needs admin rights, a CSRF token and the
     * typed word "RESET", because there is no undo.
     */
    public function resetDemo(): void
    {
        require_permission('settings.system');
        csrf_check();

        if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'RESET') {
            flash_set('danger', 'Type RESET to confirm restoring the seeded state.');
            redirect('settings/system');
            return;
        }

        $before = $this->tableCounts();

        try {
            $after = $this->restoreSeedData();
        } catch (\Throwable $ex) {
            log_fatal('restore seeded state failed: ' . $ex->getMessage());

            flash_set(
                'danger',
                'Could not restore the seeded state - the database may now be incomplete. '
                . 'Run php tools/install.php to rebuild it. (' . $ex->getMessage() . ')'
            );
            redirect('settings/system');
            return;
        }

        audit_log('system-reset', 'settings', null, $before, [
            'note'  => 'Restored the seeded demo state',
            'after' => $after,
        ]);

        flash_set(
            'success',
            'Seeded state restored: ' . $after['drivers'] . ' drivers, '
            . $after['leave_records'] . ' leave records, ' . $after['branches'] . ' branches, '
            . $after['agencies'] . ' agencies and ' . $after['leave_types'] . ' leave types.'
        );

        redirect('settings/system');
    }

    /**
     * Truncate every seeded data table and re-run the three seed files in the
     * same order tools/install.php uses, so the result matches a fresh install.
     *
     * `users` is deliberately left alone: the account you are signed in as must
     * survive, and leave_records.created_by still references it.
     *
     * @return array<string, int> table => rows now present
     */
    private function restoreSeedData(): array
    {
        // TRUNCATE also resets AUTO_INCREMENT so ids line up with a fresh
        // install. It is DDL (implicit commit) and MySQL rejects it while a
        // foreign key points at the table, hence the checks toggle.
        Database::exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ([
                'audit_logs', 'leave_applications', 'leave_records', 'drivers',
                'branches', 'agencies', 'leave_types', 'settings',
            ] as $table) {
                Database::exec('TRUNCATE TABLE `' . $table . '`');
            }
        } finally {
            Database::exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $root = dirname(__DIR__, 2);

        foreach (['seed.sql', 'seed-drivers.sql', 'seed-leave.sql'] as $seedFile) {
            $path = $root . '/database/' . $seedFile;

            foreach (Database::statementsFromFile($path) as $statement) {
                try {
                    Database::exec($statement);
                } catch (\Throwable $ex) {
                    // An overlapping row on a repeat run is fine; anything else is fatal.
                    if ((string)$ex->getCode() === '1062') {
                        continue;
                    }

                    throw $ex;
                }
            }
        }

        return $this->tableCounts();
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];

        foreach ([
            'branches', 'agencies', 'leave_types',
            'drivers', 'leave_records', 'leave_applications',
            'settings', 'users', 'audit_logs',
        ] as $table) {
            $counts[$table] = (int)Database::scalar('SELECT COUNT(*) FROM `' . $table . '`');
        }

        return $counts;
    }

    /* ------------------------------------------------- bulk delete ----- */

    /**
     * Admin: wipe the whole address book and every leave record.
     *
     * POST /settings/bulk-delete - unlike removeSampleData() this ignores the
     * SAMPLE DATA tag and empties the operational data outright, while keeping
     * reference data (branches, agencies, leave types), the application
     * settings and every user account.
     *
     * Needs admin rights, a CSRF token and the typed word "PURGE".
     */
    public function bulkDelete(): void
    {
        require_permission('settings.system');
        csrf_check();

        if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'PURGE') {
            flash_set('danger', 'Type PURGE to confirm deleting the address book and every leave record.');
            redirect('settings/system');
            return;
        }

        try {
            $removed = $this->purgeAllRecords();
        } catch (\Throwable $ex) {
            log_fatal('bulk delete failed: ' . $ex->getMessage());

            flash_set('danger', 'Could not delete the records. (' . $ex->getMessage() . ')');
            redirect('settings/system');
            return;
        }

        audit_log('bulk-delete', 'drivers', null, $removed, ['source' => 'admin-panel']);

        flash_set(
            'success',
            'Bulk delete complete: ' . $removed['leave_records'] . ' leave records, '
            . $removed['leave_applications'] . ' leave applications and '
            . $removed['drivers'] . ' drivers removed. Branches, agencies, leave types, '
            . 'application settings and accounts were kept.'
        );

        redirect('settings/system');
    }

    /**
     * Delete every leave record, every leave application and every driver,
     * in foreign-key order.
     *
     * BOTH registers point at drivers with ON DELETE RESTRICT, so each has to
     * go before the drivers they point at - deleting drivers first is exactly
     * what produced the 1451 integrity error. Nothing else is touched.
     *
     * @return array<string, int> table => rows deleted
     */
    private function purgeAllRecords(): array
    {
        return [
            'leave_records'      => Database::exec('DELETE FROM leave_records'),
            'leave_applications' => Database::exec('DELETE FROM leave_applications'),
            'drivers'            => Database::exec('DELETE FROM drivers'),
        ];
    }

    /* --------------------------------------------- backup / restore --- */

    /** Tables a JSON backup carries, in the order they are re-inserted. */
    private const BACKUP_TABLES = [
        'users', 'branches', 'agencies', 'leave_types',
        'drivers', 'leave_records', 'leave_applications',
        'settings', 'audit_logs',
    ];

    /** Guard rail against a mis-picked file - 32 MB is far more than needed. */
    private const MAX_BACKUP_BYTES = 33554432;

    /**
     * Admin: download every application table as a JSON backup.
     *
     * GET /settings/backup - column names are kept verbatim so
     * settings/restore can put the file back unchanged. The result is a plain
     * JSON document that is safe to keep, diff or carry between installs.
     */
    public function backup(): void
    {
        require_permission('settings.system');

        $tables = [];
        $counts = [];

        foreach (self::BACKUP_TABLES as $table) {
            $rows = Database::all('SELECT * FROM `' . $table . '` ORDER BY `id`');

            $tables[$table] = $rows;
            $counts[$table] = count($rows);
        }

        $json = json_encode([
            'app'          => defined('APP_NAME') ? APP_NAME : 'LMS',
            'format'       => 1,
            'exported_at'  => now(),
            'table_counts' => $counts,
            'tables'       => $tables,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            View::error(500, 'The backup could not be encoded. (' . json_last_error_msg() . ')');
        }

        $slug     = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-',
            defined('APP_NAME') ? APP_NAME : 'LMS'), '-'));
        $filename = $slug . '-backup-' . date('Y-m-d') . '.json';

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');
            header('Content-Length: ' . strlen($json));
        }

        echo $json;
        exit;
    }

    /**
     * Admin: replace the database contents from an uploaded JSON backup.
     *
     * POST /settings/restore - the file must be one this app produced
     * (format 1). Every known table is cleared and re-inserted with foreign
     * key checks off, mirroring restoreSeedData(). Columns that do not exist
     * in the target table are ignored rather than executed, so a hand-edited
     * file cannot smuggle SQL through the import.
     */
    public function restore(): void
    {
        require_permission('settings.system');
        csrf_check();

        if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'RESTORE') {
            flash_set('danger', 'Type RESTORE to confirm importing the backup.');
            redirect('settings/system');
            return;
        }

        if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            flash_set('danger', 'Please choose a JSON backup file to upload.');
            redirect('settings/system');
            return;
        }

        $size = (int)$_FILES['backup_file']['size'];

        if ($size <= 0 || $size > self::MAX_BACKUP_BYTES) {
            flash_set('danger', 'That backup file is empty or larger than 32 MB.');
            redirect('settings/system');
            return;
        }

        $raw  = (string)file_get_contents($_FILES['backup_file']['tmp_name']);
        $data = json_decode($raw, true);

        if (!is_array($data)
            || (int)($data['format'] ?? 0) !== 1
            || !isset($data['tables'])
            || !is_array($data['tables'])
        ) {
            flash_set('danger', 'That file is not a valid ' . (defined('APP_NAME') ? APP_NAME : 'LMS') . ' JSON backup.');
            redirect('settings/system');
            return;
        }

        $before = $this->tableCounts();

        try {
            $restored = $this->applyBackup($data['tables']);
        } catch (\Throwable $ex) {
            log_fatal('backup restore failed: ' . $ex->getMessage());

            flash_set('danger', 'Could not import the backup. (' . $ex->getMessage() . ')');
            redirect('settings/system');
            return;
        }

        $after = $this->tableCounts();

        audit_log('database-restored', 'settings', null, ['before' => $before], ['after' => $after]);

        $summary = [];

        foreach ($restored as $table => $count) {
            $summary[] = $count . ' ' . str_replace('_', ' ', (string)$table);
        }

        flash_set('success', 'Backup imported: ' . implode(', ', $summary) . '.');

        redirect('settings/system');
    }

    /**
     * Clear every backed-up table, then re-insert what the file carries.
     *
     * @param array<string, mixed> $tables
     * @return array<string, int> table => rows inserted
     */
    private function applyBackup(array $tables): array
    {
        $restored = [];

        // Same reasoning as restoreSeedData(): with the checks off the wipe
        // and re-insert order stops mattering, so a child row may arrive
        // before the parent it points at.
        Database::exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            // Wipe first so importing a smaller backup really replaces what
            // is there instead of appending to it.
            foreach (self::BACKUP_TABLES as $table) {
                Database::exec('DELETE FROM `' . $table . '`');
                $restored[$table] = 0;
            }

            foreach (self::BACKUP_TABLES as $table) {
                $rows = $tables[$table] ?? null;

                if (is_array($rows)) {
                    $restored[$table] = $this->insertRows($table, $rows);
                }
            }
        } finally {
            Database::exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $restored;
    }

    /**
     * Insert decoded rows into $table, keeping only columns that really exist.
     *
     * @param array<int, mixed> $rows
     */
    private function insertRows(string $table, array $rows): int
    {
        $allowed = array_map(
            static fn (array $column): string => (string)$column['Field'],
            Database::all('SHOW COLUMNS FROM `' . $table . '`')
        );

        $inserted = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $columns = array_values(array_intersect($allowed, array_keys($row)));

            if ($columns === []) {
                continue;
            }

            $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ('
                 . implode(',', array_fill(0, count($columns), '?')) . ')';

            $params = array_map(static function (string $column) use ($row) {
                $value = $row[$column];

                // A JSON column may come back decoded - store it back as text.
                return is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE)
                    : $value;
            }, $columns);

            Database::run($sql, $params);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * Read validation errors that a previous redirect parked in the flash.
     *
     * NOTE: deliberately does NOT unset the flash - View::render() consumes it
     * via flash_take() so the success/warning message still reaches the page.
     * (Unsetting here used to silently swallow every success message.)
     *
     * @return array<string, string>
     */
    private function flashErrors(): array
    {
        $flash = $_SESSION['flash'] ?? null;

        return is_array($flash) && isset($flash['errors']) && is_array($flash['errors'])
            ? $flash['errors']
            : [];
    }

    /* ---------------------------------------------------- leave types --- */

    public function leaveTypes(array $params = []): void
    {
        require_permission('settings.leave_types');

        View::render('settings/leave-types', [
            'rows'   => Lookup::leaveTypes(false),
            'errors' => $this->flashErrors(),
        ], 'Leave Types', 'settings-leave-types', [
            ['label' => 'Settings'],
            ['label' => 'Leave Types'],
        ]);
    }

    public function branches(array $params = []): void
    {
        require_permission('settings.branches');

        View::render('settings/branches', [
            'rows'   => Lookup::branches(false),
            'errors' => $this->flashErrors(),
        ], 'Branches', 'settings-branches', [
            ['label' => 'Settings'],
            ['label' => 'Branches'],
        ]);
    }

    public function agencies(array $params = []): void
    {
        require_permission('settings.agencies');

        View::render('settings/agencies', [
            'rows'   => Lookup::agencies(false),
            'errors' => $this->flashErrors(),
        ], 'Agencies', 'settings-agencies', [
            ['label' => 'Settings'],
            ['label' => 'Agencies'],
        ]);
    }

    /* -------------------------------------------------------- system --- */

    public function system(array $params = []): void
    {
        require_permission('settings.system');

        // This screen owns a handful of keys that must exist before it can
        // render a field for them, so guarantee they are on file.
        $this->ensureDefaultSettings();

        // Seeding writes rows this request then reads back through Lookup
        // (the option-list usage counts): drop any cache an earlier read left.
        \App\Models\Lookup::forgetSettings();

        View::render('settings/system', [
            'settings' => Database::all('SELECT * FROM settings ORDER BY group_name, setting_key'),
            'errors'   => $this->flashErrors(),
            // How many records hold each configured option. Shown beside the
            // three lists, because removing an option is the one edit on this
            // screen that can strand data - see optionListUsage().
            'usage'    => $this->optionListSummary(),
        ], 'System Settings', 'settings-system', [
            ['label' => 'Settings'],
            ['label' => 'System Settings'],
        ]);
    }

    /**
     * User account administration - the sidebar's "Users" screen.
     *
     * Formerly the "User Accounts" tab on System Settings; it has its own
     * route so the entry can live in the sidebar and carry its own
     * permission (settings.users) instead of riding along with
     * settings.system.
     */
    public function users(array $params = []): void
    {
        require_permission('settings.users');

        View::render('settings/users', [
            'users'  => Lookup::users(),
            'roles'  => Database::all('SELECT slug, name FROM roles ORDER BY id'),
            'errors' => $this->flashErrors(),
        ], 'Users', 'settings-users', [
            ['label' => 'Settings'],
            ['label' => 'Users'],
        ]);
    }

    /**
     * Guarantee the settings this screen owns exist before it renders them.
     *
     * database/seed.sql covers a fresh install and tools/migrate-*.php cover
     * an existing one, but neither runs on its own. An install that received
     * the patch files without running anything therefore has no rows for
     * these keys: the report falls back to the hard-coded defaults in
     * Dashboard (target 0%, window 30 days) while report.php tells the admin
     * to change a setting this screen never showed - and Destination Country
     * would be a dropdown fed entirely by Lookup's shipped fallback, editable
     * nowhere.
     *
     * INSERT IGNORE keeps it idempotent - an admin's tuned value is never
     * overwritten - and the superseded key is dropped so the form cannot offer
     * a field nothing reads.
     */
    private function ensureDefaultSettings(): void
    {
        foreach ([
            ['leave_percentage',      '7',  'Target leave percentage (%)',        'operations'],
            ['pending_window_days',   '30', 'Pending LWD window (days)',          'operations'],
            // A PLAIN list, not value|Label - see Lookup::destinationCountries().
            ['destination_countries', Lookup::DESTINATION_COUNTRIES,
                'Destination country options (comma separated)',                   'operations'],
        ] as [$key, $value, $label, $group]) {
            Database::run(
                'INSERT IGNORE INTO settings (setting_key, setting_value, label, group_name)
                 VALUES (?, ?, ?, ?)',
                [$key, $value, $label, $group]
            );
        }

        Database::run("DELETE FROM settings WHERE setting_key = 'pending_window_months'");
    }

    public function audit(array $params = []): void
    {
        require_permission('audit.view');

        $filters = [
            'action'     => (string)($_GET['action'] ?? ''),
            'table_name' => (string)($_GET['table_name'] ?? ''),
            'q'          => trim((string)($_GET['q'] ?? '')),
            'date_from'  => (string)($_GET['date_from'] ?? ''),
            'date_to'    => (string)($_GET['date_to'] ?? ''),
        ];

        // Client-side paging: the audit trail ships whole so paging and the
        // rows-per-page select do not reload the settings screen.
        $result = AuditLog::paginate($filters, table_row_cap(), 0);

        View::render('settings/audit', [
            'rows'     => $result['rows'],
            'total'    => $result['total'],
            'filters'  => $filters,
            'actions'  => AuditLog::actions(),
            'query'    => $_GET,
            'tables'   => ['drivers', 'leave_records', 'leave_applications', 'branches', 'agencies', 'leave_types', 'settings', 'users'],
        ], 'Audit Log', 'settings-audit', [
            ['label' => 'Settings'],
            ['label' => 'Audit Log'],
        ]);
    }

    /** Agencies carry contact details, so they get their own handler. */
    /* --------------------------------------------------- roles & permissions -- */

    /**
     * Admin: the role list plus a permission matrix for editing one of them.
     * GET /settings/roles
     */
    public function roles(array $params = []): void
    {
        require_permission(['settings.roles', 'settings.permissions']);

        View::render('settings/roles', [
            'roles'       => Database::all(
                'SELECT r.*, (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count,
                               (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
                           FROM roles r ORDER BY r.is_system DESC, r.id'
            ),
            'catalogue'   => permission_catalogue(),
            'grants'      => self::permissionGrants(),
            'errors'      => $this->flashErrors(),
            'editingRole' => (int)($_GET['edit'] ?? 0),
        ], 'Roles & Permissions', 'settings-roles', [
            ['label' => 'Settings'],
            ['label' => 'Roles & Permissions'],
        ]);
    }

    /** Admin: the permission catalogue as stored in the database. */
    public function permissions(array $params = []): void
    {
        require_permission('settings.permissions');

        $rows = Database::all('SELECT * FROM permissions ORDER BY group_name, permission_key');
        $used = [];

        foreach (Database::all(
            'SELECT r.name AS role_name, p.permission_key
               FROM role_permissions rp
               JOIN roles r       ON r.id = rp.role_id
               JOIN permissions p  ON p.id = rp.permission_id'
        ) as $link) {
            $used[(string)$link['permission_key']][] = (string)$link['role_name'];
        }

        View::render('settings/permissions', [
            'rows' => $rows,
            'used' => $used,
            'errors' => $this->flashErrors(),
        ], 'Permissions', 'settings-roles', [
            ['label' => 'Settings'],
            ['label' => 'Permissions'],
        ]);
    }

    /** @return array<int, array<int, string>> role id => permission keys */
    private static function permissionGrants(): array
    {
        $grants = [];

        foreach (Database::all(
            'SELECT role_id, permission_key
               FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id'
        ) as $link) {
            $grants[(int)$link['role_id']][] = (string)$link['permission_key'];
        }

        return $grants;
    }

    /** Save a role's name/description and the permissions it grants. */
    private function saveRole(array $input): array
    {
        $id   = (int)($input['id'] ?? 0);
        $role = $id > 0 ? Database::one('SELECT * FROM roles WHERE id = ?', [$id]) : null;

        if ($role === null) {
            return ['ok' => false, 'message' => 'That role no longer exists.'];
        }

        $name = trim((string)($input['name'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'message' => 'A role name is required.', 'errors' => ['name' => 'A role name is required.']];
        }

        Database::run(
            'UPDATE roles SET name = ?, description = ? WHERE id = ?',
            [$name, trim((string)($input['description'] ?? '')) ?: null, $id]
        );

        // The submitted list is the whole truth for this role.
        $submitted = array_values(array_filter(
            array_map('strval', (array)($input['permissions'] ?? []))
        ));
        $valid     = array_values(array_intersect($submitted, permission_keys()));

        Database::run('DELETE FROM role_permissions WHERE role_id = ?', [$id]);

        foreach ($valid as $key) {
            $permissionId = (int) Database::scalar('SELECT id FROM permissions WHERE permission_key = ?', [$key]);

            if ($permissionId > 0) {
                Database::run(
                    'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                    [$id, $permissionId]
                );
            }
        }

        // An administrator editing their own role must see the effect at once.
        forget_permissions();

        audit_log('role-updated', 'roles', $id, ['before' => ['name' => $role['name']]], [
            'name' => $name, 'permissions' => $valid,
        ]);

        return ['ok' => true, 'message' => '"' . $name . '" now grants ' . count($valid) . ' permission(s).'];
    }

    private function saveAgency(array $input): array
    {
        $v = new \App\Core\Validator($input);

        $v->required('agency_name', 'Agency name')
            ->maxLength('agency_name', 'Agency name', 120)
            ->required('code', 'Agency code')
            ->maxLength('code', 'Agency code', 20)
            ->maxLength('contact_person', 'Contact person', 120)
            ->maxLength('mobile', 'Mobile', 30)
            ->email('email', 'Email')
            ->maxLength('address', 'Address', 255)
            ->in('status', 'Status', ['active', 'inactive']);

        $id = (int)($input['id'] ?? 0);

        if ($v->passes()) {
            foreach (['agency_name', 'code'] as $field) {
                if (Lookup::codeTaken('agencies', $field, $v->text($field), $id > 0 ? $id : null)) {
                    $v->addError($field, 'An agency with that ' . $field . ' already exists.');
                }
            }
        }

        if ($v->fails()) {
            return ['ok' => false, 'message' => $v->firstError(), 'errors' => $v->errors()];
        }

        $row = [
            'agency_name'    => $v->text('agency_name'),
            'code'           => $v->text('code'),
            'contact_person' => $v->nullable('contact_person'),
            'mobile'         => $v->nullable('mobile'),
            'email'          => $v->nullable('email'),
            'address'        => $v->nullable('address'),
            'remarks'        => $v->nullable('remarks'),
            'status'         => $v->text('status') ?: 'active',
        ];

        if ($id > 0) {
            $before = Database::one('SELECT * FROM agencies WHERE id = ?', [$id]);

            if ($before === null) {
                return ['ok' => false, 'message' => 'That agency no longer exists.'];
            }

            Database::update('agencies', $row, 'id = ?', [$id]);
            audit_log('agency-updated', 'agencies', $id, $before, $row);

            return ['ok' => true, 'message' => 'Agency updated.'];
        }

        $newId = Database::insert('agencies', $row + ['created_at' => now(), 'updated_at' => now()]);
        audit_log('agency-created', 'agencies', $newId, null, $row);

        return ['ok' => true, 'message' => 'Agency added.'];
    }

    /** Key/value application settings. */
    private function saveSettings(array $input): array
    {
        $values = (array)($input['settings'] ?? []);

        if ($values === []) {
            return ['ok' => false, 'message' => 'Nothing to save.'];
        }

        // The configurable option lists: validate before the loop writes
        // anything, so a bad edit is refused whole rather than half-saved.
        // Keys not posted by this form are left alone.
        $listErrors = [];
        $listKeys   = ['employment_statuses', 'operational_statuses', 'destination_countries'];

        foreach ($listKeys as $listKey) {
            if (!array_key_exists($listKey, $values)) {
                continue;
            }

            $raw   = trim((string)$values[$listKey]);
            $oneOf = [];
            $plain = $listKey === 'destination_countries';

            // saveSettings() truncates at setting_value's VARCHAR(500), which
            // would cut a name in half with no warning. Refuse instead.
            if (mb_strlen($raw) > 500) {
                $listErrors[$listKey] = 'That list is ' . mb_strlen($raw)
                    . ' characters - a setting holds 500. Remove some '
                    . ($plain ? 'countries' : 'options') . '.';
                continue;
            }

            if ($raw === '') {
                $listErrors[$listKey] = $plain
                    ? 'Add at least one country, separated by commas.'
                    : 'Add at least one option, as value|Label pairs separated by commas.';
                continue;
            }

            // Destination countries are a PLAIN list: the name is both the
            // value and the label, so the pairs syntax does not apply here.
            if ($plain) {
                \App\Models\Lookup::parsePlainList($raw, $oneOf);
                if ($oneOf !== []) {
                    $listErrors[$listKey] = implode(' ', $oneOf);
                }
                continue;
            }

            \App\Models\Lookup::parseOptionListString($raw, $oneOf);
            if ($oneOf !== []) {
                $listErrors[$listKey] = implode(' ', $oneOf);
                continue;
            }

            // The KPIs (dashboard, capacity planning, leave pickers) count
            // active drivers with status = "active" - the list cannot drop it.
            if ($listKey === 'employment_statuses'
                && !array_key_exists('active', \App\Models\Lookup::parseOptionListString($raw))
            ) {
                $listErrors[$listKey] = 'The employment list must keep the value "active" - it drives every active-driver total.';
            }
        }

        // A VALUE STILL IN USE MUST NOT VANISH SILENTLY. Removing an option
        // orphans every record holding it: the driver form used to fall back to
        // its first option and rewrite the driver. So the two driver lists
        // REFUSE the removal while any driver still holds the value.
        // Destinations are historical - leave rows keep their own value and the
        // forms add it back as its own option - so those removals are allowed
        // and reported instead.
        $notes = [];

        if ($listErrors === []) {
            $usage = $this->optionListUsage();

            foreach ($listKeys as $listKey) {
                if (!array_key_exists($listKey, $values)) {
                    continue;
                }

                $plain   = $listKey === 'destination_countries';
                $current = $plain
                    ? \App\Models\Lookup::destinationCountries()
                    : array_keys($listKey === 'employment_statuses'
                        ? \App\Models\Lookup::employmentStatuses()
                        : \App\Models\Lookup::operationalStatuses());

                $kept = $plain
                    ? \App\Models\Lookup::parsePlainList((string)$values[$listKey])
                    : array_keys(\App\Models\Lookup::parseOptionListString((string)$values[$listKey]));

                // Countries match case-insensitively, the way the parser
                // dedupes them: retyping "saudi arabia" is not a removal.
                $keptKeys = [];
                foreach ($kept as $keptValue) {
                    $keptKeys[$plain ? mb_strtolower((string)$keptValue) : (string)$keptValue] = true;
                }

                $dropped = [];
                $records = 0;

                foreach ($current as $value) {
                    $key = $plain ? mb_strtolower((string)$value) : (string)$value;

                    if (isset($keptKeys[$key])) {
                        continue;
                    }

                    $count = (int)($usage[$listKey][$key] ?? 0);

                    if ($count > 0) {
                        $dropped[] = $value;
                        $records  += $count;
                    }
                }

                if ($dropped === []) {
                    continue;
                }

                if ($plain) {
                    $notes[] = 'Removed ' . count($dropped) . ' country name(s) still'
                        . ' used by ' . $records . ' leave row(s) - those rows keep'
                        . ' their value and stay filterable.';
                    continue;
                }

                $shown = implode(', ', array_map(
                    static fn ($v): string => '"' . $v . '"',
                    array_slice($dropped, 0, 3)
                ));

                if (count($dropped) > 3) {
                    $shown .= ' and ' . (count($dropped) - 3) . ' more';
                }

                $listErrors[$listKey] = 'Cannot remove ' . $shown . ' - ' . $records
                    . ' driver(s) still use it. Reassign those drivers first, or'
                    . ' keep the value in the list.';
            }
        }

        if ($listErrors !== []) {
            return [
                'ok'      => false,
                'message' => (string)reset($listErrors),
                'errors'  => $listErrors,
            ];
        }

        $changed = [];

        foreach ($values as $key => $value) {
            $key   = (string)$key;
            $value = mb_substr(trim((string)$value), 0, 500);

            // Whitelist: only known keys are ever written.
            if ($key === '' || !preg_match('/^[a-z0-9_]{2,80}$/', $key)) {
                continue;
            }

            $before = Database::scalar(
                'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1', [$key]
            );

            if ($before === null) {
                Database::insert('settings', [
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'label'         => (string)($input['labels'][$key] ?? $key),
                    'group_name'    => 'general',
                    'updated_at'    => now(),
                ]);
            } elseif ((string)$before !== $value) {
                Database::update('settings',
                    ['setting_value' => $value, 'updated_at' => now()],
                    'setting_key = ?', [$key]);
            } else {
                continue;
            }

            $changed[$key] = ['old' => $before, 'new' => $value];
        }

        if ($changed !== []) {
            audit_log('settings-changed', 'settings', null, null, $changed);
        }

        // The removal guard above reads the lists through Lookup, which memoises
        // them for the request: without this flush, a save followed by another
        // read in the same request would still compare against the old lists.
        \App\Models\Lookup::forgetSettings();

        return [
            'ok'      => true,
            'message' => trim('Settings saved. ' . implode(' ', $notes)),
        ];
    }

    /**
     * How many records hold each configured option, for the three lists.
     *
     * WHY. Removing an option is the only edit on this screen that can strand
     * data: the driver keeps a value the list no longer offers. The counts are
     * shown beside each list and read back on save, so a removal of a value
     * still in use is refused for the two driver lists and reported for
     * destinations (whose rows are history and keep their value).
     *
     * Destination keys come back lowercased: a country is stored under the name
     * it was picked with, and the list dedupes case-insensitively.
     *
     * @return array<string, array<string, int>> setting key => value => count
     */
    private function optionListUsage(): array
    {
        $collect = static function (string $sql): array {
            $out = [];

            foreach (Database::all($sql) as $row) {
                $key = (string)$row['k'];

                if ($key !== '') {
                    $out[$key] = (int)$row['n'];
                }
            }

            return $out;
        };

        return [
            'employment_statuses' => $collect(
                'SELECT status AS k, COUNT(*) AS n FROM drivers
                  WHERE status IS NOT NULL AND status <> "" GROUP BY status'
            ),
            'operational_statuses' => $collect(
                'SELECT operational_status AS k, COUNT(*) AS n FROM drivers
                  WHERE operational_status IS NOT NULL AND operational_status <> ""
                  GROUP BY operational_status'
            ),
            'destination_countries' => $collect(
                'SELECT LOWER(destination) AS k, COUNT(*) AS n FROM (
                     SELECT destination FROM leave_records
                     UNION ALL
                     SELECT destination FROM leave_applications
                 ) d
                 WHERE destination IS NOT NULL AND destination <> ""
                 GROUP BY LOWER(destination)'
            ),
        ];
    }

    /**
     * optionListUsage() shaped for the screen: one row per configured option, in
     * list order, so a reader can see what a removal would cost.
     *
     * @return array<string, array{rows: array<int, array{label: string, count: int}>, in_use: int, unused: int}>
     */
    private function optionListSummary(): array
    {
        $usage = $this->optionListUsage();

        $build = static function (array $labels, array $counts, bool $lower): array {
            $rows  = [];
            $inUse = 0;

            foreach ($labels as $label) {
                $label = (string)$label;
                $count = (int)($counts[$lower ? mb_strtolower($label) : $label] ?? 0);

                if ($count > 0) {
                    $inUse++;
                }

                $rows[] = ['label' => $label, 'count' => $count];
            }

            return ['rows' => $rows, 'in_use' => $inUse, 'unused' => count($rows) - $inUse];
        };

        return [
            'employment_statuses' => $build(
                array_values(\App\Models\Lookup::employmentStatuses()),
                $usage['employment_statuses'],
                false
            ),
            'operational_statuses' => $build(
                array_values(\App\Models\Lookup::operationalStatuses()),
                $usage['operational_statuses'],
                false
            ),
            'destination_countries' => $build(
                \App\Models\Lookup::destinationCountries(),
                $usage['destination_countries'],
                true
            ),
        ];
    }

    /* --------------------------------------------------- save dispatch -- */

    /**
     * Single save endpoint; the "kind" field decides what is being written.
     * Supports both HTML posts (redirect + flash) and JSON (Fetch).
     */
    public function save(array $params = []): void
    {
        csrf_check();

        $input  = app_context() === 'api' ? json_input() : $_POST;
        $kind   = (string)($input['kind'] ?? '');
        $return = (string)($input['return_to'] ?? 'settings/system');

        // One form endpoint serves every settings screen: ask for the
        // permission the submitted kind actually needs.
        require_permission($this->permissionForKind($kind) ?? 'settings.system');

        $result = match ($kind) {
            'settings'    => $this->saveSettings($input),
            'user'        => $this->saveUser($input),
            'role'        => $this->saveRole($input),
            'leave_type'  => $this->saveSimple(
                'leave_types', $input, 'name', ['name' => 'Leave type name'], 'Leave Types', 'settings/leave-types',
                ['description' => ['Description', 255]]
            ),
            'branch'      => $this->saveSimple(
                'branches', $input, 'code', ['branch_name' => 'Branch name', 'code' => 'Branch code'], 'Branches', 'settings/branches'
            ),
            'agency'      => $this->saveAgency($input),
            default       => ['ok' => false, 'message' => 'Unknown form.'],
        };

        if (app_context() === 'api') {
            json_out($result, $result['ok'] ? 200 : 422);
        }

        if (!$result['ok']) {
            flash_set('danger', $result['message'], $result['errors'] ?? []);
            redirect($return);
            return;
        }

        flash_set('success', $result['message']);
        redirect($return);
    }

    /**
     * Permission required to write a given settings `kind`. Shared by save()
     * and destroy(), so the two can never drift apart.
     */
    private function permissionForKind(string $kind): string|array|null
    {
        return match ($kind) {
            'settings'   => 'settings.system',
            'user'       => 'settings.users',
            // Editing a role and granting its permissions are separate rights.
            'role'       => ['settings.roles', 'settings.permissions'],
            'leave_type' => 'settings.leave_types',
            'branch'     => 'settings.branches',
            'agency'     => 'settings.agencies',
            default      => null,
        };
    }

    /**
     * Generic create/update for leave types and branches.
     *
     * $labels   required, capped at 120 chars and uniqueness-checked - these
     *           define the row.
     * $optional fields the form may post that are NOT part of the identity
     *           (field => [label, max]). leave_types.description was missing
     *           here, so it was silently dropped on every save and the grid
     *           could only ever show a dash.
     */
    private function saveSimple(string $table, array $input, string $codeField, array $labels, string $noun, string $return, array $optional = []): array
    {
        $v = new \App\Core\Validator($input);

        foreach ($labels as $field => $label) {
            $v->required($field, $label)->maxLength($field, $label, 120);
        }

        foreach ($optional as $field => [$label, $max]) {
            $v->maxLength($field, $label, $max);
        }

        $v->in('status', 'Status', ['active', 'inactive']);

        $id = (int)($input['id'] ?? 0);

        if ($v->passes()) {
            foreach (array_keys($labels) as $field) {
                if (Lookup::codeTaken($table, $field, $v->text($field), $id > 0 ? $id : null)) {
                    $v->addError($field, 'A ' . strtolower($labels[$field]) . ' with that value already exists.');
                }
            }
        }

        if ($v->fails()) {
            return ['ok' => false, 'message' => $v->firstError(), 'errors' => $v->errors()];
        }

        $row = ['status' => $v->text('status') ?: 'active'];

        foreach (array_keys($labels) as $field) {
            $row[$field] = $v->text($field);
        }

        // Optional columns are written only when the form actually posted them,
        // so a form without that field can never blank out an existing value.
        // An empty string becomes NULL rather than '' so the grid's dash is
        // reserved for "genuinely not set".
        foreach (array_keys($optional) as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = trim((string) $input[$field]);
            $row[$field] = $value === '' ? null : $value;
        }

        if ($id > 0) {
            $before = Database::one('SELECT * FROM `' . $table . '` WHERE id = ?', [$id]);

            if ($before === null) {
                return ['ok' => false, 'message' => 'That record no longer exists.'];
            }

            Database::update($table, $row, 'id = ?', [$id]);
            audit_log(strtolower($noun) . '-updated', $table, $id, $before, $row);

            return ['ok' => true, 'message' => $noun . ' updated.'];
        }

        $newId = Database::insert($table, $row + ['created_at' => now(), 'updated_at' => now()]);
        audit_log(strtolower($noun) . '-created', $table, $newId, null, $row);

        return ['ok' => true, 'message' => $noun . ' added.'];
    }

    /** Create / update a user account (admin only). */
    /**
     * users.role carries the primary role; user_roles is what permissions are
     * actually read from. Keep them in step so a role change takes effect at
     * once. The join table allows several rows per user, so extra roles can be
     * layered on later without changing this method's signature.
     */
    private function syncUserRole(int $userId, string $slug): void
    {
        $roleId = (int) Database::scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);

        Database::run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);

        if ($roleId > 0) {
            Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);
        }

        forget_permissions();
    }

    private function saveUser(array $input): array
    {
        $v  = new \App\Core\Validator($input);
        $id = (int)($input['id'] ?? 0);

        // Role slugs come from the database, so adding a role needs no code.
        $roleSlugs = array_values(array_column(Database::all('SELECT slug FROM roles ORDER BY id'), 'slug'));

        $v->required('username', 'Username')
            ->maxLength('username', 'Username', 50)
            ->required('full_name', 'Full name')
            ->maxLength('full_name', 'Full name', 120)
            ->in('role', 'Role', $roleSlugs)
            ->in('status', 'Status', ['active', 'inactive']);

        // Password required on create, optional on update, always >= 8 chars.
        if ($id === 0) {
            $v->required('password', 'Password');
        }
        if ($v->text('password') !== '' && strlen($v->text('password')) < 8) {
            $v->addError('password', 'Password must be at least 8 characters.');
        }

        if ($v->passes()
            && Lookup::codeTaken('users', 'username', $v->text('username'), $id > 0 ? $id : null)
        ) {
            $v->addError('username', 'That username is already taken.');
        }

        if ($v->fails()) {
            return ['ok' => false, 'message' => $v->firstError(), 'errors' => $v->errors()];
        }

        $row = [
            'username'   => $v->text('username'),
            'full_name'  => $v->text('full_name'),
            'role'       => $v->text('role'),
            'status'     => $v->text('status'),
            'updated_at' => now(),
        ];

        $password = $v->text('password');

        if ($id > 0) {
            // Never let an admin lock themselves out.
            if ($id === (int)($_SESSION['user_id'] ?? 0) && $row['status'] === 'inactive') {
                return ['ok' => false, 'message' => 'You cannot deactivate your own account.'];
            }

            $before = Database::one(
                'SELECT id, username, full_name, role, status FROM users WHERE id = ?', [$id]
            );

            if ($before === null) {
                return ['ok' => false, 'message' => 'That user no longer exists.'];
            }

            Database::update('users', $row, 'id = ?', [$id]);

            if ($password !== '') {
                Database::update('users',
                    ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
                    'id = ?', [$id]);
            }

            audit_log('user-updated', 'users', $id, $before, $row);

            $this->syncUserRole($id, $row['role']);

            return ['ok' => true, 'message' => 'User updated.'];
        }

        $row += [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at'    => now(),
        ];

        $newId = Database::insert('users', $row);

        $this->syncUserRole($newId, $row['role']);

        audit_log('user-created', 'users', $newId, null, $row + ['password_hash' => '(hidden)']);

        return ['ok' => true, 'message' => 'User created.'];
    }
}

