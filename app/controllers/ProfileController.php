<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Validator;
use App\Core\View;

/**
 * My Profile - the signed-in user's own account.
 *
 * Everything here acts on $_SESSION['user_id'] only. No password, TOTP secret
 * or recovery code is ever rendered back to the browser after it has been set.
 */
final class ProfileController
{
    /** Full account row for the signed-in user, or null. */
    private function me(): ?array
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        return Database::one(
            'SELECT id, username, password_hash, full_name, email, role, status,
                    password_changed_at, totp_secret, totp_enabled_at, two_factor_required
               FROM users WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    public function index(array $params = []): void
    {
        require_login();

        $me = $this->me();

        if ($me === null) {
            redirect('login');
        }

        // Enrolment secret lives in the session only - it is written to the
        // database (encrypted) solely once the user proves the code.
        $enrol = $_SESSION['pending_2fa_enroll'] ?? null;

        if (is_array($enrol) && (int) ($enrol['expires'] ?? 0) < time()) {
            unset($_SESSION['pending_2fa_enroll']);
            $enrol = null;
        }

        $uri = null;

        if (is_array($enrol) && !empty($enrol['secret'])) {
            $uri = totp_uri((string) $enrol['secret'], (string) $me['username'], APP_NAME);
        }

        $activity = Database::all(
            'SELECT action, ip_address, user_agent, created_at
               FROM audit_logs WHERE user_id = ?
              ORDER BY id DESC LIMIT 20',
            [(int) $me['id']]
        );

        View::render('profile/index', [
            'user'              => $me,
            'twoFactorEnabled'  => !empty($me['totp_enabled_at']),
            'mustEnrol'         => !empty($_SESSION['must_enroll_2fa']),
            'enrol'             => $enrol,
            'qrUri'             => $uri,
            'recoveryShown'     => $_SESSION['recovery_shown'] ?? null,
            'recoveryRemaining' => recovery_remaining((int) $me['id']),
            'activity'          => $activity,
            'minPassword'       => MIN_PASSWORD_LENGTH,
            'canForce'          => can('settings.users'),
        ], 'My Profile', 'profile');
    }

    /**
     * Personal information: full name and email address.
     *
     * One endpoint backs the single Save button on My Profile. When the
     * request carries no 'email' key the stored address is left untouched,
     * so callers that post only a name keep working.
     */
    public function updateName(array $params = []): void
    {
        require_login();
        csrf_check();

        $me = $this->me();
        $v  = new Validator($_POST);
        $v->required('full_name', 'Full name')->maxLength('full_name', 'Full name', 120);

        $hasEmail = array_key_exists('email', $_POST);
        $email    = trim((string) ($_POST['email'] ?? ''));

        if ($hasEmail && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $v->addError('email', 'That does not look like a valid email address.');
        }

        if ($v->fails()) {
            flash_set('danger', (string) $v->firstError());
            redirect('profile');
            return;
        }

        $nameBefore  = (string) $me['full_name'];
        $nameAfter   = $v->text('full_name');
        $emailBefore = (string) ($me['email'] ?? '');
        $emailAfter  = $hasEmail ? ($email === '' ? null : mb_substr($email, 0, 160)) : null;

        $changed = false;

        if ($nameBefore !== $nameAfter) {
            Database::update('users', [
                'full_name'  => $nameAfter,
                'updated_at' => now(),
            ], 'id = ?', [(int) $me['id']]);

            $_SESSION['user']['full_name'] = $nameAfter;

            audit_log('PROFILE_UPDATED', 'users', (int) $me['id'], ['full_name' => $nameBefore], ['full_name' => $nameAfter]);
            $changed = true;
        }

        if ($hasEmail && $emailBefore !== (string) $emailAfter) {
            Database::update('users', [
                'email'      => $emailAfter,
                'updated_at' => now(),
            ], 'id = ?', [(int) $me['id']]);

            audit_log('PROFILE_UPDATED', 'users', (int) $me['id'], ['email' => $emailBefore], ['email' => $emailAfter]);
            $changed = true;
        }

        if ($changed) {
            flash_set('success', 'Your profile has been updated.');
        } else {
            flash_set('info', 'Nothing to change.');
        }

        redirect('profile');
    }

    /** Personal information: email address. */
    public function updateEmail(array $params = []): void
    {
        require_login();
        csrf_check();

        $me    = $this->me();
        $email = trim((string) ($_POST['email'] ?? ''));
        $v     = new Validator($_POST);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $v->addError('email', 'That does not look like a valid email address.');
        }

        if ($v->fails()) {
            flash_set('danger', (string) $v->firstError());
            redirect('profile');
            return;
        }

        $before = (string) $me['email'];
        $value  = $email === '' ? null : mb_substr($email, 0, 160);

        if ($before !== (string) $value) {
            Database::update('users', [
                'email'      => $value,
                'updated_at' => now(),
            ], 'id = ?', [(int) $me['id']]);

            audit_log('PROFILE_UPDATED', 'users', (int) $me['id'], ['email' => $before], ['email' => $value]);
            flash_set('success', 'Your email address has been updated.');
        } else {
            flash_set('info', 'Nothing to change.');
        }

        redirect('profile');
    }

    /**
     * Change password. Requires the current password, stores only the hash,
     * and never writes the password itself anywhere.
     */
    public function changePassword(array $params = []): void
    {
        require_login();
        csrf_check();

        $me = $this->me();

        if ($me === null) {
            redirect('login');
            return;
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $v = new Validator($_POST);
        $v->required('current_password', 'Current password')
            ->required('new_password', 'New password')
            ->required('confirm_password', 'Confirm password');

        if ($v->passes()) {
            if (!password_verify($current, (string) $me['password_hash'])) {
                $v->addError('current_password', 'Your current password is not correct.');
            } elseif (strlen($new) < MIN_PASSWORD_LENGTH) {
                $v->addError('new_password', 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.');
            } elseif ($new !== $confirm) {
                $v->addError('confirm_password', 'The two new passwords do not match.');
            } elseif (password_verify($new, (string) $me['password_hash'])) {
                $v->addError('new_password', 'Choose a password you have not used here before.');
            }
        }

        if ($v->fails()) {
            flash_set('danger', (string) $v->firstError());
            redirect('profile');
            return;
        }

        Database::update('users', [
            'password_hash'       => password_hash($new, PASSWORD_DEFAULT),
            'password_changed_at' => now(),
            'updated_at'          => now(),
        ], 'id = ?', [(int) $me['id']]);

        audit_log('PASSWORD_CHANGED', 'users', (int) $me['id'], null, ['method' => 'self-service']);

        flash_set('success', 'Your password has been changed.');
        redirect('profile');
    }

    /* ------------------------------------------- two-factor (TOTP) ------ */

    /** Step 1: mint a secret and put it in the session, not the database. */
    public function twoFactorStart(array $params = []): void
    {
        require_login();
        csrf_check();

        $me = $this->me();

        if ($me === null) {
            redirect('login');
            return;
        }

        if (!empty($me['totp_enabled_at'])) {
            flash_set('info', 'Two-factor authentication is already enabled.');
            redirect('profile');
            return;
        }

        $_SESSION['pending_2fa_enroll'] = [
            'secret'  => totp_secret_new(),
            'expires' => time() + 600,
        ];
        unset($_SESSION['recovery_shown']);

        flash_set('info', 'Scan the QR code with your authenticator app, then enter the 6-digit code it shows.');
        redirect('profile');
    }

    /** Step 2: verify the code, and only then persist the secret. */
    public function twoFactorEnable(array $params = []): void
    {
        require_login();
        csrf_check();

        $me    = $this->me();
        $enrol = $_SESSION['pending_2fa_enroll'] ?? null;

        if ($me === null) {
            redirect('login');
            return;
        }

        if (!is_array($enrol) || empty($enrol['secret']) || (int) ($enrol['expires'] ?? 0) < time()) {
            unset($_SESSION['pending_2fa_enroll']);
            flash_set('danger', 'The setup step expired. Start again.');
            redirect('profile');
            return;
        }

        $code = trim((string) ($_POST['code'] ?? ''));

        if (!totp_verify((string) $enrol['secret'], $code)) {
            flash_set('danger', 'That code is not correct. Check your authenticator app and try again.');
            redirect('profile');
            return;
        }

        Database::update('users', [
            'totp_secret'     => secret_encrypt((string) $enrol['secret']),
            'totp_enabled_at' => now(),
            'updated_at'      => now(),
        ], 'id = ?', [(int) $me['id']]);

        unset($_SESSION['pending_2fa_enroll'], $_SESSION['must_enroll_2fa']);

        // Shown exactly once - from here on only hashes exist.
        $_SESSION['recovery_shown'] = recovery_regenerate((int) $me['id']);

        audit_log('2FA_ENABLED', 'users', (int) $me['id'], null, ['method' => 'totp']);

        flash_set('success', 'Two-factor authentication is enabled. Save your recovery codes now - they are shown only once.');
        redirect('profile');
    }

    /** Abandon enrolment without changing anything. */
    public function twoFactorCancel(array $params = []): void
    {
        require_login();
        csrf_check();

        unset($_SESSION['pending_2fa_enroll']);
        flash_set('info', 'Setup cancelled - two-factor authentication is still off.');
        redirect('profile');
    }

    /**
     * Turn 2FA off. Needs the CURRENT PASSWORD, a valid CURRENT 2FA CODE and an
     * explicit confirmation - all three, every time.
     */
    public function twoFactorDisable(array $params = []): void
    {
        require_login();
        csrf_check();

        $me = $this->me();

        if ($me === null) {
            redirect('login');
            return;
        }

        if (empty($me['totp_enabled_at'])) {
            flash_set('info', 'Two-factor authentication is not enabled.');
            redirect('profile');
            return;
        }

        if (!isset($_POST['confirm'])) {
            flash_set('danger', 'Please confirm that you want to disable two-factor authentication.');
            redirect('profile');
            return;
        }

        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $me['password_hash'])) {
            flash_set('danger', 'Your current password is not correct.');
            redirect('profile');
            return;
        }

        if (!totp_verify(secret_decrypt((string) $me['totp_secret']), trim((string) ($_POST['code'] ?? '')))) {
            flash_set('danger', 'That code is not correct - two-factor authentication was NOT disabled.');
            redirect('profile');
            return;
        }

        Database::update('users', [
            'totp_secret'     => null,
            'totp_enabled_at' => null,
            'updated_at'      => now(),
        ], 'id = ?', [(int) $me['id']]);

        Database::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [(int) $me['id']]);

        unset($_SESSION['recovery_shown'], $_SESSION['pending_2fa_enroll']);

        audit_log('2FA_DISABLED', 'users', (int) $me['id'], null, ['by' => 'self']);

        flash_set('warning', 'Two-factor authentication has been disabled.');
        redirect('profile');
    }

    /** Fresh recovery codes: password + a valid 2FA code required. */
    public function regenerateRecovery(array $params = []): void
    {
        require_login();
        csrf_check();

        $me = $this->me();

        if ($me === null || empty($me['totp_enabled_at'])) {
            redirect('profile');
            return;
        }

        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $me['password_hash'])) {
            flash_set('danger', 'Your current password is not correct.');
            redirect('profile');
            return;
        }

        if (!totp_verify(secret_decrypt((string) $me['totp_secret']), trim((string) ($_POST['code'] ?? '')))) {
            flash_set('danger', 'That code is not correct - the existing recovery codes were kept.');
            redirect('profile');
            return;
        }

        $_SESSION['recovery_shown'] = recovery_regenerate((int) $me['id']);

        audit_log('RECOVERY_CODES_REGENERATED', 'users', (int) $me['id'], null, []);

        flash_set('success', 'New recovery codes generated. The previous codes no longer work.');
        redirect('profile');
    }

    /** "I have stored these" - clears the one-time display from the session. */
    public function acknowledgeRecovery(array $params = []): void
    {
        require_login();
        csrf_check();

        unset($_SESSION['recovery_shown']);
        flash_set('info', 'Recovery codes hidden. They cannot be shown again.');
        redirect('profile');
    }

    /* ------------------------------------- administrator 2FA actions ---- */

    /** Require / release 2FA for another account (settings.users). */
    public function forceTwoFactor(array $params = []): void
    {
        require_login();
        csrf_check();
        require_permission('settings.users');

        $targetId = (int) ($_POST['user_id'] ?? 0);
        $required = (int) ($_POST['required'] ?? 0) === 1;

        $target = Database::one('SELECT id, username, two_factor_required FROM users WHERE id = ?', [$targetId]);

        if ($target === null) {
            flash_set('danger', 'That user no longer exists.');
            redirect('settings/users');
            return;
        }

        Database::update('users', [
            'two_factor_required' => $required ? 1 : 0,
            'updated_at'          => now(),
        ], 'id = ?', [$targetId]);

        audit_log($required ? '2FA_REQUIRED' : '2FA_REQUIREMENT_REMOVED', 'users', $targetId, [
            'two_factor_required' => (int) $target['two_factor_required'],
        ], ['two_factor_required' => $required ? 1 : 0]);

        flash_set('success', $required
            ? $target['username'] . ' must now configure two-factor authentication.'
            : $target['username'] . ' is no longer required to use two-factor authentication.');
        redirect('settings/users');
    }

    /** Clear a user's 2FA so they can enrol again (settings.users). */
    public function resetTwoFactor(array $params = []): void
    {
        require_login();
        csrf_check();
        require_permission('settings.users');

        $targetId = (int) ($_POST['user_id'] ?? 0);
        $target   = Database::one('SELECT id, username, totp_enabled_at FROM users WHERE id = ?', [$targetId]);

        if ($target === null) {
            flash_set('danger', 'That user no longer exists.');
            redirect('settings/users');
            return;
        }

        Database::update('users', [
            'totp_secret'     => null,
            'totp_enabled_at' => null,
            'updated_at'      => now(),
        ], 'id = ?', [$targetId]);

        Database::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$targetId]);

        audit_log('2FA_RESET', 'users', $targetId, null, ['was_enabled' => !empty($target['totp_enabled_at'])]);

        flash_set('warning', 'Two-factor configuration cleared for ' . $target['username']
            . '. They will need to enrol again.');
        redirect('settings/users');
    }
}
