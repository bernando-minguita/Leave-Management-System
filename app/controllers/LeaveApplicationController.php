<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Validator;
use App\Core\View;
use App\Models\Driver;
use App\Models\LeaveApplication;
use App\Models\Lookup;

/**
 * Leave / vacation applications: a standalone register with an
 * approved / rejected decision. Nothing here touches leave_records, so the
 * dashboard, the calendar and the expected-returns panels are unaffected.
 */
final class LeaveApplicationController
{
    /* ------------------------------------------------------------ list --- */

    public function index(array $params = []): void
    {
        require_login();

        $filters = $this->filters();
        $sort    = $this->sort();
        // Client-side paging: the whole filtered set ships once (see
        // assets/js/table.js) so no interaction with the grid reloads the page.
        $result  = LeaveApplication::paginate($filters, $sort, table_row_cap(), 0);

        View::render('applications/index', [
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'filters' => $filters,
            'sort'    => $sort,
            'query'   => $_GET,
            'counts'  => LeaveApplication::counts(),
        ] + $this->lookupData(), 'Leave Applications', 'leave-applications', [
            ['label' => 'Leave & Vacation'],
            ['label' => 'Leave Applications'],
        ]);
    }

    /**
     * GET leave-applications/export?export=csv|xls
     *
     * Exports the CURRENT filter set: the buttons build their links from
     * $filters, so a download always matches what is on screen. Same shape as
     * LeaveController's export so the two screens behave alike.
     */
    public function export(array $params = []): void
    {
        require_login();
        require_permission('reports.export');

        $filters = $this->filters();
        $rows    = LeaveApplication::all($filters, $this->sort());

        $headers = [
            'Badge', 'Driver', 'Iqama', 'Branch', 'Leave Type',
            'Application Date', 'Last Working', 'Expected Return',
            'Approval Status', 'Leave Status', 'Date Created',
            // Same three in the same order as the screen, so a reader can put
            // the file beside the list without re-mapping columns.
            'Last Updated', 'Created By',
            'Destination Country', 'Reason', 'Remarks',
        ];

        $data = array_map(static function (array $r): array {
            return [
                $r['badge_number'],
                $r['driver_name'],
                $r['iqama_number'],
                $r['branch_name'] ?? '',
                $r['leave_type'],
                $r['application_date'],
                $r['last_working_date'] ?? '',
                $r['expected_arrival_date'] ?? '',
                status_label((string) $r['status']),
                // Same helper the screen uses, so a planned trip reads
                // "Planned" in the file exactly as it does on the page.
                LeaveApplication::displayLeaveStatus($r),
                // Same value the list shows: when the row was actually filed,
                // not the application_date the user chose.
                fmt_date_time($r['created_at'] ?? null),
                fmt_date_time($r['updated_at'] ?? null),
                $r['created_by_name'] ?? '',
                $r['destination'] ?? '',
                $r['reason'] ?? '',
                $r['remarks'] ?? '',
            ];
        }, $rows);

        $name   = 'leave-applications-' . date('Ymd-His');
        $format = export_format((string) ($_GET['export'] ?? ''), ['csv', 'xls']);

        if ($format === 'xls') {
            export_xls($headers, $data, $name . '.xls', 'Leave Applications');
        }

        export_csv($headers, $data, $name . '.csv');
    }

    /* ----------------------------------------------------------- create --- */

    public function create(array $params = []): void
    {
        require_login();

        $driver = Driver::find((int) ($_GET['driver_id'] ?? 0));

        // The Address Book's "+" opens this screen, so this is where the
        // "no leave for a deactivated driver" rule has to bite: they get
        // neither the button nor the form.
        if ($driver !== null && (string) $driver['status'] !== 'active') {
            flash_set(
                'warning',
                $driver['driver_name'] . ' is not active ('
                . Lookup::employmentLabel((string) $driver['status'])
                . '). Leave applications can only be raised for active drivers.'
            );
            redirect('drivers/' . (int) $driver['id']);
            return;
        }

        View::render('applications/form', [
            'application' => null,
            'errors'      => [],
            'old'         => $_POST,
            'driver'      => $driver,
        ] + $this->lookupData(), 'New Leave Application', 'leave-applications', [
            ['label' => 'Leave & Vacation', 'url' => url('leave-applications')],
            ['label' => 'New Application'],
        ]);
    }

    public function save(array $params = []): void
    {
        require_login();
        csrf_check();

        // Same rule as create(): a deactivated driver raises no application,
        // whatever a bookmarked form posts.
        $driver = Driver::find((int) ($_POST['driver_id'] ?? 0));

        if ($driver !== null && (string) $driver['status'] !== 'active') {
            flash_set(
                'warning',
                $driver['driver_name'] . ' is not active ('
                . Lookup::employmentLabel((string) $driver['status'])
                . '). Leave applications can only be raised for active drivers.'
            );
            redirect('drivers/' . (int) $driver['id']);
            return;
        }

        $v = $this->validate($_POST);

        if ($v->fails()) {
            $this->form(null, $_POST, $v->errors(), Driver::find((int) ($_POST['driver_id'] ?? 0)),
                'New Leave Application', 'New Application');

            return;
        }

        $id = LeaveApplication::create($this->payload($v));

        audit_log('leave-application-created', 'leave_applications', $id, null, $_POST);

        flash_set('success', 'Leave application recorded.');
        redirect('leave-applications');
    }

    /* ------------------------------------------------------------ view ---- */

    /**
     * GET leave-applications/{id}
     *
     * Read-only target for the View action in the list. Gated by leave.view
     * rather than leave.edit, so a viewer - or Operations once an
     * administrator narrows its grants - can open an application without
     * being handed the ability to change it. Every other list in the app
     * already pointed somewhere; this one had nowhere to go.
     */
    public function view(array $params): void
    {
        require_login();

        $application = LeaveApplication::find((int) ($params['id'] ?? 0));

        if ($application === null) {
            View::error(404, 'That leave application could not be found.');
            return;
        }

        $id = (int) $application['id'];

        View::render('applications/view', [
            'application'     => $application,
            'linkedRecordId'  => LeaveApplication::linkedRecordId($id),
        ], 'Leave Application #' . $id, 'leave-applications', [
            ['label' => 'Leave & Vacation', 'url' => url('leave-applications')],
            ['label' => 'Application #' . $id],
        ]);
    }

    /* ------------------------------------------------------------ edit ---- */

    public function edit(array $params): void
    {
        require_login();

        $application = LeaveApplication::find((int) ($params['id'] ?? 0));

        if ($application === null) {
            View::error(404, 'That leave application could not be found.');
            return;
        }

        $this->form($application, $_POST ?: $application, [], Driver::find((int) $application['driver_id']),
            'Edit Leave Application', 'Edit Application');
    }

    public function update(array $params): void
    {
        require_login();
        csrf_check();

        $id  = (int) ($params['id'] ?? 0);
        $row = LeaveApplication::find($id);

        if ($row === null) {
            View::error(404, 'That leave application could not be found.');
            return;
        }

        $v = $this->validate($_POST);

        if ($v->fails()) {
            $this->form($row, $_POST, $v->errors(), Driver::find((int) ($_POST['driver_id'] ?? 0)),
                'Edit Leave Application', 'Edit Application');

            return;
        }

        $payload = $this->payload($v);

        // Requirement 3: while a record exists, ITS status owns this column -
        // and an unlinked application keeps whatever is stored unless the form
        // actually chose a value (a blank must never reset it).
        if (LeaveApplication::linkedRecordId($id) !== null
            || trim((string) ($payload['leave_status'] ?? '')) === ''
        ) {
            unset($payload['leave_status']);
        }

        LeaveApplication::update($id, $payload);

        audit_log('leave-application-updated', 'leave_applications', $id, $row, $_POST);

        flash_set('success', 'Leave application updated.');
        redirect('leave-applications');
    }

    /* ---------------------------------------------------------- decision -- */

    /** POST leave-applications/{id}/status  with status=pending|approved|rejected|cancelled */
    public function status(array $params): void
    {
        require_login();
        csrf_check();

        $id     = (int) ($params['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');

        if (!in_array($status, LeaveApplication::STATUSES, true)) {
            flash_set('danger', 'Unknown status.');
            redirect('leave-applications');
            return;
        }

        $before = LeaveApplication::find($id);

        if ($before === null) {
            flash_set('danger', 'That leave application could not be found.');
            redirect('leave-applications');
            return;
        }

        // A linked application's decision cannot be reversed underneath the
        // leave record it produced - and its leave status follows the record
        // from here on (requirement 3), so there is nothing left to hand-edit.
        $linked = LeaveApplication::linkedRecordId($id);

        if ($linked !== null) {
            flash_set(
                'warning',
                'Leave record #' . $linked . ' was created from this application, so its'
                . ' decision is closed. Change the leave record instead - this application'
                . ' follows it.'
            );
            redirect('leave-applications/' . $id);
            return;
        }

        LeaveApplication::setStatus($id, $status);

        audit_log('leave-application-status', 'leave_applications', $id,
            ['status' => (string) $before['status']], ['status' => $status]);

        $labels = ['pending' => 'reset to pending', 'approved' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled'];
        flash_set('success', 'Application ' . $labels[$status] . '.');

        redirect('leave-applications');
    }

    public function destroy(array $params): void
    {
        require_login();
        csrf_check();

        $id  = (int) ($params['id'] ?? 0);
        $row = LeaveApplication::find($id);

        if ($row === null) {
            flash_set('danger', 'That leave application could not be found.');
            redirect('leave-applications');
            return;
        }

        // Keep the link: an application that produced a leave record is part
        // of the leave history and cannot be removed from under it.
        $linked = LeaveApplication::linkedRecordId($id);

        if ($linked !== null) {
            flash_set(
                'warning',
                'Leave record #' . $linked . ' came from this application, so the'
                . ' application has to stay. Delete that record first if the pair'
                . ' really has to go.'
            );
            redirect('leave-applications/' . $id);
            return;
        }

        LeaveApplication::delete($id);
        audit_log('leave-application-deleted', 'leave_applications', $id, $row, null);

        flash_set('success', 'Leave application deleted.');
        redirect('leave-applications');
    }

    /* --------------------------------------------------------- internals -- */

    /** Shared renderer for the add / edit form. */
    private function form(
        ?array $application,
        array $old,
        array $errors,
        ?array $driver,
        string $title,
        string $crumb
    ): void {
        View::render('applications/form', [
            'application' => $application,
            'errors'      => $errors,
            'old'         => $old,
            'driver'      => $driver,
        ] + $this->lookupData(), $title, 'leave-applications', [
            ['label' => 'Leave & Vacation', 'url' => url('leave-applications')],
            ['label' => $crumb],
        ]);
    }

    private function validate(array $input): Validator
    {
        $v = new Validator($input);

        $v->required('driver_id', 'Driver')
            ->existsIn('driver_id', 'Driver', 'drivers')
            ->required('leave_type_id', 'Leave type')
            ->existsIn('leave_type_id', 'Leave type', 'leave_types')
            ->required('application_date', 'Application date')
            ->date('application_date', 'Application date')
            // The last day worked is the commitment on this form; when the
            // driver is due back is not. dateNotAfter() returns early when
            // either side is blank, so the order rule needs no change here.
            ->required('last_working_date', 'Last working date')
            ->date('last_working_date', 'Last working date')
            ->date('expected_arrival_date', 'Expected return date')
            ->existsIn('branch_id', 'Branch', 'branches')
            // The last day worked has to fall before the driver is due back.
            ->dateNotAfter('last_working_date', 'Last working date',
                'expected_arrival_date', 'expected return date')
            ->maxLength('destination', 'Destination Country', 120)
            ->maxLength('reason', 'Reason', 255)
            ->maxLength('remarks', 'Remarks', 500);

        return $v;
    }

    private function payload(Validator $v): array
    {
        // Snapshot the branch when the form leaves it blank, exactly as the
        // leave record form does - it is never read live from the driver.
        $driver = Driver::find((int) $v->text('driver_id'));
        $branch = $v->text('branch_id');

        if ($branch === '' && $driver !== null) {
            $branch = (string) ($driver['branch_id'] ?? '');
        }

        // Resignation / Terminated: the driver never comes back, so there is
        // no arrival to expect (round 6, item 2). The rule lives on the model
        // so the form, the controller and the tests share one implementation.
        $expected = LeaveApplication::expectedArrivalFor(
            (int) $v->text('leave_type_id'),
            $v->dateValue('expected_arrival_date')
        );

        return [
            'driver_id'             => (int) $v->text('driver_id'),
            'leave_type_id'         => (int) $v->text('leave_type_id'),
            'branch_id'             => $branch !== '' ? (int) $branch : null,
            'application_date'      => (string) $v->dateValue('application_date'),
            'last_working_date'     => $v->dateValue('last_working_date'),
            'expected_arrival_date' => $expected,
            'destination'           => $v->nullable('destination'),
            'reason'                => $v->nullable('reason'),
            'remarks'               => $v->nullable('remarks'),
            'status'                => (string) $v->text('status') ?: 'pending',
            // Posted by the select (or the hidden field on a linked form).
            // LeaveApplication::update() keeps whatever is stored when the
            // value is blank, and update() below drops it entirely while a
            // record owns the column.
            'leave_status'          => (string) $v->text('leave_status'),
        ];
    }

    /** @return array<string, string> query-string filters */
    private function filters(): array
    {
        $from = (string) ($_GET['date_from'] ?? '');
        $to   = (string) ($_GET['date_to'] ?? '');

        return [
            'q'             => trim((string) ($_GET['q'] ?? '')),
            // The checkbox filters post a comma list ("pending,approved"), so
            // each part is checked against the whitelist rather than the whole
            // string - comparing the joined string rejected every multi-select.
            'status'        => self::allowedList(
                (string) ($_GET['status'] ?? ''),
                LeaveApplication::STATUSES
            ),
            'leave_status'  => self::allowedList(
                (string) ($_GET['leave_status'] ?? ''),
                LeaveApplication::LEAVE_STATUSES
            ),
            'branch_id'     => (string) ($_GET['branch_id'] ?? ''),
            'leave_type_id' => (string) ($_GET['leave_type_id'] ?? ''),
            'date_field'    => self::allowedList(
                (string) ($_GET['date_field'] ?? ''),
                array_keys(LeaveApplication::DATE_FIELDS),
                // Nothing ticked defaults to Last Working Date - the date
                // this register is actually about, not the filing date.
                'last_working_date'
            ),
            'date_from'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : '',
            'date_to'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : '',
        ];
    }

    /**
     * Keep only the values a checkbox filter is allowed to send.
     *
     * A multi-select posts "pending,approved"; testing that whole string
     * against the whitelist failed, so every part is tested and the survivors
     * re-joined. Anything unmatched is dropped - never bound.
     */
    private static function allowedList(string $value, array $allowed, string $fallback = ''): string
    {
        $kept = array_values(array_intersect(filter_values($value), $allowed));

        return $kept === [] ? $fallback : implode(',', $kept);
    }

    /** @return array{0: string, 1: string} */
    private function sort(): array
    {
        $column = (string) ($_GET['sort'] ?? 'application_date');
        $dir    = strtolower((string) ($_GET['dir'] ?? 'desc'));

        if (!isset(LeaveApplication::SORTABLE[$column])) {
            $column = 'application_date';
        }

        return [$column, $dir === 'asc' ? 'asc' : 'desc'];
    }

    /** @return array<string, mixed> */
    private function lookupData(): array
    {
        return [
            'branches'   => Lookup::branchPairs(false),
            'leaveTypes' => Lookup::leaveTypePairs(false),
            // Drivers are chosen with the type-ahead search rather than a
            // 459-row <select>, so there is no reason to hydrate them all.
        ];
    }
}
