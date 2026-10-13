<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/rbac-test.php\n");
}


require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$base = 'http://127.0.0.1/lms';

$pass = 0;
$fail = 0;
$issues = [];
function ck(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $issues;
    $ok ? $pass++ : $fail++;
    if (!$ok) {
        $issues[] = $label . ($detail === '' ? '' : '  [' . $detail . ']');
    }
    printf("%-58s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $ok || $detail === '' ? '' : '  [' . $detail . ']');
}

/** Sign in as $user into a private cookie jar and return it. */
function signIn(string $user, string $password): string
{
    global $base;
    $jar = tempnam(sys_get_temp_dir(), 'rb');

    $call = static function (string $url, array $post = []) use ($jar): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE => $jar,
            CURLOPT_COOKIEJAR  => $jar,
            CURLOPT_TIMEOUT     => 30,
            CURLOPT_HEADER      => true,
        ]);
        if ($post !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return ['code' => $code, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    };

    $g = $call($base . '/login');
    preg_match('/name="_csrf"[^>]*value="([^"]+)"/', $g['body'], $m);
    $call($base . '/login', [
        '_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $password, 'redirect' => '',
    ]);

    return $jar;
}

function status(string $jar, string $path): int
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR  => $jar,
        CURLOPT_TIMEOUT     => 30,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code;
}

function body(string $jar, string $path): string
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR  => $jar,
        CURLOPT_TIMEOUT     => 30,
    ]);
    $out = (string) curl_exec($ch);
    curl_close($ch);

    return $out;
}

/* ------------------------------------------------------- fixtures -------- */

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$roles = ['admin', 'hr', 'operations', 'viewer'];
$users = [];

// Idempotent: a previous run that died before cleanup leaves fixtures behind,
// and the unique username would then abort this one.
Database::run("DELETE FROM users WHERE username LIKE 'zrbac\\_%'");

foreach ($roles as $slug) {
    $username = 'zrbac_' . $slug;
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$username, password_hash('test123', PASSWORD_DEFAULT), 'RBAC ' . $slug, $slug, 'active']);

    $userId = (int) $pdo->lastInsertId();
    $roleId = (int) Database::scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);

    $users[$slug] = $username;
}

ck('four role fixture accounts created', count($users) === 4);

$jars = [];
foreach ($roles as $slug) {
    $jars[$slug] = signIn($users[$slug], 'test123');
}
ck('all four roles signed in', count($jars) === 4);

/* ------------------------------------------- the permission matrix -------- */
// [path, expected status per role]  200 = allowed, 403 = denied.
$matrix = [
    // Dashboard - every role.
    ['/dashboard', ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 200]],
    // Address book: readable by all...
    ['/drivers', ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 200]],
    // ...written only by administrator and HR.
    ['/drivers/create', ['admin' => 200, 'hr' => 200, 'operations' => 403, 'viewer' => 403]],
    ['/drivers/import', ['admin' => 200, 'hr' => 200, 'operations' => 403, 'viewer' => 403]],
    ['/import', ['admin' => 200, 'hr' => 200, 'operations' => 403, 'viewer' => 403]],
    ['/drivers?export=csv', ['admin' => 200, 'hr' => 200, 'operations' => 403, 'viewer' => 403]],
    // Leave: everyone reads, viewer never creates.
    ['/leave/records', ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 200]],
    ['/leave/current', ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 200]],
    ['/leave/new', ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 403]],
    // Reports - every role may read.
    ['/reports/leave', ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 200]],
    // Settings - administrator only.
    // Administrator reaches the hub and is forwarded to the System screen.
    ['/settings', ['admin' => 302, 'hr' => 403, 'operations' => 403, 'viewer' => 403]],
    ['/settings/branches', ['admin' => 200, 'hr' => 403, 'operations' => 403, 'viewer' => 403]],
    ['/settings/agencies', ['admin' => 200, 'hr' => 403, 'operations' => 403, 'viewer' => 403]],
    ['/settings/leave-types', ['admin' => 200, 'hr' => 403, 'operations' => 403, 'viewer' => 403]],
    ['/settings/system', ['admin' => 200, 'hr' => 403, 'operations' => 403, 'viewer' => 403]],
    // Formerly the "User Accounts" tab on System Settings.
    ['/settings/users', ['admin' => 200, 'hr' => 403, 'operations' => 403, 'viewer' => 403]],
    ['/settings/roles', ['admin' => 200, 'hr' => 403, 'operations' => 403, 'viewer' => 403]],
    ['/settings/backup', ['admin' => 200, 'hr' => 403, 'operations' => 403, 'viewer' => 403]],
    // Audit - administrator and HR only.
    ['/settings/audit', ['admin' => 200, 'hr' => 200, 'operations' => 403, 'viewer' => 403]],
];

$probes = 0;
foreach ($matrix as $entry) {
    $path     = $entry[0];
    $expected = $entry[1];

    foreach ($expected as $slug => $code) {
        $actual = status($jars[$slug], $path);
        $probes++;
        ck(sprintf('%-10s %-24s %d', $slug, $path, $code), $actual === $code, 'got ' . $actual);
    }
}
echo "\n{$probes} route probes\n";

/* -------------------------------------------- navigation & chrome --------- */

// Navigation is checked on /drivers: the dashboard carries its own
// "New Leave" shortcuts, which would mask what the sidebar actually shows.
$navAdmin = body($jars['admin'], '/drivers');
$navHr    = body($jars['hr'], '/drivers');
$navView  = body($jars['viewer'], '/drivers');
$navOps   = body($jars['operations'], '/drivers');

ck('admin nav shows Settings screens', str_contains($navAdmin, 'settings/branches'));
ck('admin nav shows Roles & Permissions', str_contains($navAdmin, 'settings/roles'));
ck('admin nav shows New Leave', str_contains($navAdmin, 'leave/new'));

ck('HR nav shows New Leave', str_contains($navHr, 'leave/new'));
ck('HR nav shows the Audit Log', str_contains($navHr, 'settings/audit'));
ck('HR nav hides branch settings', !str_contains($navHr, 'settings/branches'));
ck('HR nav hides Roles & Permissions', !str_contains($navHr, 'settings/roles'));

ck('operations nav shows New Leave', str_contains($navOps, 'leave/new'));
ck('operations nav hides the audit log', !str_contains($navOps, 'settings/audit'));
ck('operations nav hides all Settings', !str_contains($navOps, 'settings/'));

ck('viewer nav hides New Leave', !str_contains($navView, 'leave/new'));
ck('viewer nav hides every Settings screen', !str_contains($navView, 'settings/'));
ck('viewer still sees the address book', str_contains($navView, '/drivers'));

// Bulk-importing the Address Book rewrites every driver in one POST, so the
// sidebar shortcut to it is an administrator's. The route itself still sits
// behind drivers.import (probed above) - this is the menu being deliberately
// narrower than the permission, not the permission widened to match it.
$sidebarImport = static function (string $html): bool {
    return preg_match('#class="sidebar-link[^"]*"\s+href="[^"]*/import"#', $html) === 1;
};
ck('admin nav offers Import / Export', $sidebarImport($navAdmin));
ck('HR nav hides Import / Export', !$sidebarImport($navHr));
ck('operations nav hides Import / Export', !$sidebarImport($navOps));
ck('viewer nav hides Import / Export', !$sidebarImport($navView));

// Top bar shortcuts.
$chromeAdmin = body($jars['admin'], '/leave/records');
$chromeView  = body($jars['viewer'], '/leave/records');
ck('admin topbar offers Audit', str_contains($chromeAdmin, 'settings/audit'));
ck('admin topbar offers Settings', str_contains($chromeAdmin, 'settings/system'));
ck('viewer topbar hides Audit', !str_contains($chromeView, 'settings/audit'));
ck('viewer topbar hides Settings', !str_contains($chromeView, 'settings/system'));
ck('topbar shows the role label, not a raw slug',
    str_contains($chromeAdmin, 'Administrator'));

// Grid controls follow permissions too.
ck('admin sees the bulk-select grid', str_contains($chromeAdmin, 'data-bulk-select'));
ck('viewer does not', !str_contains($chromeView, 'data-bulk-select'));
ck('viewer sees no Delete in the row menu',
    !preg_match('#/leave/\d+/delete#', $chromeView));

/* ----------------------------------------- no role names in the code ----- */

$offenders = [];
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(APP_ROOT . '/app', FilesystemIterator::SKIP_DOTS)
) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());

    // Quoted role slugs used as an authorization condition.
    if (preg_match("/require_admin\s*\(|is_admin\s*\(/", $source) === 1) {
        $offenders[] = $file->getFilename() . ' (require_admin/is_admin)';
    }
}
ck('no require_admin()/is_admin() left anywhere', $offenders === [], implode(', ', $offenders));

$navSource = (string) file_get_contents(APP_ROOT . '/app/core/View.php');
ck('navigation no longer switches on role names',
    !preg_match("/'roles'\s*=>/", $navSource) && !str_contains($navSource, "user\['role'\]"));

/* --------------------------------------------------------- cleanup ------- */

foreach ($users as $username) {
    Database::run('DELETE FROM users WHERE username = ?', [$username]);
}
ck('fixture accounts removed',
    (int) Database::scalar('SELECT COUNT(*) FROM users WHERE username LIKE "zrbac_%"') === 0);
ck('their role links removed with them',
    (int) Database::scalar(
        'SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE u.username LIKE "zrbac\_%"'
    ) === 0);

foreach ($jars as $jar) {
    @unlink($jar);
}

// Plain-ASCII summary under storage/logs (the project root stays clean, and
// PowerShell's stream redirection mangles the encoding of a piped report).
file_put_contents(
    dirname(__DIR__) . '/storage/logs/rbac-summary.txt',
    ($issues === [] ? "no failures\n" : implode("\n", $issues) . "\n")
    . "TOTAL {$pass} passed, {$fail} failed\n"
);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

