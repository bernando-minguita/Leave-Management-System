<?php
/**
 * Current Leave - drivers physically away right now.
 *
 * Uses: $rows, $total, $filters, $sort, $query,
 *       $branches, $agencies, $leaveTypes
 */
$sort = $sort ?? ['expected_arrival_date', 'asc'];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Current Leave',
    'description' => number_format((int)$total) . ' driver(s) currently away.',
    'actions'     => (can('leave.create')
            ? '<a class="btn btn-primary btn-sm" href="' . e(url('leave/new')) . '">'
              . '<i class="bi bi-plus-circle me-1"></i>New Leave</a>'
            : '')
        . '<a class="btn btn-outline-secondary btn-sm" href="'
        . e(url('leave/current?' . http_build_query(
            sort_params(array_merge((array)$filters, ['export' => 'xls']))
        ))) . '">'
        . '<i class="bi bi-file-earmark-excel me-1"></i>Excel</a>',
]) ?>

<?= View::partial('partials/filter-bar', [
    'filters' => $filters, 'branches' => $branches, 'agencies' => $agencies,
    'leaveTypes' => $leaveTypes, 'action' => url('leave/current'),
    'showStatus' => false,
]) ?>

<div class="card border-0 shadow-sm">
    <?php // Same floor as Leave Records: a one-result search still has room
          // for the row-action dropdown to open instead of being clipped. ?>
    <div class="table-responsive table-grid">
        <table class="table table-sm table-hover align-middle mb-0 data-table"
               data-dd-table
               data-dd-prefs="current-leave"
               data-dd-size="<?= (int)per_page() ?>"
               data-dd-initial="<?= e(($sort[0] ?? '') . '|' . ($sort[1] ?? 'asc') . '|' . page_no()) ?>">
            <thead class="table-light">
            <tr>
            
    <th class="text-center">#</th>
                <?= sort_header('badge_number', 'Badge', $sort, $query ?? []) ?>
                <?= sort_header('driver_name', 'Driver', $sort, $query ?? []) ?>
                <?= sort_header('iqama_number', 'Iqama', $sort, $query ?? []) ?>
                <?= sort_header('agency', 'Agency', $sort, $query ?? []) ?>
                <?= sort_header('branch', 'Branch', $sort, $query ?? []) ?>
                <?= sort_header('leave_type', 'Leave Type', $sort, $query ?? []) ?>
                <?= sort_header('last_working_date', 'Last Working', $sort, $query ?? []) ?>
                <?= sort_header('leave_start_date', 'Leave Start', $sort, $query ?? []) ?>
                <?= sort_header('expected_arrival_date', 'Expected Return', $sort, $query ?? []) ?>
                <?= sort_header('destination', 'Destination Country', $sort, $query ?? []) ?>
                <th>Days Away</th>
                <?= sort_header('status', 'Status', $sort, $query ?? []) ?>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            
<?php // Rows ship whole: numbered in order, not from the URL's page. ?>
            <?php $rowNo = 1; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="13">
                    <?= empty_state(
                        'bi-airplane',
                        'No drivers are currently on leave.',
                        'Everyone in the current filter is available.',
                        can('leave.create')
                            ? '<a class="btn btn-primary btn-sm" href="' . e(url('leave/new')) . '">'
                              . '<i class="bi bi-plus-circle me-1"></i>New Leave</a>'
                            : ''
                    ) ?>
                </td></tr>
            <?php else: foreach ($rows as $row): ?>
                <?php $days = max(0, days_between((string)$row['leave_start_date'], today())); ?>
                
                <tr class="<?= $row['status'] === 'overdue' ? 'table-danger' : '' ?>" data-dd-row>
    <td class="text-center"><?= $rowNo++ ?></td>
                    <td class="text-nowrap fw-semibold"><?= e($row['badge_number']) ?></td>
                    <td>
                        <a class="link-dark text-decoration-none fw-semibold"
                           href="<?= e(url('drivers/' . (int)$row['driver_id'])) ?>">
                            <?= e($row['driver_name']) ?>
                        </a>
                    </td>
                    <td class="text-nowrap"><?= e($row['iqama_number']) ?></td>
                    <td class="text-nowrap"><?= e($row['agency_name'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['leave_type']) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['last_working_date'])) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['leave_start_date'])) ?></td>
                    <td class="text-nowrap fw-semibold"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                    <td class="text-nowrap"><?= e($row['destination'] ?? '—') ?></td>
                    <td><span class="badge text-bg-light border"><?= day_count($days) ?></span></td>
                    <td><?= status_badge((string)$row['status']) ?></td>
                    <td class="text-end text-nowrap">
                        <div class="btn-group btn-group-sm">
                            <a class="btn btn-outline-secondary" href="<?= e(url('leave/' . (int)$row['id'])) ?>"
                               title="View"><i class="bi bi-eye"></i></a>
                            <?php if (can('leave.edit')): ?>
                                <a class="btn btn-outline-secondary"
                                   href="<?= e(url('leave/' . (int)$row['id'] . '/edit')) ?>"
                                   title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </div>
                        <?= View::partial('partials/return-button', ['row' => $row]) ?>
                        <?php if (can('leave.return')
                            && \App\Models\LeaveRecord::canMarkNotReturned($row)): ?>
                            <button class="btn btn-sm btn-warning d-inline" type="button"
                                    data-action="not-returned"
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-driver="<?= e((string)$row['driver_name']) ?>"
                                    data-badge="<?= e((string)($row['badge_number'] ?? '')) ?>"
                                    data-expected="<?= e((string)($row['expected_arrival_date'] ?? '')) ?>"
                                    data-start="<?= e((string)($row['leave_start_date'] ?? '')) ?>"
                                    title="Close as Not Returned">
                                <i class="bi bi-person-x"></i>
                                <span class="d-none d-xl-inline ms-1">Not Returned</span>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer bg-white border-top-0">
        <form method="get" action="<?= e(url('leave/current')) ?>">
            <?php foreach (($query ?? []) as $key => $value): ?>
                <?php if (!in_array($key, ['page', 'per_page'], true)): ?>
                    <input type="hidden" name="<?= e((string)$key) ?>" value="<?= e((string)$value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?= pagination((int)$total, per_page(), page_no(), $query ?? []) ?>
        </form>
    </div>
</div>
