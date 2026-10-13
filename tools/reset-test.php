<?php

declare(strict_types=1);

/**
 * "Restore seeded state" test (CLI only).
 *
 *   php tools/reset-test.php
 *   php tools/reset-test.php http://localhost/lms
 *
 * Proves the admin demo-data button really resets the database:
 *   1. the control is rendered on Settings > System
 *   2. a wrong or missing confirmation changes nothing
 *   3. a missing CSRF token is rejected
 *   4. confirm=RESET removes the probe row, returns every seeded table to the
 *      counts it had beforehand and leaves `users` untouched
 *
 * Always finishes in the seeded state. Exits non-zero on any failure.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/reset-test.php\n");
}

$base = rtrim($argv[1] ?? 'http://localhost/lms', '/');

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The curl PHP extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/app/config/config.php';

/** Tiny cookie-aware HTTP client built on cURL (uses a real cookie jar). */
final class Client
{
    private string $cookieFile;

    public function __construct()
    {
        // A real cookie jar file so cURL keeps the session across redirects.
        $this->cookieFile = rtrim((string)sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'lms-reset-' . bin2hex(random_bytes(6)) . '.txt';
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    /** @return array{0:int,1:string} [status, body] */
    public function request(string $method, string $url, array $post = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 6,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADER         => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body   = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, $body];
    }

    /** Pull a hidden input out of an HTML form. */
    public function hidden(string $html, string $name): string
    {
        if (preg_match('#name="' . preg_quote($name, '#') . '"\s+value="([^"]*)"#', $html, $m) === 1) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        return '';
    }
}

/* ------------------------------------------------------------- database -- */

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $ex) {
    fwrite(STDERR, 'Cannot connect to MySQL: ' . $ex->getMessage() . "\n");
    exit(1);
}

$pdo->exec("SET time_zone = '" . APP_TZ_OFFSET . "'");

$TRACKED = [
    'branches', 'agencies', 'leave_types',
    'drivers', 'leave_records', 'settings', 'audit_logs', 'users',
];

function row_counts(PDO $pdo, array $tables): array
{
    $out = [];

    foreach ($tables as $table) {
        $out[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    return $out;
}

$probeBadge = 'ZZ-RESET-PROBE';

// A row that is NOT part of the seed - it must vanish after the reset.
$pdo->exec("DELETE FROM drivers WHERE badge_number = '" . $probeBadge . "'");
$pdo->exec(
    "INSERT INTO drivers (badge_number, driver_name, iqama_number, status)
     VALUES ('" . $probeBadge . "', 'Reset Probe', 'ZZPROBE01', 'active')"
);

$probeRows = (int)$pdo->query(
    "SELECT COUNT(*) FROM drivers WHERE badge_number = '" . $probeBadge . "'"
)->fetchColumn();

$companyBefore = (string)$pdo->query(
    "SELECT setting_value FROM settings WHERE setting_key = 'company_name'"
)->fetchColumn();

$issues = [];
$passed = 0;

$check = static function (string $name, bool $ok, string $detail = '') use (&$issues, &$passed): void {
    if ($ok) {
        $passed++;
        printf("%-40s OK\n", $name);

        return;
    }

    $issues[] = $name . ($detail === '' ? '' : ' => ' . $detail);
    printf("%-40s FAIL %s\n", $name, $detail);
};

$check('probe row inserted', $probeRows === 1, 'expected 1, got ' . $probeRows);

/* ----------------------------------------------------------------- auth -- */

$client = new Client();

[, $loginHtml] = $client->request('GET', $base . '/login');
$csrf = $client->hidden($loginHtml, '_csrf');

if ($csrf === '') {
    fwrite(STDERR, "FAIL login: no CSRF token on {$base}/login\n");
    exit(1);
}

[$status, $body] = $client->request('POST', $base . '/login', [
    '_csrf'    => $csrf,
    'username' => 'admin',
    'password' => getenv('LMS_TEST_PASS') ?: 'admin123',
]);

$check('login', $status === 200 && strpos($body, 'id="password"') === false, 'HTTP ' . $status);

// Steady state: probe row in place and the login audit entry already written.
// Everything below is measured against this, not against the pre-probe counts.
$baseline = row_counts($pdo, $TRACKED);

// What the reset must leave behind: same as the steady state but without the
// probe driver, and an audit trail that has been re-seeded to this one entry.
$expected           = $baseline;
$expected['drivers']    = $baseline['drivers'] - 1;
$expected['audit_logs'] = 1;

/* ------------------------------------------------------ rendered control -- */

[$pageStatus, $page] = $client->request('GET', $base . '/settings/system');

$check(
    'Settings > System loads',
    $pageStatus === 200 && strpos($page, 'id="password"') === false,
    'HTTP ' . $pageStatus
);
$check('demo-data card rendered', strpos($page, 'Two ways to clean up') !== false);
$check(
    'delete sample data button rendered',
    strpos($page, 'Delete sample data') !== false
    && strpos($page, 'id="removeSampleModal"') !== false
    && strpos($page, 'settings/remove-sample-data') !== false
);
$check(
    'restore seeded state button rendered',
    strpos($page, 'Restore seeded state') !== false
    && strpos($page, 'id="resetDemoModal"') !== false
    && strpos($page, 'settings/reset-demo') !== false
);
$check(
    'typed gates rendered',
    substr_count($page, 'data-reset-confirm') === 2
    && substr_count($page, 'data-reset-submit') === 2
);

$resetCsrf = $client->hidden($page, '_csrf');
$check('CSRF token on reset form', $resetCsrf !== '');

$probe = static function () use ($pdo, $probeBadge): int {
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM drivers WHERE badge_number = '" . $probeBadge . "'"
    )->fetchColumn();
};

$untouched = static function (string $name, array $before) use (&$check, $pdo, $TRACKED, $probe): void {
    $now  = row_counts($pdo, $TRACKED);
    $diff = [];

    foreach ($before as $table => $count) {
        if (($now[$table] ?? null) !== $count) {
            $diff[] = $table . ' ' . $count . '->' . ($now[$table] ?? 'missing');
        }
    }

    $check($name, $diff === [] && $probe() === 1, implode(', ', $diff) ?: 'probe row lost');
};

/* -------------------------------------------------------- negative paths -- */

$before = row_counts($pdo, $TRACKED);

[$st] = $client->request('POST', $base . '/settings/reset-demo', ['confirm' => 'RESET']);
$check('missing CSRF rejected', $st === 403, 'HTTP ' . $st);
$untouched('nothing changed without CSRF', $before);

$before = row_counts($pdo, $TRACKED);

[$st, $bd] = $client->request('POST', $base . '/settings/reset-demo', [
    '_csrf'   => $resetCsrf,
    'confirm' => 'delete everything',
]);

$check(
    'wrong confirmation rejected',
    $st === 200 && strpos($bd, 'Type RESET to confirm') !== false,
    'HTTP ' . $st
);
$untouched('nothing changed with wrong confirmation', $before);

/* ---------------------------------------------------- delete sample data -- */

$before = row_counts($pdo, $TRACKED);

[$st, $bd] = $client->request('POST', $base . '/settings/remove-sample-data', [
    '_csrf'   => $resetCsrf,
    'confirm' => 'RESET',   // the restore button's word, not this action's
]);

$check(
    'sample delete wants the word DELETE',
    $st === 200 && strpos($bd, 'Type DELETE to confirm') !== false,
    'HTTP ' . $st
);
$untouched('nothing changed with wrong DELETE word', $before);

[$st, $bd] = $client->request('POST', $base . '/settings/remove-sample-data', [
    '_csrf'   => $resetCsrf,
    'confirm' => 'DELETE',
]);

$check(
    'confirm=DELETE accepted',
    $st === 200 && strpos($bd, 'Sample data removed') !== false,
    'HTTP ' . $st
);

$stripped = row_counts($pdo, $TRACKED);

// Only the untagged probe driver should be left; everything demo goes.
$check('sample drivers deleted', $stripped['drivers'] === $probe(),
    $stripped['drivers'] . ' driver(s) left');
$check('sample leave records deleted', $stripped['leave_records'] === 0,
    $stripped['leave_records'] . ' left');
$check('sample agencies deleted', $stripped['agencies'] === 0,
    $stripped['agencies'] . ' left');

// Reference data is not tagged, so it must survive untouched.
$check('branches kept', $stripped['branches'] === $baseline['branches'],
    $baseline['branches'] . ' -> ' . $stripped['branches']);
$check('leave types kept', $stripped['leave_types'] === $baseline['leave_types'],
    $baseline['leave_types'] . ' -> ' . $stripped['leave_types']);
$check('settings kept', $stripped['settings'] === $baseline['settings'],
    $baseline['settings'] . ' -> ' . $stripped['settings']);
$check('users kept', $stripped['users'] === $baseline['users'],
    $baseline['users'] . ' -> ' . $stripped['users']);
$check('untagged probe driver survived', $probe() === 1, $probe() . ' row(s)');

/* --------------------------------------------------------- the real reset -- */

[$st, $bd] = $client->request('POST', $base . '/settings/reset-demo', [
    '_csrf'   => $resetCsrf,
    'confirm' => 'RESET',
]);

$check(
    'confirm=RESET accepted',
    $st === 200 && strpos($bd, 'Seeded state restored') !== false,
    'HTTP ' . $st
);

$after = row_counts($pdo, $TRACKED);

$check('probe row removed', $probe() === 0, $probe() . ' row(s) left');

foreach (['branches', 'agencies', 'leave_types', 'drivers', 'leave_records', 'settings'] as $table) {
    $check(
        sprintf('%-14s back to %d', $table, $expected[$table]),
        $after[$table] === $expected[$table],
        $expected[$table] . ' -> ' . $after[$table]
    );
}

$check(
    'users untouched',
    $after['users'] === $expected['users'],
    $expected['users'] . ' -> ' . $after['users']
);

$companyAfter = (string)$pdo->query(
    "SELECT setting_value FROM settings WHERE setting_key = 'company_name'"
)->fetchColumn();

$check(
    'company_name preserved',
    $companyAfter === $companyBefore,
    $companyBefore . ' -> ' . $companyAfter
);

$logged = (int)$pdo->query(
    "SELECT COUNT(*) FROM audit_logs WHERE action = 'system-reset'"
)->fetchColumn();

$check('reset written to audit trail', $logged === 1, $logged . ' row(s)');

[$st, $bd] = $client->request('GET', $base . '/settings/system');
$check(
    'still signed in after reset',
    $st === 200 && strpos($bd, 'id="password"') === false,
    'HTTP ' . $st
);

/* --------------------------------------------------------------- report -- */

printf("\n%d passed, %d failed\n", $passed, count($issues));

if ($issues !== []) {
    foreach ($issues as $issue) {
        fwrite(STDERR, '  FAIL ' . $issue . "\n");
    }

    exit(1);
}

echo "OK  restore-seeded-state works end to end.\n";
exit(0);
