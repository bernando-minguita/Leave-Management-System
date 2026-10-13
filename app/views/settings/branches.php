<?php
/**
 * Settings > Branches (admin only).
 * Uses: $rows, $errors
 */
$rows   = $rows ?? [];
$errors = $errors ?? [];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Branches',
    'description' => 'Operational locations drivers are assigned to.',
]) ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">All branches</h2>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal"
                data-bs-target="#branchModal" data-mode="create">
            <i class="bi bi-plus-lg me-1"></i>Add Branch
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th class="text-center">#</th><th>Branch Name</th><th>Code</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php $rowNo = offset() + 1; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="5">
                    <?= empty_state('bi-geo-alt', 'No branches configured.',
                        'Add branches so drivers can be assigned to a location.') ?>
                </td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td class="text-center"><?= $rowNo++ ?></td>
                    <td class="fw-semibold"><?= e($row['branch_name']) ?></td>
                    <td><span class="badge text-bg-light border"><?= e($row['code']) ?></span></td>
                    <td>
                        <?php if ($row['status'] === 'active'): ?>
                            <span class="badge text-bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal"
                                data-bs-target="#branchModal" data-mode="edit"
                                data-id="<?= (int)$row['id'] ?>"
                                data-branch_name="<?= e($row['branch_name']) ?>"
                                data-code="<?= e($row['code']) ?>"
                                data-status="<?= e($row['status']) ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="post" action="<?= e(url('settings/destroy')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="branch">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <input type="hidden" name="return_to" value="settings/branches">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    data-confirm="Delete branch &quot;<?= e($row['branch_name']) ?>&quot;? Branches in use by drivers are kept.">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add / edit modal -->
<div class="modal fade" id="branchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(url('settings/save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="branch">
            <input type="hidden" name="return_to" value="settings/branches">
            <input type="hidden" name="id" id="br_id" value="">

            <div class="modal-header">
                <h3 class="modal-title h6" id="br_title">Add Branch</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="br_name">
                        Branch Name <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           class="form-control<?= isset($errors['branch_name']) ? ' is-invalid' : '' ?>"
                           id="br_name" name="branch_name" maxlength="100" required>
                    <?php if (isset($errors['branch_name'])): ?>
                        <div class="invalid-feedback"><?= e($errors['branch_name']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="br_code">Code <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control<?= isset($errors['code']) ? ' is-invalid' : '' ?>"
                           id="br_code" name="code" maxlength="20" required placeholder="e.g. DMM">
                    <?php if (isset($errors['code'])): ?>
                        <div class="invalid-feedback"><?= e($errors['code']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="br_status">Status</label>
                    <select class="form-select" id="br_status" name="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        var modal = document.getElementById('branchModal');
        if (!modal) { return; }
        modal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) { return; }
            var isEdit = (btn.getAttribute('data-mode') || 'create') === 'edit';
            modal.querySelector('#br_id').value = isEdit ? (btn.getAttribute('data-id') || '') : '';
            modal.querySelector('#br_name').value = btn.getAttribute('data-branch_name') || '';
            modal.querySelector('#br_code').value = btn.getAttribute('data-code') || '';
            modal.querySelector('#br_status').value = btn.getAttribute('data-status') || 'active';
            modal.querySelector('#br_title').textContent = isEdit ? 'Edit Branch' : 'Add Branch';
        });
    })();
</script>

