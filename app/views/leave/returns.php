<?php
/**
 * Expected Returns - the operational answer board.
 * Sections: Today / Tomorrow / This Week / Overdue (spec sections 17 & 20).
 *
 * Uses: $sections, $today, $tomorrow, $weekEnd, $filters, $strip,
 *       $branches, $agencies, $leaveTypes
 */
$sections = $sections ?? [];
$strip    = $strip ?? [];
$monthChoices = $monthChoices ?? [];
$monthLabel   = $monthLabel ?? '';

$cards = [
    ['key' => 'today',    'label' => 'Returning Today',    'icon' => 'bi-box-arrow-in-right', 'tone' => 'primary'],
    ['key' => 'tomorrow', 'label' => 'Returning Tomorrow', 'icon' => 'bi-arrow-right-circle', 'tone' => 'info'],
    ['key' => 'week',     'label' => 'Returning This Week','icon' => 'bi-calendar-week',      'tone' => 'success'],
    ['key' => 'overdue',  'label' => 'Overdue',            'icon' => 'bi-exclamation-triangle-fill', 'tone' => 'danger'],
    // Driven by the "Returning By Month" dropdown shown in the card header
    // (current month / next month only - see LeaveController::returns()).
    [
        'key'   => 'month',
        'label' => 'Returning By Month',
        'icon'  => 'bi-calendar-month',
        'tone'  => 'secondary',
    ],
];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Expected Returns',
    'description' => 'Drivers expected back today, tomorrow, this week - plus anything overdue.',
    'actions'     => '<button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">'
        . '<i class="bi bi-printer me-1"></i>Print</button>',
]) ?>

<!-- Expected return visualisation (spec section 20) --------------------- -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="arrival-strip">
            <?php foreach ($strip as $item): ?>
                <div class="arrival-cell tone-<?= e($item['tone']) ?>">
                    <div class="arrival-label"><?= e($item['label']) ?></div>
                    <div class="arrival-date"><?= e($item['date']) ?></div>
                    <div class="arrival-rule"></div>
                    <div class="arrival-count">
                        <strong><?= (int)$item['count'] ?></strong>
                        <?= (int)$item['count'] === 1 ? 'Driver' : 'Drivers' ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?= View::partial('partials/filter-bar', [
    'filters' => $filters, 'branches' => $branches, 'agencies' => $agencies,
    'leaveTypes' => $leaveTypes, 'action' => url('leave/returns'),
    'monthChoices' => $monthChoices,
    'showStatus' => false, 'showDateRange' => false,
]) ?>

<?php foreach ($cards as $card): ?>
    <?php $rows = $sections[$card['key']] ?? []; ?>
    <div class="card border-0 shadow-sm mb-3<?= $card['key'] === 'overdue' && $rows !== [] ? ' overdue-card' : '' ?>">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
            <?php if ($card['key'] === 'month'): ?>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h2 class="h6 mb-0">
                        <i class="bi <?= e($card['icon']) ?> me-2 text-<?= e($card['tone']) ?>"></i>
                        <?= e($card['label']) ?>
                    </h2>

                    <?php
                    // The month lives in its own GET form so it submits on its
                    // own, carrying the other active filters along with it.
                    $keep = ['q', 'branch_id', 'agency_id', 'leave_type_id']; ?>
                    <form method="get" action="<?= e(url('leave/returns')) ?>" class="d-inline">
                        <?php foreach ($keep as $name): ?>
                            <?php if (($filters[$name] ?? '') !== ''): ?>
                                <input type="hidden" name="<?= e($name) ?>" value="<?= e($filters[$name]) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php // Changing the month must not lose the sort either. ?>
                        <?= hold_sort() ?>

                        <select class="form-select form-select-sm w-auto"
                                id="f_returning_month" name="returning_month"
                                onchange="rememberScroll(); this.form.submit()"
                                aria-label="Returning by month">
                            <?= options($monthChoices, $filters['returning_month'] ?? '') ?>
                        </select>
                    </form>

                    <span class="badge text-bg-<?= e($card['tone']) ?>"><?= count($rows) ?></span>
                </div>
            <?php else: ?>
                <h2 class="h6 mb-0<?= $card['key'] === 'overdue' ? ' text-danger' : '' ?>">
                    <i class="bi <?= e($card['icon']) ?> me-2 text-<?= e($card['tone']) ?>"></i>
                    <?= e($card['label']) ?>
                    <span class="badge text-bg-<?= e($card['tone']) ?> ms-1"><?= count($rows) ?></span>
                </h2>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <?php // Every board sorts in place: the engine reorders the rows
                  // the server rendered, so all five are marked. The four
                  // short ones carry no pager, so their size is the row count
                  // - anything smaller would hide rows with no page links to
                  // bring them back. Only Returning By Month can hold a
                  // pageful, so only it keeps the real pager and per_page(). ?>
            <?php $isMonth = $card['key'] === 'month'; ?>
            <table class="table table-sm table-hover align-middle mb-0"
                   data-dd-table
                   data-dd-size="<?= (int)($isMonth ? per_page() : max(1, count($rows))) ?>"
                   data-dd-initial="<?= $isMonth ? '|asc|' . (int)page_no() : '|asc|1' ?>">
                <thead class="table-light">
                <tr>
                    <th class="text-center">#</th>
                    <?php // sort_header_client(): this page does not read
                          // ?sort= back, so the headers offer the browser sort
                          // rather than a link that would do nothing. ?>
                    <?= sort_header_client('driver_name', 'Driver') ?>
                    <?= sort_header_client('badge_number', 'Badge') ?>
                    <?= sort_header_client('branch_name', 'Branch') ?>
                    <?= sort_header_client('agency_name', 'Agency') ?>
                    <?= sort_header_client('leave_type', 'Leave Type') ?>
                    <?= sort_header_client('expected_arrival_date', 'Expected Return') ?>
                    <?= sort_header_client('destination', 'Destination Country') ?>
                    <?= sort_header_client('status_label', 'Status') ?>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
            <?php // Rows ship whole: numbered in order, not from the URL's page. ?>
            <?php $rowNo = 1; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="10">
                        <?php if ($card['key'] === 'overdue'): ?>
                            <?= empty_state('bi-check2-circle', 'No overdue returns.', 'Everyone is back on time.') ?>
                        <?php elseif ($card['key'] === 'today'): ?>
                            <?= empty_state('bi-check2-circle', 'No drivers are due back today.') ?>
                        <?php elseif ($card['key'] === 'tomorrow'): ?>
                            <?= empty_state('bi-arrow-right-circle', 'No drivers are due back tomorrow.') ?>
                        <?php elseif ($card['key'] === 'month'): ?>
                            <?= empty_state('bi-calendar-month', 'No returns expected in ' . $monthLabel . '.', 'Choose the other month in the filter above.') ?>
                        <?php else: ?>
                            <?= empty_state('bi-calendar-week', 'No upcoming returns.', 'Nothing due in the next 7 days.') ?>
                        <?php endif; ?>
                    </td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr class="<?= $card['key'] === 'overdue' ? 'table-danger' : '' ?>" data-dd-row>
    <td class="text-center"><?= $rowNo++ ?></td>
                        <td>
                            <a class="link-dark text-decoration-none fw-semibold"
                               href="<?= e(url('drivers/' . (int)$row['driver_id'])) ?>">
                                <?= e($row['driver_name']) ?>
                            </a>
                        </td>
                        <td class="text-nowrap"><?= e($row['badge_number']) ?></td>
                        <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                        <td class="text-nowrap"><?= e($row['agency_name'] ?? '—') ?></td>
                        <td class="text-nowrap"><?= e($row['leave_type']) ?></td>
                        <td class="text-nowrap fw-semibold"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                        <td class="text-nowrap"><?= e($row['destination'] ?? '—') ?></td>
                        <td><?= status_badge((string)$row['status']) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('leave/' . (int)$row['id'])) ?>"
                               title="View"><i class="bi bi-eye"></i></a>
                            <?= View::partial('partials/return-button', ['row' => $row]) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($card['key'] === 'month'): ?>
            <?php // Same wrapper as the other lists: without it the Rows
                  // select has no form to fall back to when JavaScript is off. ?>
            <div class="card-footer bg-white border-top-0">
                <form method="get" action="<?= e(url('leave/returns')) ?>">
                    <?php foreach (($filters ?? []) as $key => $value): ?>
                        <?php if (!in_array($key, ['page', 'per_page'], true) && $value !== '' && $value !== null): ?>
                            <input type="hidden" name="<?= e((string)$key) ?>" value="<?= e((string)$value) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php // $filters never holds the sort - it lives in the URL. ?>
                    <?= hold_sort() ?>
                    <?= pagination(count($rows), per_page(), page_no(), $filters ?? []) ?>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
