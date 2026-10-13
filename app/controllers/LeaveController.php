<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Status;
use App\Core\View;
use App\Models\Driver;
use App\Models\LeaveApplication;
use App\Models\LeaveRecord;
use App\Models\Lookup;

/**
 * Leave & Vacation: entry, current leave, history, calendar, expected
 * returns, mark-returned and cancellation.
 */
final class LeaveController
{
    /** Filters accepted from the query string on every leave list. */
    private function filters(): array
    {
        return [
            'q'            => trim((string)($_GET['q'] ?? '')),
            'branch_id'    => (string)($_GET['branch_id'] ?? ''),
            'agency_id'    => (string)($_GET['agency_id'] ?? ''),
            'leave_type_id'=> (string)($_GET['leave_type_id'] ?? ''),
            'driver_id'    => (string)($_GET['driver_id'] ?? ''),
            'status'       => (string)($_GET['status'] ?? ''),
            'destination'  => trim((string)($_GET['destination'] ?? '')),
            'date_field'   => (string)($_GET['date_field'] ?? 'leave_start_date'),
            'date_from'    => (string)($_GET['date_from'] ?? ''),
            'date_to'      => (string)($_GET['date_to'] ?? ''),
            'returning_month' => (string)($_GET['returning_month'] ?? ''),
        ];
    }

    /**
     * Months offered by the "Returning By Month" filter.
     *
     * Built from the data rather than hard-coded, so the list covers every
     * month that actually has somebody due back - today that runs well past
     * the next month because of the forward-dated planned trips that have
     * since started.
     *
     * Uses exactly the criteria the month card itself uses, so an option can
     * never appear with an empty result set (or leave one out).
     *
     * @return array<string, string> month key (Y-m) => display label
     */
    private static function returningMonthChoices(): array
    {
        // Every month that has a return still due. Planned trips are OUT for
        // the same reason the list below is: a driver who has not left yet
        // cannot be "due back", so a month whose only arrivals are planned
        // ones would offer an option that always renders empty (round 6).
        // Cancelled and already-returned rows stay out for the same reason.
        $months = Database::all(
            "SELECT DISTINCT DATE_FORMAT(expected_arrival_date, '%Y-%m') AS ym
               FROM leave_records
              WHERE actual_arrival_date IS NULL
                AND status NOT IN ('cancelled','not_returned','planned')
                AND expected_arrival_date IS NOT NULL
              ORDER BY ym"
        );

        $choices = [];

        foreach ($months as $row) {
            $key = trim((string)($row['ym'] ?? ''));

            if ($key === '' || strlen($key) !== 7) {
                continue;
            }

            $choices[$key] = date('F Y', (int)strtotime($key . '-01'));
        }

        // The current month is always offered and is the landing spot, even
        // when nothing is due back this month - otherwise the default would
        // silently jump to whichever month happens to come first.
        $current = date('Y-m');
        $choices[$current] = $choices[$current] ?? date('F Y');

        ksort($choices);

        return $choices;
    }

    /**
     * The requested month, validated against the list, defaulting to the
     * current month when it has returns and otherwise to the earliest one that does.
     */
    private function monthKey(string $requested, array $choices): string
    {
        if ($requested !== '' && array_key_exists($requested, $choices)) {
            return $requested;
        }

        $current = date('Y-m');

        if (array_key_exists($current, $choices)) {
            return $current;
        }

        return (string)array_key_first($choices);
    }

    private function sort(string $default = 'leave_start_date', string $dir = 'desc'): array
    {
        return sort_state(LeaveRecord::SORTABLE, $default, $dir);
    }

    private function lookupData(): array
    {
        return [
            'branches'   => Lookup::branchPairs(false),
            'agencies'   => Lookup::agencyPairs(false),
            'leaveTypes' => Lookup::leaveTypePairs(false),
            'drivers'    => Driver::paginate(['status' => 'active'], ['driver_name', 'asc'], 1, 0),
        ];
    }

    /* ------------------------------------------------------- new leave -- */

    public function create(array $params = []): void
    {
        require_login();

        // "Add Leave" on the Address Book and the driver profile both link here
        // with ?driver_id=N. Resolve it so the form opens with that driver
        // already chosen (search box filled + details panel queried) instead of
        // an empty search box the user has to retype into.
        $driverId = (int)($_GET['driver_id'] ?? 0);
        $driver   = $driverId > 0 ? Driver::find($driverId) : null;

        // "Apply New Leave" on an approved application links here with
        // ?application_id=N. The application's leave type, branch, dates,
        // destination, reason and remarks are offered as the starting point
        // and the record is linked to it on save - nothing is written until
        // the form is submitted. The link is MANDATORY on create: the form
        // can be opened bare (the picker then has to be used), but save()
        // refuses a leave with no approved application behind it.
        $application   = null;
        $applicationId = (int)($_GET['application_id'] ?? 0);

        if ($applicationId > 0) {
            $candidate = LeaveApplication::find($applicationId);

            if ($candidate !== null
                && ($driver === null || (int)$candidate['driver_id'] === (int)$driver['id'])
            ) {
                $application = $candidate;
                $driverId    = (int)$candidate['driver_id'];
                $driver      = $driver ?: Driver::find($driverId);
            }
        }

        // Requirement 9 on the SCREEN, not only on the save: a deactivated
        // driver gains no new leave, and a bookmarked ?driver_id= or
        // ?application_id= URL must not open a form whose submit would be
        // refused a moment later anyway. save() still re-checks.
        if ($driver !== null && (string)$driver['status'] !== 'active') {
            flash_set(
                'warning',
                $driver['driver_name'] . ' is not active ('
                . Lookup::employmentLabel((string)$driver['status'])
                . '). Leave can only be recorded for active drivers.'
            );
            redirect('drivers/' . (int)$driver['id']);
            return;
        }

        $old = $_POST ?: [];

        if ($application !== null && $old === []) {
            $old = [
                'leave_type_id'         => $application['leave_type_id'],
                'last_working_date'     => $application['last_working_date'],
                'expected_arrival_date' => $application['expected_arrival_date'],
                'destination'           => $application['destination'],
                'reason'                => $application['reason'],
                'remarks'               => $application['remarks'],
                'leave_application_id'  => $application['id'],
                'driver_id'             => $application['driver_id'],
            ];

            // The seventh linked field: the branch the application was filed
            // under. Only when it carries one - with the key absent the form
            // falls back to the driver's current branch, exactly as it does
            // for a leave with no application behind it.
            if (($application['branch_id'] ?? null) !== null && $application['branch_id'] !== '') {
                $old['branch_id'] = $application['branch_id'];
            }
        }

        // A blank start arrives already derived from the application's last
        // working day, so the field is never empty when there is one to use.
        $old = $this->withDefaultStart($old);

        View::render('leave/form', [
            'leave'       => null,
            'errors'      => [],
            'old'         => $old,
            'driver'      => $driver,
            'application' => $application,
        ] + $this->lookupData(), 'New Leave / Vacation', 'leave-new', [
            ['label' => 'Leave & Vacation'],
            ['label' => 'New Leave / Vacation'],
        ]);
    }

    public function save(array $params = []): void
    {
        require_login();
        csrf_check();

        // Derived first, so required(), the overlap guard, the payload and the
        // audit entry below all read the same date.
        $input = $this->withDefaultStart($_POST);

        // Requirement 9: a deactivated driver gains no new leave. The Address
        // Book row button and the profile header button hide themselves for
        // such drivers; this closes the bookmarked-URL shortcut.
        $prospective = Driver::find((int)($input['driver_id'] ?? 0));

        if ($prospective !== null && (string)$prospective['status'] !== 'active') {
            flash_set(
                'warning',
                $prospective['driver_name'] . ' is not active ('
                . Lookup::employmentLabel((string)$prospective['status'])
                . '). Leave can only be recorded for active drivers.'
            );
            redirect('drivers/' . (int)$prospective['id']);
            return;
        }

        $v = $this->validate($input);
        $this->validateLink($v);

        if ($v->fails()) {
            $driver = Driver::find((int)($input['driver_id'] ?? 0));

            View::render('leave/form', [
                'leave'  => null,
                'errors' => $v->errors(),
                'old'    => $input,
                'driver' => $driver,
            ] + $this->lookupData(), 'New Leave / Vacation', 'leave-new', [
                ['label' => 'Leave & Vacation'],
                ['label' => 'New Leave / Vacation'],
            ]);

            return;
        }

        // Overlap guard (spec section 29).
        $overlap = LeaveRecord::findOverlap(
            (int)$v->text('driver_id'),
            $v->dateValue('leave_start_date'),
            $v->dateValue('expected_arrival_date')
        );

        if ($overlap !== null) {
            flash_set(
                'warning',
                'This driver already has leave from ' . fmt_date($overlap['leave_start_date'])
                . ' to ' . fmt_date($overlap['expected_arrival_date'])
                . ' (' . $overlap['leave_type'] . '). Adjust the dates or open the existing record.',
                ['leave_start_date' => 'Dates overlap an existing leave record.']
            );
            redirect('leave/new');
            return;
        }

        $id = LeaveRecord::create($this->payload($v));

        audit_log('leave-created', 'leave_records', $id, null, $input);

        flash_set('success', 'Leave record created for ' . fmt_date($v->dateValue('leave_start_date')) . '.');
        redirect('leave/' . $id);
    }

    /* ---------------------------------------------------- current leave -- */

    public function current(array $params = []): void
    {
        require_login();

        if (($_GET['export'] ?? '') !== '') {
            $this->export('current');
        }

        $filters = $this->filters();
        $sort    = $this->sort('expected_arrival_date', 'asc');

        $filters['status'] = '';

        $total = LeaveRecord::countCurrentlyAway($filters);
        // Client-side paging: rows ship whole and the browser slices them.
        $rows  = LeaveRecord::currentlyAway($filters, table_row_cap(), 0, $sort);

        View::render('leave/current', [
            'rows'    => $rows,
            'total'   => $total,
            'filters' => $filters,
            'sort'    => $sort,
            'query'   => $_GET,
        ] + $this->lookupData(), 'Current Leave', 'leave-current', [
            ['label' => 'Leave & Vacation'],
            ['label' => 'Current Leave'],
        ]);
    }

    /* ---------------------------------------------------- leave records - */

    public function records(array $params = []): void
    {
        require_login();

        if (($_GET['export'] ?? '') !== '') {
            $this->export('records');
        }

        $filters = $this->filters();
        $sort    = $this->sort();

        // The grid pages itself (assets/js/table.js): ship every matching row
        // once so paging, sorting and Rows-per-page happen in place. The count
        // still comes from paginate()'s own COUNT(*), which is what the pager
        // shows - not the capped row total.
        $result = LeaveRecord::paginate($filters, $sort, table_row_cap(), 0);

        View::render('leave/records', [
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'filters' => $filters,
            'sort'    => $sort,
            'query'   => $_GET,
        ] + $this->lookupData(), 'Leave Records', 'leave-records', [
            ['label' => 'Leave & Vacation'],
            ['label' => 'Leave Records'],
        ]);
    }

    /* ------------------------------------------------------- calendar --- */

    public function calendar(array $params = []): void
    {
        require_login();

        View::render('leave/calendar', [
            'filters' => $this->filters(),
            'query'   => $_GET,
        ] + $this->lookupData(), 'Leave Calendar', 'leave-calendar', [
            ['label' => 'Leave & Vacation'],
            ['label' => 'Calendar'],
        ]);
    }

    /* ------------------------------------------------- expected returns - */

    public function returns(array $params = []): void
    {
        require_login();

        $today    = today();
        $tomorrow = date_add_days($today, 1);
        $weekEnd  = date_add_days($today, 6);

        $filters = $this->filters();

        // "Returning By Month" offers every month that has returns scheduled;
        // an unknown value in the query string falls back to the current month.
        $monthChoices = self::returningMonthChoices();
        $monthKey     = $this->monthKey($filters['returning_month'], $monthChoices);

        $filters['returning_month'] = $monthKey;

        $monthStart = $monthKey . '-01';
        $monthEnd   = date('Y-m-t', (int)strtotime($monthStart));

        $sections = [
            'today'    => LeaveRecord::expectedArrivals($today, $today, $filters),
            'tomorrow' => LeaveRecord::expectedArrivals($tomorrow, $tomorrow, $filters),
            'week'     => LeaveRecord::expectedArrivals($today, $weekEnd, $filters),
            'overdue'  => LeaveRecord::overdue($filters),
            // All returns whose expected return falls inside the chosen
            // month. Planned trips are left out (round 6): a driver who has
            // not left yet has not got a return to make, so a month of
            // nothing but planned arrivals now lists nobody rather than
            // pretending the fleet is coming back.
            'month'    => LeaveRecord::expectedArrivals($monthStart, $monthEnd, $filters, 0),
        ];

        View::render('leave/returns', [
            'sections'     => $sections,
            'today'        => $today,
            'tomorrow'     => $tomorrow,
            'weekEnd'      => $weekEnd,
            'filters'      => $filters,
            'monthChoices' => $monthChoices,
            'monthLabel'   => $monthChoices[$monthKey] ?? $monthKey,
            'strip' => [
                ['label' => 'TODAY', 'date' => fmt_date($today, 'd M'), 'count' => count($sections['today']), 'tone' => 'primary'],
                ['label' => 'TOMORROW', 'date' => fmt_date($tomorrow, 'd M'), 'count' => count($sections['tomorrow']), 'tone' => 'info'],
                ['label' => 'THIS WEEK', 'date' => fmt_date($today, 'd M') . ' – ' . fmt_date($weekEnd, 'd M'), 'count' => count($sections['week']), 'tone' => 'success'],
                ['label' => 'OVERDUE', 'date' => 'Past expected return', 'count' => count($sections['overdue']), 'tone' => 'danger'],
            ],
        ] + $this->lookupData(), 'Expected Returns', 'leave-returns', [
            ['label' => 'Leave & Vacation'],
            ['label' => 'Expected Returns'],
        ]);
    }

    /* --------------------------------------------------- view / edit ---- */

    public function view(array $params): void
    {
        require_login();

        $leave = LeaveRecord::find((int)$params['id']);

        if ($leave === null) {
            View::error(404, 'That leave record could not be found.');
            return;
        }

        // The Not Returned banner names a user id; resolve it once so the
        // details page can say WHO confirmed the closure. The FK is
        // ON DELETE SET NULL, so a removed account falls back to "a user".
        $confirmedBy = null;

        if (!empty($leave['not_returned_by'])) {
            $by = Database::one(
                'SELECT full_name, username FROM users WHERE id = ?',
                [(int) $leave['not_returned_by']]
            );

            if ($by !== null) {
                $confirmedBy = trim($by['full_name'] . ' (' . $by['username'] . ')');
            }
        }

        View::render('leave/view', [
            'leave'       => $leave,
            'history'     => Driver::leaveHistory((int)$leave['driver_id'], 20),
            'confirmedBy' => $confirmedBy,
        ], 'Leave Record #' . (int)$leave['id'], 'leave-records', [
            ['label' => 'Leave & Vacation', 'url' => url('leave/records')],
            ['label' => 'Record #' . (int)$leave['id']],
        ]);
    }

    public function edit(array $params): void
    {
        require_login();

        $leave = LeaveRecord::find((int)$params['id']);

        if ($leave === null) {
            View::error(404, 'That leave record could not be found.');
            return;
        }

        $old = $this->withDefaultStart($_POST ?: $leave);

        View::render('leave/form', [
            'leave'  => $leave,
            'errors' => [],
            'old'    => $old,
            'driver' => Driver::find((int)$leave['driver_id']),
        ] + $this->lookupData(), 'Edit Leave Record', 'leave-records', [
            ['label' => 'Leave & Vacation', 'url' => url('leave/records')],
            ['label' => 'Record #' . (int)$leave['id'], 'url' => url('leave/' . (int)$leave['id'])],
            ['label' => 'Edit'],
        ]);
    }

    public function update(array $params): void
    {
        require_login();
        csrf_check();

        $leave = LeaveRecord::find((int)$params['id']);

        if ($leave === null) {
            View::error(404, 'That leave record could not be found.');
            return;
        }

        if ($leave['status'] === Status::CANCELLED) {
            flash_set('warning', 'Cancelled records cannot be edited. Create a new record instead.');
            redirect('leave/' . (int)$leave['id']);
            return;
        }

        $input = $this->withDefaultStart($_POST);

        $v = $this->validate($input);
        $this->validateLink($v, (int)$leave['id']);

        if ($v->fails()) {
            View::render('leave/form', [
                'leave'  => $leave,
                'errors' => $v->errors(),
                'old'    => $input,
                'driver' => Driver::find((int)$leave['driver_id']),
            ] + $this->lookupData(), 'Edit Leave Record', 'leave-records', [
                ['label' => 'Leave & Vacation', 'url' => url('leave/records')],
                ['label' => 'Record #' . (int)$leave['id'], 'url' => url('leave/' . (int)$leave['id'])],
                ['label' => 'Edit'],
            ]);

            return;
        }

        $overlap = LeaveRecord::findOverlap(
            (int)$v->text('driver_id'),
            $v->dateValue('leave_start_date'),
            $v->dateValue('expected_arrival_date'),
            (int)$leave['id']
        );

        if ($overlap !== null) {
            flash_set('warning', 'The updated dates overlap another leave record for this driver.',
                ['leave_start_date' => 'Dates overlap an existing leave record.']);
            redirect('leave/' . (int)$leave['id'] . '/edit');
            return;
        }

        $before = $leave;
        LeaveRecord::update((int)$leave['id'], $this->payload($v));

        audit_log('leave-updated', 'leave_records', (int)$leave['id'], $before, $input);

        flash_set('success', 'Leave record updated.');
        redirect('leave/' . (int)$leave['id']);
    }

    /* ------------------------------------------------- mark returned ---- */

    /**
     * Quick return from a modal or a list row - the user never has to open
     * the full record. Accepts a normal form post or JSON (Fetch).
     */
    public function markReturned(array $params): void
    {
        require_login();
        csrf_check();

        $input = app_context() === 'api' ? json_input() : $_POST;
        $id    = (int)($params['id'] ?? ($input['id'] ?? 0));
        $leave = LeaveRecord::find($id);

        if ($leave === null) {
            if (app_context() === 'api') {
                json_error('That leave record could not be found.', 404);
            }
            View::error(404, 'That leave record could not be found.');
            return;
        }

        if (!LeaveRecord::canMarkReturned($leave)) {
            $message = 'This record is already closed or cancelled.';
            if (app_context() === 'api') {
                json_error($message, 422);
            }
            flash_set('warning', $message);
            redirect('leave/' . $id);
            return;
        }

        $v = new \App\Core\Validator($input);
        $v->required('actual_arrival_date', 'Actual arrival date')
            ->date('actual_arrival_date', 'Actual arrival date');

        $actual = $v->dateValue('actual_arrival_date');

        // Actual arrival can never be before the driver left (spec section 29).
        if ($actual !== null && $leave['leave_start_date'] !== null
            && $actual < (string)$leave['leave_start_date']
        ) {
            $v->addError('actual_arrival_date', 'Actual arrival cannot be before the leave start date.');
        }

        if ($v->fails()) {
            $message = (string)$v->firstError();
            if (app_context() === 'api') {
                json_error($message, 422, ['field' => 'actual_arrival_date']);
            }
            flash_set('danger', $message);
            redirect('leave/' . $id);
            return;
        }

        $remarks = $v->nullable('return_remarks');

        LeaveRecord::markReturned($id, $actual, $v->nullable('actual_arrival_time'), $remarks);

        // The note travels with the change, exactly as it does for
        // leave-cancelled and leave-not-returned: without it the trail
        // recorded the status flip but not the remark behind it, so a later
        // edit left no trace of what was written the first time.
        audit_log('driver-returned', 'leave_records', $id, [
            'status' => $leave['status'], 'actual_arrival_date' => $leave['actual_arrival_date'],
            'return_remarks' => (string) ($leave['return_remarks'] ?? ''),
        ], [
            'status' => Status::RETURNED, 'actual_arrival_date' => $actual,
            'return_remarks' => (string) ($remarks ?? ''),
        ]);

        if (app_context() === 'api') {
            json_out([
                'ok'      => true,
                'message' => 'Driver marked as returned on ' . fmt_date($actual) . '.',
                'kpis'    => LeaveRecord::kpis(),
            ]);
        }

        flash_set('success', 'Driver marked as returned on ' . fmt_date($actual) . '.');
        redirect('leave/returns');
    }

    /* ------------------------------------------------------- cancel ----- */

    public function cancel(array $params): void
    {
        require_login();
        csrf_check();

        $input = app_context() === 'api' ? json_input() : $_POST;
        $leave = LeaveRecord::find((int)($params['id'] ?? 0));

        if ($leave === null) {
            if (app_context() === 'api') {
                json_error('That leave record could not be found.', 404);
            }
            View::error(404, 'That leave record could not be found.');
            return;
        }

        if ($leave['status'] === Status::CANCELLED) {
            if (app_context() === 'api') {
                json_error('This record is already cancelled.', 422);
            }
            flash_set('info', 'This record is already cancelled.');
            redirect('leave/' . (int)$leave['id']);
            return;
        }

        $reason = trim((string)($input['cancel_reason'] ?? ''));

        if ($reason === '') {
            $message = 'Please give a reason for cancelling.';
            if (app_context() === 'api') {
                json_error($message, 422, ['field' => 'cancel_reason']);
            }
            flash_set('danger', $message);
            redirect('leave/' . (int)$leave['id']);
            return;
        }

        LeaveRecord::cancel((int)$leave['id'], $reason);

        audit_log('leave-cancelled', 'leave_records', (int)$leave['id'],
            ['status' => $leave['status']], ['status' => Status::CANCELLED, 'reason' => $reason]);

        if (app_context() === 'api') {
            json_out(['ok' => true, 'message' => 'Leave record cancelled.']);
        }

        flash_set('success', 'Leave record cancelled.');
        redirect('leave/' . (int)$leave['id']);
    }

    /**
     * POST leave/{id}/not-returned
     *
     * Closes a leave out WITHOUT an arrival: management has confirmed the
     * driver is not coming back. Same guard set as cancel() - login,
     * permission, CSRF - and the status is written straight rather than
     * derived, which is why Status::sync() has to leave it alone afterwards.
     *
     * Unlike the old one-click version, this now requires a reason, the date
     * the decision was taken and remarks. Those four facts (the fourth being
     * the signed-in user) are what turn an unexplained gap in the leave
     * history into a reportable answer.
     */
    public function notReturned(array $params = []): void
    {
        require_login();
        require_permission('leave.return');
        csrf_check();

        $leave = LeaveRecord::find((int)($params['id'] ?? 0));

        if ($leave === null) {
            flash_set('danger', 'That leave record could not be found.');
            redirect('leave/records');
            return;
        }

        if (!LeaveRecord::canMarkNotReturned($leave)) {
            flash_set('warning', 'This record cannot be closed as not returned.');
            redirect('leave/' . (int)$leave['id']);
            return;
        }

        $errors = LeaveRecord::validateNotReturned($_POST, $leave);

        if ($errors !== []) {
            // The form lives in a modal, so say which field was wrong instead
            // of failing silently and leaving the user to guess.
            flash_set('danger', 'Not returned: ' . reset($errors));
            redirect('leave/' . (int)$leave['id']);
            return;
        }

        LeaveRecord::markNotReturned((int)$leave['id'], $_POST);

        audit_log('leave-not-returned', 'leave_records', (int)$leave['id'],
            [
                'status' => $leave['status'],
                'reason' => (string) ($leave['not_returned_reason'] ?? ''),
            ],
            [
                'status'  => Status::NOT_RETURNED,
                'reason'  => trim((string) ($_POST['not_returned_reason'] ?? '')),
                'date'    => trim((string) ($_POST['not_returned_date'] ?? '')),
                'remarks' => trim((string) ($_POST['not_returned_remarks'] ?? '')),
            ]);

        flash_set('success', 'Leave closed as Not Returned.');
        redirect('leave/' . (int)$leave['id']);
    }

    /* ------------------------------------------------------- export ----- */

    /** CSV / JSON export for the Current Leave and Leave Records screens. */
    private function export(string $which): void
    {
        // ?export= is a separate right from viewing the list, and it arrives as
        // a query string the route map cannot see.
        require_permission('reports.export');
        $filters = $this->filters();

        if ($which === 'current') {
            $filters['status'] = '';
            $rows = LeaveRecord::currentlyAway($filters, 0, 0);
        } else {
            $rows = LeaveRecord::all($filters, $this->sort());
        }

        $headers = [
            'Badge', 'Driver', 'Iqama', 'Passport', 'Agency', 'Branch', 'Leave Type',
            'Last Working', 'Leave Start', 'Ticket Date', 'Expected Return',
            'Actual Arrival', 'Destination Country', 'Status', 'Created',
            // Same two as the screen, appended so nothing already in the file
            // changes position.
            'Last Updated', 'Created By',
        ];

        $data = array_map(static function (array $r): array {
            return [
                $r['badge_number'], $r['driver_name'], $r['iqama_number'],
                $r['passport_number'] ?? '', $r['agency_name'],
                $r['branch_name'], $r['leave_type'], $r['last_working_date'], $r['leave_start_date'],
                $r['ticket_date'], $r['expected_arrival_date'], $r['actual_arrival_date'],
                $r['destination'], status_label((string)$r['status']), $r['created_at'],
                $r['updated_at'], $r['created_by_name'] ?? '',
            ];
        }, $rows);

        $format = export_format((string) ($_GET['export'] ?? ''), ['csv', 'xls', 'json']);
        $name   = 'leave-records-' . date('Ymd-His');

        if ($format === 'json') {
            export_json($data, $name . '.json');
        }

        if ($format === 'xls') {
            export_xls($headers, $data, $name . '.xls', 'Leave Records');
        }

        export_csv($headers, $data, $name . '.csv');
    }

    /* --------------------------------------------------------- delete --- */

    /**
     * Admin: delete one leave record.
     *
     * POST /leave/{id}/delete - a leave record has no dependants of its own,
     * so it goes outright; the driver and the leave type are untouched.
     */
    public function destroy(array $params): void
    {
        require_permission('leave.delete');
        csrf_check();

        $record = Database::one(
            'SELECT * FROM leave_records WHERE id = ?',
            [(int)($params['id'] ?? 0)]
        );

        if ($record === null) {
            View::error(404, 'That leave record could not be found.');
            return;
        }

        $linkedApplication = ($record['leave_application_id'] ?? null) !== null
            ? (int)$record['leave_application_id']
            : null;

        Database::run('DELETE FROM leave_records WHERE id = ?', [(int)$record['id']]);

        // The trip is gone, so its application goes back to "not started" and
        // may be applied for again.
        if ($linkedApplication !== null) {
            LeaveApplication::resetLeaveStatus($linkedApplication);
        }

        audit_log('leave-deleted', 'leave_records', (int)$record['id'], $record, null);

        flash_set('success', 'Leave record deleted.');
        redirect($this->safeReturnPath((string)($_POST['return_to'] ?? ''), 'leave/records'));
    }

    /**
     * Admin: delete the leave records ticked in the Leave Records grid.
     *
     * POST /leave/bulk-delete - ids are normalised before use, so a tampered
     * or empty selection changes nothing.
     */
    public function bulkDelete(): void
    {
        require_permission('leave.delete');
        csrf_check();

        $ids = self::selectedIds($_POST['ids'] ?? []);

        if ($ids === []) {
            flash_set('warning', 'Select at least one leave record to delete.');
            redirect($this->safeReturnPath((string)($_POST['return_to'] ?? ''), 'leave/records'));
            return;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        // Collect the applications these records came from before the rows
        // go, so each one can be handed back to "not started" afterwards.
        $linked = Database::all(
            'SELECT DISTINCT leave_application_id FROM leave_records
              WHERE id IN (' . $in . ') AND leave_application_id IS NOT NULL',
            $ids
        );

        $deleted     = Database::run("DELETE FROM leave_records WHERE id IN ($in)", $ids)->rowCount();
        $notDeleted  = count($ids) - $deleted;

        foreach ($linked as $row) {
            LeaveApplication::resetLeaveStatus((int)$row['leave_application_id']);
        }

        audit_log('leave-bulk-deleted', 'leave_records', null, null, [
            'selected' => count($ids),
            'deleted'  => $deleted,
            'ids'      => $ids,
        ]);

        flash_set(
            $deleted > 0 ? 'success' : 'danger',
            $deleted . ' leave record(s) deleted.'
            . ($notDeleted > 0 ? ' ' . $notDeleted . ' no longer existed.' : '')
        );

        redirect($this->safeReturnPath((string)($_POST['return_to'] ?? ''), 'leave/records'));
    }

    /** Normalise a posted id list: ints, unique, > 0. */
    private static function selectedIds($raw): array
    {
        $ids = array_map('intval', array_values((array)$raw));
        $ids = array_unique(array_filter($ids, static fn (int $v): bool => $v > 0));
        sort($ids);

        return array_values($ids);
    }

    /** Guard a posted redirect target: relative paths only, never off-site. */
    private function safeReturnPath(string $path, string $fallback): string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//')) {
            return $fallback;
        }

        return $path;
    }

    /* --------------------------------------------------- validation ---- */

    /**
     * A blank Leave Start Date falls back to the day after the last working
     * day - the driver works that day, so the leave begins the next one.
     *
     * Applied BEFORE validation so required() sees a value instead of
     * rejecting the save, and before the overlap check, the payload and the
     * audit entry so all four agree on the same date. An input already
     * carrying a start (or one with no last working date to derive from) is
     * returned untouched: the default never overwrites a choice and never
     * invents a date out of nothing.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function withDefaultStart(array $input): array
    {
        if (trim((string) ($input['leave_start_date'] ?? '')) !== '') {
            return $input;
        }

        $start = LeaveRecord::defaultStart($input['last_working_date'] ?? null);

        if ($start !== null) {
            $input['leave_start_date'] = $start;
        }

        return $input;
    }

    /**
     * Shared validation for new + edit leave records.
     *
     * @param array<string, mixed> $input
     */
    private function validate(array $input): \App\Core\Validator
    {
        $typeId = (int) ($input['leave_type_id'] ?? 0);

        $v = new \App\Core\Validator($input);

        $v->required('driver_id', 'Driver')
            ->existsIn('driver_id', 'Driver', 'drivers')
            ->required('leave_type_id', 'Leave type')
            ->existsIn('leave_type_id', 'Leave type', 'leave_types')
            ->date('last_working_date', 'Last working date')
            ->required('leave_start_date', 'Leave start date')
            ->date('leave_start_date', 'Leave start date')
            ->date('ticket_date', 'Ticket date');

        // Required only of a type that actually comes back. For Resignation,
        // Terminated and Absconded leave.js hides the field and disables the
        // input, so nothing is posted for it and demanding it here would make
        // those records unsavable. Anything not recognised - no leave type
        // chosen yet, or an id the catalogue does not know - still demands it,
        // so the rule fails closed rather than quietly letting a blank through.
        if ($typeId <= 0 || !in_array($typeId, LeaveApplication::noReturnTypeIds(), true)) {
            $v->required('expected_arrival_date', 'Expected return date');
        }

        $v->date('expected_arrival_date', 'Expected return date')
            ->date('actual_arrival_date', 'Actual arrival date')
            // Spec section 29: a corrected arrival can never predate the day
            // the driver left - same rule Mark Returned enforces.
            ->dateNotBefore('actual_arrival_date', 'Actual arrival date',
                'leave_start_date', 'leave start date')
            // Rule 1: leave start must be on/after the last working day.
            ->dateNotAfter('last_working_date', 'Last working date', 'leave_start_date', 'leave start date')
            // Rule 2: ticket date must be on/after the leave start and the
            // last working day (both optional, skipped when blank).
            ->dateNotBefore('ticket_date', 'Ticket date', 'leave_start_date', 'leave start date')
            ->dateNotBefore('ticket_date', 'Ticket date', 'last_working_date', 'last working date')
            // Rule 3: expected return must fall after all three.
            ->dateAfter('expected_arrival_date', 'Expected return date', 'ticket_date', 'ticket date')
            ->dateAfter('expected_arrival_date', 'Expected return date', 'leave_start_date', 'leave start date')
            ->dateAfter('expected_arrival_date', 'Expected return date', 'last_working_date', 'last working date')
            ->maxLength('passport_number', 'Passport number', 30)
            ->existsIn('branch_id', 'Branch', 'branches')
            ->maxLength('destination', 'Destination Country', 120)
            ->maxLength('reason', 'Reason', 255)
            ->maxLength('remarks', 'Remarks', 500);

        return $v;
    }

    /**
     * The application the form wants to link this record to (requirement 1).
     *
     * On CREATE the link is MANDATORY: a leave is filed against an approved
     * application, so a blank field is itself the failure - the very thing
     * the picker on the form exists to prevent. Everything else is decided
     * by LeaveApplication::linkError(): same driver, approved, not already
     * used by another record.
     *
     * EDITING stays permissive: a legacy record that was filed without an
     * application has to remain correctable, and its link may be cleared
     * (tools/leave-link-test.php asserts both).
     *
     * @param int|null $recordId the record being edited (its own link is fine)
     */
    private function validateLink(\App\Core\Validator $v, ?int $recordId = null): void
    {
        $applicationId = (int)$v->text('leave_application_id');

        if ($applicationId <= 0) {
            if ($recordId === null) {
                $v->addError(
                    'leave_application_id',
                    'Link the approved application this leave was filed under'
                    . ' - a new leave cannot be created without one.'
                );
            }

            return;
        }

        $driverId = (int)$v->text('driver_id');

        if ($driverId <= 0) {
            return;  // driver_id is required anyway; that error covers it
        }

        $error = LeaveApplication::linkError($applicationId, $driverId, $recordId);

        if ($error !== null) {
            $v->addError('leave_application_id', $error);
        }
    }

    /** Normalise the validated payload for create/update. */
    private function payload(\App\Core\Validator $v): array
    {
        // Snapshot the driver's passport and branch whenever the form leaves
        // them blank. They live on the record itself from then on, so a later
        // passport renewal or branch transfer in the address book cannot
        // rewrite this history.
        $driver   = Driver::find((int) $v->text('driver_id'));
        $passport = $v->text('passport_number');
        $branch   = $v->text('branch_id');

        if ($passport === '' && $driver !== null) {
            $passport = (string) ($driver['passport_number'] ?? '');
        }
        if ($branch === '' && $driver !== null) {
            $branch = (string) ($driver['branch_id'] ?? '');
        }

        return [
            'driver_id'             => (int)$v->text('driver_id'),
            'leave_type_id'         => (int)$v->text('leave_type_id'),
            'leave_application_id'  => (int)$v->text('leave_application_id') ?: null,
            'last_working_date'     => $v->dateValue('last_working_date'),
            'leave_start_date'      => (string)$v->dateValue('leave_start_date'),
            'ticket_date'           => $v->dateValue('ticket_date'),
            // Resignation / Terminated / Absconded: the driver never comes
            // back, so there is no arrival to expect (round 6, item 2). The
            // rule lives on the model so the form, this controller, the API
            // and the tests share one implementation - and it is what stops a
            // date picked before the type was switched from being stored.
            'expected_arrival_date' => LeaveApplication::expectedArrivalFor(
                (int) $v->text('leave_type_id'),
                $v->dateValue('expected_arrival_date')
            ),
            'actual_arrival_date'   => $v->dateValue('actual_arrival_date'),
            'actual_arrival_time'   => $v->nullable('actual_arrival_time'),
            'passport_number'       => $passport !== '' ? mb_substr($passport, 0, 30) : null,
            'branch_id'             => $branch !== '' ? (int) $branch : null,
            'destination'           => $v->nullable('destination'),
            'reason'                => $v->nullable('reason'),
            'remarks'               => $v->nullable('remarks'),
            'return_remarks'        => $v->nullable('return_remarks'),
        ];
    }
}


