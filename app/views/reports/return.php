<?php
/**
 * Return Report - expected vs actual with day variance.
 * Uses: $rows, $total, $filters, $branches, $agencies, $leaveTypes
 */

$branches = $branches ?? [];
$agencies = $agencies ?? [];
$leaveTypes = $leaveTypes ?? [];

// Counted over the WHOLE report, not over $rows: with pagination $rows is
// only the page on screen, and badges that shrank as you paged would be
// reading "how many of the ten rows in front of me" while claiming to
// describe all 28. Report::returnsSummary() runs the same comparison in SQL
// against the same WHERE the rows came from.
$summary = $summary ?? ['on_time' => 0, 'late' => 0];
$onTime  = (int) ($summary['on_time'] ?? 0);
$late    = (int) ($summary['late'] ?? 0);
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Return Report',
    'description' => number_format((int)$total) . ' record(s)',
    // The counts stay visible, and the view controls move up beside them so
    // the shared Columns control has a .page-head to anchor into.
    'actions'     => View::partial('partials/report-actions', [
        'action' => url('reports/return'), 'filters' => $filters,
        'badge'  => '<span class="badge text-bg-success">' . $onTime . ' on time / early</span>'
            . '<span class="badge text-bg-danger">' . $late . ' late</span>',
    ]),
]) ?>

<?= View::partial('partials/report-toolbar', [
    'action' => url('reports/return'), 'filters' => $filters,
    'branches' => $branches, 'agencies' => $agencies, 'leaveTypes' => $leaveTypes,
]) ?>

<div class="d-none d-print-block mb-3">
    <h1 class="h5 mb-0"><?= e(defined('APP_NAME') ? APP_NAME : 'LMS') ?> - Return Report</h1>
    <div class="small">Generated <?= e(fmt_date_time(now())) ?> (<?= e(APP_TIMEZONE) ?>)</div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
            
    <th class="text-center">#</th>
                <th>Driver</th><th>Badge</th><th>Expected Return</th><th>Actual Arrival</th>
                <th>Difference</th><th>Branch</th><th>Agency</th><th>Status</th>
            </tr>
            </thead>
            <tbody>
            
<?php $rowNo = offset() + 1; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9">
                    <?= empty_state('bi-clipboard-check', 'No return records match these filters.') ?>
                </td></tr>
            <?php else: foreach ($rows as $row): ?>
                <?php
                $variance = (int)($row['variance_days'] ?? 0);
                $pending  = $row['actual_arrival_date'] === null;
                ?>
                
                <tr class="<?= !$pending && $variance > 0 ? 'table-warning' : '' ?>">
    <td class="text-center"><?= $rowNo++ ?></td>
                    <td class="text-nowrap"><?= e($row['driver_name']) ?></td>
                    <td class="text-nowrap"><?= e($row['badge_number']) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                    <td class="text-nowrap">
                        <?= $pending
                            ? '<span class="text-body-secondary">Not returned</span>'
                            : e(fmt_date($row['actual_arrival_date'])) ?>
                    </td>
                    <td>
                        <?php if ($pending): ?>
                            <span class="badge text-bg-secondary">pending</span>
                        <?php elseif ($variance === 0): ?>
                            <span class="badge text-bg-success">on time</span>
                        <?php elseif ($variance > 0): ?>
                            <span class="badge text-bg-danger">+<?= $variance ?>d</span>
                        <?php else: ?>
                            <span class="badge text-bg-info"><?= $variance ?>d</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['agency_name'] ?? '—') ?></td>
                    <td><?= status_badge((string)$row['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php // Same shared pager as the Leave Report: "Showing ..." counts the
          // WHOLE report, and the links keep the current filters. ?>
    <div class="card-footer bg-white">
        <?= pagination((int)$total, per_page(), page_no(), $query ?? []) ?>
    </div>
</div>
