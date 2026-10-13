<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\View;
use App\Models\Driver;
use App\Models\DriverImport;
use App\Models\DriverChangeRequest;
use App\Models\DriverHistory;
use App\Models\LeaveRecord;
use App\Models\Lookup;

/**
 * Address Book: list, profile, create/edit, deactivate, import/export.
 */
final class DriverController
{
    /** Filters accepted from the query string. */
    private function filters(): array
    {
        return [
            'q'                => trim((string)($_GET['q'] ?? '')),
            'branch_id'        => (string)($_GET['branch_id'] ?? ''),
            'agency_id'        => (string)($_GET['agency_id'] ?? ''),
            'status'           => (string)($_GET['status'] ?? ''),
            'operational_status' => (string)($_GET['operational_status'] ?? ''),
            'nationality'      => (string)($_GET['nationality'] ?? ''),
        ];
    }

    private function sort(): array
    {
        return sort_state(Driver::SORTABLE, 'badge_number', 'asc');
    }

    /* ------------------------------------------------------------ list -- */

    public function index(array $params = []): void
    {
        require_login();

        // Any ?export= value reaches export(), which validates the format
        // itself - a CSV-only screen must not silently render the HTML page
        // for ?export=xls either.
        if (($_GET['export'] ?? '') !== '') {
            // Exporting the address book is a different right from listing it,
            // and it is triggered by a query string the route map cannot see.
            require_permission('drivers.export');
            $this->export($params);
        }

        $filters = $this->filters();
        $sort    = $this->sort();

        // Client-side paging (assets/js/table.js): every matching row ships
        // once, so sorting, paging and Rows-per-page happen in place.
        $result = Driver::paginate($filters, $sort, table_row_cap(), 0);

        View::render('drivers/index', [
            'rows'          => $result['rows'],
            'total'         => $result['total'],
            'filters'       => $filters,
            'sort'          => $sort,
            'branches'      => Lookup::branchPairs(false),
            'agencies'      => Lookup::agencyPairs(false),
            'nationalities' => Driver::nationalities(),
            'query'         => $_GET,
        ], 'Address Book · Drivers', 'drivers', [
            ['label' => 'Address Book'],
            ['label' => 'Drivers'],
        ]);
    }

    /** CSV / Excel export of the current filtered list. */
    public function export(array $params = []): void
    {
        require_login();

        $rows = Driver::allForExport($this->filters());

        $headers = [
            'Badge Number', 'Driver Name', 'Iqama Number', 'Passport Number',
            'Mobile', 'Alternate Mobile',
            'Nationality', 'Agency', 'Branch', 'Department', 'Position', 'Joining Date',
            'Last Working Date',
            'Emergency Contact', 'Emergency Number', 'Address', 'City', 'Remarks',
            'Employment Status', 'Operational Status',
        ];

        $data = array_map(static function (array $r): array {
            return [
                $r['badge_number'], $r['driver_name'], $r['iqama_number'],
                $r['passport_number'] ?? null, $r['mobile_number'],
                $r['alternate_mobile'], $r['nationality'], $r['agency_name'], $r['branch_name'],
                $r['department'], $r['position'], $r['joining_date'],
                // The deactivation end date travels with the export: the
                // forecast uses it, so a spreadsheet reader needs it too.
                $r['last_working_date'] ?? '',
                $r['emergency_contact_name'],
                $r['emergency_contact_number'], $r['address'], $r['city'], $r['remarks'],
                // Export the LABELS, not the slugs: a spreadsheet reader has
                // no Settings screen to look the slugs up in.
                \App\Models\Lookup::employmentLabel((string)$r['status']),
                \App\Models\Lookup::operationalLabel((string)($r['operational_status'] ?? '')),
            ];
        }, $rows);

        $name   = 'drivers-' . date('Ymd-His');
        $format = export_format((string) ($_GET['export'] ?? ''), ['csv', 'xls']);

        if ($format === 'xls') {
            // Badge and Iqama are written as NUMBERS so Excel sorts them as
            // numbers instead of stacking "100" under "9". The cost is a
            // leading zero being dropped ("007" -> 7), which is why this is
            // Excel-only: the CSV still carries the stored string byte for
            // byte, and it is the CSV that has to round-trip into another
            // system.
            //
            // A value that is not purely digits is left as text - casting an
            // alphanumeric badge would silently turn it into 0, which is a
            // far worse lie than typing it as text.
            $numeric = static function (array $row): array {
                foreach ([0, 2] as $i) { // Badge Number, Iqama Number
                    $raw = trim((string) ($row[$i] ?? ''));

                    if ($raw !== '' && preg_match('/^-?[0-9]+$/', $raw) === 1) {
                        $row[$i] = (int) $raw;
                    }
                }

                return $row;
            };

            export_xls($headers, array_map($numeric, $data), $name . '.xls', 'Address Book - Drivers');
        }

        export_csv($headers, $data, $name . '.csv');
    }

    /* --------------------------------------------------------- profile -- */

    public function profile(array $params): void
    {
        require_login();

        $driver = Driver::find((int)$params['id']);

        if ($driver === null) {
            View::error(404, 'That driver could not be found.');
            return;
        }

        View::render('drivers/profile', [
            'driver'  => $driver,
            'current' => Driver::currentLeave((int)$driver['id']),
            'history' => Driver::leaveHistory((int)$driver['id']),
        ], $driver['driver_name'], 'drivers', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => $driver['driver_name']],
        ]);
    }

    /** Stand-alone leave history page (reached from the action menu). */
    public function leaveHistory(array $params): void
    {
        require_login();

        $driver = Driver::find((int)$params['id']);

        if ($driver === null) {
            View::error(404, 'That driver could not be found.');
            return;
        }

        $rows = Driver::leaveHistory((int)$driver['id']);

        View::render('drivers/history', [
            'driver' => $driver,
            'rows'   => $rows,
        ], 'Leave History · ' . $driver['driver_name'], 'drivers', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => $driver['driver_name'], 'url' => url('drivers/' . (int)$driver['id'])],
            ['label' => 'Leave History'],
        ]);
    }

    /* ---------------------------------------------------- create / edit - */

    public function create(array $params = []): void
    {
        require_login();

        View::render('drivers/form', [
            'driver'    => null,
            'errors'    => [],
            'old'       => $_POST,
            'branches'  => Lookup::branchPairs(false),
            'agencies'  => Lookup::agencyPairs(false),
        ], 'Add Driver', 'drivers', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => 'Drivers', 'url' => url('drivers')],
            ['label' => 'Add Driver'],
        ]);
    }

    public function edit(array $params): void
    {
        require_login();

        $driver = Driver::find((int)$params['id']);

        if ($driver === null) {
            View::error(404, 'That driver could not be found.');
            return;
        }

        $old = $_POST ?: $driver;

        View::render('drivers/form', [
            'driver'    => $driver,
            'errors'    => [],
            'old'       => $old,
            'branches'  => Lookup::branchPairs(false),
            'agencies'  => Lookup::agencyPairs(false),
        ], 'Edit Driver · ' . $driver['driver_name'], 'drivers', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => $driver['driver_name'], 'url' => url('drivers/' . (int)$driver['id'])],
            ['label' => 'Edit'],
        ]);
    }

    public function save(array $params = []): void
    {
        require_login();
        csrf_check();

        $v = $this->validate($_POST);

        if ($v->fails()) {
            View::render('drivers/form', [
                'driver'   => null,
                'errors'   => $v->errors(),
                'old'      => $_POST,
                'branches' => Lookup::branchPairs(false),
                'agencies' => Lookup::agencyPairs(false),
            ], 'Add Driver', 'drivers', [
                ['label' => 'Address Book', 'url' => url('drivers')],
                ['label' => 'Drivers', 'url' => url('drivers')],
                ['label' => 'Add Driver'],
            ]);

            return;
        }

        $row = $this->payload($v);

        if (Driver::duplicateExists('badge_number', (string)$row['badge_number'])) {
            flash_set('danger', 'Badge number ' . $row['badge_number'] . ' already exists.', [
                'badge_number' => 'This badge number is already assigned to another driver.',
            ]);
            redirect('drivers/create');
        }

        if (Driver::duplicateExists('iqama_number', (string)$row['iqama_number'])) {
            flash_set('danger', 'Iqama number ' . $row['iqama_number'] . ' already exists.', [
                'iqama_number' => 'This iqama number is already assigned to another driver.',
            ]);
            redirect('drivers/create');
        }

        $row['created_at'] = now();
        $row['updated_at'] = now();

        $id = Database::insert('drivers', $row);

        audit_log('driver-created', 'drivers', $id, null, $row);

        flash_set('success', 'Driver ' . $row['driver_name'] . ' has been added.');
        redirect('drivers/' . $id);
    }

    public function update(array $params): void
    {
        require_login();
        // Defence in depth: the route map already gates this on
        // drivers.master_correct, but a controller that writes master data
        // should say so itself rather than trust the map to still be current.
        require_permission('drivers.master_correct');
        csrf_check();

        $driver = Driver::find((int)$params['id']);

        if ($driver === null) {
            View::error(404, 'That driver could not be found.');
            return;
        }

        $v = $this->validate($_POST, (int)$driver['id']);

        if ($v->fails()) {
            View::render('drivers/form', [
                'driver'   => $driver,
                'errors'   => $v->errors(),
                'old'      => $_POST,
                'branches' => Lookup::branchPairs(false),
                'agencies' => Lookup::agencyPairs(false),
            ], 'Edit Driver · ' . $driver['driver_name'], 'drivers', [
                ['label' => 'Address Book', 'url' => url('drivers')],
                ['label' => $driver['driver_name'], 'url' => url('drivers/' . (int)$driver['id'])],
                ['label' => 'Edit'],
            ]);

            return;
        }

        $row = $this->payload($v);

        if (Driver::duplicateExists('badge_number', (string)$row['badge_number'], (int)$driver['id'])) {
            flash_set('danger', 'That badge number belongs to another driver.', [
                'badge_number' => 'This badge number is already assigned to another driver.',
            ]);
            redirect('drivers/' . (int)$driver['id'] . '/edit');
        }

        if (Driver::duplicateExists('iqama_number', (string)$row['iqama_number'], (int)$driver['id'])) {
            flash_set('danger', 'That iqama number belongs to another driver.', [
                'iqama_number' => 'This iqama number is already assigned to another driver.',
            ]);
            redirect('drivers/' . (int)$driver['id'] . '/edit');
        }

        $row['updated_at'] = now();

        // Direct correction of master data. The reason is not decoration:
        // without it nobody can tell, a year from now, why a driver's branch
        // or status moved - so the save is refused rather than defaulted.
        // A change confined to ungoverned columns (remarks, the emergency
        // contact pair) needs no reason, because nothing governed moved.
        $reason = trim((string)($_POST['reason'] ?? ''));
        $changed = [];

        foreach (array_keys(Driver::GOVERNABLE) as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $was = $driver[$field] ?? null;
            $is  = $row[$field] ?? null;

            if ((string)($was ?? '') !== (string)($is ?? '')) {
                $changed[$field] = true;
            }
        }

        if ($changed !== [] && mb_strlen($reason) < 5) {
            flash_set(
                'danger',
                'You are changing master driver information. Give a reason of at least 5 characters.',
                ['reason' => 'A reason is required whenever master information changes.']
            );
            redirect('drivers/' . (int)$driver['id'] . '/edit');
            return;
        }

        Database::update('drivers', $row, 'id = ?', [(int)$driver['id']]);

        // One history row per governed field that actually moved. recordDiff()
        // skips everything outside GOVERNABLE, so touching remarks writes
        // nothing at all here.
        DriverHistory::recordDiff(
            (int)$driver['id'],
            $driver,
            $row,
            'direct_correction',
            $reason !== '' ? $reason : null,
            (int)(current_user()['id'] ?? 0)
        );

        audit_log('driver-updated', 'drivers', (int)$driver['id'], $driver,
            $reason !== '' ? $row + ['reason' => $reason] : $row);

        flash_set('success', 'Driver details updated.');
        redirect('drivers/' . (int)$driver['id']);
    }

    /* ------------------------------------------------------ deactivate -- */

    /** Soft deactivation: keeps drivers that have leave history off the delete path. */
    public function deactivate(array $params): void
    {
        require_login();
        csrf_check();

        $driver = Driver::find((int)$params['id']);

        if ($driver === null) {
            View::error(404, 'That driver could not be found.');
            return;
        }

        if ($driver['status'] === 'inactive') {
            flash_set('info', 'That driver is already inactive.');
            redirect('drivers/' . (int)$driver['id']);
            return;
        }

        // Requirement 6: deactivation asks for the last day the driver
        // worked. The date is what keeps them in the capacity, dashboard and
        // picker counts up to AND including that day (Driver::activeOnSql),
        // so a deactivation never erases dates they were still working.
        $lwd = trim((string)($_POST['last_working_date'] ?? ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lwd) !== 1 || strtotime($lwd) === false) {
            flash_set(
                'danger',
                'Enter the Last Working Date - the last day this driver worked.',
                ['last_working_date' => 'Enter the last day this driver worked.']
            );
            redirect('drivers/' . (int)$driver['id']);
            return;
        }

        $joining = (string)($driver['joining_date'] ?? '');

        if ($joining !== '' && $lwd < $joining) {
            flash_set(
                'danger',
                'The last working date cannot be before the joining date (' . fmt_date($joining) . ').',
                ['last_working_date' => 'The last working date cannot be before the joining date.']
            );
            redirect('drivers/' . (int)$driver['id']);
            return;
        }

        $open = Driver::activeLeaveCount((int)$driver['id']);

        if ($open > 0) {
            flash_set(
                'warning',
                'This driver has ' . $open . ' open leave record(s). Mark the driver as returned first.'
            );
            redirect('drivers/' . (int)$driver['id']);
            return;
        }

        Database::update('drivers', [
            'status'            => 'inactive',
            'last_working_date' => $lwd,
            'updated_at'        => now(),
        ], 'id = ?', [(int)$driver['id']]);

        audit_log('driver-deactivated', 'drivers', (int)$driver['id'],
            [
                'status'            => $driver['status'],
                'last_working_date' => $driver['last_working_date'] ?? null,
            ],
            ['status' => 'inactive', 'last_working_date' => $lwd]);

        flash_set(
            'success',
            $driver['driver_name'] . ' has been deactivated. Last working date: '
            . fmt_date($lwd) . '. They still count in the driver totals up to that day.'
        );
        redirect('drivers/' . (int)$driver['id']);
    }

    public function activate(array $params): void
    {
        require_login();
        csrf_check();

        $driver = Driver::find((int)$params['id']);

        if ($driver === null) {
            View::error(404, 'That driver could not be found.');
            return;
        }

        Database::update('drivers', [
            'status'            => 'active',
            // Requirement 8: a driver back at work has no end date any more -
            // leaving one behind would keep subtracting them from the
            // forecast after their return.
            'last_working_date' => null,
            'updated_at'        => now(),
        ], 'id = ?', [(int)$driver['id']]);

        audit_log('driver-activated', 'drivers', (int)$driver['id'],
            [
                'status'            => $driver['status'],
                'last_working_date' => $driver['last_working_date'] ?? null,
            ],
            ['status' => 'active', 'last_working_date' => null]);

        flash_set(
            'success',
            $driver['driver_name'] . ' is active again.'
            . (($driver['last_working_date'] ?? null) !== null
                ? ' The last working date has been cleared.'
                : '')
        );
        redirect('drivers/' . (int)$driver['id']);
    }

    /* ------------------------------------------------------- history ---- */

    /**
     * Master-data history for one driver.
     *
     * Deliberately a separate page from leave-history: that one answers
     * "where have they been", this answers "how did their record come to
     * look like this". Read-only by construction - the view offers no edit
     * or delete control, and DriverHistory exposes no update() for it to
     * call even if it did.
     */
    public function history(array $params): void
    {
        require_login();
        require_permission('drivers.history.view');

        $driver = Driver::find((int)($params['id'] ?? 0));

        if ($driver === null) {
            View::error(404, 'That driver could not be found.');
            return;
        }

        $filters = [
            'field'      => (string)($_GET['field'] ?? ''),
            'source'     => (string)($_GET['source'] ?? ''),
            'changed_by' => (string)($_GET['changed_by'] ?? ''),
            'date_from'  => (string)($_GET['date_from'] ?? ''),
            'date_to'    => (string)($_GET['date_to'] ?? ''),
        ];

        $result = DriverHistory::forDriver((int)$driver['id'], $filters, 500, 0);

        View::render('drivers/master-history', [
            'driver'       => $driver,
            'rows'         => $result['rows'],
            'total'        => $result['total'],
            'filters'      => $filters,
            'fieldOptions' => DriverHistory::fieldsUsed((int)$driver['id']),
            'sources'      => DriverHistory::SOURCES,
            'requests'     => DriverChangeRequest::forDriver((int)$driver['id']),
            'canRequest'   => can('drivers.edit'),
        ], 'Driver History · ' . $driver['driver_name'], 'drivers', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => $driver['driver_name'],
             'url'   => url('drivers/' . (int)$driver['id'])],
            ['label' => 'History'],
        ]);
    }

    /* ---------------------------------------------------------- delete -- */

    /**
     * Hard delete from the Address Book (admin only).
     *
     * Only drivers with no leave records can go: leave_records.driver_id is
     * ON DELETE RESTRICT, and the history matters more than the row does.
     * Everyone else is deactivated instead.
     */
    public function destroy(array $params): void
    {
        require_permission('drivers.delete');
        csrf_check();

        $driver = Driver::find((int)($params['id'] ?? 0));

        if ($driver === null) {
            View::error(404, 'That driver could not be found.');
            return;
        }

        $path   = trim((string)($_POST['return_to'] ?? ''));
        $return = ($path === '' || str_contains($path, '://') || str_starts_with($path, '//'))
            ? 'drivers'
            : $path;

        // BOTH registers are ON DELETE RESTRICT, so a driver with either kind
        // of history has to be deactivated rather than deleted - checking only
        // leave_records let an application-only driver reach the DELETE and
        // trip the foreign key instead of getting the friendly message.
        // Four registers block a delete, not two: a driver with an open change
        // request or any governed history is somebody the business is actively
        // tracking, and driver_history.driver_id is ON DELETE RESTRICT - so the
        // count has to reach the foreign key BEFORE the DELETE does, or the user
        // gets a raw 1451 instead of the sentence explaining why.
        $records = (int)Database::scalar(
            'SELECT (SELECT COUNT(*) FROM leave_records          WHERE driver_id = ?)
                  + (SELECT COUNT(*) FROM leave_applications      WHERE driver_id = ?)
                  + (SELECT COUNT(*) FROM driver_change_requests WHERE driver_id = ?)
                  + (SELECT COUNT(*) FROM driver_history         WHERE driver_id = ?)',
            [(int)$driver['id'], (int)$driver['id'], (int)$driver['id'], (int)$driver['id']]
        );

        if ($records > 0) {
            flash_set(
                'danger',
                $driver['driver_name'] . ' has ' . $records . ' leave, application, change-request'
                . ' or history record(s) and cannot be deleted. Deactivate the driver instead'
                . ' so the history is kept.'
            );
            redirect($return);
            return;
        }

        Database::run('DELETE FROM drivers WHERE id = ?', [(int)$driver['id']]);

        audit_log('driver-deleted', 'drivers', (int)$driver['id'], $driver, null);

        flash_set('success', $driver['driver_name'] . ' deleted.');
        redirect($return);
    }

    /* --------------------------------------------------- bulk delete ---- */

    /**
     * Admin: delete several drivers at once from the Address Book.
     *
     * POST /drivers/bulk-delete - applies the same rule as destroy(): a driver
     * with leave history is never deleted, only drivers with no records go.
     * That keeps the single- and multi-select paths consistent and stops the
     * ON DELETE RESTRICT foreign key from blowing up mid-loop.
     */
    public function bulkDelete(): void
    {
        require_permission('drivers.delete');
        csrf_check();

        $ids = self::selectedIds($_POST['ids'] ?? []);

        if ($ids === []) {
            flash_set('warning', 'Select at least one driver to delete.');
            redirect($this->safeReturnPath((string)($_POST['return_to'] ?? ''), 'drivers'));
            return;
        }

        $deleted   = 0;
        $withLeave = 0;

        foreach ($ids as $id) {
            $driver = Driver::find($id);

            if ($driver === null) {
                continue;
            }

            // Same combined guard as destroy(): applications block deletion
            // too, otherwise the loop dies on the foreign key mid-run.
            // Same four-register guard as destroy(): applications and history block
            // too, otherwise the loop dies on a foreign key mid-run.
            $records = (int)Database::scalar(
                'SELECT (SELECT COUNT(*) FROM leave_records          WHERE driver_id = ?)
                      + (SELECT COUNT(*) FROM leave_applications      WHERE driver_id = ?)
                      + (SELECT COUNT(*) FROM driver_change_requests WHERE driver_id = ?)
                      + (SELECT COUNT(*) FROM driver_history         WHERE driver_id = ?)',
                [$id, $id, $id, $id]
            );

            if ($records > 0) {
                $withLeave++;
                continue;
            }

            Database::run('DELETE FROM drivers WHERE id = ?', [$id]);
            $deleted++;
        }

        audit_log('drivers-bulk-deleted', 'drivers', null, null, [
            'selected' => count($ids),
            'deleted'  => $deleted,
            'skipped'  => $withLeave,
            'ids'      => $ids,
        ]);

        if ($deleted === 0) {
            flash_set(
                'danger',
                'None of the ' . count($ids) . ' selected driver(s) could be deleted - they all '
                . 'have leave records. Deactivate them instead so the history is kept.'
            );
        } else {
            flash_set(
                'success',
                $deleted . ' driver(s) deleted.'
                . ($withLeave > 0
                    ? ' ' . $withLeave . ' skipped because they have leave records.'
                    : '')
            );
        }

        redirect($this->safeReturnPath((string)($_POST['return_to'] ?? ''), 'drivers'));
    }

    /** Normalise a posted id list: ints, unique, > 0. */
    private static function selectedIds($raw): array
    {
        $ids = array_map('intval', array_values((array)$raw));
        $ids = array_unique(array_filter($ids, static fn (int $v): bool => $v > 0));
        sort($ids);

        return array_values($ids);
    }

    /** Same guard as destroy() uses inline, shared with bulkDelete(). */
    private function safeReturnPath(string $path, string $fallback): string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//')) {
            return $fallback;
        }

        return $path;
    }

    /* ---------------------------------------------------------- import -- */

    public function importForm(array $params = []): void
    {
        require_permission('drivers.import');

        if (($_GET['template'] ?? '') === '1') {
            $this->importTemplate();
        }

        View::render('drivers/import', [
            'plan'   => $_SESSION['import_plan'] ?? null,
            'result' => $_SESSION['import_result'] ?? null,
        ], 'Import / Export', 'drivers', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => 'Import / Export'],
        ]);
    }

    /** CSV template download so users see the exact expected headers. */
    private function importTemplate(): void
    {
        $template = DriverImport::template();
        $headers  = array_shift($template);

        export_csv($headers, $template, 'drivers-import-template.csv');
    }

    /**
     * Steps 1-2 of the import: upload, validate headers, preview with errors.
     * Nothing is written to the database until the confirm step.
     */
    public function importRun(array $params = []): void
    {
        require_permission('drivers.import');
        csrf_check();

        // IMPORTANT: the confirm branch must run BEFORE the staged plan is
        // cleared. Unsetting it first meant "Confirm import" always reported
        // "nothing to import" and silently did nothing.
        if (($_POST['step'] ?? '') === 'confirm') {
            $this->importConfirm();

            return;
        }

        // A brand new upload: drop any previously staged plan/result.
        unset($_SESSION['import_plan'], $_SESSION['import_result']);

        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            flash_set('danger', 'Please choose a CSV file to upload.');
            redirect('import');
        }

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');

        if ($handle === false) {
            flash_set('danger', 'The uploaded file could not be read.');
            redirect('import');
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);
            flash_set('danger', 'That file does not contain any rows.');
            redirect('import');
        }

        // Drop a UTF-8 BOM (Excel / our own template add one) so the first
        // column compares cleanly during header validation.
        if (isset($header[0]) && is_string($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        }

        $check = DriverImport::headersValid($header);

        if (!$check['valid']) {
            fclose($handle);
            flash_set(
                'danger',
                'The file is missing required column(s): ' . implode(', ', $check['missing'])
                . '. Download the template to see the expected headers.',
                ['csv_file' => 'Missing required columns: ' . implode(', ', $check['missing'])]
            );
            redirect('import');
        }

        $data = [];
        while (($cells = fgetcsv($handle)) !== false) {
            $data[] = $cells;
        }
        fclose($handle);

        if ($data === []) {
            flash_set('danger', 'The file contains a header row but no data.');
            redirect('import');
        }

        $_SESSION['import_plan'] = DriverImport::plan($header, $data);

        View::render('drivers/import', [
            'plan'   => $_SESSION['import_plan'],
            'result' => null,
        ], 'Import / Export · Preview', 'drivers', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => 'Import / Export'],
        ]);
    }

    /** Step 3: write the previewed rows (never silently). */
    private function importConfirm(): void
    {
        $plan = $_SESSION['import_plan'] ?? null;

        if (!is_array($plan) || $plan['rows'] === []) {
            flash_set('warning', 'There is nothing to import. Please upload a file first.');
            redirect('import');
            return;
        }

        $mode   = ($_POST['mode'] ?? 'skip') === 'update' ? 'update' : 'skip';
        $result = DriverImport::commit($plan['rows'], $mode);

        // Agencies the file introduced are created on commit - surface it so a
        // brand new lookup value never appears silently.
        $newAgencies = (int)($result['agencies_created'] ?? 0);
        $agencyNote  = $newAgencies > 0
            ? ' ' . $newAgencies . ' new ' . ($newAgencies === 1 ? 'agency was' : 'agencies were')
              . ' created in Settings -> Agencies.'
            : '';

        audit_log('drivers-imported', 'drivers', null, null, [
            'mode' => $mode, 'inserted' => $result['inserted'], 'updated' => $result['updated'],
            'agencies_created' => $newAgencies,
        ]);

        unset($_SESSION['import_plan']);

        $_SESSION['import_result'] = $result + ['mode' => $mode];

        flash_set(
            'success',
            'Import finished: ' . $result['inserted'] . ' added, '
            . $result['updated'] . ' updated, ' . $result['skipped'] . ' skipped.'
            . $agencyNote
        );

        redirect('import');
    }

    /* ----------------------------------------------------- validation -- */

    /**
     * Shared validation for create + edit (server side).
     *
     * @param array<string, mixed> $input
     */
    private function validate(array $input, ?int $exceptId = null): \App\Core\Validator
    {
        $v = new \App\Core\Validator($input);

        $v->required('badge_number', 'Badge number')
            ->maxLength('badge_number', 'Badge number', 30)
            ->required('driver_name', 'Driver name')
            ->maxLength('driver_name', 'Driver name', 120)
            ->required('iqama_number', 'Iqama number')
            ->maxLength('iqama_number', 'Iqama number', 30)
            ->maxLength('passport_number', 'Passport number', 30)
            ->maxLength('mobile_number', 'Mobile number', 30)
            ->maxLength('alternate_mobile', 'Alternate mobile', 30)
            ->maxLength('nationality', 'Nationality', 60)
            ->maxLength('department', 'Department', 100)
            ->maxLength('position', 'Position', 100)
            ->maxLength('city', 'City', 80)
            ->maxLength('address', 'Address', 255)
            ->maxLength('emergency_contact_name', 'Emergency contact name', 120)
            ->maxLength('emergency_contact_number', 'Emergency contact number', 30)
            ->date('joining_date', 'Joining date')
            ->date('last_working_date', 'Last working date')
            // An end date before the day the driver joined can never be true.
            // Both date helpers skip blanks, so the field being hidden for an
            // active driver needs no special case here.
            ->dateNotBefore('last_working_date', 'Last working date', 'joining_date', 'joining date')
            // Both status lists come from Settings (value|Label). Validator::in()
            // accepts an empty value, so Operational Status may stay unset.
            ->in('status', 'Employment status', array_keys(Lookup::employmentStatuses()))
            ->in('operational_status', 'Operational status', array_keys(Lookup::operationalStatuses()));

        // Agency / branch must exist when supplied (spec section 29).
        if (($input['agency_id'] ?? '') !== '') {
            $v->existsIn('agency_id', 'Agency', 'agencies');
        }
        if (($input['branch_id'] ?? '') !== '') {
            $v->existsIn('branch_id', 'Branch', 'branches');
        }

        // Uniqueness (validated here so both create and edit share one rule).
        if ($v->passes()) {
            foreach (['badge_number', 'iqama_number'] as $field) {
                if (Driver::duplicateExists($field, $v->text($field), $exceptId)) {
                    $v->addError($field, 'That ' . str_replace('_', ' ', $field)
                        . ' is already assigned to another driver.');
                }
            }
        }

        return $v;
    }

    /**
     * Build the insert/update row from a validated payload.
     *
     * @return array<string, mixed>
     */
    private function payload(\App\Core\Validator $v): array
    {
        return [
            'badge_number'             => $v->text('badge_number'),
            'driver_name'              => $v->text('driver_name'),
            'iqama_number'             => $v->text('iqama_number'),
            'passport_number'          => $v->nullable('passport_number'),
            'mobile_number'            => $v->nullable('mobile_number'),
            'alternate_mobile'         => $v->nullable('alternate_mobile'),
            'nationality'              => $v->nullable('nationality'),
            'agency_id'                => $v->nullable('agency_id') !== null ? (int)$v->text('agency_id') : null,
            'branch_id'                => $v->nullable('branch_id') !== null ? (int)$v->text('branch_id') : null,
            'department'               => $v->nullable('department'),
            'position'                 => $v->nullable('position'),
            'joining_date'             => $v->dateValue('joining_date'),
            'emergency_contact_name'   => $v->nullable('emergency_contact_name'),
            'emergency_contact_number' => $v->nullable('emergency_contact_number'),
            'address'                  => $v->nullable('address'),
            'city'                     => $v->nullable('city'),
            'remarks'                  => $v->nullable('remarks'),
            'status'                   => $v->text('status') ?: 'active',
            // The end date belongs to a leaving status. Setting the status
            // back to active drops it here as well as in activate(), so a
            // reactivated driver can never keep a stale date that would hold
            // them out of the forecast after their return.
            'last_working_date'        => ($v->text('status') ?: 'active') === 'active'
                ? null
                : $v->dateValue('last_working_date'),
            'operational_status'       => $v->nullable('operational_status'),
        ];
    }
}


