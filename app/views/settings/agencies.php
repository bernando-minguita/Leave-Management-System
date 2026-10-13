<?php
/**
 * Settings > Agencies (admin only).
 * Uses: $rows, $errors
 */
$rows   = $rows ?? [];
$errors = $errors ?? [];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Agencies',
    'description' => 'Manpower suppliers providing drivers to the operation.',
]) ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">All agencies</h2>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal"
                data-bs-target="#agencyModal" data-mode="create">
            <i class="bi bi-plus-lg me-1"></i>Add Agency
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th class="text-center">#</th>
                <th>Agency</th><th>Code</th><th>Contact Person</th><th>Mobile</th>
                <th>Email</th><th>Status</th><th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php $rowNo = offset() + 1; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="8">
                    <?= empty_state('bi-briefcase', 'No agencies configured.',
                        'Add agencies so drivers can be linked to a supplier.') ?>
                </td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td class="text-center"><?= $rowNo++ ?></td>
                    <td class="fw-semibold"><?= e($row['agency_name']) ?></td>
                    <td><span class="badge text-bg-light border"><?= e($row['code']) ?></span></td>
                    <td><?= e($row['contact_person'] ?? '—') ?></td>
                    <td class="text-nowrap" dir="ltr"><?= e($row['mobile'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['email'] ?? '—') ?></td>
                    <td>
                        <?php if ($row['status'] === 'active'): ?>
                            <span class="badge text-bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal"
                                data-bs-target="#agencyModal" data-mode="edit"
                                data-id="<?= (int)$row['id'] ?>"
                                data-agency_name="<?= e($row['agency_name']) ?>"
                                data-code="<?= e($row['code']) ?>"
                                data-contact_person="<?= e($row['contact_person'] ?? '') ?>"
                                data-mobile="<?= e($row['mobile'] ?? '') ?>"
                                data-email="<?= e($row['email'] ?? '') ?>"
                                data-address="<?= e($row['address'] ?? '') ?>"
                                data-remarks="<?= e($row['remarks'] ?? '') ?>"
                                data-status="<?= e($row['status']) ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="post" action="<?= e(url('settings/destroy')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="agency">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <input type="hidden" name="return_to" value="settings/agencies">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    data-confirm="Delete agency &quot;<?= e($row['agency_name']) ?>&quot;? Agencies in use by drivers are kept.">
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
<div class="modal fade" id="agencyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(url('settings/save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="agency">
            <input type="hidden" name="return_to" value="settings/agencies">
            <input type="hidden" name="id" id="ag_id" value="">

            <div class="modal-header">
                <h3 class="modal-title h6" id="ag_title">Add Agency</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="ag_agency_name">
                            Agency Name <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control<?= isset($errors['agency_name']) ? ' is-invalid' : '' ?>"
                               id="ag_agency_name" name="agency_name" maxlength="120" required>
                        <?php if (isset($errors['agency_name'])): ?>
                            <div class="invalid-feedback"><?= e($errors['agency_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="ag_code">Code <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control<?= isset($errors['code']) ? ' is-invalid' : '' ?>"
                               id="ag_code" name="code" maxlength="20" required placeholder="e.g. AWM">
                        <?php if (isset($errors['code'])): ?>
                            <div class="invalid-feedback"><?= e($errors['code']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ag_contact_person">Contact Person</label>
                        <input type="text" class="form-control" id="ag_contact_person"
                               name="contact_person" maxlength="120">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ag_mobile">Mobile</label>
                        <input type="text" class="form-control" id="ag_mobile" name="mobile"
                               maxlength="30" dir="ltr">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ag_email">Email</label>
                        <input type="email"
                               class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                               id="ag_email" name="email" maxlength="120">
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ag_status">Status</label>
                        <select class="form-select" id="ag_status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="ag_address">Address</label>
                        <input type="text" class="form-control" id="ag_address" name="address"
                               maxlength="255">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="ag_remarks">Remarks</label>
                        <textarea class="form-control" id="ag_remarks" name="remarks" rows="2"
                                  maxlength="500"></textarea>
                    </div>
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
        var modal = document.getElementById('agencyModal');
        if (!modal) { return; }
        modal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) { return; }
            var isEdit = (btn.getAttribute('data-mode') || 'create') === 'edit';
            var fields = ['id', 'agency_name', 'code', 'contact_person', 'mobile',
                'email', 'address', 'remarks', 'status'];

            fields.forEach(function (name) {
                var input = modal.querySelector('#ag_' + (name === 'id' ? 'id' : name));
                if (!input) { return; }
                input.value = isEdit ? (btn.getAttribute('data-' + name) || '') : '';
            });

            modal.querySelector('#ag_status').value =
                (btn.getAttribute('data-status') || 'active');
            modal.querySelector('#ag_title').textContent = isEdit ? 'Edit Agency' : 'Add Agency';
        });
    })();
</script>

