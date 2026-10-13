<?php
/**
 * Shared leave-history table (driver profile + full history page).
 * Uses: $rows, $compact
 */
$rows    = $rows ?? [];
$compact = $compact ?? false;
?>

<table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light">
    <tr>
    
    <th class="text-center">#</th>
        <th>Leave Type</th>
        <th>Last Working</th>
        <th>Leave Start</th>
        <th>Ticket</th>
        <th>Expected Return</th>
        <th>Actual Arrival</th>
        <th>Destination Country</th>
        <?php if (!$compact): ?><th>Days</th><?php endif; ?>
        <th>Status</th>
        <th class="text-end">Actions</th>
    </tr>
    </thead>
    <tbody>
    
<?php $rowNo = offset() + 1; ?>
    <?php if ($rows === []): ?>
        <tr>
            <td colspan="<?= $compact ? 9 : 10 ?>">
                <?= empty_state('bi-journal-x', 'No leave records yet.',
                    'This driver has not been recorded on leave.') ?>
            </td>
        </tr>
    <?php else: foreach ($rows as $row): ?>
        <?php
        $start = (string)$row['leave_start_date'];
        $end   = (string)($row['actual_arrival_date'] ?? $row['expected_arrival_date']);
        $days  = days_between($start, $end);
        ?>
        
        <tr class="<?= $row['status'] === 'overdue' ? 'table-danger' : '' ?>">
    <td class="text-center"><?= $rowNo++ ?></td>
            <td class="text-nowrap"><?= e($row['leave_type'] ?? '—') ?></td>
            <td class="text-nowrap"><?= e(fmt_date($row['last_working_date'])) ?></td>
            <td class="text-nowrap"><?= e(fmt_date($row['leave_start_date'])) ?></td>
            <td class="text-nowrap"><?= e(fmt_date($row['ticket_date'])) ?></td>
            <td class="text-nowrap"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
            <td class="text-nowrap">
                <?php if (!empty($row['actual_arrival_date'])): ?>
                    <?= e(fmt_date($row['actual_arrival_date'])) ?>
                    <?php if (!empty($row['actual_arrival_time'])): ?>
                        <span class="text-body-secondary small"><?= e(substr((string)$row['actual_arrival_time'], 0, 5)) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-body-secondary">—</span>
                <?php endif; ?>
            </td>
            <td class="text-nowrap"><?= e($row['destination'] ?? '—') ?></td>
            <?php if (!$compact): ?>
                <td class="text-nowrap"><span class="badge text-bg-light border"><?= day_count($days) ?></span></td>
            <?php endif; ?>
            <td><?= status_badge((string)$row['status']) ?></td>
            <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('leave/' . (int)$row['id'])) ?>"
                   title="View record"><i class="bi bi-eye"></i></a>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
