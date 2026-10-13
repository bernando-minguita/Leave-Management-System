<?php
/**
 * One change request: what is proposed, who filed it, and - when the viewer
 * may take it - the two decisions.
 *
 * Uses: $request, $driver, $field, $label, $oldShown, $newShown, $canDecide,
 *       $canCancel, $pending, $error
 *
 * Approve and Reject both live here rather than in the queue, because a
 * reviewer needs the full context (the reason, the current value, the person
 * asking) next to the button they are pressing.
 */
$request  = $request ?? [];
$driver   = $driver ?? [];
$field    = (string)($field ?? '');
$label    = (string)($label ?? $field);
$oldShown = (string)($oldShown ?? '');
$newShown = (string)($newShown ?? '');
$canDecide = (bool)($canDecide ?? false);
$canCancel = (bool)($canCancel ?? false);
$pending   = (bool)($pending ?? false);
$error     = (string)($error ?? '');
$status    = (string)($request['status'] ?? '');

$statusBadge = [
    'pending'   => ['warning', 'bi-hourglass-split', 'Pending review'],
    'approved'  => ['success', 'bi-check-circle-fill', 'Approved'],
    'rejected'  => ['danger', 'bi-x-circle-fill', 'Rejected'],
    'cancelled' => ['secondary', 'bi-slash-circle', 'Withdrawn'],
][$status] ?? ['secondary', 'bi-question-circle', $status];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Change Request #' . (int)($request['id'] ?? 0),
    'description' => $label . ' on ' . (string)($driver['driver_name'] ?? '')
        . ' · <span class="badge text-bg-' . $statusBadge[0] . '">'
        . '<i class="bi ' . $statusBadge[1] . ' me-1"></i>'
        . e($statusBadge[2]) . '</span>',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="'
        . e(url('driver-changes')) . '">'
        . '<i class="bi bi-arrow-left me-1"></i>Back to queue</a>'
        . '<a class="btn btn-outline-primary btn-sm" href="'
        . e(url('drivers/' . (int)($driver['id'] ?? 0))) . '">'
        . '<i class="bi bi-person me-1"></i>Open profile</a>',
]) ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($status === 'rejected' && ($request['review_note'] ?? '') !== ''): ?>
    <div class="alert alert-outline-danger">
        <strong>Rejected:</strong> <?= e((string)$request['review_note']) ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-body-tertiary fw-semibold">
                <i class="bi bi-arrow-left-right me-2"></i>The proposed change
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-sm-5">
                        <div class="small text-body-secondary text-uppercase">Field</div>
                        <div class="fw-semibold"><?= e($label) ?></div>
                    </div>
                    <div class="col-sm-3">
                        <div class="small text-body-secondary text-uppercase">Current</div>
                        <div><?= e($oldShown) ?></div>
                    </div>
                    <div class="col-sm-1 text-center d-none d-sm-block">
                        <i class="bi bi-arrow-right text-body-secondary mt-4"></i>
                    </div>
                    <div class="col-sm-3">
                        <div class="small text-body-secondary text-uppercase">Requested</div>
                        <div class="fw-bold text-success"><?= e($newShown) ?></div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="small text-body-secondary text-uppercase">Reason</div>
                    <div><?= nl2br(e((string)($request['reason'] ?? ''))) ?></div>
                </div>

                <div class="row small">
                    <div class="col-sm-6 mb-2">
                        <span class="text-body-secondary">Requested by</span><br>
                        <strong><?= e((string)($request['requested_by_name'] ?? '—')) ?></strong>
                        · <?= e((string)($request['requested_at'] ?? '')) ?>
                    </div>
                    <div class="col-sm-6 mb-2">
                        <span class="text-body-secondary">Effective date</span><br>
                        <?= ($request['effective_date'] ?? null) !== null
                            ? e((string)$request['effective_date'])
                            : '<span class="text-body-secondary">Immediate</span>' ?>
                    </div>
                    <?php if (($request['reviewed_at'] ?? null) !== null): ?>
                    <div class="col-sm-6 mb-2">
                        <span class="text-body-secondary">Reviewed by</span><br>
                        <strong><?= e((string)($request['reviewed_by_name'] ?? '—')) ?></strong>
                        · <?= e((string)$request['reviewed_at']) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (($request['review_note'] ?? '') !== '' && $status !== 'rejected'): ?>
                    <div class="small border-top pt-2 mt-2">
                        <span class="text-body-secondary">Review note:</span>
                        <?= e((string)$request['review_note']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($pending && $canDecide): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-body-tertiary fw-semibold">
                <i class="bi bi-gavel me-2"></i>Decide
            </div>
            <div class="card-body">
                <form method="post"
                      action="<?= e(url('driver-changes/' . (int)$request['id'] . '/approve')) ?>">
                    <?= csrf_field() ?>
                    <label class="form-label" for="ap_eff">Effective date</label>
                    <input class="form-control mb-2" type="date" name="effective_date"
                           id="ap_eff" value="<?= e((string)($request['effective_date'] ?? '')) ?>">
                    <label class="form-label" for="ap_note">Note (optional)</label>
                    <textarea class="form-control mb-3" name="review_note" id="ap_note"
                              rows="2" maxlength="500"
                              placeholder="Anything the record should carry"></textarea>
                    <button class="btn btn-success w-100 mb-2" type="submit">
                        <i class="bi bi-check-circle me-1"></i>Approve &amp; apply
                    </button>
                    <div class="form-text">
                        Applies the change to the master record, writes it to Driver
                        History and closes this request - as one transaction.
                    </div>
                </form>

                <hr>

                <form method="post"
                      action="<?= e(url('driver-changes/' . (int)$request['id'] . '/reject')) ?>">
                    <?= csrf_field() ?>
                    <label class="form-label" for="rj_note">
                        Rejection reason <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control mb-3" name="review_note" id="rj_note"
                              rows="2" minlength="5" maxlength="500" required
                              placeholder="Why this cannot be applied"></textarea>
                    <button class="btn btn-outline-danger w-100" type="submit">
                        <i class="bi bi-x-circle me-1"></i>Reject
                    </button>
                    <div class="form-text">
                        Rejecting changes nothing on the driver - only this request.
                    </div>
                </form>
            </div>
        </div>
        <?php elseif ($pending && $canCancel): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-body-tertiary fw-semibold">
                <i class="bi bi-clock-history me-2"></i>Waiting for a reviewer
            </div>
            <div class="card-body">
                <p class="text-body-secondary small">
                    You filed this request. Somebody with approval rights has to
                    action it before the record changes.
                </p>
                <form method="post"
                      action="<?= e(url('driver-changes/' . (int)$request['id'] . '/cancel')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-secondary w-100" type="submit"
                            onclick="return confirm('Withdraw this request?');">
                        <i class="bi bi-x me-1"></i>Withdraw request
                    </button>
                </form>
            </div>
        </div>
        <?php elseif ($pending): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body text-body-secondary small">
                <i class="bi bi-lock me-1"></i>
                Waiting for a reviewer. You do not hold
                <code>drivers.change_request_approve</code>, so there is nothing
                for you to action here.
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body small">
                <div class="fw-semibold mb-2">This driver's record</div>
                <a href="<?= e(url('drivers/' . (int)$driver['id'] . '/history')) ?>">
                    <i class="bi bi-clock-history me-1"></i>See how it got here
                </a><br>
                <a href="<?= e(url('drivers/' . (int)$driver['id'] . '/leave-history')) ?>">
                    <i class="bi bi-airplane me-1"></i>Leave history
                </a>
            </div>
        </div>
    </div>
</div>

