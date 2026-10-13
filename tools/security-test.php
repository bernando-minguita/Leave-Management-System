<?php

declare(strict_types=1);

/**
 * Security regression test: post-login open redirect, response security
 * headers, cache headers and the login rate limiter.
 *
 *   php tools/security-test.php
 *
 * Section 1 is pure unit work. Sections 2-4 make real HTTP calls, so they
 * need the dev server on http://127.0.0.1/lms. Sign-in uses a throwaway
 * account the test creates and removes itself - no shared password, and no
 * chance of tripping the rate limit on a real one.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/security-test.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$BASE  = 'http://127.0.0.1/lms';
$JAR   = tempnam(sys_get_temp_dir(), 'sec');
$TOKEN = '';
$pass  = 0;
$fail  = 0;

function ck(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%-64s %s%s\n", $label, $ok ? 'ok' : 'FAIL', $ok || $detail === '' ? '' : '  [' . $detail . ']');
}

/** One named response header, or '' when absent. */
function hdr(array $res, string $name): string
{
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $res['head'], $m) === 1) {
        return trim($m[1]);
    }

    return '';
}

/** One request returning code, raw header block and body. */
function req(string $path, array $post = []): array
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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (security-test) AppleWebKit/537.36 Chrome/120 Safari/537.36',
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

    if (preg_match('/name="_csrf"[^>]*value="([^"]+)"/', $body, $m) === 1) {
        $TOKEN = $m[1];
    }

    return ['code' => $code, 'head' => substr($raw, 0, $size), 'body' => $body];
}

/** The CSRF token currently held by the jar. */
function csrf(): string
{
    global $TOKEN;

    return $TOKEN;
}

/* =========================================================================
 * Throwaway account
 *
 * Sections 3 and 4 need a real sign-in. Minting one rather than depending on
 * a password that may have been rotated since the README was written also
 * keeps the shared admin bucket's rate limit untouched - that limit is 5 per
 * 15 minutes, and a failed experiment used to burn it. Same pattern as
 * tools/account-test.php and tools/role-ui-test.php.
 *
 * `hr` is the lowest shipped role holding BOTH dashboard.view and
 * drivers.export, which is exactly what section 4 asserts on; `viewer` has
 * the first but not the second.
 * ====================================================================== */

$user     = 'zsec_' . substr(md5((string) microtime(true) . getmypid()), 0, 6);
$userPass = 'SecTest4567!';

// A previous run that died mid-flight must not accumulate accounts.
Database::run("DELETE FROM users WHERE username LIKE 'zsec\\_%'");

Database::run(
    'INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$user, password_hash($userPass, PASSWORD_DEFAULT), 'Security Test', 'hr', 'active', now(), now()]
);
$userId = (int) Database::scalar('SELECT id FROM users WHERE username = ?', [$user]);

Database::run(
    'INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?',
    [$userId, 'hr']
);

register_shutdown_function(static function () use ($user, $userId): void {
    \App\Core\Database::run('DELETE FROM audit_logs WHERE user_id = ?', [$userId]);
    \App\Core\Database::run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
    \App\Core\Database::run('DELETE FROM users WHERE id = ?', [$userId]);
    \App\Core\Database::run(
        "DELETE FROM rate_limits WHERE scope = 'login' AND bucket LIKE ?",
        ['%|' . $user]
    );
});

/* =========================================================================
 * 1. safe_redirect_target - the gate in front of every Location:
 * ====================================================================== */

echo "== 1. safe_redirect_target ==\n";

$rejected = [
    'https://evil.com'                => 'absolute http',
    'http://evil.com'                 => 'absolute http, plain',
    '//evil.com'                      => 'protocol-relative',
    '/\\evil.com'                     => 'protocol-relative, backslash',
    '\\\\evil.com'                    => 'backslash authority',
    'javascript:alert(1)'             => 'javascript scheme',
    'JaVaScRiPt:alert(1)'             => 'mixed-case javascript',
    'data:text/html;base64,PHN2Zz4='  => 'data scheme',
    'java' . "\t" . 'script:alert(1)' => 'tab split inside the scheme',
    ' https://evil.com'               => 'leading space before a scheme',
    "\nhttps://evil.com"              => 'leading newline before a scheme',
    'vbscript:msgbox(1)'              => 'vbscript scheme',
    '/lms/dashboard'                  => 'absolute path',
    'login'                           => 'the login page itself',
    ''                                => 'empty',
    '   '                             => 'whitespace only',
];

$bad = 0;
foreach ($rejected as $value => $why) {
    if (safe_redirect_target($value) !== 'dashboard') {
        $bad++;
        ck('rejects ' . $why, false, var_export($value, true));
    }
}
ck('every hostile shape falls back to the dashboard (' . count($rejected) . ' vectors)', $bad === 0);

$accepted = [
    'dashboard',
    'drivers',
    'leave/new',
    'driver-dashboard?export=csv',
    'reports/drivers?from=2026-01-01&to=2026-01-31',
    'settings/system#users',
    // No scheme and no authority: url() prefixes base_path(), so this stays
    // same-origin. Accepted on purpose - it is a path, not a redirect away.
    'evil.com',
];

$bad = 0;
foreach ($accepted as $value) {
    if (safe_redirect_target($value) !== $value) {
        $bad++;
        ck('accepts a bare in-app path', false, $value);
    }
}
ck('every in-app path survives untouched (' . count($accepted) . ' vectors)', $bad === 0);

ck('an explicit fallback is honoured', safe_redirect_target('//evil.com', 'drivers') === 'drivers');
ck('the dashboard is the default fallback', safe_redirect_target('//evil.com') === 'dashboard');

$authSrc = (string) file_get_contents(APP_ROOT . '/app/controllers/AuthController.php');
$fnSrc   = (string) file_get_contents(APP_ROOT . '/app/helpers/functions.php');
ck('resolveTarget routes through the gate rather than returning the raw value',
    str_contains($authSrc, 'return safe_redirect_target($target);'));
ck('the gate is defined next to url() and redirect()',
    str_contains($fnSrc, 'function safe_redirect_target(string $target'));

/* =========================================================================
 * 2. Response security headers
 * ====================================================================== */

echo "\n== 2. response security headers ==\n";

req('/logout');                                  // start from a clean session
$login = req('/login?to=' . urlencode('https://evil.com'));

ck('GET /login returns 200', $login['code'] === 200, 'code=' . $login['code']);
ck('X-Content-Type-Options is nosniff',
    hdr($login, 'X-Content-Type-Options') === 'nosniff', hdr($login, 'X-Content-Type-Options'));
ck('X-Frame-Options is DENY',
    hdr($login, 'X-Frame-Options') === 'DENY', hdr($login, 'X-Frame-Options'));
ck('Referrer-Policy is strict-origin-when-cross-origin',
    hdr($login, 'Referrer-Policy') === 'strict-origin-when-cross-origin', hdr($login, 'Referrer-Policy'));
ck('Permissions-Policy denies camera',
    str_contains(hdr($login, 'Permissions-Policy'), 'camera=()'), hdr($login, 'Permissions-Policy'));

$csp = hdr($login, 'Content-Security-Policy');
ck("Content-Security-Policy carries frame-ancestors 'none'",
    str_contains($csp, "frame-ancestors 'none'"), $csp);
ck("... and object-src 'none'",
    str_contains($csp, "object-src 'none'"), $csp);
ck("... and base-uri 'self'",
    str_contains($csp, "base-uri 'self'"), $csp);
// Documented in README "Known gaps": script-src needs browser verification
// first, because ~18 views emit inline <script> and login runs reCAPTCHA.
ck('script-src is still deliberately absent (documented gap)',
    !str_contains($csp, 'script-src'), $csp);

/* =========================================================================
 * 3. The open redirect, end to end
 * ====================================================================== */

echo "\n== 3. open redirect ==\n";

$attacks = [
    'https://evil.com/phish' => 'absolute URL',
    '//evil.com'             => 'protocol-relative URL',
    'javascript:alert(1)'    => 'javascript scheme',
    'http://evil.com'        => 'plain http URL',
];

foreach ($attacks as $evil => $why) {
    // login() returns early when already signed in, so start clean each time.
    req('/logout');
    $got = req('/login?to=' . urlencode($evil));

    $carried = '';
    if (preg_match('/name="redirect"[^>]*value="([^"]*)"/', $got['body'], $m) === 1) {
        $carried = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    ck("$why: the form round-trips the value it was given", $carried === $evil, $carried);

    $res = req('/login', [
        '_csrf'    => csrf(),
        'username' => $user,
        'password' => $userPass,
        'redirect' => $carried,
    ]);
    $loc = hdr($res, 'Location');

    ck("$why: sign-in itself still succeeds",
        in_array($res['code'], [301, 302, 303], true), 'code=' . $res['code']);
    ck("$why: Location never names another origin",
        $loc !== '' && preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $loc) !== 1, $loc);
    ck("$why: and lands on a same-origin path",
        $loc !== '' && str_starts_with($loc, '/lms/'), $loc);
}
req('/logout');

/* =========================================================================
 * 4. Cache headers
 * ====================================================================== */

echo "\n== 4. cache-control ==\n";

ck('the login response is already no-store',
    str_contains(hdr($login, 'Cache-Control'), 'no-store'), hdr($login, 'Cache-Control'));

req('/logout');
req('/login');                                  // fresh session, refreshes the CSRF token
$auth = req('/login', [
    '_csrf' => csrf(), 'username' => $user, 'password' => $userPass, 'redirect' => '',
]);

ck('signing in for the cache checks succeeds',
    in_array($auth['code'], [301, 302, 303], true), 'code=' . $auth['code']);

$dash = req('/dashboard');
ck('GET /dashboard returns 200', $dash['code'] === 200, 'code=' . $dash['code']);
ck('the authenticated page is no-store',
    str_contains(hdr($dash, 'Cache-Control'), 'no-store'), hdr($dash, 'Cache-Control'));
ck('... and private',
    str_contains(hdr($dash, 'Cache-Control'), 'private'), hdr($dash, 'Cache-Control'));
ck('security headers are present on the authenticated page too',
    hdr($dash, 'X-Content-Type-Options') === 'nosniff' && hdr($dash, 'X-Frame-Options') === 'DENY',
    hdr($dash, 'X-Frame-Options'));

$exp = req('/drivers/export?export=csv');
ck('the CSV export returns 200', $exp['code'] === 200, 'code=' . $exp['code']);
ck('the export is served as an attachment',
    str_contains(hdr($exp, 'Content-Disposition'), 'attachment'), hdr($exp, 'Content-Disposition'));
// header() replaces by name, so an export has to restate no-store itself
// rather than rely on whatever bootstrap already sent.
ck('the export restates no-store instead of dropping it',
    str_contains(hdr($exp, 'Cache-Control'), 'no-store'), hdr($exp, 'Cache-Control'));
ck('... and still carries the security headers',
    hdr($exp, 'X-Content-Type-Options') === 'nosniff', hdr($exp, 'X-Content-Type-Options'));

/* =========================================================================
 * 5. Rate limiter - the row the race loses must still be counted
 * ====================================================================== */

echo "\n== 5. rate limiter ==\n";

$scope  = 'security-test';
$bucket = 'zsec|probe';
$pick   = static fn (): int => (int) Database::scalar(
    'SELECT attempts FROM rate_limits WHERE scope = ? AND bucket = ?',
    [$scope, $bucket]
);

Database::run('DELETE FROM rate_limits WHERE scope = ?', [$scope]);

rate_limit_fail($scope, $bucket, 900);
ck('the first failure opens the window at 1', $pick() === 1, (string) $pick());

rate_limit_fail($scope, $bucket, 900);
ck('the second failure increments to 2', $pick() === 2, (string) $pick());

// Reproduce the race itself: both requests read "no row yet" and both INSERT.
// With a bare INSERT the loser was swallowed by uq_rate_scope_bucket, so the
// pair counted as 1 and the allowance quietly widened.
Database::run('DELETE FROM rate_limits WHERE scope = ?', [$scope]);
$raceSql = 'INSERT INTO rate_limits (scope, bucket, attempts, window_started_at, last_attempt_at)
            VALUES (?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE
                rate_limits.attempts = rate_limits.attempts + 1,
                rate_limits.last_attempt_at = ?';
$now = date('Y-m-d H:i:s');
Database::run($raceSql, [$scope, $bucket, $now, $now, $now]);
Database::run($raceSql, [$scope, $bucket, $now, $now, $now]);
ck('two racing INSERTs land as 2, not 1', $pick() === 2, (string) $pick());

$secSrc = (string) file_get_contents(APP_ROOT . '/app/helpers/security.php');
ck('rate_limit_fail actually writes ON DUPLICATE KEY UPDATE',
    str_contains($secSrc, 'ON DUPLICATE KEY UPDATE'));

for ($i = 0; $i < 5; $i++) {
    rate_limit_fail($scope, $bucket, 900);
}
ck('the limit still trips once the allowance is spent',
    rate_limit_exceeded($scope, $bucket, 5, 900) === true);
ck('a rolled-over window releases it again',
    rate_limit_exceeded($scope, $bucket, 5, -1) === false);

Database::run('DELETE FROM rate_limits WHERE scope = ?', [$scope]);
@unlink($JAR);

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
