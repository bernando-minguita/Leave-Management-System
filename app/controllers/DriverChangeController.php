<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Models\Driver;
use App\Models\DriverChangeRequest;
use App\Models\Lookup;

/**
 * Driver master-data change requests: file one, review one, decide one.
 *
 * This is the ordinary path for changing a driver record. Writing master data
 * directly (drivers/{id}/edit) is the exception and needs drivers.master_correct;
 * what a role with `drivers.edit` uses instead is this controller.
 *
 * The write itself happens only in DriverChangeRequest::approve(), which applies
 * the change, writes the history row and closes the request in one transaction.
 */
final class DriverChangeController
{
    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'status'       => (string)($_GET['status'] ?? ''),
            'field'        => (string)($_GET['field'] ?? ''),
            'requested_by' => (string)($_GET['requested_by'] ?? ''),
            'branch_id'    => (string)($_GET['branch_id'] ?? ''),
            'q'            => trim((string)($_GET['q'] ?? '')),
            'date_from'    => (string)($_GET['date_from'] ?? ''),
            'date_to'      => (string)($_GET['date_to'] ?? ''),
        ];
    }

    /** @return array{0:string, 1:string} */
    private function sort(): array
    {
        return sort_state(DriverChangeRequest::SORTABLE, 'requested_at', 'desc');
    }

    /* ------------------------------------------------------------ list -- */

    public function index(array $params = []): void
    {
        require_login();

        // Same pattern as the address book: a ?export the route map cannot see
        // has to be validated here rather than quietly rendering the page.
        if (($_GET['export'] ?? '') !== '') {
            $this->export($params);
            return;
        }

        $filters = $this->filters();
        $sort    = $this->sort();
        $result  = DriverChangeRequest::paginate($filters, $sort, table_row_cap(), 0);

        View::render('drivers/changes', [
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'filters'    => $filters,
            'sort'       => $sort,
            'statuses'   => DriverChangeRequest::STATUSES,
            'fields'     => Driver::GOVERNABLE,
            'branches'   => Lookup::branchPairs(false),
            'requesters' => DriverChangeRequest::requesters(),
            'pending'    => DriverChangeRequest::pendingCount(),
            'query'      => $_GET,
        ], 'Driver Change Requests', 'driver-changes', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => 'Change Requests'],
        ]);
    }

    /** CSV / Excel of the current filtered queue. */
    public function export(array $params = []): void
    {
        require_login();

        // Guarded here as well as by the route map: /driver-changes?export=csv
        // never reaches /driver-changes/export, so it is the same URL the list
        // uses and needs the same check made explicit.
        require_permission(['drivers.edit', 'drivers.change_request_approve']);

        $filters = $this->filters();
        $sort    = $this->sort();

        // No paging on an export - the whole filtered set goes out, exactly as
        // it does for every other export in the product.
        $result = DriverChangeRequest::paginate($filters, $sort, PHP_INT_MAX, 0);

        $headers = [
            'Request #', 'Driver', 'Badge', 'Field', 'Current Value',
            'Requested Value', 'Reason', 'Status', 'Requested By',
            'Requested At', 'Reviewed By', 'Reviewed At', 'Review Note',
            'Effective Date',
        ];

        $data = array_map(static function (array $r): array {
            $field = (string)$r['field_name'];

            return [
                (int)$r['id'],
                (string)$r['driver_name'],
                (string)$r['badge_number'],
                Driver::GOVERNABLE[$field] ?? $field,
                Driver::displayGovernedValue($field, $r['old_value']),
                Driver::displayGovernedValue($field, $r['new_value']),
                (string)$r['reason'],
                DriverChangeRequest::STATUSES[(string)$r['status']]
                    ?? (string)$r['status'],
                (string)($r['requested_by_name'] ?? ''),
                (string)($r['requested_at'] ?? ''),
                (string)($r['reviewed_by_name'] ?? ''),
                (string)($r['reviewed_at'] ?? ''),
                (string)($r['review_note'] ?? ''),
                (string)($r['effective_date'] ?? ''),
            ];
        }, $result['rows']);

        $name   = 'driver-changes-' . date('Ymd-His');
        $format = export_format((string)($_GET['export'] ?? ''), ['csv', 'xls']);

        if ($format === 'xls') {
            export_xls($headers, $data, $name . '.xls', 'Driver Change Requests');
        }

        export_csv($headers, $data, $name . '.csv');
    }

    /* ----------------------------------------------------------- create -- */

    /**
     * The form: one field, its current value, the proposed value, a reason.
     * The master is read at render time so "Current Value" cannot be stale on
     * screen, and again at submit time so it cannot be stale in the row.
     */
    public function create(array $params = []): void
    {
        require_login();

        $driverId = (int)($_GET['driver_id'] ?? 0);
        $driver   = $driverId > 0 ? Driver::find($driverId) : null;

        if ($driverId > 0 && $driver === null) {
            View::error(404, 'That driver could not be found.');
            return;
        }

        $field = (string)($_GET['field'] ?? '');

        $error = (string)($_SESSION['cr_error'] ?? '');
        unset($_SESSION['cr_error']);

        View::render('drivers/change-form', [
            'driver'       => $driver,
            'drivers'      => $driver === null ? $this->driverPicker() : [],
            'field'        => $field,
            'fields'       => Driver::GOVERNABLE,
            'branches'     => Lookup::branchPairs(false),
            'agencies'     => Lookup::agencyPairs(false),
            'statuses'     => Lookup::employmentStatuses(),
            'opStatuses'   => Lookup::operationalStatuses(),
            'currentValue' => $driver !== null && Driver::isGovernable($field)
                ? $driver[$field] ?? null
                : null,
            'input'        => $_SESSION['cr_input'] ?? null,
            'error'        => $error,
        ], 'Request a Driver Change', 'driver-changes', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => 'Change Requests', 'url' => url('driver-changes')],
            ['label' => 'New Request'],
        ]);

        unset($_SESSION['cr_input']);
    }


    public function store(): void
    {
        require_permission('drivers.edit');
        csrf_check();

        $driverId = (int)($_POST['driver_id'] ?? 0);
        $field    = (string)($_POST['field'] ?? '');
        $new      = trim((string)($_POST['new_value'] ?? ''));
        $reason   = trim((string)($_POST['reason'] ?? ''));
        $effective = trim((string)($_POST['effective_date'] ?? ''));

        $driver = $driverId > 0 ? Driver::find($driverId) : null;
        $back   = url('driver-changes/new')
            . ($driverId > 0 ? '?driver_id=' . $driverId : '');

        if ($driver === null) {
            flash_set('danger', 'Choose a driver before filing a request.');
            redirect('driver-changes/new');
            return;
        }

        // Whitelist first: a posted field name is never trusted, even for the
        // length of a query.
        if (!Driver::isGovernable($field)) {
            $this->fail('That field cannot be requested.', $back, $driverId, $field);
            return;
        }

        if (mb_strlen($reason) < 5) {
            $this->fail('Give a reason of at least 5 characters.', $back, $driverId, $field);
            return;
        }

        if ($effective !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) {
            $this->fail('The effective date must be YYYY-MM-DD.', $back, $driverId, $field);
            return;
        }

        $value = DriverChangeRequest::coerce($field, $new);
        $error = DriverChangeRequest::validate($field, $value);

        if ($error !== null) {
            $this->fail($error, $back, $driverId, $field);
            return;
        }

        $old = $driver[$field] ?? null;

        $oldText = ($old === null || $old === '') ? null : (string)$old;
        $newText = ($value === null || $value === '') ? null : (string)$value;

        if ($oldText === $newText) {
            $this->fail(
                'That is already the value on file - there is nothing to change.',
                $back,
                $driverId,
                $field
            );
            return;
        }

        try {
            $id = DriverChangeRequest::submit(
                $driverId,
                $field,
                $value,
                $reason,
                (int)(current_user()['id'] ?? 0),
                $effective !== '' ? $effective : null
            );
        } catch (\RuntimeException $ex) {
            $this->fail($ex->getMessage(), $back, $driverId, $field);
            return;
        }

        audit_log('driver-change-requested', 'driver_change_requests', $id, null, [
            'driver_id'  => $driverId,
            'field_name' => $field,
            'old_value'  => $oldText,
            'new_value'  => $newText,
            'reason'     => $reason,
        ]);

        flash_set(
            'success',
            'Change request #' . $id . ' filed for '
            . $driver['driver_name'] . '. It waits for a reviewer.'
        );
        redirect('driver-changes/' . $id);
    }

    /**
     * Re-render the form with an error. The value the user typed is kept in
     * the session so a rejected reason does not make them retype it.
     */
    private function fail(string $message, string $back, int $driverId, string $field): void
    {
        $_SESSION['cr_error'] = $message;
        $_SESSION['cr_input'] = [
            'driver_id'   => $driverId,
            'field'       => $field,
            'new_value'   => (string)($_POST['new_value'] ?? ''),
            'reason'      => (string)($_POST['reason'] ?? ''),
            'effective_date' => (string)($_POST['effective_date'] ?? ''),
        ];

        redirect($back . (str_contains($back, '?') ? '&' : '?') . 'field=' . urlencode($field));
    }


    /* ------------------------------------------------------------- view -- */

    /**
     * One request, with the decision controls when the viewer may take them.
     * A requester sees Cancel, a reviewer sees Approve / Reject, and neither
     * button is ever rendered for someone who cannot use it - the route map
     * repeats the check server-side, so the buttons are only a courtesy.
     */
    public function view(array $params): void
    {
        require_login();

        $id  = (int)($params['id'] ?? 0);
        $req = DriverChangeRequest::find($id);

        if ($req === null) {
            View::error(404, 'That change request could not be found.');
            return;
        }

        $driver = Driver::find((int)$req['driver_id']);

        if ($driver === null) {
            View::error(404, 'The driver for this request is no longer on file.');
            return;
        }

        $uid        = (int)(current_user()['id'] ?? 0);
        $pending    = (string)$req['status'] === 'pending';
        $canDecide  = can('drivers.change_request_approve');
        $filedIt    = (int)$req['requested_by'] === $uid;

        View::render('drivers/change-view', [
            'request'   => $req,
            'driver'    => $driver,
            'field'     => (string)$req['field_name'],
            'label'     => Driver::GOVERNABLE[(string)$req['field_name']]
                ?? (string)$req['field_name'],
            'oldShown'  => Driver::displayGovernedValue(
                (string)$req['field_name'],
                $req['old_value']
            ),
            'newShown'  => Driver::displayGovernedValue(
                (string)$req['field_name'],
                $req['new_value']
            ),
            'canDecide' => $canDecide,
            'canCancel' => $pending && $filedIt && can('drivers.edit'),
            'pending'   => $pending,
            'error'     => (string)($_SESSION['cr_decision_error'] ?? ''),
        ], 'Change Request #' . $id, 'driver-changes', [
            ['label' => 'Address Book', 'url' => url('drivers')],
            ['label' => 'Change Requests', 'url' => url('driver-changes')],
            ['label' => '#' . $id],
        ]);

        unset($_SESSION['cr_decision_error']);
    }

    /* --------------------------------------------------------- decisions -- */

    public function approve(array $params): void
    {
        require_permission('drivers.change_request_approve');
        csrf_check();

        $id   = (int)($params['id'] ?? 0);
        $note = trim((string)($_POST['review_note'] ?? ''));
        $eff  = trim((string)($_POST['effective_date'] ?? ''));
        $uid  = (int)(current_user()['id'] ?? 0);

        try {
            DriverChangeRequest::approve(
                $id,
                $uid,
                $note !== '' ? $note : null,
                $eff !== '' ? $eff : null
            );
        } catch (\RuntimeException $ex) {
            flash_set('danger', $ex->getMessage());
            redirect('driver-changes/' . $id);
            return;
        }

        flash_set('success', 'Change request #' . $id . ' approved and applied.');
        redirect('driver-changes/' . $id);
    }

    public function reject(array $params): void
    {
        require_permission('drivers.change_request_approve');
        csrf_check();

        $id   = (int)($params['id'] ?? 0);
        $note = trim((string)($_POST['review_note'] ?? ''));

        if (mb_strlen($note) < 5) {
            flash_set('danger', 'Give a reason of at least 5 characters when rejecting.');
            redirect('driver-changes/' . $id);
            return;
        }

        try {
            DriverChangeRequest::reject($id, (int)(current_user()['id'] ?? 0), $note);
        } catch (\RuntimeException $ex) {
            flash_set('danger', $ex->getMessage());
            redirect('driver-changes/' . $id);
            return;
        }

        flash_set('info', 'Change request #' . $id . ' rejected. Nothing was changed.');
        redirect('driver-changes/' . $id);
    }

    public function cancel(array $params): void
    {
        require_permission('drivers.edit');
        csrf_check();

        $id = (int)($params['id'] ?? 0);

        try {
            DriverChangeRequest::cancel($id, (int)(current_user()['id'] ?? 0));
        } catch (\RuntimeException $ex) {
            flash_set('danger', $ex->getMessage());
            redirect('driver-changes/' . $id);
            return;
        }

        flash_set('info', 'Change request #' . $id . ' withdrawn.');
        redirect('driver-changes/' . $id);
    }

    /* ------------------------------------------------------------- misc -- */

    /**
     * id => "Badge - Name" for the driver picker on the new-request screen.
     * Paged by the same cap as every other list so a large book cannot stall
     * the page.
     *
     * @return array<int, string>
     */
    private function driverPicker(): array
    {
        $rows = \App\Core\Database::all(
            'SELECT id, badge_number, driver_name FROM drivers
             ORDER BY badge_number'
        );

        $pairs = [];
        foreach ($rows as $r) {
            $pairs[(int)$r['id']] = (string)$r['badge_number']
                . ' - ' . (string)$r['driver_name'];
        }

        return $pairs;
    }
}

