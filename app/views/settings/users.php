<?php
/**
 * Settings > User accounts - the sidebar's "Users" screen.
 *
 * This used to be the "User Accounts" tab on System Settings. It has its own
 * route (/settings/users) now, so it opens straight from the sidebar and
 * carries its own header, error block and add/edit forms.
 *
 * Uses: $users, $roles, $errors
 */
$users  = $users ?? [];
$roles  = $roles ?? [];
$errors = $errors ?? [];

// Roles come from the database. New accounts default to a non-administrator
// role so the form can never hand out full access by accident.
$defaultRole = '';
foreach ($roles as $candidate) {
    if (($candidate['slug'] ?? '') !== 'admin') {
        $defaultRole = (string)$candidate['slug'];
        break;
    }
}
if ($defaultRole === '' && $roles !== []) {
    $defaultRole = (string)$roles[0]['slug'];
}
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Users',
    'description' => 'User accounts, their roles and two-factor settings.',
    'actions'     => (can_any(['settings.roles', 'settings.permissions'])
            ? '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/roles')) . '">'
                . '<i class="bi bi-shield-lock me-1"></i>Roles &amp; Permissions</a>'
            : '')
        . (can('settings.system')
            ? '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/system')) . '">'
                . '<i class="bi bi-gear me-1"></i>System Settings</a>'
            : ''),
]) ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $field => $message): ?>
                <li><strong><?= e(ucwords(str_replace('_', ' ', (string)$field))) ?>:</strong> <?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Accounts</h2></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
        <th class="text-center">#</th>
                        <th>Username</th><th>Full Name</th><th>Role</th>
                        <th>2FA</th>
                        <th>Status</th><th>Last Login</th><th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
    <?php $rowNo = offset() + 1; ?>
                    <?php if ($users === []): ?>
                        <tr><td colspan="8">
                            <?= empty_state('bi-people', 'No user accounts.') ?>
                        </td></tr>
                    <?php else: foreach ($users as $user): ?>
                        <tr>
            <td class="text-center"><?= $rowNo++ ?></td>
                            <td class="fw-semibold"><?= e($user['username']) ?></td>
                            <td><?= e($user['full_name']) ?></td>
                            <td>
                                <span class="badge <?= $user['role'] === 'admin'
                                    ? 'text-bg-primary' : 'text-bg-secondary' ?>">
                                    <?= e(ucfirst((string)$user['role'])) ?>
                                </span>
                            </td>
                            <td class="text-nowrap">
                                <?php if (!empty($user['totp_enabled_at'])): ?>
                                    <span class="badge text-bg-success">On</span>
                                <?php else: ?>
                                    <span class="badge text-bg-light border text-body-secondary">Off</span>
                                <?php endif; ?>
                                <?php if (!empty($user['two_factor_required'])): ?>
                                    <div class="small text-warning-emphasis">
                                        <i class="bi bi-exclamation-triangle-fill"></i> required
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['status'] === 'active'): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-body-secondary text-nowrap">
                                <?= e($user['last_login_at'] !== null
                                    ? fmt_date($user['last_login_at'], date_format_pattern() . ' H:i')
                                    : 'Never') ?>
                            </td>
                            <?php
                            $isEnabled  = !empty($user['totp_enabled_at']);
                            $isRequired = !empty($user['two_factor_required']);
                            ?>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                        data-bs-toggle="modal" data-bs-target="#userModal"
                                        data-mode="edit"
                                        data-id="<?= (int)$user['id'] ?>"
                                        data-username="<?= e($user['username']) ?>"
                                        data-full_name="<?= e($user['full_name']) ?>"
                                        data-role="<?= e($user['role']) ?>"
                                        data-status="<?= e($user['status']) ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <?php if ($isEnabled): ?>
                                    <form method="post" action="<?= e(url('profile/2fa/reset')) ?>"
                                          class="d-inline"
                                          data-confirm="Clear the two-factor configuration for <?= e($user['username']) ?>? They will have to enrol again.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                        <button class="btn btn-sm btn-outline-warning" type="submit"
                                                title="Reset this user's 2FA">
                                            <i class="bi bi-shield-x"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" action="<?= e(url('profile/2fa/force')) ?>"
                                      class="d-inline"
                                      data-confirm="<?= $isRequired ? 'Stop requiring' : 'Require' ?> two-factor authentication for <?= e($user['username']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                    <input type="hidden" name="required" value="<?= $isRequired ? '0' : '1' ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"
                                            title="<?= $isRequired ? 'Stop requiring 2FA' : 'Require 2FA' ?>">
                                        <i class="bi <?= $isRequired ? 'bi-pin-angle-fill' : 'bi-pin-angle' ?>"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Add an account</h2></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('settings/save')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="kind" value="user">
                    <input type="hidden" name="id" value="0">
                    <input type="hidden" name="return_to" value="settings/users">

                    <div class="mb-3">
                        <label class="form-label" for="u_username">
                            Username <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="u_username"
                               name="username" maxlength="50" required autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="u_full_name">
                            Full Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="u_full_name"
                               name="full_name" maxlength="120" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="u_password">
                            Password <span class="text-danger">*</span>
                            <span class="text-body-secondary small">(min 8)</span>
                        </label>
                        <input type="password" class="form-control" id="u_password"
                               name="password" minlength="8" required autocomplete="new-password">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="u_role">Role</label>
                            <select class="form-select" id="u_role" name="role">
                                <?php foreach ($roles as $roleOption): ?>
                                    <option value="<?= e($roleOption['slug']) ?>"
                                        <?= $roleOption['slug'] === $defaultRole ? 'selected' : '' ?>>
                                        <?= e($roleOption['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="u_status">Status</label>
                            <select class="form-select" id="u_status" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="bi bi-person-plus me-1"></i>Create Account
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit user modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(url('settings/save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="user">
            <input type="hidden" name="return_to" value="settings/users">
            <input type="hidden" name="id" id="um_id" value="">

            <div class="modal-header">
                <h3 class="modal-title h6">Edit Account</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="um_username">Username</label>
                    <input type="text" class="form-control" id="um_username" name="username"
                           maxlength="50" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="um_full_name">Full Name</label>
                    <input type="text" class="form-control" id="um_full_name" name="full_name"
                           maxlength="120" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="um_password">New Password
                        <span class="text-body-secondary small">(leave blank to keep current)</span>
                    </label>
                    <input type="password" class="form-control" id="um_password" name="password"
                           minlength="8" autocomplete="new-password">
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label" for="um_role">Role</label>
                        <select class="form-select" id="um_role" name="role">
                            <?php foreach ($roles as $roleOption): ?>
                                <option value="<?= e($roleOption['slug']) ?>">
                                    <?= e($roleOption['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="um_status">Status</label>
                        <select class="form-select" id="um_status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
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
        var modal = document.getElementById('userModal');
        if (!modal) { return; }
        modal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) { return; }
            modal.querySelector('#um_id').value = btn.getAttribute('data-id') || '';
            modal.querySelector('#um_username').value = btn.getAttribute('data-username') || '';
            modal.querySelector('#um_full_name').value = btn.getAttribute('data-full_name') || '';
            var roleSelect = modal.querySelector('#um_role');
            roleSelect.value = btn.getAttribute('data-role')
                || (roleSelect.options[0] || {}).value
                || '';
            modal.querySelector('#um_status').value = btn.getAttribute('data-status') || 'active';
            modal.querySelector('#um_password').value = '';
        });
    })();
</script>
