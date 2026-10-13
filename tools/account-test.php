<?php

declare(strict_types=1);

/**
 * Account security test: profile, password change, TOTP 2FA, recovery codes,
 * login throttling, reCAPTCHA-on-localhost and administrator controls.
 *
 *   php tools/account-test.php
 *
 * Creates one throwaway account (zacct_*) and removes it at the end.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/account-test.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

date_default_timezone_set(APP_TIMEZONE);

$BASE  = 'http://127.0.0.1/lms';
$JAR   = tempnam(sys_get_temp_dir(), 'acct');
$TOKEN = '';
$pass  = 0;
$fail  = 0;
$lines = [];

function ck(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $lines;

    if ($cond) {
        $pass++;
        $lines[] = '[ok]   ' . $label;
    } else {
        $fail++;
        $lines[] = '[FAIL] ' . $label . ($detail === '' ? '' : ' :: ' . $detail);
    }
}

/** One client for GET and POST so the session jar is shared. */
function call(string $path, array $post = []): array
{
    global $BASE, $JAR, $TOKEN;

    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE     => $JAR,
        CURLOPT_COOKIEJAR      => $JAR,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => true,
        // A browser always sends one; the audit trail records it.
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (account-test) AppleWebKit/537.36 Chrome/120 Safari/537.36',
    ]);

    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $body = substr($raw, $size);

    // Keep a fresh CSRF token for the next form post.
    if (preg_match('/name="_csrf"[^>]*value="([^"]+)"/', $body, $m) === 1) {
        $TOKEN = $m[1];
    }

    return ['code' => $code, 'body' => $body];
}

function token(): string
{
    global $TOKEN;

    return $TOKEN;
}

function csrf(): string
{
    return token();
}

/** A fresh signed-in session for an arbitrary account. */
function loginAs(string $username, string $password): array
{
    call('/login');
    $r = call('/login', [
        '_csrf' => csrf(), 'username' => $username,
        'password' => $password, 'redirect' => '',
    ]);

    return $r;
}

function signOut(): void
{
    call('/logout');
}

/* =========================================================================
 * 1. Primitives
 * ====================================================================== */

$rfc = base32_encode('12345678901234567890');
ck('base32 matches RFC 4648', $rfc === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $rfc);
ck('base32 round-trips', base32_decode($rfc) === '12345678901234567890');

$vectors = [59 => '94287082', 1111111109 => '07081804', 1234567890 => '89005924', 2000000000 => '69279037'];
$ok = true;

foreach ($vectors as $t => $expect) {
    if (totp_code($rfc, $t, 8) !== $expect) {
        $ok = false;
    }
}

ck('TOTP matches RFC 6238 test vectors', $ok);

$sample = totp_secret_new();
ck('secret is 32 base32 chars', preg_match('/^[A-Z2-7]{32}$/', $sample) === 1, $sample);
ck('TOTP verifies its own current code', totp_verify($sample, totp_code($sample)));
ck('TOTP rejects a wrong code', !totp_verify($sample, '000000') || totp_code($sample) === '000000');

$round = secret_encrypt($sample);
ck('secret encrypts', $round !== '' && $round !== $sample);
ck('secret decrypts', secret_decrypt($round) === $sample);
ck('ciphertext does not contain the secret', strpos($round, $sample) === false);

$codes = recovery_codes_new();
ck('generates 10 recovery codes', count($codes) === 10);
ck('recovery code shape', preg_match('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $codes[0]) === 1, $codes[0]);
ck('recovery codes are stored as hashes',
    str_starts_with(recovery_hash($codes[0]), '$2y$')
    && strpos(recovery_hash($codes[0]), $codes[0]) === false);

/* =========================================================================
 * 2. reCAPTCHA is off on localhost, and the secret never reaches the browser
 * ====================================================================== */

ck('localhost is detected', is_local_host());
ck('reCAPTCHA disabled on localhost', !recaptcha_enabled());
ck('reCAPTCHA disabled while keys are empty', recaptcha_enabled() === false);
ck('secret key not defined in config as a literal',
    RECAPTCHA_SECRET_KEY === '' || !str_contains(file_get_contents(APP_ROOT . '/index.php'), RECAPTCHA_SECRET_KEY));

/* =========================================================================
 * 3. Fixture account
 * ====================================================================== */

$oldPass = 'OldPass123!';
$newPass = 'NewPass4567!';

$username = 'zacct_' . substr(md5((string) microtime(true)), 0, 6);

Database::run(
    'INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$username, password_hash($oldPass, PASSWORD_DEFAULT), 'Account Test', 'viewer', 'active', now(), now()]
);
$userId = (int) Database::scalar('SELECT id FROM users WHERE username = ?', [$username]);
$roleId = (int) Database::scalar("SELECT id FROM roles WHERE slug = 'viewer'");
Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);

ck('fixture account created', $userId > 0, 'id=' . $userId);

/* =========================================================================
 * 4. Sign in and My Profile
 * ====================================================================== */

$r = loginAs($username, $oldPass);
ck('password-only sign in succeeds (2FA off)', $r['code'] === 302, 'code=' . $r['code']);

$profile = call('/profile');
ck('GET /profile => 200', $profile['code'] === 200, 'code=' . $profile['code']);
ck('profile shows username', str_contains($profile['body'], $username));
ck('profile shows Personal information', str_contains($profile['body'], 'Personal information'));
ck('profile shows Change password', str_contains($profile['body'], 'Change password'));
ck('profile shows Two-factor authentication', str_contains($profile['body'], 'Two-factor authentication'));
ck('profile shows Security activity', str_contains($profile['body'], 'Security activity'));
ck('profile offers Enable 2FA', str_contains($profile['body'], 'profile/2fa/start'));
ck('secret is not on the page before enrolment', !str_contains($profile['body'], 'id="totpSecret"'));

/* =========================================================================
 * 5. Profile update + password change
 * ====================================================================== */

call('/profile/name', ['_csrf' => csrf(), 'full_name' => 'Renamed Account']);
ck('display name updated',
    (string) Database::scalar('SELECT full_name FROM users WHERE id = ?', [$userId]) === 'Renamed Account');

call('/profile/email', ['_csrf' => csrf(), 'email' => 'not-an-email']);
ck('invalid email rejected',
    (string) Database::scalar('SELECT email FROM users WHERE id = ?', [$userId]) === '');

call('/profile/email', ['_csrf' => csrf(), 'email' => 'tester@example.com']);
ck('valid email stored',
    (string) Database::scalar('SELECT email FROM users WHERE id = ?', [$userId]) === 'tester@example.com');

// Wrong current password.
call('/profile/password', [
    '_csrf' => csrf(), 'current_password' => 'definitely-wrong',
    'new_password' => $newPass, 'confirm_password' => $newPass,
]);
ck('wrong current password rejected',
    password_verify($oldPass, (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$userId])));

// Too short.
call('/profile/password', [
    '_csrf' => csrf(), 'current_password' => $oldPass,
    'new_password' => 'short', 'confirm_password' => 'short',
]);
ck('password below minimum length rejected',
    password_verify($oldPass, (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$userId])));

// Mismatch.
call('/profile/password', [
    '_csrf' => csrf(), 'current_password' => $oldPass,
    'new_password' => $newPass, 'confirm_password' => $newPass . 'x',
]);
ck('mismatched confirmation rejected',
    password_verify($oldPass, (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$userId])));

// Success.
call('/profile/password', [
    '_csrf' => csrf(), 'current_password' => $oldPass,
    'new_password' => $newPass, 'confirm_password' => $newPass,
]);

$hash = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$userId]);
ck('new password accepted', password_verify($newPass, $hash));
ck('only a hash is stored', str_starts_with($hash, '$2y$') && !str_contains($hash, $newPass));
ck('password_changed_at is set',
    Database::scalar('SELECT password_changed_at FROM users WHERE id = ?', [$userId]) !== null);

$pwdAudit = Database::all(
    "SELECT new_values, ip_address, user_agent FROM audit_logs
      WHERE action = 'PASSWORD_CHANGED' AND user_id = ? ORDER BY id DESC LIMIT 1",
    [$userId]
);
ck('PASSWORD_CHANGED audited', $pwdAudit !== []);
ck('audit carries IP and user agent',
    $pwdAudit !== []
    && !empty($pwdAudit[0]['ip_address'])
    && !empty($pwdAudit[0]['user_agent']),
    $pwdAudit !== [] ? json_encode($pwdAudit[0]) : 'no row');
ck('audit never contains the password',
    $pwdAudit === [] || !str_contains((string) $pwdAudit[0]['new_values'], $newPass));

signOut();
$again = loginAs($username, $oldPass);
ck('old password no longer works', $again['code'] !== 302 && !str_contains($again['body'], 'Welcome back'),
    'code=' . $again['code']);

$again = loginAs($username, $newPass);
ck('new password signs in', $again['code'] === 302, 'code=' . $again['code']);

/* =========================================================================
 * 6. Two-factor enrolment (QR + verification code)
 * ====================================================================== */

call('/profile/2fa/start', ['_csrf' => csrf()]);
$setup = call('/profile');

preg_match('/id="totpSecret"[^>]*value="([^"]+)"/', $setup['body'], $m);
$secret = $m[1] ?? '';

ck('enrolment step shows a manual key', preg_match('/^[A-Z2-7]{32}$/', $secret) === 1, $secret);
ck('setup page carries the QR renderer',
    str_contains($setup['body'], 'qrcode.min.js') && str_contains($setup['body'], 'qrBox'));
// json_encode escapes the slashes, so look for the scheme, not the separators.
ck('otpauth URI is handed to the page', str_contains($setup['body'], 'otpauth:'));
ck('secret is NOT written to the database before verification',
    (string) Database::scalar('SELECT totp_secret FROM users WHERE id = ?', [$userId]) === '');

// Wrong code keeps 2FA off.
call('/profile/2fa/enable', ['_csrf' => csrf(), 'code' => '000000']);
ck('wrong code does not enable 2FA',
    (string) Database::scalar('SELECT totp_enabled_at FROM users WHERE id = ?', [$userId]) === '');
ck('2FA still off after wrong code', !str_contains(call('/profile')['body'], 'Enabled since'));

// Correct code.
call('/profile/2fa/enable', ['_csrf' => csrf(), 'code' => totp_code($secret)]);
ck('correct code enables 2FA',
    (string) Database::scalar('SELECT totp_enabled_at FROM users WHERE id = ?', [$userId]) !== '');

$stored = (string) Database::scalar('SELECT totp_secret FROM users WHERE id = ?', [$userId]);
ck('stored secret is encrypted, not plaintext',
    $stored !== '' && $stored !== $secret && !str_contains($stored, $secret));
ck('stored secret still decrypts to the enrolment secret', secret_decrypt($stored) === $secret);

$after = call('/profile');
preg_match_all('/<span>([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})<\/span>/', $after['body'], $cm);
$recoveryCodes = $cm[1] ?? [];
ck('10 recovery codes rendered once', count($recoveryCodes) === 10, 'got ' . count($recoveryCodes));

$hashed = Database::all('SELECT code_hash FROM user_recovery_codes WHERE user_id = ?', [$userId]);
ck('10 recovery rows stored', count($hashed) === 10);

$plainStored = false;
foreach ($hashed as $row) {
    foreach ($recoveryCodes as $code) {
        if (str_contains((string) $row['code_hash'], $code)) {
            $plainStored = true;
        }
    }
}
ck('recovery codes stored only as hashes', !$plainStored);
ck('2FA_ENABLED audited',
    (int) Database::scalar(
        "SELECT COUNT(1) FROM audit_logs WHERE action = '2FA_ENABLED' AND user_id = ?",
        [$userId]
    ) >= 1);

call('/profile/2fa/ack', ['_csrf' => csrf()]);
$hidden = call('/profile');
preg_match_all('/<span>([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})<\/span>/', $hidden['body'], $hm);
ck('recovery codes hidden after acknowledgement', count($hm[1] ?? []) === 0);
ck('remaining count still shown, codes are not',
    str_contains($hidden['body'], 'unused code(s) on file'));

/* =========================================================================
 * 7. Login WITH 2FA - no session until the second factor passes
 * ====================================================================== */

signOut();
loginAs($username, $newPass);

$held = call('/profile');
ck('password alone leaves no authenticated session', $held['code'] === 302, 'code=' . $held['code']);
ck('profile unreachable before the second factor', !str_contains($held['body'], 'Personal information'));
ck('login was redirected to the 2FA challenge',
    loginAs($username, $newPass)['code'] === 302);

$challenge = call('/login/2fa');
ck('2FA challenge renders',
    $challenge['code'] === 200 && str_contains($challenge['body'], 'Two-factor verification'),
    'code=' . $challenge['code']);

$bad = call('/login/2fa', ['_csrf' => csrf(), 'code' => '999999']);
ck('wrong 2FA code rejected',
    $bad['code'] === 200 && str_contains($bad['body'], 'not correct'), 'code=' . $bad['code']);
ck('2FA_VERIFICATION_FAILED audited',
    (int) Database::scalar(
        "SELECT COUNT(1) FROM audit_logs WHERE action = '2FA_VERIFICATION_FAILED' AND user_id = ?",
        [$userId]
    ) >= 1);

$good = call('/login/2fa', ['_csrf' => csrf(), 'code' => totp_code($secret)]);
ck('correct 2FA code signs in', $good['code'] === 302, 'code=' . $good['code']);
$home = call('/dashboard');
ck('authenticated after the second factor',
    $home['code'] === 200 && str_contains($home['body'], 'Signed in as'), 'code=' . $home['code']);
ck('LOGIN_SUCCESS audited twice (password + 2FA)',
    (int) Database::scalar(
        "SELECT COUNT(1) FROM audit_logs WHERE action = 'LOGIN_SUCCESS' AND user_id = ?",
        [$userId]
    ) >= 2);

/* =========================================================================
 * 8. Recovery code is single-use
 * ====================================================================== */

$recoveryLogin = $recoveryCodes[0] ?? '';
signOut();
loginAs($username, $newPass);

$use1 = call('/login/2fa', ['_csrf' => csrf(), 'code' => $recoveryLogin]);
ck('recovery code signs in', $use1['code'] === 302, 'code=' . $use1['code']);
ck('RECOVERY_CODE_USED audited',
    (int) Database::scalar(
        "SELECT COUNT(1) FROM audit_logs WHERE action = 'RECOVERY_CODE_USED' AND user_id = ?",
        [$userId]
    ) >= 1);
ck('used recovery code is marked used',
    (int) Database::scalar(
        'SELECT COUNT(1) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NOT NULL',
        [$userId]
    ) >= 1);

signOut();
loginAs($username, $newPass);
$use2 = call('/login/2fa', ['_csrf' => csrf(), 'code' => $recoveryLogin]);
ck('same recovery code cannot be reused',
    $use2['code'] !== 302 && str_contains($use2['body'], 'not correct'), 'code=' . $use2['code']);

call('/login/2fa', ['_csrf' => csrf(), 'code' => totp_code($secret)]);
ck('authenticator still signs in after recovery use', call('/dashboard')['code'] === 200);

/* =========================================================================
 * 9. Regenerate recovery codes
 * ====================================================================== */

// Without a code.
call('/profile/2fa/recovery', [
    '_csrf' => csrf(), 'current_password' => $newPass, 'code' => '000000',
]);
ck('regeneration needs a valid 2FA code',
    (int) Database::scalar(
        'SELECT COUNT(1) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL',
        [$userId]
    ) === 9);

// With password + code.
call('/profile/2fa/recovery', [
    '_csrf' => csrf(), 'current_password' => $newPass, 'code' => totp_code($secret),
]);
ck('regeneration succeeds with password and code',
    (int) Database::scalar('SELECT COUNT(1) FROM user_recovery_codes WHERE user_id = ?', [$userId]) === 10);
ck('RECOVERY_CODES_REGENERATED audited',
    (int) Database::scalar(
        "SELECT COUNT(1) FROM audit_logs WHERE action = 'RECOVERY_CODES_REGENERATED' AND user_id = ?",
        [$userId]
    ) >= 1);
ck('an old recovery code is dead after regeneration',
    recovery_consume($userId, $recoveryLogin) === null);

$freshPage = call('/profile');
preg_match_all('/<span>([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})<\/span>/', $freshPage['body'], $fm);
$freshCodes = $fm[1] ?? [];
ck('fresh codes shown', count($freshCodes) === 10, 'got ' . count($freshCodes));
ck('fresh codes differ from the originals',
    $freshCodes !== [] && !in_array($recoveryLogin, $freshCodes, true));
call('/profile/2fa/ack', ['_csrf' => csrf()]);

/* =========================================================================
 * 10. Disable 2FA - password + code + confirmation, all three
 * ====================================================================== */

// No confirmation.
call('/profile/2fa/disable', [
    '_csrf' => csrf(), 'current_password' => $newPass, 'code' => totp_code($secret),
]);
ck('disable needs an explicit confirmation',
    (string) Database::scalar('SELECT totp_enabled_at FROM users WHERE id = ?', [$userId]) !== '');

// Wrong password.
call('/profile/2fa/disable', [
    '_csrf' => csrf(), 'current_password' => 'wrong-password',
    'code' => totp_code($secret), 'confirm' => '1',
]);
ck('disable needs the current password',
    (string) Database::scalar('SELECT totp_enabled_at FROM users WHERE id = ?', [$userId]) !== '');

// Wrong code.
call('/profile/2fa/disable', [
    '_csrf' => csrf(), 'current_password' => $newPass, 'code' => '000000', 'confirm' => '1',
]);
ck('disable needs a valid 2FA code',
    (string) Database::scalar('SELECT totp_enabled_at FROM users WHERE id = ?', [$userId]) !== '');

// All three.
call('/profile/2fa/disable', [
    '_csrf' => csrf(), 'current_password' => $newPass,
    'code' => totp_code($secret), 'confirm' => '1',
]);
ck('disable succeeds with password, code and confirmation',
    (string) Database::scalar('SELECT totp_enabled_at FROM users WHERE id = ?', [$userId]) === '');
ck('totp secret cleared',
    (string) Database::scalar('SELECT totp_secret FROM users WHERE id = ?', [$userId]) === '');
ck('recovery codes deleted on disable',
    (int) Database::scalar('SELECT COUNT(1) FROM user_recovery_codes WHERE user_id = ?', [$userId]) === 0);
ck('2FA_DISABLED audited',
    (int) Database::scalar(
        "SELECT COUNT(1) FROM audit_logs WHERE action = '2FA_DISABLED' AND user_id = ?",
        [$userId]
    ) >= 1);

signOut();
ck('password sign-in works again once 2FA is off',
    loginAs($username, $newPass)['code'] === 302);
signOut();

/* =========================================================================
 * 11. Administration
 * ====================================================================== */

// A viewer must not reach user administration.
loginAs($username, $newPass);
$admin = call('/settings/users');
ck('viewer cannot open Users', $admin['code'] === 403, 'code=' . $admin['code']);
signOut();

loginAs('admin', 'admin123');
$sys = call('/settings/users');
ck('administrator opens Users', $sys['code'] === 200, 'code=' . $sys['code']);
ck('users grid shows a 2FA column', str_contains($sys['body'], '<th>2FA</th>'));
ck('users grid offers the force/require action', str_contains($sys['body'], 'profile/2fa/force'));
ck('the TOTP secret is never rendered to an administrator',
    !str_contains($sys['body'], 'totp_secret') && !str_contains($sys['body'], $stored));

call('/profile/2fa/force', ['_csrf' => csrf(), 'user_id' => $userId, 'required' => '1']);
ck('administrator can require 2FA',
    (int) Database::scalar('SELECT two_factor_required FROM users WHERE id = ?', [$userId]) === 1);
ck('2FA_REQUIRED audited',
    (int) Database::scalar("SELECT COUNT(1) FROM audit_logs WHERE action = '2FA_REQUIRED'") >= 1);

// A required account is held on My Profile until it enrols.
signOut();
loginAs($username, $newPass);
$heldOn = call('/dashboard');
ck('required account is held off the dashboard', $heldOn['code'] === 302, 'code=' . $heldOn['code']);
$profHeld = call('/profile');
ck('My Profile stays reachable while enrolment is pending',
    $profHeld['code'] === 200 && str_contains($profHeld['body'], 'required for your account'),
    'code=' . $profHeld['code'] . ' banner=' . (str_contains($profHeld['body'], 'required for your account') ? 'yes' : 'no'));
signOut();
loginAs('admin', 'admin123');

call('/profile/2fa/force', ['_csrf' => csrf(), 'user_id' => $userId, 'required' => '0']);
ck('administrator can release the requirement',
    (int) Database::scalar('SELECT two_factor_required FROM users WHERE id = ?', [$userId]) === 0);

call('/profile/2fa/reset', ['_csrf' => csrf(), 'user_id' => $userId]);
ck('administrator can reset a 2FA configuration',
    (string) Database::scalar('SELECT totp_secret FROM users WHERE id = ?', [$userId]) === '');
ck('2FA_RESET audited',
    (int) Database::scalar("SELECT COUNT(1) FROM audit_logs WHERE action = '2FA_RESET'") >= 1);

signOut();

/* =========================================================================
 * 12. Throttling and account enumeration
 * ====================================================================== */

// Enumeration is checked FIRST: once the account is throttled the message
// changes and the two responses stop being comparable.
$unknown = loginAs('no_such_user_zz99', 'whatever');
$wrong   = loginAs($username, 'definitely-not-the-password');
ck('unknown user and wrong password give the same message',
    str_contains($unknown['body'], 'The username or password you entered is not correct.')
    && str_contains($wrong['body'], 'The username or password you entered is not correct.'),
    'unknown=' . (str_contains($unknown['body'], 'Too many') ? 'throttled' : 'credential')
    . ' wrong=' . (str_contains($wrong['body'], 'Too many') ? 'throttled' : 'credential'));
ck('no user-id leak for an unknown account',
    !str_contains($unknown['body'], 'no_such_user_zz99') || $unknown['code'] === 200);

$blocked = '';
for ($i = 1; $i <= 6; $i++) {
    $try = loginAs($username, 'definitely-not-the-password');
    if (str_contains($try['body'], 'Too many')) {
        $blocked = 'attempt ' . $i;
        break;
    }
}
ck('repeated failures lock the login form', $blocked !== '', 'never locked');

/* =========================================================================
 * 13. Every required audit event exists
 * ====================================================================== */

$requiredActions = [
    'LOGIN_SUCCESS', 'LOGIN_FAILED', 'PASSWORD_CHANGED', 'PROFILE_UPDATED',
    '2FA_ENABLED', '2FA_DISABLED', '2FA_VERIFICATION_FAILED',
    'RECOVERY_CODE_USED', 'RECOVERY_CODES_REGENERATED',
];

$found = array_column(
    Database::all(
        'SELECT DISTINCT action FROM audit_logs WHERE action IN ('
        . implode(',', array_fill(0, count($requiredActions), '?')) . ')',
        $requiredActions
    ),
    'action'
);

foreach ($requiredActions as $action) {
    ck('audit event ' . $action, in_array($action, $found, true));
}

$withAgent = (int) Database::scalar(
    'SELECT COUNT(1) FROM audit_logs WHERE user_agent IS NOT NULL AND ip_address IS NOT NULL'
);
ck('audit rows carry IP address and user agent', $withAgent > 0, 'rows=' . $withAgent);

/* =========================================================================
 * 14. Cleanup
 * ====================================================================== */

Database::run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
Database::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
Database::run('DELETE FROM audit_logs WHERE user_id = ?', [$userId]);
Database::run('DELETE FROM users WHERE id = ?', [$userId]);
Database::run('DELETE FROM rate_limits WHERE bucket = ?', [client_ip() . '|' . $username]);

ck('fixture account removed',
    (int) Database::scalar('SELECT COUNT(1) FROM users WHERE id = ?', [$userId]) === 0);
ck('fixture audit rows removed',
    (int) Database::scalar('SELECT COUNT(1) FROM audit_logs WHERE user_id = ?', [$userId]) === 0);

@unlink($JAR);

$lines[] = '';
$lines[] = $fail === 0
    ? 'ALL ' . $pass . ' passed'
    : $pass . ' passed, ' . $fail . ' failed';

$summary = implode(PHP_EOL, $lines) . PHP_EOL;
file_put_contents(LOG_PATH . '/account-summary.txt', $summary);
echo $summary;

exit($fail === 0 ? 0 : 1);
