<?php
/**
 * Shared report toolbar: the filter form (Apply / Reset).
 *
 * Print / CSV / Excel no longer live here - they moved to the page header
 * (partials/report-actions) so the shared Columns control has a .page-head
 * to anchor into. Keeping them here as well would have put every view
 * control in two places.
 *
 * Uses: $action (form action URL), $filters, $branches, $agencies, $leaveTypes,
 *       $showDates
 */
$action     = $action ?? '';
$filters    = $filters ?? [];
$showDates  = $showDates ?? true;
?>

<form method="get" action="<?= e($action) ?>" class="card border-0 shadow-sm mb-3 no-print">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1" for="r_q">Search</label>
                <input type="text" class="form-control form-control-sm" id="r_q" name="q"
                       value="<?= e($filters['q'] ?? '') ?>" placeholder="Name, badge or iqama">
            </div>

            <?= View::partial('partials/check-filter', [
                'name' => 'branch_id', 'label' => 'Branch',
                'pairs' => $branches ?? [], 'selected' => $filters['branch_id'] ?? '',
                'placeholder' => 'All', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'agency_id', 'label' => 'Agency',
                'pairs' => $agencies ?? [], 'selected' => $filters['agency_id'] ?? '',
                'placeholder' => 'All', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'leave_type_id', 'label' => 'Leave Type',
                'pairs' => $leaveTypes ?? [], 'selected' => $filters['leave_type_id'] ?? '',
                'placeholder' => 'All', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'status', 'label' => 'Status',
                // Status::ALL is a list, so array_map keeps 0..4 as keys -
                // pair it explicitly or the boxes submit "1" instead of
                // "on_leave" and the filter returns nothing.
                'pairs' => array_combine(\App\Core\Status::ALL, array_map(
                    static fn(string $code): string => status_label($code),
                    \App\Core\Status::ALL
                )),
                'selected' => $filters['status'] ?? '',
                'placeholder' => 'All', 'col' => 'col-6 col-md-3',
            ]) ?>

            <?php if ($showDates): ?>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="r_field">Date field</label>
                    <select class="form-select form-select-sm" id="r_field" name="date_field">
                        <option value="leave_start_date"
                            <?= ($filters['date_field'] ?? '') === 'leave_start_date' ? 'selected' : '' ?>>Leave start</option>
                        <option value="expected_arrival_date"
                            <?= ($filters['date_field'] ?? '') === 'expected_arrival_date' ? 'selected' : '' ?>>Expected return</option>
                        <option value="actual_arrival_date"
                            <?= ($filters['date_field'] ?? '') === 'actual_arrival_date' ? 'selected' : '' ?>>Actual arrival</option>
                        <option value="created_at"
                            <?= ($filters['date_field'] ?? '') === 'created_at' ? 'selected' : '' ?>>Created</option>
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="r_from">From</label>
                    <input type="date" class="form-control form-control-sm" id="r_from" name="date_from"
                           value="<?= e($filters['date_from'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="r_to">To</label>
                    <input type="date" class="form-control form-control-sm" id="r_to" name="date_to"
                           value="<?= e($filters['date_to'] ?? '') ?>">
                </div>
            <?php endif; ?>

            <div class="col-12">
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="bi bi-funnel me-1"></i>Apply
                    </button>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e($action) ?>">
                        <i class="bi bi-x-circle me-1"></i>Reset
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>
