<?php
/**
 * Agency summary report.
 * Uses: $rows
 */
$totals = ['total_leaves' => 0, 'away_now' => 0, 'overdue_count' => 0,
    'returned_count' => 0, 'planned_count' => 0, 'cancelled_count' => 0,
    'not_returned_count' => 0];

foreach ($rows as $row) {
    foreach ($totals as $key => $_) {
        $totals[$key] += (int)($row[$key] ?? 0);
    }
}
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Agency Report',
    'description' => 'Leave activity summarised by manpower agency',
    // Print / CSV / Excel move into the header so the shared Columns control
    // has a .page-head to anchor into (table-prefs.js anchorFor()).
    'actions'     => View::partial('partials/report-actions', [
        'action' => url('reports/agency'), 'filters' => $filters ?? [],
    ]),
]) ?>

<?= View::partial('partials/summary-filter', [
    'action'  => url('reports/agency'),
    'filters' => $filters ?? [],
]) ?>

<div class="d-none d-print-block mb-3">
    <h1 class="h5 mb-0"><?= e(defined('APP_NAME') ? APP_NAME : 'LMS') ?> - Agency Report</h1>
    <div class="small">Generated <?= e(fmt_date_time(now())) ?> (<?= e(APP_TIMEZONE) ?>)</div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th class="text-center">#</th>
                <th>Agency</th><th>Code</th><th class="text-end">Total Leaves</th>
                <th class="text-end">Away Now</th><th class="text-end">Overdue</th>
                <th class="text-end">Returned</th><th class="text-end">Planned</th>
                <th class="text-end">Cancelled</th><th class="text-end">Not Returned</th>
            </tr>
            </thead>
            <tbody>
            <?php $rowNo = offset() + 1; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="10">
                    <?= empty_state('bi-building', 'No agencies configured.',
                        'Add agencies under Settings to see this report.') ?>
                </td></tr>
            <?php else: foreach ($rows as $row): ?>
                    <tr class="<?= (int)$row['overdue_count'] > 0 ? 'table-danger' : '' ?>">
                        <td class="text-center"><?= $rowNo++ ?></td>
                    <td class="fw-semibold"><?= e($row['label']) ?></td>
                    <td class="text-nowrap"><?= e($row['code'] ?? '—') ?></td>
                    <td class="text-end"><?= (int)$row['total_leaves'] ?></td>
                    <td class="text-end">
                        <span class="badge text-bg-light border"><?= (int)$row['away_now'] ?></span>
                    </td>
                    <td class="text-end"><?= (int)$row['overdue_count'] ?></td>
                    <td class="text-end"><?= (int)$row['returned_count'] ?></td>
                    <td class="text-end"><?= (int)$row['planned_count'] ?></td>
                    <td class="text-end"><?= (int)$row['cancelled_count'] ?></td>
                    <td class="text-end"><?= (int)$row['not_returned_count'] ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot class="table-light fw-semibold">
            <tr>
                <td colspan="3">Total</td>
                <td class="text-end"><?= (int)$totals['total_leaves'] ?></td>
                <td class="text-end"><?= (int)$totals['away_now'] ?></td>
                <td class="text-end"><?= (int)$totals['overdue_count'] ?></td>
                <td class="text-end"><?= (int)$totals['returned_count'] ?></td>
                <td class="text-end"><?= (int)$totals['planned_count'] ?></td>
                <td class="text-end"><?= (int)$totals['cancelled_count'] ?></td>
                <td class="text-end"><?= (int)$totals['not_returned_count'] ?></td>
            </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
