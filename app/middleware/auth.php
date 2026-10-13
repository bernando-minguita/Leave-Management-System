<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Status;
use App\Core\View;

/* ===========================================================================
 * Session / identity
 * ======================================================================== */

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    // Re-read the account on every request so a role change or deactivation
    // takes effect on the very next page load instead of at session end.
    static $cached = false;
    if ($cached) {
        return $_SESSION['user'] ?? null;
    }

    $stmt = Database::run(
        'SELECT id, username, full_name, role, status FROM users WHERE id = ? LIMIT 1',
        [(int)$_SESSION['user_id']]
    );
    $row = $stmt->fetch();

    if ($row === false || $row['status'] !== 'active') {
        unset($_SESSION['user_id'], $_SESSION['user']);
        $cached = true;

        return null;
    }

    $_SESSION['user'] = [
        'id'        => (int)$row['id'],
        'username'  => (string)$row['username'],
        'full_name' => (string)$row['full_name'],
        'role'      => (string)$row['role'],
    ];
    $cached = true;

    return $_SESSION['user'];
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Redirect to the login page unless a valid session exists. */
function require_login(): void
{
    if (current_user() === null) {
        if (app_context() === 'api') {
            json_error('Please sign in to continue.', 401);
        }

        $_SESSION['login_redirect'] = request_path();
        redirect('login');
    }

    // Housekeeping: keep stored statuses in line with today's date so KPIs,
    // badges and the calendar can never drift after a midnight rollover.
    Status::sync();

    // System policy: an account told to enrol in 2FA is held on the profile
    // page until it has. Cleared by ProfileController once 2FA is enabled.
    if (app_context() !== 'api' && !empty($_SESSION['must_enroll_2fa'])) {
        $path = trim(request_path(), '/');

        if ($path !== 'profile' && $path !== 'logout' && !str_starts_with($path, 'profile/')) {
            redirect('profile');
        }
    }
}

/* Authorization moved to middleware/permissions.php: the application asks for
 * a permission ("settings.system"), never for a role name, so an access rule
 * can be changed in Settings without editing any code. */

/* ===========================================================================
 * Authentication
 *
 * Credential verification and session creation are deliberately SEPARATE: when
 * two-factor authentication is on, no authenticated session exists until the
 * second factor has been proved. See AuthController.
 * ======================================================================== */

/** IP the current request came from (used as a rate-limit bucket). */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Verify username/password WITHOUT creating a session. Returns the user row on
 * success, null otherwise. Never reveals which part of the credential was wrong.
 */
function verify_credentials(string $username, string $password): ?array
{
    $row = Database::one(
        'SELECT id, username, password_hash, full_name, email, role, status,
                totp_enabled_at, two_factor_required
           FROM users WHERE username = ? LIMIT 1',
        [trim($username)]
    );

    if ($row === null || $row['status'] !== 'active') {
        // Spend the same time as a real hash so timing cannot be used to
        // discover which usernames exist.
        password_verify($password, '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin');

        return null;
    }

    if (!password_verify($password, (string) $row['password_hash'])) {
        return null;
    }

    // Re-hash when the default algorithm has moved on.
    if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
        Database::update('users', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at'    => now(),
        ], 'id = ?', [(int) $row['id']]);
    }

    return $row;
}

/**
 * Open the authenticated session. Must only be called once every required
 * factor has been proved - this is what makes a user "logged in".
 */
function complete_login(array $row): void
{
    session_regenerate_id(true);

    unset($_SESSION['pending_2fa']);

    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['user']    = [
        'id'        => (int) $row['id'],
        'username'  => (string) $row['username'],
        'full_name' => (string) $row['full_name'],
        'role'      => (string) $row['role'],
    ];

    if (!empty($row['two_factor_required']) && empty($row['totp_enabled_at'])) {
        $_SESSION['must_enroll_2fa'] = 1;
    } else {
        unset($_SESSION['must_enroll_2fa']);
    }

    Database::update('users', ['last_login_at' => now(), 'updated_at' => now()], 'id = ?', [(int) $row['id']]);

    audit_log('LOGIN_SUCCESS', 'users', (int) $row['id'], null, ['username' => $row['username']]);
}

/** The staged, not-yet-authenticated 2FA challenge, or null. */
function two_factor_pending(): ?array
{
    $pending = $_SESSION['pending_2fa'] ?? null;

    if (!is_array($pending)) {
        return null;
    }

    if ((int) ($pending['expires'] ?? 0) < time()) {
        unset($_SESSION['pending_2fa']);

        return null;
    }

    return $pending;
}

function clear_two_factor_pending(): void
{
    unset($_SESSION['pending_2fa']);
}

function logout(): void
{
    if (!empty($_SESSION['user_id'])) {
        audit_log('LOGOUT', 'users', (int) $_SESSION['user_id']);
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'],
            (bool)$params['secure'], (bool)$params['httponly']);
    }

    session_destroy();
}

