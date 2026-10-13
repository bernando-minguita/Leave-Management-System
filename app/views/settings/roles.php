<?php
/**
 * Settings -> Roles & Permissions.
 *
 * Uses: $roles, $catalogue, $grants, $editingRole, $errors
 */
$roles       = $roles ?? [];
$catalogue   = $catalogue ?? [];
$grants      = $grants ?? [];
$editingRole = $editingRole ?? 0;

$edit = null;
foreach ($roles as $candidate) {
    if ((int)$candidate['id'] === $editingRole) {
        $edit = $candidate;
        break;
    }
}
$editGrants = $edit !== null ? ($grants[(int)$edit['id']] ?? []) : [];
$isSelf     = $edit !== null && (int)($edit['id'] ?? 0) === (int)($_SESSION['user']['id'] ?? 0);
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Roles & Permissions',
    'description' => count($roles) . ' role(s). Permissions live in the database, so access rules '
        . 'can be changed here without touching application code.',
    'actions'     => can('settings.permissions')
        ? '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/permissions')) . '">'
          . '<i class="bi bi-list-check me-1"></i>Permission catalogue</a>'
        : '',
]) ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $message): ?><div><?= e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-<?= $edit !== null ? '5' : '12' ?>">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Roles</h2></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                <th class="text-center">#</th>
                        <th>Role</th><th>Slug</th><th class="text-end">Permissions</th>
                        <th class="text-end">Users</th><th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
            <?php $rowNo = offset() + 1; ?>
                    <?php foreach ($roles as $role): ?>
                        <tr class="<?= (int)$role['id'] === $editingRole ? 'table-primary' : '' ?>">
    <td class="text-center"><?= $rowNo++ ?></td>
                            <td>
                                <strong><?= e($role['name']) ?></strong>
                                <?php if ((int)($role['is_system'] ?? 0) === 1): ?>
                                    <span class="badge text-bg-secondary ms-1">system</span>
                                <?php endif; ?>
                                <?php if (!empty($role['description'])): ?>
                                    <div class="small text-body-secondary"><?= e($role['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><code><?= e($role['slug']) ?></code></td>
                            <td class="text-end"><?= (int)$role['permission_count'] ?></td>
                            <td class="text-end"><?= (int)$role['user_count'] ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (can('settings.roles')): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= e(url('settings/roles?edit=' . (int)$role['id'])) ?>">
                                        <i class="bi bi-shield-check"></i> Permissions
                                    </a>
                                <?php else: ?>
                                    <span class="small text-body-secondary">view only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($edit !== null): ?>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Permissions for <?= e($edit['name']) ?></h2>
                </div>
                <form method="post" action="<?= e(url('settings/save')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="kind" value="role">
                    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                    <input type="hidden" name="return_to"
                           value="<?= e('settings/roles?edit=' . (int)$edit['id']) ?>">

                    <div class="card-body">
                        <?php if ($isSelf): ?>
                            <div class="alert alert-warning small py-2">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                This is your own role - removals take effect on your next page load.
                            </div>
                        <?php endif; ?>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="roleName">Role name</label>
                                <input class="form-control" type="text" id="roleName" name="name"
                                       maxlength="120" required value="<?= e($edit['name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="roleSlug">Slug</label>
                                <input class="form-control" type="text" id="roleSlug"
                                       value="<?= e($edit['slug']) ?>" readonly>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="roleDesc">Description</label>
                                <input class="form-control" type="text" id="roleDesc"
                                       name="description" maxlength="255"
                                       value="<?= e((string)($edit['description'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2 mb-2">
                            <strong class="small">Permissions</strong>
                            <span class="badge text-bg-light" data-perm-count>
                                <?= count($editGrants) ?> granted
                            </span>
                            <button type="button" class="btn btn-link btn-sm ms-auto" data-perm-all>
                                Select all
                            </button>
                            <button type="button" class="btn btn-link btn-sm" data-perm-none>
                                Clear all
                            </button>
                        </div>

                        <div class="row g-2">
                            <?php foreach ($catalogue as $group => $entries): ?>
                                <div class="col-md-6">
                                    <div class="border rounded p-2 h-100">
                                        <div class="small fw-bold text-body-secondary mb-1">
                                            <?= e($group) ?>
                                        </div>
                                        <?php foreach ($entries as $key => $label): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                       name="permissions[]" value="<?= e($key) ?>"
                                                       id="perm_<?= e(str_replace('.', '-', $key)) ?>"
                                                       data-perm
                                                       <?= in_array($key, $editGrants, true) ? 'checked' : '' ?>>
                                                <label class="form-check-label small"
                                                       for="perm_<?= e(str_replace('.', '-', $key)) ?>">
                                                    <?= e($label) ?>
                                                    <code class="ms-1"><?= e($key) ?></code>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="card-footer bg-white d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-lg me-1"></i>Save permissions
                        </button>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('settings/roles')) ?>">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    (function () {
        var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-perm]'));
        var count = document.querySelector('[data-perm-count]');
        if (!boxes.length) { return; }

        function sync() {
            var n = boxes.filter(function (b) { return b.checked; }).length;
            if (count) { count.textContent = n + ' granted'; }
        }

        function setAll(value) {
            boxes.forEach(function (b) { b.checked = value; });
            sync();
        }

        document.querySelectorAll('[data-perm-all]').forEach(function (btn) {
            btn.addEventListener('click', function () { setAll(true); });
        });
        document.querySelectorAll('[data-perm-none]').forEach(function (btn) {
            btn.addEventListener('click', function () { setAll(false); });
        });
        boxes.forEach(function (b) { b.addEventListener('change', sync); });
        sync();
    })();
</script>
