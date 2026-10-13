<?php
/**
 * Full leave history for one driver.
 * Uses: $driver, $rows
 */
$driver = $driver ?? [];
$rows   = $rows ?? [];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Leave History · ' . ($driver['driver_name'] ?? ''),
    'description' => count($rows) . ' record' . (count($rows) === 1 ? '' : 's') . ' on file.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="'
        . e(url('drivers/' . (int)($driver['id'] ?? 0))) . '">'
        . '<i class="bi bi-person me-1"></i>Back to profile</a>'
        . (can('leave.create')
            ? '<a class="btn btn-primary btn-sm" href="'
              . e(url('leave/new')) . '?driver_id=' . (int)($driver['id'] ?? 0) . '">'
              . '<i class="bi bi-plus-circle me-1"></i>Add Leave</a>'
            : ''),
]) ?>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <?= View::partial('partials/leave-history-table', ['rows' => $rows, 'compact' => false]) ?>
    </div>
</div>
