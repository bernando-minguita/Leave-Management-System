<?php
/**
 * Settings > Leave Types (admin only).
 * Uses: $rows, $errors
 */
$rows   = $rows ?? [];
$errors = $errors ?? [];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Leave Types',
    'description' => 'Classification only - leave types never carry a balance or entitlement.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/system')) . '">'
        . '<i class="bi bi-gear me-1"></i>System Settings</a>',
]) ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">All leave types</h2>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal"
                data-bs-target="#leaveTypeModal" data-mode="create">
            <i class="bi bi-plus-lg me-1"></i>Add Leave Type
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th class="text-center">#</th><th>Name</th><th>Description</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php $rowNo = offset() + 1; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="5">
                    <?= empty_state('bi-tags', 'No leave types configured.',
                        'Add at least one leave type before recording leave.') ?>
                </td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td class="text-center"><?= $rowNo++ ?></td>
                    <td class="fw-semibold"><?= e($row['name']) ?></td>
                    <td><?= e($row['description'] ?? '—') ?></td>
                    <td>
                        <?php if ($row['status'] === 'active'): ?>
                            <span class="badge text-bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal"
                                data-bs-target="#leaveTypeModal" data-mode="edit"
                                data-id="<?= (int)$row['id'] ?>"
                                data-name="<?= e($row['name']) ?>"
                                data-description="<?= e($row['description'] ?? '') ?>"
                                data-status="<?= e($row['status']) ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="post" action="<?= e(url('settings/destroy')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="leave_type">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <input type="hidden" name="return_to" value="settings/leave-types">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    data-confirm="Delete leave type &quot;<?= e($row['name']) ?>&quot;? Leave types used by leave records are kept.">
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
<div class="modal fade" id="leaveTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(url('settings/save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="leave_type">
            <input type="hidden" name="return_to" value="settings/leave-types">
            <input type="hidden" name="id" id="lt_id" value="">

            <div class="modal-header">
                <h3 class="modal-title h6" id="lt_title">Add Leave Type</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="lt_name">Name <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                           id="lt_name" name="name" maxlength="80" required>
                    <?php if (isset($errors['name'])): ?>
                        <div class="invalid-feedback"><?= e($errors['name']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="lt_description">Description</label>
                    <input type="text" class="form-control" id="lt_description" name="description"
                           maxlength="255">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="lt_status">Status</label>
                    <select class="form-select" id="lt_status" name="status">
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
    // Populate the modal for edit, reset it for create.
    (function () {
        var modal = document.getElementById('leaveTypeModal');
        if (!modal) { return; }
        modal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) { return; }
            var isEdit = (btn.getAttribute('data-mode') || 'create') === 'edit';
            modal.querySelector('#lt_id').value = isEdit ? (btn.getAttribute('data-id') || '') : '';
            modal.querySelector('#lt_name').value = btn.getAttribute('data-name') || '';
            modal.querySelector('#lt_description').value = btn.getAttribute('data-description') || '';
            modal.querySelector('#lt_status').value = btn.getAttribute('data-status') || 'active';
            modal.querySelector('#lt_title').textContent = isEdit ? 'Edit Leave Type' : 'Add Leave Type';
        });
    })();
</script>

