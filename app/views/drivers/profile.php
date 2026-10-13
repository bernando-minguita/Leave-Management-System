<?php
/**
 * Driver profile: personal / contact / employment details, live leave status
 * and the complete leave history.
 *
 * Uses: $driver, $current, $history
 */
$driver  = $driver ?? [];
$current = $current ?? null;
$history = $history ?? [];
$isAway  = $current !== null && in_array($current['status'], ['on_leave', 'overdue'], true);
?>

<?= View::partial('partials/page-head', [
    'title'       => (string)$driver['driver_name'],
    'description' => 'Badge ' . (string)$driver['badge_number']
        . ' · Iqama ' . (string)$driver['iqama_number']
        . ' · ' . \App\Models\Lookup::employmentLabel((string)($driver['status'] ?? '')),
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('drivers')) . '">'
        . '<i class="bi bi-arrow-left me-1"></i>Back to list</a>'
        . (can('drivers.master_correct')
            ? '<a class="btn btn-outline-primary btn-sm" href="'
              . e(url('drivers/' . (int)$driver['id'] . '/edit')) . '">'
              . '<i class="bi bi-pencil me-1"></i>Edit</a>'
            : '')
        // Requesting a change is the ordinary way in: a different right
        // (drivers.edit) so it can be granted without also handing over the
        // direct-correction form.
        . (can('drivers.edit')
            ? '<a class="btn btn-outline-primary btn-sm" href="'
              . e(url('driver-changes/new')) . '?driver_id=' . (int)$driver['id'] . '">'
              . '<i class="bi bi-pencil-square me-1"></i>Request a change</a>'
            : '')
        . (can('drivers.history.view')
            ? '<a class="btn btn-outline-secondary btn-sm" href="'
              . e(url('drivers/' . (int)$driver['id'] . '/history')) . '">'
              . '<i class="bi bi-clock-history me-1"></i>History</a>'
            : '')
        . (can('leave.create') && ($driver['status'] ?? '') === 'active'
            ? '<a class="btn btn-primary btn-sm" href="'
              . e(url('leave-applications/new')) . '?driver_id=' . (int)$driver['id'] . '">'
              . '<i class="bi bi-plus-circle me-1"></i>New Leave Application</a>'
            : ''),
]) ?>

<!-- Current leave status banner ------------------------------------------ -->
<?php if ($isAway): ?>
    <div class="alert alert-primary d-flex align-items-start gap-3 mb-3 current-leave-banner">
        <i class="bi bi-airplane fs-4 mt-1"></i>
        <div class="flex-grow-1">
            <div class="fw-bold mb-1">Currently On Leave</div>
            <div class="row g-2 small">
                <div class="col-auto"><span class="text-body-secondary">Leave Type:</span>
                    <strong><?= e($current['leave_type'] ?? '—') ?></strong></div>
                <div class="col-auto"><span class="text-body-secondary">Leave Start:</span>
                    <strong><?= e(fmt_date($current['leave_start_date'])) ?></strong></div>
                <div class="col-auto"><span class="text-body-secondary">Expected Return:</span>
                    <strong><?= e(fmt_date($current['expected_arrival_date'])) ?></strong></div>
                <div class="col-auto"><span class="text-body-secondary">Destination Country:</span>
                    <strong><?= e($current['destination'] ?? '—') ?></strong></div>
                <div class="col-auto"><span class="text-body-secondary">Days Away:</span>
                    <strong><?= max(0, days_between((string)$current['leave_start_date'], today())) ?>d</strong></div>
            </div>
        </div>
        <?= status_badge((string)$current['status']) ?>
    </div>
<?php elseif (($current['status'] ?? '') === 'planned'): ?>
    <div class="alert alert-secondary d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-calendar-event fs-5"></i>
        <div>
            <strong>Upcoming leave</strong> starting <?= e(fmt_date($current['leave_start_date'])) ?>
            (expected back <?= e(fmt_date($current['expected_arrival_date'])) ?>).
        </div>
        <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= e(url('leave/' . (int)$current['id'])) ?>">Open</a>
    </div>
<?php else: ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div><strong>Currently Available</strong> - no open leave record.</div>
        <?php // Requirement 9: no "add leave" button for a deactivated driver - ?>
        <?php // they cannot gain new leave, only keep their history. ?>
        <?php if (can('leave.create') && ($driver['status'] ?? '') === 'active'): ?>
            <a class="btn btn-sm btn-success ms-auto"
               href="<?= e(url('leave-applications/new')) ?>?driver_id=<?= (int)$driver['id'] ?>">
                New Leave Application
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-6 col-xxl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h6 mb-0"><i class="bi bi-person me-2"></i>Personal Information</h3></div>
            <div class="card-body">
                <dl class="row mb-0 detail-list">
                    <dt>Driver Name</dt><dd><?= e($driver['driver_name']) ?></dd>
                    <dt>Badge Number</dt><dd><?= e($driver['badge_number']) ?></dd>
                    <dt>Iqama Number</dt><dd><?= e($driver['iqama_number']) ?></dd>
<dt>Passport Number</dt><dd><?= e($driver['passport_number'] ?? '') !== '' ? e($driver['passport_number']) : '<span class="text-body-secondary">—</span>' ?></dd>
                    <dt>Nationality</dt><dd><?= e($driver['nationality'] ?? '—') ?></dd>
                    <dt>Joining Date</dt><dd><?= e(fmt_date($driver['joining_date'])) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6 col-xxl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h3 class="h6 mb-0"><i class="bi bi-telephone me-2"></i>Contact</h3>
                <?php if (can('drivers.master_correct') && simledger_enabled()): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm"
                            data-action="sync-simledger"
                            data-url="<?= e(url('api/drivers/sync-simledger')) ?>"
                            data-driver-id="<?= (int)$driver['id'] ?>"
                            data-badge="<?= e($driver['badge_number']) ?>">
                        <i class="bi bi-arrow-repeat me-1"></i>Sync from SIMLedger
                    </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <dl class="row mb-0 detail-list">
                    <dt>Mobile</dt>
                    <dd id="driverMobile"><?php if (!empty($driver['mobile_number'])): ?>
                        <a href="tel:<?= e($driver['mobile_number']) ?>" dir="ltr"><?= e($driver['mobile_number']) ?></a>
                    <?php else: ?>—<?php endif; ?></dd>
                    <dt>Alternate</dt>
                    <dd><?php if (!empty($driver['alternate_mobile'])): ?>
                        <a href="tel:<?= e($driver['alternate_mobile']) ?>" dir="ltr"><?= e($driver['alternate_mobile']) ?></a>
                    <?php else: ?>—<?php endif; ?></dd>
                    <dt>Address</dt><dd><?= e($driver['address'] ?? '—') ?></dd>
                    <dt>City</dt><dd><?= e($driver['city'] ?? '—') ?></dd>
                    <dt>Emergency</dt>
                    <dd>
                        <?php if (!empty($driver['emergency_contact_name'])): ?>
                            <?= e($driver['emergency_contact_name']) ?>
                            <?php if (!empty($driver['emergency_contact_number'])): ?>
                                <span class="text-body-secondary d-block" dir="ltr">
                                    <?= e($driver['emergency_contact_number']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>


    <div class="col-12 col-lg-6 col-xxl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h6 mb-0"><i class="bi bi-briefcase me-2"></i>Employment</h3></div>
            <div class="card-body">
                <dl class="row mb-0 detail-list">
                    <dt>Agency</dt><dd><?= e($driver['agency_name'] ?? '—') ?></dd>
                    <dt>Branch</dt><dd><?= e($driver['branch_name'] ?? '—') ?></dd>
                    <dt>Department</dt><dd><?= e($driver['department'] ?? '—') ?></dd>
                    <dt>Position</dt><dd><?= e($driver['position'] ?? '—') ?></dd>
                    <dt>Employment Status</dt>
                    <dd>
                        <?php $empStatus = (string)($driver['status'] ?? ''); ?>
                        <?php if ($empStatus === 'active'): ?>
                            <span class="badge text-bg-success"><?= e(\App\Models\Lookup::employmentLabel($empStatus)) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary"><?= e(\App\Models\Lookup::employmentLabel($empStatus)) ?></span>
                        <?php endif; ?>
                    </dd>
                    <dt>Operational Status</dt>
                    <dd>
                        <?php // Separate question from employment: is the driver
                              // actually available for work right now? ?>
                        <?php $opStatus = (string)($driver['operational_status'] ?? ''); ?>
                        <?php if ($opStatus === ''): ?>
                            <span class="text-body-secondary">—</span>
                        <?php else: ?>
                            <span class="badge text-bg-info text-body"><?= e(\App\Models\Lookup::operationalLabel($opStatus)) ?></span>
                        <?php endif; ?>
                    </dd>
                    <?php // The deactivation end date: only a leaving driver has one, and it is
                          // what keeps them in the totals up to and including that day. ?>
                    <dt>Last Working Date</dt>
                    <dd>
                        <?php if (($driver['last_working_date'] ?? null) === null || $driver['last_working_date'] === ''): ?>
                            <span class="text-body-secondary">—</span>
                        <?php else: ?>
                            <strong><?= e(fmt_date($driver['last_working_date'])) ?></strong>
                            <?php if ($empStatus === 'active'): ?>
                                <div class="small text-body-secondary">Driver still counts as active.</div>
                            <?php else: ?>
                                <div class="small text-body-secondary">Counted in driver totals through this day.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6 col-xxl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h6 mb-0"><i class="bi bi-sticky me-2"></i>Additional Information</h3></div>
            <div class="card-body">
                <p class="small text-body-secondary mb-3"><?= e($driver['remarks'] ?? 'No remarks.') ?></p>
                <div class="d-flex justify-content-between small border-top pt-2">
                    <span class="text-body-secondary">Total leave records</span>
                    <strong><?= count($history) ?></strong>
                </div>
                <div class="d-flex justify-content-between small">
                    <span class="text-body-secondary">Returned</span>
                    <strong><?= count(array_filter($history, static fn (array $r): bool => $r['status'] === 'returned')) ?></strong>
                </div>
                <div class="d-flex justify-content-between small">
                    <span class="text-body-secondary">Record created</span>
                    <strong><?= e(fmt_date($driver['created_at'])) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Leave history -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h3 class="h6 mb-0"><i class="bi bi-clock-history me-2"></i>Leave History</h3>
        <a class="small link-offset-2" href="<?= e(url('drivers/' . (int)$driver['id'] . '/leave-history')) ?>">
            Open full history</a>
    </div>
    <div class="table-responsive">
        <?= View::partial('partials/leave-history-table', ['rows' => array_slice($history, 0, 10), 'compact' => true]) ?>
    </div>
</div>

<!-- Account status ------------------------------------------------------- -->
<?php $openLeave = (int)(\App\Core\Database::scalar(
    "SELECT COUNT(*) FROM leave_records
      WHERE driver_id = ? AND status IN ('on_leave','overdue','planned')",
    [(int)$driver['id']]
) ?? 0); ?>

<div class="card border-0 shadow-sm mt-3">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="small text-body-secondary" style="max-width: 60ch;">
            <i class="bi bi-info-circle me-1"></i>
            Deactivating keeps every record in history. A driver with leave
            records is never deleted - only drivers with no leave history can be
            removed from the Address Book.
            <?php if ($openLeave > 0): ?>
                This driver has <strong><?= $openLeave ?></strong> open leave
                record(s) and must be marked returned first.
            <?php endif; ?>
        </div>

        <div class="d-flex gap-2">
            <?php if (!can('drivers.deactivate')): ?>
                <?php // Needs drivers.deactivate: show no action at all. ?>
            <?php elseif (($driver['status'] ?? '') === 'active'): ?>
                <?php // Modal trigger: the flow collects the Last Working Date first. ?>
                <button class="btn btn-outline-danger btn-sm" type="button"
                        data-action="deactivate-driver"
                        data-url="<?= e(url('drivers/' . (int)$driver['id'] . '/deactivate')) ?>"
                        data-driver="<?= e($driver['driver_name']) ?>"
                        data-badge="<?= e($driver['badge_number']) ?>"
                        data-return="<?= e('drivers/' . (int)$driver['id']) ?>">
                    <i class="bi bi-person-dash me-1"></i>Deactivate
                </button>
            <?php else: ?>
                <form method="post"
                      action="<?= e(url('drivers/' . (int)$driver['id'] . '/activate')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-success btn-sm" type="submit">
                        <i class="bi bi-person-check me-1"></i>Reactivate
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
