<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\View;

/**
 * Login / logout / two-factor challenge. Login is a normal form POST (not
 * AJAX) so it works with JavaScript disabled and keeps redirect-after-post.
 *
 * The order matters: rate limit -> reCAPTCHA -> password -> second factor ->
 * ONLY THEN a session. verify_credentials() and complete_login() are separate
 * so an authenticated session cannot exist before 2FA has passed.
 */
final class AuthController
{
    /** Attempts allowed per IP+username before the login form locks out. */
    private const LOGIN_MAX = 5;
    private const LOGIN_WINDOW = 900;
    private const TOTP_MAX = 5;
    private const TOTP_WINDOW = 900;

    public function showLogin(array $params = []): void
    {
        if (is_logged_in()) {
            redirect('dashboard');
        }

        $this->renderLogin(null, null, '', (string) ($_GET['to'] ?? ''));
    }

    public function login(array $params = []): void
    {
        if (is_logged_in()) {
            redirect('dashboard');
        }

        csrf_check();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $redirect = (string) ($_POST['redirect'] ?? '');

        $bucket = client_ip() . '|' . mb_strtolower($username);

        // 1. Rate limit - checked before anything expensive happens.
        if (rate_limit_exceeded('login', $bucket, self::LOGIN_MAX, self::LOGIN_WINDOW)) {
            $minutes = (int) ceil(rate_limit_retry_after('login', $bucket, self::LOGIN_WINDOW) / 60);
            $this->auditFailedLogin($username, 'rate_limited');

            $this->renderLogin(
                'Too many sign-in attempts. Please try again in ' . max(1, $minutes) . ' minute(s).',
                null, $username, $redirect
            );
            return;
        }

        // 2. Google reCAPTCHA (skipped automatically on localhost).
        if (recaptcha_enabled()) {
            $captcha = recaptcha_verify((string) ($_POST['g-recaptcha-response'] ?? ''));

            if (!$captcha['ok']) {
                $this->renderLogin($captcha['error'], null, $username, $redirect);
                return;
            }
        }

        // 3. Credentials. The message is deliberately identical for an unknown
        //    user and a wrong password, so accounts cannot be enumerated.
        $errors = [];

        if ($username === '') {
            $errors['username'] = 'Username is required.';
        }
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }

        $user = $errors === [] ? verify_credentials($username, $password) : null;

        if ($user === null) {
            if ($errors === []) {
                rate_limit_fail('login', $bucket, self::LOGIN_WINDOW);
                $this->auditFailedLogin($username, 'bad_credentials');
                $errors['password'] = 'The username or password you entered is not correct.';
            }

            $this->renderLogin(null, $errors, $username, $redirect);
            return;
        }

        rate_limit_clear('login', $bucket);

        $target = $this->resolveTarget($redirect);

        // 4. Second factor - NO authenticated session is created yet.
        if (!empty($user['totp_enabled_at'])) {
            $_SESSION['pending_2fa'] = [
                'user_id'   => (int) $user['id'],
                'username'  => (string) $user['username'],
                'full_name' => (string) $user['full_name'],
                'redirect'  => $target,
                'expires'   => time() + 300,
            ];

            redirect('login/2fa');
            return;
        }

        complete_login($user);
        flash_set('success', 'Welcome back, ' . $user['full_name'] . '.');
        redirect($target);
    }

    /**
     * Audit a rejected sign-in against the account it was aimed at.
     * The id is resolved silently - nothing here is ever shown to the caller,
     * so it cannot be used to discover whether the username exists.
     */
    private function auditFailedLogin(string $username, string $reason): void
    {
        $id = Database::scalar('SELECT id FROM users WHERE username = ?', [$username]);
        $id = $id !== null ? (int) $id : null;

        audit_log('LOGIN_FAILED', 'users', $id, null,
            ['username' => $username, 'reason' => $reason], $id);
    }

    /** Where to send the user after a successful sign-in. */
    private function resolveTarget(string $requested): string
    {
        $target = $requested;

        if ($target === '' && !empty($_SESSION['login_redirect'])) {
            $target = (string) $_SESSION['login_redirect'];
        }

        unset($_SESSION['login_redirect']);

        // Untrusted: ?to= and the hidden redirect field are attacker-shaped
        // ("?to=https://evil.com" is a phishing kit, not a feature). This is
        // the one gate on the way to a Location: header - url() itself will
        // happily pass an absolute URL through, because it is a builder, not
        // a validator. $_SESSION['login_redirect'] comes from request_path()
        // and is already a bare path, but it goes through the same check so
        // there is only one place that ever decides.
        return safe_redirect_target($target);
    }

    /** Shared renderer for the sign-in page. */
    private function renderLogin(
        ?string $error,
        ?array $errors,
        string $username,
        string $redirect
    ): void {
        View::render('auth/login', [
            'error'     => $error,
            'errors'    => $errors ?? [],
            'username'  => $username,
            'redirect'  => $redirect,
            'recaptcha' => recaptcha_enabled(),
            'siteKey'   => RECAPTCHA_SITE_KEY,
        ], 'Sign in', '', [], 'layouts/auth');
    }

    /* ------------------------------------------------ 2nd factor ------- */

    /** The 6-digit challenge. Rendered only while a login is pending. */
    public function twoFactorForm(array $params = []): void
    {
        if (is_logged_in()) {
            redirect('dashboard');
        }

        $pending = two_factor_pending();

        if ($pending === null) {
            redirect('login');
        }

        View::render('auth/two-factor', [
            'error'    => null,
            'username' => (string) $pending['username'],
            'minutes'  => (int) ceil(((int) $pending['expires'] - time()) / 60),
        ], 'Two-factor verification', '', [], 'layouts/auth');
    }

    public function twoFactorVerify(array $params = []): void
    {
        if (is_logged_in()) {
            redirect('dashboard');
        }

        csrf_check();

        $pending = two_factor_pending();

        if ($pending === null) {
            redirect('login');
        }

        $userId = (int) $pending['user_id'];
        $bucket = client_ip() . '|' . $userId;
        $code   = trim((string) ($_POST['code'] ?? ''));

        $fail = function (string $message) use ($pending): void {
            View::render('auth/two-factor', [
                'error'    => $message,
                'username' => (string) $pending['username'],
                'minutes'  => (int) ceil(((int) $pending['expires'] - time()) / 60),
            ], 'Two-factor verification', '', [], 'layouts/auth');
        };

        if (rate_limit_exceeded('2fa', $bucket, self::TOTP_MAX, self::TOTP_WINDOW)) {
            audit_log('2FA_VERIFICATION_FAILED', 'users', $userId, null, ['reason' => 'rate_limited'], $userId);
            clear_two_factor_pending();
            flash_set('danger', 'Too many verification attempts. Please sign in again.');
            redirect('login');
            return;
        }

        $row = Database::one(
            'SELECT id, username, password_hash, full_name, email, role, status,
                    totp_secret, totp_enabled_at, two_factor_required
               FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );

        if ($row === null || $row['status'] !== 'active') {
            clear_two_factor_pending();
            redirect('login');
            return;
        }

        // a. The authenticator app's current code.
        if (totp_verify(secret_decrypt((string) $row['totp_secret']), $code)) {
            rate_limit_clear('2fa', $bucket);
            complete_login($row);

            flash_set('success', 'Welcome back, ' . $row['full_name'] . '.');
            redirect((string) ($pending['redirect'] ?: 'dashboard'));
            return;
        }

        // b. A one-time recovery code, for a lost authenticator.
        $recoveryId = recovery_consume($userId, $code);

        if ($recoveryId !== null) {
            rate_limit_clear('2fa', $bucket);
            audit_log('RECOVERY_CODE_USED', 'users', $userId, null, ['recovery_code_id' => $recoveryId], $userId);
            complete_login($row);

            flash_set('warning', 'Signed in with a recovery code. '
                . recovery_remaining($userId) . ' unused code(s) remain - regenerate them from My Profile.');
            redirect((string) ($pending['redirect'] ?: 'dashboard'));
            return;
        }

        rate_limit_fail('2fa', $bucket, self::TOTP_WINDOW);
        audit_log('2FA_VERIFICATION_FAILED', 'users', $userId, null, [], $userId);

        $fail('That code is not correct. Check your authenticator app and try again.');
    }

    public function logout(array $params = []): void
    {
        logout();
        redirect('login');
    }
}
