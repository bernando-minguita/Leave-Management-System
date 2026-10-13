<?php
/**
 * Leave Report.
 * Uses: $rows, $total, $filters, $branches, $agencies, $leaveTypes
 */

$branches = $branches ?? [];
$agencies = $agencies ?? [];
$leaveTypes = $leaveTypes ?? [];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Leave Report',
    'description' => number_format((int)$total) . ' record(s)',
    // Print / CSV / Excel sit up here so the shared Columns control has a
    // header to anchor into (table-prefs.js looks for .page-head) instead of
    // being dropped into a row of its own below the filters.
    'actions'     => View::partial('partials/report-actions', [
        'action' => url('reports/leave'), 'filters' => $filters,
    ]),
]) ?>

<?= View::partial('partials/report-toolbar', [
    'action' => url('reports/leave'), 'filters' => $filters,
    'branches' => $branches, 'agencies' => $agencies, 'leaveTypes' => $leaveTypes,
]) ?>

<!-- Print-only header -->
<div class="d-none d-print-block mb-3">
    <h1 class="h5 mb-0"><?= e(defined('APP_NAME') ? APP_NAME : 'LMS') ?> - Leave Report</h1>
    <div class="small">Generated <?= e(fmt_date_time(now())) ?> (<?= e(APP_TIMEZONE) ?>)</div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th class="text-center">#</th>
                <th>Driver</th><th>Badge</th><th>Iqama</th><th>Agency</th><th>Branch</th>
                <th>Leave Type</th><th>Leave Start</th><th>Expected Return</th>
                <th>Actual Arrival</th><th>Destination Country</th><th>Status</th>
                <th>Not Returned Reason</th>
            </tr>
            </thead>
            <tbody>
            <?php $rowNo = offset() + 1; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="13">
                    <?= empty_state('bi-file-earmark-x', 'No records match these filters.',
                        'Widen the date range or clear a filter.') ?>
                </td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr class="<?= $row['status'] === 'overdue' ? 'table-danger' : '' ?>">
    <td class="text-center"><?= $rowNo++ ?></td>
                    <td class="text-nowrap"><?= e($row['driver_name']) ?></td>
                    <td class="text-nowrap"><?= e($row['badge_number']) ?></td>
                    <td class="text-nowrap"><?= e($row['iqama_number']) ?></td>
                    <td class="text-nowrap"><?= e($row['agency_name'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['leave_type']) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['leave_start_date'])) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['actual_arrival_date'])) ?></td>
                    <td class="text-nowrap"><?= e($row['destination'] ?? '—') ?></td>
                    <td><?= status_badge((string)$row['status']) ?></td>
                    <td class="text-nowrap"><?= e($row['not_returned_reason'] ?? '—') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php // Shared pager. It prints "Showing 1-25 of 28" against the FULL
          // report total rather than the rows on this page, and every link
          // carries the filters already applied - so paging never silently
          // widens the search. Without JS the rows-per-page select simply
          // submits the form. ?>
    <div class="card-footer bg-white">
        <?= pagination((int)$total, per_page(), page_no(), $query ?? []) ?>
    </div>
</div>
