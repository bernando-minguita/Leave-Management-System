<?php
/**
 * Standard filter bar used by Current Leave / Leave Records / Reports.
 *
 * GET form: submitting refreshes the same screen with the filters applied.
 * hold_sort() carries the current ?sort=&dir= through it - a GET form sends
 * only the fields it contains, so without that the Apply button silently
 * dropped the column order the reader had chosen.
 *
 * Uses: $filters, $branches, $agencies, $leaveTypes, $action (form action URL)
 */
$filters  = $filters ?? [];
$branches = $branches ?? [];
$agencies = $agencies ?? [];
$leaveTypes = $leaveTypes ?? [];
$action   = $action ?? '';
$showStatus  = $showStatus ?? true;
$showDateRange = $showDateRange ?? true;
$monthChoices  = $monthChoices ?? [];
?>

<form method="get" action="<?= e($action) ?>" class="card border-0 shadow-sm mb-3 filter-card">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1" for="f_q">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="f_q" name="q"
                           value="<?= e($filters['q'] ?? '') ?>"
                           placeholder="Name, badge, iqama or mobile">
                </div>
            </div>

            <?= View::partial('partials/check-filter', [
                'name' => 'branch_id', 'label' => 'Branch',
                'pairs' => $branches, 'selected' => $filters['branch_id'] ?? '',
                'placeholder' => 'All branches',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'agency_id', 'label' => 'Agency',
                'pairs' => $agencies, 'selected' => $filters['agency_id'] ?? '',
                'placeholder' => 'All agencies',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'leave_type_id', 'label' => 'Leave Type',
                'pairs' => $leaveTypes, 'selected' => $filters['leave_type_id'] ?? '',
                'placeholder' => 'All leave types',
            ]) ?>

            <?php if ($showStatus): ?>
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
                    'placeholder' => 'All statuses',
                ]) ?>
            <?php endif; ?>

            <?php
            // The month selector itself lives in the Expected Returns card
            // header (see views/leave/returns.php). This hidden field only
            // keeps the chosen month when the top filters are applied.
            if ($monthChoices !== []): ?>
                <input type="hidden" name="returning_month"
                       value="<?= e($filters['returning_month'] ?? '') ?>">
            <?php endif; ?>

            <?php if ($showDateRange): ?>
                <?= View::partial('partials/check-filter', [
                    'name'  => 'date_field',
                    'label' => 'Date field',
                    'pairs' => [
                        'leave_start_date'      => 'Leave start',
                        'expected_arrival_date' => 'Expected return',
                        'actual_arrival_date'   => 'Actual arrival',
                        'created_at'            => 'Created',
                    ],
                    // Nothing selected still means "leave start" downstream, so
                    // show it ticked rather than a placeholder that lies.
                    'selected' => ($filters['date_field'] ?? '') !== ''
                        ? $filters['date_field'] : 'leave_start_date',
                    'placeholder' => 'Leave start',
                    'col'         => 'col-6 col-md-3 col-xl-2',
                ]) ?>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label small mb-1" for="f_from">From</label>
                    <input type="date" class="form-control form-control-sm" id="f_from"
                           name="date_from" value="<?= e($filters['date_from'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label small mb-1" for="f_to">To</label>
                    <input type="date" class="form-control form-control-sm" id="f_to"
                           name="date_to" value="<?= e($filters['date_to'] ?? '') ?>">
                </div>
            <?php endif; ?>

            <?php // The order the reader chose lives in the URL; a GET form
                  // would otherwise strip it on Apply. ?>
            <?= hold_sort() ?>

            <div class="col-12 col-md-auto ms-md-auto">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i>Apply
                    </button>
                    <?php
                    // Reset returns to the list with no query string at all.
                    $resetUrl = $action !== '' ? $action : url('drivers');
                    ?>
                    <a href="<?= e($resetUrl) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i>Reset
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>
