<?php
/**
 * Settings -> Permissions: the catalogue as stored in the database, with the
 * roles that currently hold each permission.
 *
 * Uses: $rows, $used, $errors
 */
$rows = $rows ?? [];
$used = $used ?? [];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Permissions',
    'description' => count($rows) . ' permission(s) on file. Grant or revoke them per role from '
        . 'Roles & Permissions - no code change is needed to re-point an access rule.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/roles')) . '">'
        . '<i class="bi bi-shield-lock me-1"></i>Roles & Permissions</a>',
]) ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $message): ?><div><?= e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Permission catalogue</h2></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
            
    <th class="text-center">#</th>
                <th>Group</th><th>Permission</th><th>Description</th>
                <th>Held by</th><th class="text-end">Roles</th>
            </tr>
            </thead>
            <tbody>
            
<?php $rowNo = offset() + 1; ?>
            <?php foreach ($rows as $row): ?>
                <?php $key = (string)$row['permission_key']; ?>
                
                <tr>
    <td class="text-center"><?= $rowNo++ ?></td>
                    <td class="text-nowrap"><span class="badge text-bg-light"><?= e($row['group_name']) ?></span></td>
                    <td class="text-nowrap"><code><?= e($key) ?></code></td>
                    <td class="small"><?= e($row['label']) ?></td>
                    <td class="small">
                        <?php if (empty($used[$key])): ?>
                            <span class="text-body-secondary">nobody</span>
                        <?php else: ?>
                            <?= e(implode(', ', $used[$key])) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= count($used[$key] ?? []) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
