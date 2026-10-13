<?php
/**
 * Single leave application - the target of "View" on the applications list.
 *
 * Every other list in the app already has a View action (leave records,
 * current leave, returns, drivers); this register did not, so a role holding
 * only leave.view - Viewer, and Operations if an administrator narrows its
 * grants - saw a completely empty Actions column with no way in at all.
 *
 * Deliberately read-only: leave.view opens it, leave.edit is still required
 * to change anything, so widening View widens nothing else.
 *
 * Uses: $application
 */
$application = $application ?? [];
$id          = (int) ($application['id'] ?? 0);
$driverId    = (int) ($application['driver_id'] ?? 0);
$status      = (string) ($application['status'] ?? '');
$leaveStatus = (string) ($application['leave_status'] ?? '');
// Requirement 9 beats requirement 5: an approved-and-not-started application
// only offers "Apply New Leave" while its driver is still employed, because
// save() would refuse the POST anyway. See applications/index.php.
$driverStatus = (string) ($application['driver_status'] ?? '');
$canEdit      = can('leave.edit');
// Set by LeaveApplicationController::view(): the record this application
// produced, when it has one (requirement 5's other half).
$linkedRecordId = (int) ($linkedRecordId ?? 0);
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Leave Application #' . $id,
    'description' => (string) ($application['driver_name'] ?? '')
        . ' · Badge ' . (string) ($application['badge_number'] ?? '')
        . ' · ' . status_label($status),
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="'
        . e(url('leave-applications')) . '">'
        . '<i class="bi bi-arrow-left me-1"></i>Back to applications</a>'
        // Requirement 5: the approved-and-not-started application carries the
        // button that turns it into a record; a linked one links to it instead.
        . ($linkedRecordId > 0
            ? '<a class="btn btn-outline-primary btn-sm" href="'
              . e(url('leave/' . $linkedRecordId)) . '">'
              . '<i class="bi bi-file-earmark-check me-1"></i>Leave record #'
              . $linkedRecordId . '</a>'
            : ($status === 'approved' && $leaveStatus === 'not_started'
                && $driverStatus === 'active' && can('leave.create')
                ? '<a class="btn btn-primary btn-sm" href="'
                  . e(url('leave/new')) . '?application_id=' . $id
                  . '&driver_id=' . $driverId . '">'
                  . '<i class="bi bi-plus-circle me-1"></i>Apply New Leave</a>'
                : ''))
        . ($canEdit && $status !== 'cancelled'
            ? '<a class="btn btn-outline-primary btn-sm" href="'
              . e(url('leave-applications/' . $id . '/edit')) . '">'
              . '<i class="bi bi-pencil me-1"></i>Edit</a>'
            : ''),
]) ?>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Application</h2>
                <?= status_badge($status) ?>
            </div>
            <div class="card-body">
                <dl class="row mb-0 detail-list">
                    <dt>Leave Type</dt><dd><?= e($application['leave_type'] ?? '—') ?></dd>
                    <dt>Branch</dt><dd><?= e($application['branch_name'] ?? '—') ?></dd>
                    <dt>Application Date</dt>
                    <dd><?= e(fmt_date($application['application_date'] ?? null)) ?></dd>
                    <dt>Last Working Date</dt>
                    <dd><?= e(fmt_date($application['last_working_date'] ?? null)) ?></dd>
                    <dt>Expected Return</dt>
                    <dd><strong><?= e(fmt_date($application['expected_arrival_date'] ?? null)) ?></strong></dd>
                    <dt>Destination Country</dt><dd><?= e($application['destination'] ?? '—') ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Decision</h2>
                <?= status_badge($leaveStatus) ?>
            </div>
            <div class="card-body">
                <dl class="row mb-0 detail-list">
                    <dt>Approval Status</dt><dd><?= status_badge($status) ?></dd>
                    <dt>Leave Status</dt><dd><?= status_badge($leaveStatus) ?></dd>
                    <?php if ($linkedRecordId > 0): ?>
                        <?php // Requirement 3: while a record exists, its status is the truth. ?>
                        <dt>Signed off by</dt>
                        <dd class="small mb-0">
                            <i class="bi bi-link-45deg me-1"></i>
                            Leave record #<?= $linkedRecordId ?> - this Leave Status
                            follows that record automatically.
                        </dd>
                    <?php endif; ?>
                    <dt>Reason</dt><dd><?= e($application['reason'] ?? '—') ?></dd>
                    <dt>Remarks</dt><dd><?= e($application['remarks'] ?? '—') ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Driver -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Driver</h2>
        <a class="small link-offset-2" href="<?= e(url('drivers/' . $driverId)) ?>">
            Open profile</a>
    </div>
    <div class="card-body d-flex flex-wrap gap-4">
        <div><div class="small text-body-secondary">Name</div>
            <strong><?= e($application['driver_name'] ?? '—') ?></strong></div>
        <div><div class="small text-body-secondary">Badge</div>
            <strong><?= e($application['badge_number'] ?? '—') ?></strong></div>
        <div><div class="small text-body-secondary">Iqama</div>
            <strong><?= e($application['iqama_number'] ?? '—') ?></strong></div>
    </div>
</div>

<!-- Widen nothing by accident: reading is allowed, changing is not. -->
<?php if (! $canEdit): ?>
    <p class="small text-body-secondary mt-3 mb-0">
        <i class="bi bi-lock me-1"></i>You can view this application, but changing it
        requires the Edit permission.
    </p>
<?php endif; ?>
