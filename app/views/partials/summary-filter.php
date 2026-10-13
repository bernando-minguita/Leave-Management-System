<?php
/**
 * Date-range toolbar for the two summary reports (Branch / Agency).
 *
 * Shares the wording and field names with partials/report-toolbar so the two
 * screens behave the same, but drops the branch/agency/status checkboxes -
 * those would be meaningless on a report that already groups by them.
 *
 * Print / CSV / Excel moved to the page header (partials/report-actions), so
 * this form is filters only - and so the shared Columns control has a
 * .page-head to anchor into.
 *
 * Uses: $action (absolute URL of this report), $filters (date_field, date_from,
 *       date_to).
 */
$action  = $action ?? '';
$filters = $filters ?? [];

$fields = [
    'leave_start_date'     => 'Leave start',
    'expected_arrival_date'=> 'Expected return',
    'actual_arrival_date'  => 'Actual arrival',
    'created_at'           => 'Created',
];
?>

<form method="get" action="<?= e($action) ?>" class="card border-0 shadow-sm mb-3 no-print">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="s_field">Date field</label>
                <select class="form-select form-select-sm" id="s_field" name="date_field">
                    <?php foreach ($fields as $value => $label): ?>
                        <option value="<?= e($value) ?>"
                            <?= ($filters['date_field'] ?? '') === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="s_from">From</label>
                <input type="date" class="form-control form-control-sm" id="s_from" name="date_from"
                       value="<?= e($filters['date_from'] ?? '') ?>">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="s_to">To</label>
                <input type="date" class="form-control form-control-sm" id="s_to" name="date_to"
                       value="<?= e($filters['date_to'] ?? '') ?>">
            </div>

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
