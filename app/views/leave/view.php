<?php
/**
 * Single leave record - the target of "View" everywhere in the app.
 *
 * Uses: $leave, $history
 */
$leave    = $leave ?? [];
$history  = $history ?? [];
$status   = (string)($leave['status'] ?? '');
// The closure facts name a user id; LeaveController::view() resolves it once
// so the banner can say WHO confirmed the non-return, not a raw id.
$confirmedBy = $confirmedBy ?? null;
// Administrator correction of a dead-end status - same gate and eligibility
// as the row-actions menu (route map: settings.system).
$canChange = can('settings.system')
    && \App\Models\LeaveRecord::canChangeStatus($leave);
// Mark Returned is offered only when the driver is actually away
// (Status::AWAY). A planned trip has not begun, so there is nobody to bring
// back - LeaveRecord::canMarkReturned() is the same rule the API enforces.
$canMark = \App\Models\LeaveRecord::canMarkReturned($leave);
$late     = $leave['actual_arrival_date'] !== null
    ? days_between((string)$leave['expected_arrival_date'], (string)$leave['actual_arrival_date'])
    : days_between((string)$leave['expected_arrival_date'], today());
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Leave Record #' . (int)$leave['id'],
    'description' => (string)$leave['driver_name']
        . ' · Badge ' . (string)$leave['badge_number']
        . ' · ' . status_label($status),
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('leave/records')) . '">'
        . '<i class="bi bi-arrow-left me-1"></i>Back to records</a>'
        . (can('leave.edit') && $status !== 'cancelled'
            ? '<a class="btn btn-outline-primary btn-sm" href="'
              . e(url('leave/' . (int)$leave['id'] . '/edit')) . '">'
              . '<i class="bi bi-pencil me-1"></i>Edit</a>'
            : '')
        . (can('leave.return') && $canMark
            ? '<button class="btn btn-success btn-sm" type="button" data-action="mark-returned" data-id="'
              . (int)$leave['id'] . '" data-driver="' . e((string)$leave['driver_name'])
              . '" data-badge="' . e((string)$leave['badge_number'])
              . '" data-expected="' . e((string)$leave['expected_arrival_date'])
              . '" data-start="' . e((string)$leave['leave_start_date']) . '">'
              . '<i class="bi bi-check2-circle me-1"></i>Mark Returned</button>'
            : '')
        . ($canChange
            ? '<button class="btn btn-outline-primary btn-sm" type="button" data-action="change-status" data-id="'
              . (int)$leave['id'] . '" data-driver="' . e((string)$leave['driver_name'])
              . '" data-badge="' . e((string)$leave['badge_number'])
              . '" data-status-label="' . e(status_label($status))
              . '" data-start="' . e((string)$leave['leave_start_date'])
              . '" data-expected="' . e((string)$leave['expected_arrival_date']) . '">'
              . '<i class="bi bi-arrow-repeat me-1"></i>Change Status</button>'
            : ''),
]) ?>

<!-- Status banner -->
<?php if ($status === 'overdue'): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div><strong><?= max(0, $late) ?> day(s) past the expected return date.</strong>
            Record the actual arrival as soon as the driver is back.</div>
    </div>
<?php elseif ($status === 'returned'): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>
            Returned on <strong><?= e(fmt_date($leave['actual_arrival_date'])) ?></strong>
            <?php if ($late !== 0): ?>
                <span class="badge <?= $late > 0 ? 'text-bg-danger' : 'text-bg-secondary' ?> ms-1">
                    <?= $late > 0 ? $late . 'd late' : abs($late) . 'd early' ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($status === 'cancelled'): ?>
    <div class="alert alert-secondary d-flex align-items-center gap-2">
        <i class="bi bi-slash-circle fs-5"></i>
        <div><strong>Cancelled:</strong> <?= e($leave['cancelled_reason'] ?? 'No reason recorded.') ?></div>
    </div>
<?php elseif ($status === 'not_returned'): ?>
    <?php // The four closure facts are the whole point of the status: WHY it
          // was closed, WHEN the decision was taken, WHO confirmed it and the
          // remarks that make it auditable years later. ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-person-x fs-5"></i>
        <div>
            <strong>Not Returned</strong> — closed on
            <strong><?= e(fmt_date($leave['not_returned_date'] ?? null)) ?></strong>
            for reason: <strong><?= e((string)($leave['not_returned_reason'] ?? '—')) ?></strong>.
            Remarks: <?= e((string)($leave['not_returned_remarks'] ?? '—')) ?>.
            Confirmed by <strong><?= e($confirmedBy ?? 'a user') ?></strong>
        </div>
    </div>
<?php elseif ($status === 'on_leave'): ?>
    <div class="alert alert-primary d-flex align-items-center gap-2">
        <i class="bi bi-airplane fs-5"></i>
        <div>Currently away · <strong>
            <?= max(0, days_between((string)$leave['leave_start_date'], today())) ?> day(s)</strong>
            out, expected back <strong><?= e(fmt_date($leave['expected_arrival_date'])) ?></strong>.</div>
    </div>
<?php else: ?>
    <div class="alert alert-secondary d-flex align-items-center gap-2">
        <i class="bi bi-calendar-event fs-5"></i>
        <div>Starts <strong><?= e(fmt_date($leave['leave_start_date'])) ?></strong>.</div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Dates</h2>
                <?= status_badge($status) ?>
            </div>
            <div class="card-body">
                <dl class="row mb-0 detail-list">
                    <dt>Leave Type</dt><dd><?= e($leave['leave_type'] ?? '—') ?></dd>
                    <?php if (!empty($leave['leave_application_id'])): ?>
                        <?php // Requirement 1: this record came from an application. ?>
                        <dt>Applied via</dt>
                        <dd>
                            <a href="<?= e(url('leave-applications/' . (int)$leave['leave_application_id'])) ?>">
                                <i class="bi bi-file-earmark-text me-1"></i>
                                Application #<?= (int)$leave['leave_application_id'] ?>
                            </a>
                        </dd>
                    <?php endif; ?>
                    <dt>Last Working Date</dt><dd><?= e(fmt_date($leave['last_working_date'])) ?></dd>
                    <dt>Leave Start</dt><dd><strong><?= e(fmt_date($leave['leave_start_date'])) ?></strong></dd>
                    <dt>Ticket Date</dt><dd><?= e(fmt_date($leave['ticket_date'])) ?></dd>
                    <dt>Expected Return</dt>
                    <dd><strong><?= e(fmt_date($leave['expected_arrival_date'])) ?></strong></dd>
                    <dt>Actual Arrival</dt>
                    <dd>
                        <?php if ($leave['actual_arrival_date'] !== null): ?>
                            <strong><?= e(fmt_date($leave['actual_arrival_date'])) ?></strong>
                            <?php if ($leave['actual_arrival_time'] !== null): ?>
                                <span class="text-body-secondary">
                                    <?= e(substr((string)$leave['actual_arrival_time'], 0, 5)) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-body-secondary">Not yet recorded</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Trip Details</h2></div>
            <div class="card-body">
                <dl class="row mb-0 detail-list">
                    <dt>Destination Country</dt><dd><?= e($leave['destination'] ?? '—') ?></dd>
                    <dt>Reason</dt><dd><?= e($leave['reason'] ?? '—') ?></dd>
                    <dt>Remarks</dt><dd><?= e($leave['remarks'] ?? '—') ?></dd>
                    <dt>Return Remarks</dt><dd><?= e($leave['return_remarks'] ?? '—') ?></dd>
                    <dt>Agency</dt><dd><?= e($leave['agency_name'] ?? '—') ?></dd>
                    <dt>Branch</dt><dd><?= e($leave['branch_name'] ?? '—') ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Driver -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Driver</h2>
        <a class="small link-offset-2" href="<?= e(url('drivers/' . (int)$leave['driver_id'])) ?>">
            Open profile</a>
    </div>
    <div class="card-body d-flex flex-wrap gap-4">
        <div><div class="small text-body-secondary">Name</div>
            <strong><?= e($leave['driver_name']) ?></strong></div>
        <div><div class="small text-body-secondary">Badge</div>
            <strong><?= e($leave['badge_number']) ?></strong></div>
        <div><div class="small text-body-secondary">Iqama</div>
            <strong><?= e($leave['iqama_number']) ?></strong></div>
        <div><div class="small text-body-secondary">Mobile</div>
            <strong dir="ltr"><?= e($leave['mobile_number'] ?? '—') ?></strong></div>
        <div><div class="small text-body-secondary">Nationality</div>
            <strong><?= e($leave['nationality'] ?? '—') ?></strong></div>
        <div><div class="small text-body-secondary">Position</div>
            <strong><?= e($leave['position'] ?? '—') ?></strong></div>
    </div>
</div>

<!-- Cancel (only when still open, and only for roles allowed to cancel) -->
<?php if (can('leave.cancel') && $status !== 'cancelled'): ?>
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-body-secondary">
                <i class="bi bi-info-circle me-1"></i>
                Cancelling keeps the record in history but stops it counting as current leave.
            </div>
            <button class="btn btn-outline-danger btn-sm" type="button"
                    data-action="cancel-leave" data-id="<?= (int)$leave['id'] ?>"
                    data-driver="<?= e((string)$leave['driver_name']) ?>">
                <i class="bi bi-slash-circle me-1"></i>Cancel Leave
            </button>
        </div>
    </div>
<?php endif; ?>

<!-- Other records for this driver -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Other records for this driver</h2>
    </div>
    <div class="table-responsive">
        <?= View::partial('partials/leave-history-table', [
            'rows' => array_values(array_filter(
                $history,
                static fn (array $r): bool => (int)$r['id'] !== (int)$leave['id']
            )),
            'compact' => true,
        ]) ?>
    </div>
</div>


