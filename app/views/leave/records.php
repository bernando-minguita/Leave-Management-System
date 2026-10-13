<?php
/**
 * Leave Records - full historical list with filters.
 *
 * Uses: $rows, $total, $filters, $sort, $query,
 *       $branches, $agencies, $leaveTypes
 */
$sort = $sort ?? ['leave_start_date', 'desc'];

// Where single- and multi-delete send the admin back to: this grid with the
// filters still applied (the page is dropped so the result is visible).
$gridReturn = 'leave/records';

if (!empty($query)) {
    $gridReturn .= '?' . http_build_query(array_diff_key((array)$query, ['page' => 1]));
}

// Resolved once: the tick-boxes, the toolbar and the row menu all ask the
// same question, and the answer must never disagree between them.
$canDelete = can('leave.delete');
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Leave Records',
    'description' => number_format((int)$total) . ' record(s) matching the current filters.',
    'actions'     => (can('leave.create')
            ? '<a class="btn btn-primary btn-sm" href="' . e(url('leave/new')) . '">'
              . '<i class="bi bi-plus-circle me-1"></i>New Leave</a>'
            : '')
        . (can('reports.export')
            // Filters AND sort: the file must match the screen, and the screen
            // may be showing a column order the reader chose themselves.
            ? '<a class="btn btn-outline-secondary btn-sm" href="'
              . e(url('leave/records?' . http_build_query(
                  sort_params(array_merge((array)$filters, ['export' => 'xls']))
              ))) . '">'
              . '<i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
            : ''),
]) ?>

<?= View::partial('partials/filter-bar', [
    'filters' => $filters, 'branches' => $branches, 'agencies' => $agencies,
    'leaveTypes' => $leaveTypes, 'action' => url('leave/records'),
    'showStatus' => true, 'showDateRange' => true,
]) ?>

<div class="card border-0 shadow-sm">
    <?php if ($canDelete): ?>
        <?php // data-confirm lives on the submit button: on the form it would
              // fire for every click inside the table it wraps. ?>
        <form method="post" action="<?= e(url('leave/bulk-delete')) ?>"
              id="bulkLeaveForm" data-bulk-select>
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= e($gridReturn) ?>">
            <div class="card-header bg-white d-flex flex-wrap align-items-center gap-3">
                <span class="small text-body-secondary mb-0">
                    <strong data-bulk-count>0</strong> selected
                </span>
                <button type="submit" class="btn btn-sm btn-outline-danger ms-auto"
                        data-bulk-submit
                        data-confirm="Delete the selected leave records? This cannot be undone."
                        disabled>
                    <i class="bi bi-trash me-1"></i>Delete selected
                </button>
            </div>
        </form>
        <?php // The form deliberately stops before the table: a <form> inside a
              // <form> is dropped by HTML parsers, which would strand every
              // row-level action. The tick boxes join it via form="bulkLeaveForm". ?>
    <?php endif; ?>
    <div class="table-responsive table-grid">
        <table class="table table-sm table-hover align-middle mb-0 data-table"
               data-dd-table
               data-dd-prefs="leave-records"
               data-dd-size="<?= (int)per_page() ?>"
               data-dd-initial="<?= e(($sort[0] ?? '') . '|' . ($sort[1] ?? 'asc') . '|' . page_no()) ?>">
            <thead class="table-light">
            <tr>
                <th class="text-center">#</th>
                <?php if ($canDelete): ?>
                    <th class="ps-3" style="width: 42px">
                        <input type="checkbox" class="form-check-input" data-check-all
                               form="bulkLeaveForm"
                               title="Select all on this page" aria-label="Select all">
                    </th>
                <?php endif; ?>
                <?= sort_header('driver_name', 'Driver', $sort, $query ?? []) ?>
                <?= sort_header('badge_number', 'Badge', $sort, $query ?? []) ?>
                <?= sort_header('branch', 'Branch', $sort, $query ?? []) ?>
                <?= sort_header('leave_type', 'Leave Type', $sort, $query ?? []) ?>
                <?= sort_header('last_working_date', 'Last Working', $sort, $query ?? []) ?>
                <?= sort_header('leave_start_date', 'Leave Start', $sort, $query ?? []) ?>
                <?= sort_header('ticket_date', 'Ticket', $sort, $query ?? []) ?>
                <?= sort_header('expected_arrival_date', 'Expected Return', $sort, $query ?? []) ?>
                <?php // Computed from the two dates on the row, so there is no
                      // column behind it and nothing to sort by. ?>
                <th title="Leave start to expected return - informational only">Duration</th>
                <?= sort_header('actual_arrival_date', 'Actual Arrival', $sort, $query ?? []) ?>
                <?= sort_header('destination', 'Destination Country', $sort, $query ?? []) ?>
                <?= sort_header('status', 'Status', $sort, $query ?? []) ?>
                <?= sort_header('created_at', 'Created', $sort, $query ?? []) ?>
                <?php // Auxiliary columns, APPENDED after every existing one so
                      // no earlier column changes index - saved widths and
                      // hidden choices are keyed by position. Hidden on the
                      // server so there is no flash before table-prefs.js runs;
                      // it reads this default off the <th> on a first visit. ?>
                <?= sort_header('updated_at', 'Last Updated', $sort, $query ?? [], 'display:none') ?>
                <?= sort_header('created_by_name', 'Created By', $sort, $query ?? [], 'display:none') ?>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php // Rows arrive whole and are numbered here in order, so the
                  // number on a row stays correct whichever page the browser
                  // is showing - offset() would start at the URL's page. ?>
            <?php $rowNo = 1; ?>
            <?php if (empty($rows)): ?>
                <?php // The empty state is one cell spanning every column: an
                      // off-by-one here leaves a visible gap before the right
                      // edge. 15 headers without the bulk-select column (#, 12
                      // sortable, Duration, Actions), 16 with it, plus the two
                      // appended hidden ones. ?>
                <tr><td colspan="<?= ($canDelete ? 16 : 15) + 2 ?>">
                    <?= empty_state(
                        'bi-journal-x',
                        'No leave records match your filters.',
                        'Adjust the filters above, or create a new leave record.',
                        can('leave.create')
                            ? '<a class="btn btn-primary btn-sm" href="' . e(url('leave/new')) . '">'
                              . '<i class="bi bi-plus-circle me-1"></i>New Leave</a>'
                            : ''
                    ) ?>
                </td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr class="<?= $row['status'] === 'overdue' ? 'table-danger' : '' ?>" data-dd-row>
    <td class="text-center"><?= $rowNo++ ?></td>
                    <?php if ($canDelete): ?>
                        <td class="ps-3 align-middle">
                            <input type="checkbox" class="form-check-input" name="ids[]"
                                   form="bulkLeaveForm"
                                   value="<?= (int)$row['id'] ?>" data-row-check
                                   aria-label="Select record <?= (int)$row['id'] ?>">
                        </td>
                    <?php endif; ?>
                    <td>
                        <a class="link-dark text-decoration-none fw-semibold"
                           href="<?= e(url('drivers/' . (int)$row['driver_id'])) ?>">
                            <?= e($row['driver_name']) ?>
                        </a>
                    </td>
                    <td class="text-nowrap"><?= e($row['badge_number']) ?></td>
                    <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['leave_type']) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['last_working_date'])) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['leave_start_date'])) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['ticket_date'])) ?></td>
                    <td class="text-nowrap fw-semibold"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                    <td class="text-nowrap"><?= e(app_duration(
                        $row['leave_start_date'] !== null ? (string) $row['leave_start_date'] : null,
                        $row['expected_arrival_date'] !== null ? (string) $row['expected_arrival_date'] : null
                    )) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['actual_arrival_date'])) ?></td>
                    <td class="text-nowrap"><?= e($row['destination'] ?? '—') ?></td>
                    <td><?= status_badge((string)$row['status']) ?></td>
                    <td class="text-nowrap small text-body-secondary"><?= e(fmt_date($row['created_at'])) ?></td>
                    <?php // Time as well as date: "when was it last touched"
                          // is usually today, and a date alone cannot tell it
                          // apart from the day it was created. Hidden to match
                          // its header. ?>
                    <td class="text-nowrap small" style="display:none"><?= e(fmt_date_time($row['updated_at'] ?? null)) ?></td>
                    <td class="text-nowrap small" style="display:none"><?= e($row['created_by_name'] ?? '—') ?></td>
                    <td class="text-end"><?= View::partial('partials/row-actions', ['row' => $row, 'returnTo' => $gridReturn]) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer bg-white border-top-0">
        <form method="get" action="<?= e(url('leave/records')) ?>">
            <?php foreach (($query ?? []) as $key => $value): ?>
                <?php if (!in_array($key, ['page', 'per_page'], true)): ?>
                    <input type="hidden" name="<?= e((string)$key) ?>" value="<?= e((string)$value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?= pagination((int)$total, per_page(), page_no(), $query ?? []) ?>
        </form>
    </div>
</div>
