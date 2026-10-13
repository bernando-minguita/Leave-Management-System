<?php
/**
 * Leave Calendar (FullCalendar) with filter offcanvas.
 *
 * Uses: $filters, $query, $branches, $agencies, $leaveTypes
 */

// Status legend - colours must match LeaveApi::colour().
$legend = [
    ['status' => 'planned',   'label' => 'Planned',   'colour' => '#6c757d'],
    ['status' => 'on_leave',  'label' => 'On Leave',  'colour' => '#1d4ed8'],
    ['status' => 'returned',  'label' => 'Returned',  'colour' => '#198754'],
    ['status' => 'overdue',   'label' => 'Overdue',   'colour' => '#dc3545'],
    // Cancelled trips are never drawn on the calendar, so they get no colour
    // here and no entry in the status filter below.
];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Leave Calendar',
    'description' => 'Every leave record on one timeline. Click an event to open it.',
    'actions'     => '<button class="btn btn-outline-secondary btn-sm" type="button" '
        . 'data-bs-toggle="offcanvas" data-bs-target="#calendarFilters">'
        . '<i class="bi bi-funnel me-1"></i>Filters</button>'
        . (can('leave.create')
            ? '<a class="btn btn-primary btn-sm" href="' . e(url('leave/new')) . '">'
              . '<i class="bi bi-plus-circle me-1"></i>New Leave</a>'
            : ''),
]) ?>

<!-- Filter offcanvas ------------------------------------------------------ -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="calendarFilters"
     aria-labelledby="calendarFiltersLabel">
    <div class="offcanvas-header">
        <h2 class="h6" id="calendarFiltersLabel">
            <i class="bi bi-funnel me-2"></i>Calendar Filters
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <form id="calendarFilterForm">
            <div class="mb-3">
                <label class="form-label" for="cf_branch">Branch</label>
                <select class="form-select" id="cf_branch" name="branch_id">
                    <option value="">All branches</option>
                    <?= options($branches ?? [], $filters['branch_id'] ?? '') ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="cf_agency">Agency</label>
                <select class="form-select" id="cf_agency" name="agency_id">
                    <option value="">All agencies</option>
                    <?= options($agencies ?? [], $filters['agency_id'] ?? '') ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="cf_type">Leave Type</label>
                <select class="form-select" id="cf_type" name="leave_type_id">
                    <option value="">All leave types</option>
                    <?= options($leaveTypes ?? [], $filters['leave_type_id'] ?? '') ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="cf_status">Status</label>
                <select class="form-select" id="cf_status" name="status">
                    <option value="">All statuses</option>
                    <?php // Cancelled is omitted: those rows are not returned
                          // by the calendar feed, so the option would be a lie. ?>
                    <?php foreach (\App\Core\Status::ALL as $code): ?>
                        <?php if ($code === \App\Core\Status::CANCELLED) { continue; } ?>
                        <option value="<?= e($code) ?>"
                            <?= ($filters['status'] ?? '') === $code ? 'selected' : '' ?>>
                            <?= e(status_label($code)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="cf_q">Driver</label>
                <input type="text" class="form-control" id="cf_q" name="q"
                       value="<?= e($filters['q'] ?? '') ?>" placeholder="Name, badge or iqama">
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-check2 me-1"></i>Apply
                </button>
                <button class="btn btn-outline-secondary" type="reset" id="cfReset">
                    <i class="bi bi-x-circle me-1"></i>Clear
                </button>
            </div>
        </form>

        <hr>

        <h3 class="h6">Legend</h3>
        <ul class="list-unstyled small mb-0">
            <?php foreach ($legend as $item): ?>
                <li class="d-flex align-items-center gap-2 mb-1">
                    <span class="rounded d-inline-block"
                          style="width:14px;height:14px;background:<?= e($item['colour']) ?>"></span>
                    <?= e($item['label']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<!-- Legend (always visible) ---------------------------------------------- -->
<div class="d-flex flex-wrap gap-3 mb-3 calendar-legend">
    <?php foreach ($legend as $item): ?>
        <span class="d-inline-flex align-items-center gap-2 small">
            <span class="rounded d-inline-block"
                  style="width:12px;height:12px;background:<?= e($item['colour']) ?>"></span>
            <?= e($item['label']) ?>
        </span>
    <?php endforeach; ?>
</div>

<!-- Calendar -------------------------------------------------------------- -->
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div id="leaveCalendar" data-loading-text="Loading calendar…"></div>
    </div>
</div>

<script>
    window.CALENDAR_URL = <?= json_encode(url('api/leave/calendar')) ?>;
    window.CALENDAR_RECORD_URL = <?= json_encode(url('leave/')) ?>;
</script>
<script src="<?= e(url('assets/vendor/fullcalendar/index.global.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/calendar.js')) ?>"></script>
