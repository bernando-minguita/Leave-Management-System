<?php

declare(strict_types=1);

/**
 * HTTP smoke test (CLI only).
 *
 *   php tools/smoke-test.php
 *   php tools/smoke-test.php http://localhost/lms
 *
 * Walks every screen plus the export endpoints with a real cookie session and
 * reports any non-200 status, PHP error output or unexpected login redirect.
 * Exits non-zero when anything fails, so it can gate a build.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/smoke-test.php\n");
}

$base = rtrim($argv[1] ?? 'http://localhost/lms', '/');

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The curl PHP extension is required.\n");
    exit(1);
}

/** Tiny cookie-aware HTTP client built on cURL (uses a real cookie jar). */
final class Client
{
    private string $cookieFile;

    public function __construct()
    {
        // A real cookie jar file so cURL keeps the session across redirects.
        // (CURLOPT_COOKIE is static and would keep sending the pre-login ID.)
        $this->cookieFile = rtrim((string)sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'lms-smoke-' . bin2hex(random_bytes(6)) . '.txt';
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

const PHP_ERROR_PATTERN = '/Fatal error|Parse error|Uncaught |Undefined function|Undefined class|Deprecated:|Warning: |Notice: /';

$client = new Client();
$issues = [];
$passed = 0;

$check = static function (string $name, int $status, string $body, int $minLen = 200) use (&$issues, &$passed): void {
    $problems = [];

    if ($status !== 200) {
        $problems[] = 'HTTP ' . $status;
    }
    if (preg_match(PHP_ERROR_PATTERN, $body, $m) === 1) {
        $problems[] = trim(preg_replace('/\s+/', ' ', $m[0]));
    }
    if (strpos($body, 'id="password"') !== false) {
        $problems[] = 'redirected to login';
    }
    if (strlen($body) < $minLen) {
        $problems[] = 'too short (' . strlen($body) . ' bytes)';
    }

    if ($problems === []) {
        $passed++;
        printf("%-34s OK  %6d bytes\n", $name, strlen($body));

        return;
    }

    $issues[] = $name . ' => ' . implode('; ', $problems);
    printf("%-34s FAIL %s\n", $name, implode('; ', $problems));
};

/* --------------------------------------------------------------- login -- */

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
    'redirect' => '',
]);

if ($status !== 200 || strpos($body, 'Leave Dashboard') === false) {
    fwrite(STDERR, "FAIL login: HTTP {$status} (expected the dashboard)\n");
    if (preg_match(PHP_ERROR_PATTERN, $body, $m) === 1) {
        fwrite(STDERR, '  ' . trim($m[0]) . "\n");
    }
    exit(1);
}

echo "login                                  OK  (authenticated)\n";

/* ------------------------------------------------------- fixture rows -- */
// Resolve real ids rather than hardcoding 1. After "Delete sample data" and a
// real import there may be no row with id=1 at all, so the suite must pick
// whatever actually exists instead of silently 404-ing on stale fixtures.

$driverId = 0;
$leaveId  = 0;

try {
    require __DIR__ . '/../app/config/config.php';
    $fx = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $driverId = (int)$fx->query('SELECT MIN(id) FROM drivers')->fetchColumn();
    $leaveId  = (int)$fx->query('SELECT MIN(id) FROM leave_records')->fetchColumn();
    unset($fx);
} catch (Throwable $e) {
    fwrite(STDERR, 'WARN could not resolve fixture ids: ' . $e->getMessage() . "\n");
}

$driverPages = $driverId > 0
    ? ["/drivers/{$driverId}", "/drivers/{$driverId}/edit", "/drivers/{$driverId}/leave-history"]
    : [];
$leavePages = $leaveId > 0
    ? ["/leave/{$leaveId}", "/leave/{$leaveId}/edit"]
    : [];

/* ---------------------------------------------------------------- pages -- */

$pages = array_merge([
    '/dashboard',
    '/drivers', '/drivers?q=ahmed&sort=driver_name&dir=asc&branch_id=1',
    '/drivers/create',
], $driverPages, [
    '/leave/new', '/leave/current', '/leave/records', '/leave/calendar',
    '/leave/returns',
], $leavePages, [
    '/reports/leave', '/reports/return', '/reports/branch', '/reports/agency',
    '/settings/leave-types', '/settings/branches', '/settings/agencies',
    '/settings/system', '/settings/users', '/settings/audit', '/import',
]);

foreach ($pages as $page) {
    [$status, $body] = $client->request('GET', $base . $page);
    $check($page, $status, $body);
}

/* -------------------------------------------------------------- exports -- */

$exports = [
    '/drivers?export=csv',
    '/leave/records?export=csv',
    '/leave/records?export=json',
    '/reports/leave?export=csv',
    '/reports/return?export=xls',
];

foreach ($exports as $page) {
    [$status, $body] = $client->request('GET', $base . $page);
    $check($page, $status, $body, 40);
}

/* ------------------------------------------------------------ API feeds -- */

$apis = [
    '/api/dashboard/kpis',
    '/api/drivers/search?q=ahmed',
];

if ($driverId > 0) {
    $apis[] = "/api/drivers/detail?id={$driverId}";
    $apis[] = "/api/leave/overlap-check?driver_id={$driverId}&start=2026-10-01&end=2026-10-10";
}

$apis[] = '/api/leave/calendar?start=2026-09-01&end=2026-10-31';

if ($leaveId > 0) {
    $apis[] = "/api/leave/view?id={$leaveId}";
}

foreach ($apis as $endpoint) {
    [$status, $body] = $client->request('GET', $base . $endpoint);
    $ok    = $status === 200 && preg_match(PHP_ERROR_PATTERN, $body) !== 1
        && strpos($body, '"ok"') !== false;
    $name  = $endpoint;

    if ($ok) {
        $passed++;
        printf("%-34s OK  %6d bytes\n", $name, strlen($body));
    } else {
        $issues[] = $name . ' => HTTP ' . $status . ' ' . substr($body, 0, 160);
        printf("%-34s FAIL HTTP %d %s\n", $name, $status, substr($body, 0, 120));
    }
}

/* ------------------------------------------------------------ 404 check -- */

[$status] = $client->request('GET', $base . '/this-page-does-not-exist');
if ($status === 404) {
    $passed++;
    echo "/this-page-does-not-exist               OK  (404)\n";
} else {
    $issues[] = 'unknown route => expected 404, got ' . $status;
    echo "/this-page-does-not-exist               FAIL expected 404, got {$status}\n";
}

/* -------------------------------------------------------------- summary -- */

echo "\n";
echo 'passed: ' . $passed . '   failed: ' . count($issues) . "\n";

if ($issues !== []) {
    echo "\nFailures:\n";
    foreach ($issues as $issue) {
        echo '  - ' . $issue . "\n";
    }
    exit(1);
}

echo "All smoke tests passed.\n";
exit(0);

