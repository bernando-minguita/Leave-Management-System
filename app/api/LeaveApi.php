<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Status;
use App\Models\Driver;
use App\Models\LeaveApplication;
use App\Models\LeaveRecord;
use App\Models\Lookup;

/**
 * Leave endpoints: create/update/cancel/return, the FullCalendar feed and a
 * live overlap check for the New Leave form.
 *
 * Every handler speaks JSON: {ok: bool, message: string, ...}.
 */
final class LeaveApi
{
    /* -------------------------------------------------------- calendar -- */

    /**
     * GET /api/leave/calendar?start=2026-09-01&end=2026-09-30&...
     * FullCalendar expects all-day events given as plain Y-m-d strings, which
     * keeps them from drifting a day when the browser is not in UTC+3.
     */
    public function calendar(): void
    {
        $start = $this->dateParam('start', date('Y-m-01'));
        $end   = $this->dateParam('end', date('Y-m-t'));

        // Cancelled rows are not returned by the calendar feed, so asking for
        // them (a bookmarked URL, say) is treated as "everything still planned"
        // rather than as a filter that can only ever come back empty.
        $statuses = array_values(array_diff(
            filter_values((string) ($_GET['status'] ?? '')),
            [\App\Core\Status::CANCELLED]
        ));

        $filters = [
            'branch_id'     => (string)($_GET['branch_id'] ?? ''),
            'agency_id'     => (string)($_GET['agency_id'] ?? ''),
            'leave_type_id' => (string)($_GET['leave_type_id'] ?? ''),
            'driver_id'     => (string)($_GET['driver_id'] ?? ''),
            'status'        => implode(',', $statuses),
            'q'             => trim((string)($_GET['q'] ?? '')),
        ];

        $rows = LeaveRecord::forCalendar($start, $end, $filters);

        $events = [];

        foreach ($rows as $row) {
            $status = (string)$row['status'];

            // FullCalendar treats end as exclusive, so add one day.
            $endExclusive = date('Y-m-d', (int)strtotime($row['expected_arrival_date'] . ' +1 day'));

            $short = preg_split('/\s+/', trim((string)$row['driver_name'])) ?: [];
            $first = $short[0] !== '' && $short[0] !== null ? (string)$short[0] : (string)$row['driver_name'];

            $events[] = [
                'id'      => (int)$row['id'],
                'title'   => $first . ' - ' . $row['leave_type'],
                'start'   => (string)$row['leave_start_date'],
                'end'     => $endExclusive,
                'allDay'  => true,
                'status'  => $status,
                'color'   => $this->colour($status),
                'textColor' => $this->textColour($status),
                'extendedProps' => [
                    'driver'      => (string)$row['driver_name'],
                    'badge'       => (string)$row['badge_number'],
                    'branch'      => (string)($row['branch_name'] ?? ''),
                    'agency'      => (string)($row['agency_name'] ?? ''),
                    'leaveType'   => (string)$row['leave_type'],
                    'destination' => (string)($row['destination'] ?? ''),
                    'expected'    => (string)$row['expected_arrival_date'],
                    'actual'      => (string)($row['actual_arrival_date'] ?? ''),
                    'statusLabel' => status_label($status),
                ],
            ];
        }

        json_out(['ok' => true, 'events' => $events]);
    }

    /** Distinct colours per status (mirrors the legend in the view). */
    private function colour(string $status): string
    {
        return match ($status) {
            Status::PLANNED   => '#6c757d',
            Status::ON_LEAVE  => '#1d4ed8',
            Status::RETURNED  => '#198754',
            Status::OVERDUE   => '#dc3545',
            Status::CANCELLED => '#adb5bd',
            default           => '#6c757d',
        };
    }

    private function textColour(string $status): string
    {
        return in_array($status, [Status::CANCELLED], true) ? '#212529' : '#ffffff';
    }

    /* --------------------------------------------------- overlap check -- */

    /**
     * GET /api/leave/overlap-check?driver_id=&start=&end=&id=
     * Gives the New Leave form instant feedback before the POST happens.
     */
    public function overlapCheck(): void
    {
        $driverId = (int)($_GET['driver_id'] ?? 0);
        $start    = $this->dateParam('start', '');
        $end      = $this->dateParam('end', '');
        $exceptId = (int)($_GET['id'] ?? 0);

        if ($driverId === 0 || $start === '' || $end === '') {
            json_out(['ok' => true, 'overlap' => null]);
        }

        $overlap = LeaveRecord::findOverlap(
            $driverId,
            $start,
            $end,
            $exceptId > 0 ? $exceptId : null
        );

        json_out([
            'ok'      => true,
            'overlap' => $overlap === null ? null : [
                'id'         => (int)$overlap['id'],
                'status'     => (string)$overlap['status'],
                'start'      => (string)$overlap['leave_start_date'],
                'end'        => (string)$overlap['expected_arrival_date'],
                'leave_type' => (string)($overlap['leave_type'] ?? ''),
            ],
        ]);
    }

    /* ---------------------------------------------------------- view ---- */

    /** GET /api/leave/view?id= - used by the "quick peek" modal. */
    public function view(): void
    {
        $id    = (int)($_GET['id'] ?? 0);
        $leave = $id > 0 ? LeaveRecord::find($id) : null;

        if ($leave === null) {
            json_error('That leave record could not be found.', 404);
        }

        json_out([
            'ok'    => true,
            'leave' => [
                'id'              => (int)$leave['id'],
                'status'          => (string)$leave['status'],
                'status_label'    => status_label((string)$leave['status']),
                'driver'          => (string)$leave['driver_name'],
                'badge'           => (string)$leave['badge_number'],
                'leave_type'      => (string)($leave['leave_type'] ?? ''),
                'leave_start'     => (string)$leave['leave_start_date'],
                'expected'        => (string)$leave['expected_arrival_date'],
                'actual'          => (string)($leave['actual_arrival_date'] ?? ''),
                'destination'     => (string)($leave['destination'] ?? ''),
                'reason'          => (string)($leave['reason'] ?? ''),
                'remarks'         => (string)($leave['remarks'] ?? ''),
                'return_remarks'  => (string)($leave['return_remarks'] ?? ''),
                'cancelled_reason'=> (string)($leave['cancelled_reason'] ?? ''),
            ],
        ]);
    }

    /* --------------------------------------------------------- create --- */

    /**
     * GET /api/leave/open-applications?driver_id=N[&q=]
     *
     * The list the Apply / Edit Leave form offers when you pick an
     * application to link the record to: approved, the leave has not
     * started, and no record has been created from it yet - one driver at a
     * time, because the link is per driver.
     */
    public function openApplications(): void
    {
        $driverId = (int)($_GET['driver_id'] ?? 0);

        if ($driverId <= 0) {
            json_error('driver_id is required.', 422);
        }

        $rows = LeaveApplication::openForDriver($driverId, (string)($_GET['q'] ?? ''));

        json_out([
            'ok'            => true,
            'applications'  => array_map(static function (array $row): array {
                return [
                    'id'                   => (int)$row['id'],
                    'application_date'     => (string)$row['application_date'],
                    'leave_type_id'        => (int)$row['leave_type_id'],
                    'leave_type'           => (string)$row['leave_type'],
                    'branch_id'            => $row['branch_id'] !== null ? (int)$row['branch_id'] : null,
                    'last_working_date'    => $row['last_working_date'] ?? null,
                    'expected_arrival_date'=> $row['expected_arrival_date'] ?? null,
                    'destination'          => $row['destination'] ?? null,
                    'reason'               => $row['reason'] ?? null,
                    'remarks'              => $row['remarks'] ?? null,
                ];
            }, $rows),
        ]);
    }

    public function create(): void
    {
        $input  = json_input();
        $typeId = (int) ($input['leave_type_id'] ?? 0);

        // Same default as the web form: a blank leave start is the day after
        // the last working day, applied before the validator reads it.
        if (trim((string) ($input['leave_start_date'] ?? '')) === '') {
            $derived = LeaveRecord::defaultStart($input['last_working_date'] ?? null);

            if ($derived !== null) {
                $input['leave_start_date'] = $derived;
            }
        }

        $v = new \App\Core\Validator($input);
        $v->required('driver_id', 'Driver')
            ->existsIn('driver_id', 'Driver', 'drivers')
            ->required('leave_type_id', 'Leave type')
            ->existsIn('leave_type_id', 'Leave type', 'leave_types')
            ->required('leave_start_date', 'Leave start date')
            ->date('leave_start_date', 'Leave start date')
            ->date('expected_arrival_date', 'Expected return date')
            ->date('last_working_date', 'Last working date')
            ->date('ticket_date', 'Ticket date')
            ->dateNotAfter('last_working_date', 'Last working date', 'leave_start_date', 'leave start date')
            ->dateNotBefore('ticket_date', 'Ticket date', 'leave_start_date', 'leave start date')
            ->dateNotBefore('ticket_date', 'Ticket date', 'last_working_date', 'last working date')
            ->dateAfter('expected_arrival_date', 'Expected return date', 'ticket_date', 'ticket date')
            ->dateAfter('expected_arrival_date', 'Expected return date', 'leave_start_date', 'leave start date')
            ->dateAfter('expected_arrival_date', 'Expected return date', 'last_working_date', 'last working date')
            ->maxLength('passport_number', 'Passport number', 30)
            ->existsIn('branch_id', 'Branch', 'branches');

        // Expected return is demanded only of a leave type that actually comes
        // back; LeaveController::validate() holds the same rule and the reason
        // it fails closed for anything not recognised.
        if ($typeId <= 0 || !in_array($typeId, LeaveApplication::noReturnTypeIds(), true)) {
            $v->required('expected_arrival_date', 'Expected return date');
        }

        if ($v->fails()) {
            json_error((string)$v->firstError(), 422, ['errors' => $v->errors()]);
        }

        // The application this record is applied for (requirement 1): same
        // rules the web form enforces - approved, same driver, not already
        // used by another record - and, since a leave is always filed against
        // an application, the field itself is required here too.
        $applicationId = (int)$v->text('leave_application_id');

        if ($applicationId <= 0) {
            $message = 'Link the approved application this leave was filed under'
                . ' - a new leave cannot be created without one.';

            json_error($message, 422, ['errors' => ['leave_application_id' => $message]]);
        }

        $linkError = LeaveApplication::linkError($applicationId, (int)$v->text('driver_id'));

        if ($linkError !== null) {
            json_error($linkError, 422, ['errors' => ['leave_application_id' => $linkError]]);
        }

        $overlap = LeaveRecord::findOverlap(
            (int)$v->text('driver_id'),
            (string)$v->dateValue('leave_start_date'),
            $v->dateValue('expected_arrival_date')
        );

        if ($overlap !== null) {
            json_error(
                'This driver already has leave from ' . fmt_date($overlap['leave_start_date'])
                . ' to ' . fmt_date($overlap['expected_arrival_date']) . '.',
                409,
                ['overlap' => ['id' => (int)$overlap['id']]]
            );
        }

        // Source for the passport/branch snapshot when the payload omits them.
        $driverRow = Driver::find((int) $v->text('driver_id'));

        // Requirement 9 again, on the API path: a deactivated driver gains no
        // new leave, only corrections to what they already have.
        if ($driverRow !== null && (string)$driverRow['status'] !== 'active') {
            json_error(
                'Leave can only be recorded for active drivers ('
                . Lookup::employmentLabel((string)$driverRow['status']) . ').',
                403
            );
        }

        $id = LeaveRecord::create([
            'driver_id'             => (int)$v->text('driver_id'),
            'leave_type_id'         => (int)$v->text('leave_type_id'),
            'leave_application_id'  => $applicationId > 0 ? $applicationId : null,
            'last_working_date'     => $v->dateValue('last_working_date'),
            'leave_start_date'      => (string)$v->dateValue('leave_start_date'),
            'ticket_date'           => $v->dateValue('ticket_date'),
            // Resignation / Terminated / Absconded keep no expected return;
            // the rule lives on the model so every path shares it.
            'expected_arrival_date' => LeaveApplication::expectedArrivalFor(
                (int) $v->text('leave_type_id'),
                $v->dateValue('expected_arrival_date')
            ),
            'passport_number'       => $v->text('passport_number') !== ''
                ? mb_substr($v->text('passport_number'), 0, 30)
                : (string) ($driverRow['passport_number'] ?? ''),
            'branch_id'             => $v->text('branch_id') !== ''
                ? (int) $v->text('branch_id')
                : (($driverRow['branch_id'] ?? '') !== '' && $driverRow['branch_id'] !== null
                    ? (int) $driverRow['branch_id'] : null),
            'destination'           => $v->nullable('destination'),
            'reason'                => $v->nullable('reason'),
            'remarks'               => $v->nullable('remarks'),
        ]);

        audit_log('leave-created', 'leave_records', $id, null, $input);

        json_out(['ok' => true, 'id' => $id, 'message' => 'Leave record created.', 'kpis' => LeaveRecord::kpis()]);
    }

    /* --------------------------------------------------------- update --- */

    public function update(): void
    {
        $input  = json_input();
        $id     = (int)($input['id'] ?? 0);
        $leave  = LeaveRecord::find($id);

        if ($leave === null) {
            json_error('That leave record could not be found.', 404);
        }

        if ($leave['status'] === Status::CANCELLED) {
            json_error('Cancelled records cannot be edited.', 422);
        }

        $typeId = (int) ($input['leave_type_id'] ?? 0);

        // Same default as the web form - see create().
        if (trim((string) ($input['leave_start_date'] ?? '')) === '') {
            $derived = LeaveRecord::defaultStart($input['last_working_date'] ?? null);

            if ($derived !== null) {
                $input['leave_start_date'] = $derived;
            }
        }

        $v = new \App\Core\Validator($input);
        $v->required('leave_type_id', 'Leave type')
            ->existsIn('leave_type_id', 'Leave type', 'leave_types')
            ->required('leave_start_date', 'Leave start date')
            ->date('leave_start_date', 'Leave start date')
            ->date('expected_arrival_date', 'Expected return date')
            ->date('last_working_date', 'Last working date')
            ->date('ticket_date', 'Ticket date')
            ->dateNotAfter('last_working_date', 'Last working date', 'leave_start_date', 'leave start date')
            ->dateNotBefore('ticket_date', 'Ticket date', 'leave_start_date', 'leave start date')
            ->dateNotBefore('ticket_date', 'Ticket date', 'last_working_date', 'last working date')
            ->dateAfter('expected_arrival_date', 'Expected return date', 'ticket_date', 'ticket date')
            ->dateAfter('expected_arrival_date', 'Expected return date', 'leave_start_date', 'leave start date')
            ->dateAfter('expected_arrival_date', 'Expected return date', 'last_working_date', 'last working date')
            ->maxLength('passport_number', 'Passport number', 30)
            ->existsIn('branch_id', 'Branch', 'branches');

        // Same conditional requirement as create(), and the same reason: a
        // no-return type never posts the field.
        if ($typeId <= 0 || !in_array($typeId, LeaveApplication::noReturnTypeIds(), true)) {
            $v->required('expected_arrival_date', 'Expected return date');
        }

        if ($v->fails()) {
            json_error((string)$v->firstError(), 422, ['errors' => $v->errors()]);
        }

        $overlap = LeaveRecord::findOverlap(
            (int)$leave['driver_id'],
            (string)$v->dateValue('leave_start_date'),
            $v->dateValue('expected_arrival_date'),
            $id
        );

        if ($overlap !== null) {
            json_error('The updated dates overlap another leave record for this driver.', 409);
        }

        $before = $leave;

        LeaveRecord::update($id, [
            'leave_type_id'         => (int)$v->text('leave_type_id'),
            'last_working_date'     => $v->dateValue('last_working_date'),
            'leave_start_date'      => (string)$v->dateValue('leave_start_date'),
            'ticket_date'           => $v->dateValue('ticket_date'),
            'expected_arrival_date' => LeaveApplication::expectedArrivalFor(
                (int) $v->text('leave_type_id'),
                $v->dateValue('expected_arrival_date')
            ),
            // Keep the existing snapshot unless the caller sends a new one, so
            // an update that omits these fields cannot silently blank them.
            'passport_number'       => $v->text('passport_number') !== ''
                ? mb_substr($v->text('passport_number'), 0, 30)
                : (string) ($leave['passport_number'] ?? ''),
            'branch_id'             => $v->text('branch_id') !== ''
                ? (int) $v->text('branch_id')
                : (($leave['branch_id'] ?? null) !== null ? (int) $leave['branch_id'] : null),
            'destination'           => $v->nullable('destination'),
            'reason'                => $v->nullable('reason'),
            'remarks'               => $v->nullable('remarks'),
        ]);

        audit_log('leave-updated', 'leave_records', $id, $before, $input);

        json_out(['ok' => true, 'message' => 'Leave record updated.', 'kpis' => LeaveRecord::kpis()]);
    }

    /* ------------------------------------------------- mark returned ---- */

    /** POST /api/leave/mark-returned - backs the quick modal. */
    public function markReturned(): void
    {
        $input = json_input();
        $id    = (int)($input['id'] ?? 0);
        $leave = LeaveRecord::find($id);

        if ($leave === null) {
            json_error('That leave record could not be found.', 404);
        }

        if (!LeaveRecord::canMarkReturned($leave)) {
            json_error('This record is already closed or cancelled.', 422);
        }

        $v = new \App\Core\Validator($input);
        $v->required('actual_arrival_date', 'Actual arrival date')
            ->date('actual_arrival_date', 'Actual arrival date')
            ->maxLength('return_remarks', 'Return remarks', 500);

        $actual = $v->dateValue('actual_arrival_date');

        if ($actual !== null && $actual < (string)$leave['leave_start_date']) {
            $v->addError('actual_arrival_date', 'Actual arrival cannot be before the leave start date.');
        }

        if ($v->fails()) {
            json_error((string)$v->firstError(), 422, ['errors' => $v->errors()]);
        }

        $time    = $v->nullable('actual_arrival_time');
        $remarks = $v->nullable('return_remarks');

        LeaveRecord::markReturned($id, (string)$actual, $time, $remarks);

        // The note travels with the change, exactly as it does for
        // leave-cancelled and leave-not-returned: without it the trail
        // recorded the status flip but not the remark behind it, so a later
        // edit left no trace of what was written the first time.
        audit_log('driver-returned', 'leave_records', $id, [
            'status' => $leave['status'], 'actual_arrival_date' => $leave['actual_arrival_date'],
            'return_remarks' => (string) ($leave['return_remarks'] ?? ''),
        ], [
            'status' => Status::RETURNED, 'actual_arrival_date' => $actual,
            'time' => $time, 'return_remarks' => (string) ($remarks ?? ''),
        ]);

        json_out([
            'ok'      => true,
            'message' => 'Driver marked as returned on ' . fmt_date($actual) . '.',
            'kpis'    => LeaveRecord::kpis(),
        ]);
    }

    /* --------------------------------------------------------- cancel --- */

    public function cancel(): void
    {
        $input = json_input();
        $id    = (int)($input['id'] ?? 0);
        $leave = LeaveRecord::find($id);

        if ($leave === null) {
            json_error('That leave record could not be found.', 404);
        }

        if ($leave['status'] === Status::CANCELLED) {
            json_error('This record is already cancelled.', 422);
        }

        $reason = trim((string)($input['cancel_reason'] ?? ''));

        if ($reason === '') {
            json_error('Please give a reason for cancelling.', 422, ['field' => 'cancel_reason']);
        }

        LeaveRecord::cancel($id, $reason);

        audit_log('leave-cancelled', 'leave_records', $id,
            ['status' => $leave['status']],
            ['status' => Status::CANCELLED, 'reason' => $reason]);

        json_out(['ok' => true, 'message' => 'Leave record cancelled.', 'kpis' => LeaveRecord::kpis()]);
    }

    /**
     * POST /api/leave/not-returned
     *
     * Close a leave out as a non-return. Unlike cancel(), this takes three
     * mandatory fields - reason, decision date and remarks - because the whole
     * point of the status is that it can be reported on afterwards. The user
     * who confirmed it is taken from the session, never from the request.
     */
    public function notReturned(): void
    {
        $input = json_input();
        $id    = (int)($input['id'] ?? 0);
        $leave = LeaveRecord::find($id);

        if ($leave === null) {
            json_error('That leave record could not be found.', 404);
        }

        if (!LeaveRecord::canMarkNotReturned($leave)) {
            json_error('This record cannot be closed as not returned.', 422);
        }

        $errors = LeaveRecord::validateNotReturned($input, $leave);

        if ($errors !== []) {
            json_error(reset($errors), 422, ['errors' => $errors]);
        }

        LeaveRecord::markNotReturned($id, $input);

        audit_log('leave-not-returned', 'leave_records', $id,
            ['status' => $leave['status'], 'reason' => (string) ($leave['not_returned_reason'] ?? '')],
            [
                'status'  => Status::NOT_RETURNED,
                'reason'  => trim((string) ($input['not_returned_reason'] ?? '')),
                'date'    => trim((string) ($input['not_returned_date'] ?? '')),
                'remarks' => trim((string) ($input['not_returned_remarks'] ?? '')),
            ]);

        json_out(['ok' => true, 'message' => 'Leave closed as Not Returned.', 'kpis' => LeaveRecord::kpis()]);
    }

    /**
     * POST /api/leave/change-status
     *
     * The administrator's correction valve. Not Returned and Cancelled are
     * terminal by design - Status::sync() never recomputes them and no other
     * action can move them - so reversing a mistaken closure (the driver
     * reappears; the leave is extended) can only happen through here.
     *
     * The route map gates this on settings.system, the permission only
     * administrators hold by default, so no new permission row is needed.
     * The chosen target must survive the next Status::sync(), which
     * validateStatusChange() enforces against the record's dates.
     */
    public function changeStatus(): void
    {
        $input = json_input();
        $id    = (int) ($input['id'] ?? 0);
        $leave = LeaveRecord::find($id);

        if ($leave === null) {
            json_error('That leave record could not be found.', 404);
        }

        if (!LeaveRecord::canChangeStatus($leave)) {
            json_error('Only a closed record (Not Returned or Cancelled) can be re-opened.', 422);
        }

        $errors = LeaveRecord::validateStatusChange($input, $leave);

        if ($errors !== []) {
            json_error(reset($errors), 422, ['errors' => $errors]);
        }

        LeaveRecord::changeStatus($id, $leave, $input);

        // The reversal belongs in the audit trail: what the record said, what
        // it says now, and why (the remarks travel with it).
        audit_log('leave-status-changed', 'leave_records', $id,
            [
                'status'               => (string) $leave['status'],
                'expected_arrival_date'=> (string) $leave['expected_arrival_date'],
                'actual_arrival_date'  => (string) ($leave['actual_arrival_date'] ?? ''),
                'not_returned_reason'  => (string) ($leave['not_returned_reason'] ?? ''),
                'not_returned_remarks' => (string) ($leave['not_returned_remarks'] ?? ''),
                'cancelled_reason'     => (string) ($leave['cancelled_reason'] ?? ''),
            ],
            [
                'status'                => $input['new_status'],
                'expected_arrival_date' => (string) ($input['expected_arrival_date'] ?? $leave['expected_arrival_date']),
                'actual_arrival_date'   => (string) ($input['actual_arrival_date'] ?? ''),
                'remarks'               => trim((string) ($input['remarks'] ?? '')),
            ]);

        json_out([
            'ok'      => true,
            'message' => 'Leave status changed to ' . status_label((string) $input['new_status']) . '.',
            'kpis'    => LeaveRecord::kpis(),
        ]);
    }

    /* ---------------------------------------------------------- helper -- */

    /** Read a Y-m-d parameter, falling back to $default when absent/invalid. */
    private function dateParam(string $key, string $default): string
    {
        $value = trim((string)($_GET[$key] ?? ''));

        if ($value === '') {
            return $default;
        }

        // FullCalendar sends ISO timestamps - keep just the date part.
        if (strpos($value, 'T') !== false) {
            $value = substr($value, 0, (int)strpos($value, 'T'));
        }

        return strtotime($value) === false ? $default : date('Y-m-d', (int)strtotime($value));
    }
}


