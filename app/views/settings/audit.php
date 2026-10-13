<?php
/**
 * Settings > Audit Log. Gated on `audit.view`, which Administrators and HR
 * hold - Operations and Viewer are refused (see tools/rbac-test.php).
 * Uses: $rows, $total, $filters, $actions, $tables, $query
 */
$filters = $filters ?? [];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Audit Log',
    'description' => number_format((int)$total) . ' event(s) recorded.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/system')) . '">'
        . '<i class="bi bi-gear me-1"></i>System Settings</a>',
]) ?>

<form method="get" action="<?= e(url('settings/audit')) ?>" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1" for="a_q">Search</label>
                <input type="text" class="form-control form-control-sm" id="a_q" name="q"
                       value="<?= e($filters['q'] ?? '') ?>" placeholder="Action, table or user">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="a_action">Action</label>
                <select class="form-select form-select-sm" id="a_action" name="action">
                    <option value="">All actions</option>
                    <?php foreach (($actions ?? []) as $action): ?>
                        <option value="<?= e($action) ?>"
                            <?= ($filters['action'] ?? '') === $action ? 'selected' : '' ?>>
                            <?= e($action) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="a_table">Table</label>
                <select class="form-select form-select-sm" id="a_table" name="table_name">
                    <option value="">All tables</option>
                    <?php foreach (($tables ?? []) as $table): ?>
                        <option value="<?= e($table) ?>"
                            <?= ($filters['table_name'] ?? '') === $table ? 'selected' : '' ?>>
                            <?= e($table) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="a_from">From</label>
                <input type="date" class="form-control form-control-sm" id="a_from" name="date_from"
                       value="<?= e($filters['date_from'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="a_to">To</label>
                <input type="date" class="form-control form-control-sm" id="a_to" name="date_to"
                       value="<?= e($filters['date_to'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-1">
                <button class="btn btn-primary btn-sm w-100" type="submit" title="Apply filters">
                    <i class="bi bi-funnel"></i>
                </button>
            </div>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0"
               data-dd-table
               data-dd-size="<?= (int)per_page() ?>"
               data-dd-initial="|asc|<?= (int)page_no() ?>">
            <thead class="table-light">
            <tr>
            
    <th class="text-center">#</th>
                <th>When</th><th>User</th><th>Action</th><th>Table</th>
                <th>Record</th><th>Changes</th>
            </tr>
            </thead>
            <tbody>
            
<?php // Rows ship whole and are numbered in order, so a row keeps the right
                  // number on whichever page the browser is showing. ?>
            <?php $rowNo = 1; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7">
                    <?= empty_state('bi-clock-history', 'No audit entries match these filters.') ?>
                </td></tr>
            <?php else: foreach ($rows as $row): ?>
                <?php
                $old = $row['old_values'] !== null ? json_decode((string)$row['old_values'], true) : null;
                $new = $row['new_values'] !== null ? json_decode((string)$row['new_values'], true) : null;
                ?>
                
                <tr data-dd-row>
    <td class="text-center"><?= $rowNo++ ?></td>
                    <td class="text-nowrap small"><?= e(fmt_date_time($row['created_at'])) ?></td>
                    <td class="text-nowrap"><?= e($row['full_name'] ?? $row['username'] ?? 'System') ?></td>
                    <td><span class="badge text-bg-light border"><?= e($row['action']) ?></span></td>
                    <td class="text-nowrap"><?= e($row['table_name']) ?></td>
                    <td class="text-nowrap">
                        <?= $row['record_id'] !== null ? '#' . (int)$row['record_id'] : '—' ?>
                    </td>
                    <td class="small" style="max-width: 360px;">
                        <?php if (is_array($new) && $new !== []): ?>
                            <div class="text-truncate">
                                <span class="text-success">new:</span>
                                <?= e(json_encode($new, JSON_UNESCAPED_UNICODE)) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (is_array($old) && $old !== []): ?>
                            <div class="text-truncate text-body-secondary">
                                <span class="text-danger">old:</span>
                                <?= e(json_encode($old, JSON_UNESCAPED_UNICODE)) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!is_array($old) && !is_array($new)): ?>
                            <span class="text-body-secondary">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer bg-white border-top-0">
        <form method="get" action="<?= e(url('settings/audit')) ?>">
            <?php foreach (($query ?? []) as $key => $value): ?>
                <?php if (!in_array($key, ['page', 'per_page'], true)): ?>
                    <input type="hidden" name="<?= e((string)$key) ?>" value="<?= e((string)$value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?= pagination((int)$total, per_page(), page_no(), $query ?? []) ?>
        </form>
    </div>
</div>

