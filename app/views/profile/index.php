<?php
/**
 * My Profile - personal information, password, two-factor authentication and
 * recent security activity.
 *
 * Uses: $user, $twoFactorEnabled, $mustEnrol, $enrol, $qrUri, $recoveryShown,
 *       $recoveryRemaining, $activity, $minPassword
 */
$user              = $user ?? [];
$twoFactorEnabled  = (bool) ($twoFactorEnabled ?? false);
$mustEnrol         = (bool) ($mustEnrol ?? false);
$enrol             = $enrol ?? null;
$qrUri             = $qrUri ?? null;
$recoveryShown     = $recoveryShown ?? null;
$recoveryRemaining = (int) ($recoveryRemaining ?? 0);
$activity          = $activity ?? [];
$minPassword       = (int) ($minPassword ?? MIN_PASSWORD_LENGTH);
?>

<?= View::partial('partials/page-head', [
    'title'       => 'My Profile',
    'description' => 'Manage your name, password and sign-in security.',
    'actions'     => '<span class="badge text-bg-light border"><i class="bi bi-person-badge me-1"></i>'
        . e(current_role_label()) . '</span>',
]) ?>

<?php if ($mustEnrol): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong>Two-factor authentication is required for your account.</strong><br>
            Set it up below to unlock the rest of the application.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="bi bi-person-vcard me-2 text-primary"></i>Personal information</h2>
            </div>
            <div class="card-body">
                <dl class="row mb-3">
                    <dt class="col-sm-4 text-body-secondary">Username</dt>
                    <dd class="col-sm-8">
                        <span class="fw-semibold"><?= e($user['username'] ?? '') ?></span>
                        <i class="bi bi-lock text-body-secondary ms-1"
                           title="Usernames are managed by an administrator"></i>
                    </dd>

                    <dt class="col-sm-4 text-body-secondary">Email</dt>
                    <dd class="col-sm-8"><?= e((string) ($user['email'] ?? '') ?: 'Not set') ?></dd>

                    <dt class="col-sm-4 text-body-secondary">Role</dt>
                    <dd class="col-sm-8"><?= e(current_role_label()) ?></dd>
                </dl>

                <form method="post" action="<?= e(url('profile/name')) ?>" class="mb-0">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="full_name">Full name</label>
                        <input type="text" class="form-control" id="full_name" name="full_name"
                               value="<?= e((string) ($user['full_name'] ?? '')) ?>" maxlength="120" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email address</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= e((string) ($user['email'] ?? '')) ?>" maxlength="160"
                               placeholder="you@example.com">
                        <div class="form-text">Leave blank to clear it.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="bi bi-key me-2 text-primary"></i>Change password</h2>
            </div>
            <div class="card-body">
                <p class="small text-body-secondary mb-3">
                    Minimum length is <strong><?= (int) $minPassword ?></strong> characters.
                    Your password is stored only as a salted hash and is never logged.
                </p>

                <form method="post" action="<?= e(url('profile/password')) ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password</label>
                        <input type="password" class="form-control" id="current_password"
                               name="current_password" autocomplete="current-password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="new_password">New password</label>
                        <input type="password" class="form-control" id="new_password"
                               name="new_password" autocomplete="new-password"
                               minlength="<?= (int) $minPassword ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm new password</label>
                        <input type="password" class="form-control" id="confirm_password"
                               name="confirm_password" autocomplete="new-password"
                               minlength="<?= (int) $minPassword ?>" required>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-shield-check me-1"></i>Change password
                    </button>

                    <?php if (!empty($user['password_changed_at'])): ?>
                        <span class="small text-body-secondary ms-2">
                            Last changed <?= e(fmt_date((string) $user['password_changed_at'])) ?>
                        </span>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Two-factor authentication ------------------------------------------- -->
<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100" id="twofactor">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><i class="bi bi-shield-lock me-2 text-primary"></i>Two-factor authentication</h2>
                <?php if ($twoFactorEnabled): ?>
                    <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Enabled</span>
                <?php else: ?>
                    <span class="badge text-bg-secondary">Not enabled</span>
                <?php endif; ?>
            </div>

            <div class="card-body">
                <p class="small text-body-secondary">
                    Uses a standard time-based code (TOTP) that works with Google Authenticator,
                    Microsoft Authenticator, Authy and similar apps.
                </p>

                <?php if (!$twoFactorEnabled && $enrol === null): ?>
                    <form method="post" action="<?= e(url('profile/2fa/start')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-qr-code-scan me-1"></i>Enable two-factor authentication
                        </button>
                    </form>

                <?php elseif ($enrol !== null): ?>
                    <ol class="small text-body-secondary ps-3 mb-3">
                        <li>Open your authenticator app</li>
                        <li>Scan the QR code below (or enter the key manually)</li>
                        <li>Type the 6-digit code it shows</li>
                    </ol>

                    <div class="d-flex flex-wrap gap-3 align-items-start">
                        <div id="qrBox" class="border rounded p-2 bg-white text-center"
                             style="min-width:170px;min-height:170px">
                            <div class="small text-body-secondary p-3">Generating QR code&hellip;</div>
                        </div>

                        <div class="flex-grow-1" style="min-width:240px">
                            <label class="form-label small mb-1" for="totpSecret">Manual entry key</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control font-monospace" id="totpSecret" readonly
                                       value="<?= e((string) ($enrol['secret'] ?? '')) ?>">
                                <button class="btn btn-outline-secondary" type="button" id="copySecret">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>

                            <form method="post" action="<?= e(url('profile/2fa/enable')) ?>">
                                <?= csrf_field() ?>
                                <label class="form-label small mb-1" for="enable_code">6-digit code</label>
                                <div class="input-group mb-2">
                                    <input type="text" inputmode="numeric" class="form-control" id="enable_code"
                                           name="code" maxlength="6" pattern="[0-9]{6}"
                                           autocomplete="one-time-code" placeholder="000000" required>
                                    <button class="btn btn-primary" type="submit">Verify</button>
                                </div>
                            </form>

                            <form method="post" action="<?= e(url('profile/2fa/cancel')) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Cancel setup</button>
                            </form>
                        </div>
                    </div>

                    <script src="<?= e(url('assets/vendor/qrcode/qrcode.min.js')) ?>"></script>
                    <script>
                    (function () {
                        var uri = <?= json_encode((string) $qrUri) ?>;
                        var box = document.getElementById('qrBox');
                        var copy = document.getElementById('copySecret');
                        if (copy) {
                            copy.addEventListener('click', function () {
                                navigator.clipboard.writeText(document.getElementById('totpSecret').value);
                            });
                        }
                        if (!uri || !box || typeof qrcode !== 'function') { return; }
                        try {
                            var qr = qrcode(0, 'M');
                            qr.addData(uri);
                            qr.make();
                            box.innerHTML = qr.createTableTag(4);
                        } catch (err) {
                            box.innerHTML = '<div class="small text-danger p-3">QR unavailable - use the key above.</div>';
                        }
                    }());
                    </script>
                <?php else: ?>
                    <div class="alert alert-success py-2 small">
                        <i class="bi bi-check2-circle me-1"></i>
                        Enabled since <?= e(fmt_date((string) $user['totp_enabled_at'])) ?>.
                        <?= (int) $recoveryRemaining ?> unused recovery code(s) remaining.
                    </div>

                    <button class="btn btn-outline-danger btn-sm" type="button"
                            data-bs-toggle="collapse" data-bs-target="#disable2fa">
                        <i class="bi bi-shield-x me-1"></i>Disable 2FA
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" type="button"
                            data-bs-toggle="collapse" data-bs-target="#regenCodes">
                        <i class="bi bi-arrow-repeat me-1"></i>Regenerate recovery codes
                    </button>

                    <div class="collapse mt-3" id="disable2fa">
                        <div class="border rounded p-3">
                            <h3 class="h6">Disable two-factor authentication</h3>
                            <p class="small text-body-secondary mb-2">
                                Current password, a valid code from the app and an explicit confirmation
                                are all required.
                            </p>
                            <form method="post" action="<?= e(url('profile/2fa/disable')) ?>"
                                  data-confirm="Disable two-factor authentication? Your account will be protected by your password only.">
                                <?= csrf_field() ?>
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <input type="password" class="form-control form-control-sm"
                                               name="current_password" placeholder="Current password"
                                               autocomplete="current-password" required>
                                    </div>
                                    <div class="col-sm-6">
                                        <input type="text" inputmode="numeric" class="form-control form-control-sm"
                                               name="code" maxlength="6" placeholder="6-digit code" required>
                                    </div>
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="confirm" value="1"
                                           id="confirmDisable" required>
                                    <label class="form-check-label small" for="confirmDisable">
                                        I understand this lowers my account security.
                                    </label>
                                </div>
                                <button type="submit" class="btn btn-sm btn-danger mt-2">Disable 2FA</button>
                            </form>
                        </div>
                    </div>

                    <div class="collapse mt-3" id="regenCodes">
                        <div class="border rounded p-3">
                            <h3 class="h6">Regenerate recovery codes</h3>
                            <p class="small text-body-secondary mb-2">
                                Existing codes stop working immediately.
                            </p>
                            <form method="post" action="<?= e(url('profile/2fa/recovery')) ?>">
                                <?= csrf_field() ?>
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <input type="password" class="form-control form-control-sm"
                                               name="current_password" placeholder="Current password"
                                               autocomplete="current-password" required>
                                    </div>
                                    <div class="col-sm-6">
                                        <input type="text" inputmode="numeric" class="form-control form-control-sm"
                                               name="code" maxlength="6" placeholder="6-digit code" required>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-primary mt-2">
                                    Generate new codes
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="bi bi-keyhole me-2 text-primary"></i>Recovery codes</h2>
            </div>
            <div class="card-body">
                <?php if (is_array($recoveryShown) && $recoveryShown !== []): ?>
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Save these now. They are shown <strong>only once</strong> and each works a single time.
                    </div>

                    <div class="font-monospace small border rounded p-2 mb-2 bg-light">
                        <?php foreach ($recoveryShown as $singleCode): ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <span><?= e((string) $singleCode) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="copyCodes">
                            <i class="bi bi-clipboard me-1"></i>Copy
                        </button>
                        <form method="post" action="<?= e(url('profile/2fa/ack')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-primary">I have saved them</button>
                        </form>
                    </div>

                    <script>
                    (function () {
                        var btn = document.getElementById('copyCodes');
                        if (!btn) { return; }
                        btn.addEventListener('click', function () {
                            var rows = document.querySelectorAll('.font-monospace.small div span');
                            var out = [];
                            rows.forEach(function (n) { out.push(n.textContent.trim()); });
                            navigator.clipboard.writeText(out.join('\n'));
                        });
                    }());
                    </script>

                <?php elseif (!empty($user['totp_enabled_at'])): ?>
                    <p class="small text-body-secondary mb-2">
                        <strong><?= (int) $recoveryRemaining ?></strong> unused code(s) on file.
                        They are stored only as hashes and cannot be displayed again - use
                        <em>Regenerate recovery codes</em> if you need a fresh set.
                    </p>
                    <p class="small text-body-secondary mb-0">
                        Lost your authenticator? Sign in with your password, then enter one of these
                        codes instead of the 6-digit one.
                    </p>
                <?php else: ?>
                    <p class="small text-body-secondary mb-0">
                        Recovery codes are created when you turn on two-factor authentication.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Security activity ---------------------------------------------------- -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Security activity</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                <th class="text-center">#</th>
                    <th>Action</th>
                    <th>Date / time</th>
                    <th>IP address</th>
                    <th class="d-none d-md-table-cell">Device</th>
                </tr>
            </thead>
            <tbody>
            <?php $rowNo = offset() + 1; ?>
            <?php if ($activity === []): ?>
                <tr><td colspan="5" class="text-center text-body-secondary py-4">
                    No security events recorded yet.
                </td></tr>
            <?php else: foreach ($activity as $event): ?>
                <tr>
                    <td class="text-center"><?= $rowNo++ ?></td>
                    <td>
                        <span class="badge text-bg-light border font-monospace">
                            <?= e((string) $event['action']) ?>
                        </span>
                    </td>
                    <td class="text-nowrap"><?= e((string) $event['created_at']) ?></td>
                    <td class="text-nowrap"><?= e((string) ($event['ip_address'] ?? '—')) ?></td>
                    <td class="d-none d-md-table-cell small text-body-secondary">
                        <?= e((string) mb_substr((string) ($event['user_agent'] ?? ''), 0, 70)) ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
