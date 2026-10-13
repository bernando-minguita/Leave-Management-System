<?php

declare(strict_types=1);

/**
 * End-to-end test: LMS "Sync from SIMLedger" (CLI only).
 *
 *   php tools/simledger-sync-test.php
 *   php tools/simledger-sync-test.php http://localhost/lms
 *
 * Logs in as admin, drives POST /api/drivers/sync-simledger for a driver
 * whose badge exists in SIMLedger, and asserts the LMS record picks up the
 * SIMLedger mobile (first call changes, second is idempotent), the profile
 * page carries the sync button, and a bad id404s. FIXTURE-SAFE: the driver's
 * original mobile is restored at the end.
 */
if (PHP_SAPI !== 'cli') { exit("Run from the command line.\n"); }

$base = rtrim($argv[1] ?? 'http://localhost/lms', '/');
if (!function_exists('curl_init')) { fwrite(STDERR, "curl required.\n"); exit(1); }

$fail = 0; $pass = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}

/** RFC-6238 TOTP (sha1 / base32 / 30s) - the harness completes 2FA itself. */
function base32Decode(string $s): string
{
    static $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s    = strtoupper((string)preg_replace('/[^A-Z2-7]/', '', $s));
    $buf  = 0;
    $bits = 0;
    $out  = '';
    foreach (str_split($s) as $ch) {
        $buf  = ($buf << 5) | (int)strpos($alpha, $ch);
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out  .= chr(($buf >> $bits) & 0xFF);
        }
    }
    return $out;
}

function totpNow(string $secret): string
{
    $bin  = base32Decode($secret);
    $time = pack('N*', 0, intdiv(time(), 30));
    $h    = hash_hmac('sha1', $bin . $time, true, true);
    $o    = ord($h[19]) & 0x0F;
    $code = (int)(unpack('N', substr($h, $o, 4))[1] & 0x7FFFFFFF) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

$ck = rtrim((string)sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
    . 'lms-sltest-' . bin2hex(random_bytes(6)) . '.txt';
register_shutdown_function(static function () use ($ck): void {
    if (is_file($ck)) { @unlink($ck); }
});

function req(string $url, array $opts = []): array
{
    global $ck;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_COOKIEFILE     => $ck,
        CURLOPT_COOKIEJAR      => $ck,
    ]);
    if (!empty($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($opts['post'])
            ? http_build_query($opts['post']) : (string)$opts['post']);
    }
    if (!empty($opts['json'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$opts['json']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers'] ?? []);
    }
    $body   = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

$lu = getenv('LMS_TEST_USER') ?: 'admin';
$lp = getenv('LMS_TEST_PASS') ?: 'admin123';

// --- login -------------------------------------------------------------------
[, $loginHtml] = req($base . '/login');
preg_match('/name="_csrf"\s+value="([^"]+)"/', $loginHtml, $m);
$csrf = html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8');
if ($csrf === '') { fwrite(STDERR, "FAIL login: no CSRF token\n"); exit(1); }
[$status, $body] = req($base . '/login', ['post' => [
    '_csrf' => $csrf, 'username' => $lu, 'password' => $lp, 'redirect' => '',
]]);
if ($status === 200 && strpos($body, 'Leave Dashboard') === false
    && strpos($body, 'name="totp_code"') !== false
    && preg_match('/name="_csrf"\s+value="([^"]+)"/', $body, $m2)) {
    // Password accepted - the account is enrolled in 2FA. Complete the
    // challenge with a TOTP code derived from the user's stored secret.
    require_once __DIR__ . '/../app/config/config.php';
    $fa  = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $st  = $fa->prepare('SELECT totp_secret FROM users WHERE username = ?');
    $st->execute([$lu]);
    $secret = (string)$st->fetchColumn();
    if ($secret === '') {
        fwrite(STDERR, "FAIL login: 2FA challenge but no TOTP secret stored\n");
        exit(1);
    }
    [$status, $body] = req($base . '/login', ['post' => [
        '_csrf'    => html_entity_decode($m2[1], ENT_QUOTES, 'UTF-8'),
        'totp_code' => totpNow($secret),
    ]]);
}
if ($status !== 200 || strpos($body, 'Leave Dashboard') === false) {
    $msg = '';
    if (preg_match('/<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/div>/s', $body, $am)) {
        $msg = trim((string)preg_replace('/\s+/', ' ', strip_tags($am[1])));
    }
    fwrite(STDERR, 'FAIL login: HTTP ' . $status . ($msg !== '' ? " - $msg" : '') . "\n");
    exit(1);
}
echo "login                                  OK\n";

// --- disabled contract: empty SIMLEDGER_API_TOKEN ---------------------------
// With no token the feature must be invisible and refuse cleanly: neither
// page renders a sync button and both endpoints answer "not configured"
// without ever reaching SIMLedger. Configuring a token skips this block and
// the live-sync path below applies again.
require_once __DIR__ . '/../app/config/config.php';
if (!defined('SIMLEDGER_API_TOKEN') || SIMLEDGER_API_TOKEN === '') {
    [$stList, $listHtml] = req($base . '/drivers');
    check('drivers list renders (200)', $stList === 200, 'status=' . $stList);
    check('drivers list hides the sync-all button',
        strpos($listHtml, 'data-action="sync-simledger-all"') === false);
    check('drivers list hides the sync-all endpoint',
        strpos($listHtml, 'api/drivers/sync-simledger-all') === false);
    
    $pdo   = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $anyId = (int)$pdo->query('SELECT id FROM drivers ORDER BY id ASC LIMIT 1')->fetchColumn();
    [$stProf, $profile] = req($base . '/drivers/' . $anyId);
    check('profile renders (200)', $stProf === 200, 'status=' . $stProf);
    check('profile hides the sync button',
        strpos($profile, 'data-action="sync-simledger"') === false);
    check('profile hides the sync endpoint',
        strpos($profile, 'api/drivers/sync-simledger') === false);
    
    [$status, $body] = req($base . '/api/drivers/sync-simledger', [
        'json'    => json_encode(['id' => $anyId]),
        'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
    ]);
    check('single sync refuses with "not configured"',
        $status === 422 && strpos($body, 'not configured') !== false,
        'status=' . $status . ' body=' . substr($body, 0, 160));
    
    [$status, $body] = req($base . '/api/drivers/sync-simledger-all', [
        'json'    => json_encode([]),
        'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
    ]);
    check('bulk sync refuses with "not configured"',
        $status === 422 && strpos($body, 'not configured') !== false,
        'status=' . $status . ' body=' . substr($body, 0, 160));
    
    echo "\n" . ($fail === 0 ? 'ALL PASS (SIMLedger disabled)' : 'FAILURES')
        . ": $pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}


// --- fixture: LMS driver whose badge exists + active in SIMLedger -----------
require_once __DIR__ . '/../app/config/config.php';
$lms = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$sl  = new PDO('mysql:host=' . DB_HOST . ';dbname=dcsims;charset=utf8mb4',
    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$slMobile = $sl->prepare(
    'SELECT d.id, d.name,
            (SELECT s.mobile_number FROM driver_sim_assignments a
               JOIN company_sims s ON s.id = a.sim_id
              WHERE a.driver_id = d.id AND a.returned_at IS NULL
              ORDER BY a.id DESC LIMIT 1) AS company_sim,
            (SELECT c.phone_number FROM driver_contact_numbers c
              WHERE c.driver_id = d.id AND c.is_active = 1
              ORDER BY c.is_primary DESC, c.id LIMIT 1) AS mobile
       FROM drivers d
      WHERE d.badge_number = ? AND d.is_active = 1 AND d.status = \'active\''
);
$pick = $lms->query(
    "SELECT id, badge_number, mobile_number FROM drivers
      WHERE status = 'active' ORDER BY id LIMIT 500"
)->fetchAll();

$driverId = 0; $badge = ''; $slName = ''; $expectedMobile = ''; $expectedVia = '';
$originalMobile = null;
foreach ($pick as $row) {
    $slMobile->execute([$row['badge_number']]);
    $s = $slMobile->fetch();
    if ($s === false) {
        continue;
    }
    // Sync preference: company SIM first, personal contact as fallback.
    $via = $s['company_sim'] !== null ? 'company_sim' : 'personal';
    $val = (string)($s['company_sim'] ?? $s['mobile'] ?? '');
    if ($val !== '') {
        $driverId = (int)$row['id'];
        $badge = (string)$row['badge_number'];
        $slName = (string)$s['name'];
        $expectedMobile = $val;
        $expectedVia = $via;
        $originalMobile = $row['mobile_number'] !== null ? (string)$row['mobile_number'] : null;
        break;
    }
}
if ($driverId === 0) {
    fwrite(STDERR, "FAIL fixture: no overlapping badge with a company SIM or personal mobile\n");
    exit(1);
}
check('fixture resolved', true, "badge=$badge id=$driverId via=$expectedVia");

// Force a difference so the first sync must change something.
$lms->prepare('UPDATE drivers SET mobile_number = ? WHERE id = ?')
    ->execute(['0500000000', $driverId]);

$restore = static function () use ($lms, $driverId, $originalMobile): void {
    $lms->prepare('UPDATE drivers SET mobile_number = ? WHERE id = ?')
        ->execute([$originalMobile, $driverId]);
};

// --- the sync call -----------------------------------------------------------
$url = $base . '/api/drivers/sync-simledger';
[$status, $body] = req($url, [
    'json' => json_encode(['id' => $driverId]),
    'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
]);
$j = json_decode($body, true);
check('POST sync -> 200/ok', $status === 200 && ($j['ok'] ?? false) === true,
    "status=$status body=" . substr($body, 0, 120));
check('response says changed', ($j['changed'] ?? null) === true, $body);
check('response mobile == SIMLedger mobile',
    ($j['mobile_number'] ?? '') === $expectedMobile,
    'api=' . ($j['mobile_number'] ?? '?') . " sl=$expectedMobile");
check('sync reported the expected field (' . $expectedVia . ')',
    strpos((string)($j['message'] ?? ''), '(' . $expectedVia . ')') !== false,
    (string)($j['message'] ?? ''));

// The API itself must expose both number fields.
$apiUrl = rtrim(SIMLEDGER_API_URL, '/') . '?badge=' . rawurlencode($badge);
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SIMLEDGER_API_TOKEN,
        'Accept: application/json']]);
$apiBody = (string)curl_exec($ch);
curl_close($ch);
$apiJ = json_decode($apiBody, true);
check('API exposes company_sim and mobile fields',
    is_array($apiJ) && array_key_exists('company_sim', $apiJ['driver'] ?? [])
    && array_key_exists('mobile', $apiJ['driver'] ?? []),
    substr($apiBody, 0, 160));
check('API company_sim matches the fixture expectation',
    (string)($apiJ['driver']['company_sim'] ?? '') === ($expectedVia === 'company_sim' ? $expectedMobile : ''),
    'api=' . var_export($apiJ['driver']['company_sim'] ?? null, true));

$dbMobile = (string)$lms->query('SELECT mobile_number FROM drivers WHERE id = ' . $driverId)
    ->fetchColumn();
check('LMS row updated in DB', $dbMobile === $expectedMobile, "db=$dbMobile sl=$expectedMobile");

$auditRow = $lms->query(
    "SELECT new_values FROM audit_logs
      WHERE action = 'driver-mobile-synced' AND record_id = $driverId
      ORDER BY id DESC LIMIT 1"
)->fetch();
check('audit trail row written', $auditRow !== false);

// --- idempotent second call --------------------------------------------------
[$status, $body] = req($url, [
    'json' => json_encode(['id' => $driverId]),
    'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
]);
$j = json_decode($body, true);
check('second call: ok + not changed',
    $status === 200 && ($j['ok'] ?? false) === true && ($j['changed'] ?? null) === false,
    substr($body, 0, 120));

// --- unknown driver 404 ------------------------------------------------------
[$status, $body] = req($url, [
    'json' => json_encode(['id' => 99999999]),
    'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
]);
check('unknown driver -> 404', $status === 404, "status=$status");

// --- profile page carries the button -----------------------------------------
[, $profile] = req($base . '/drivers/' . $driverId);
check('profile has sync button',
    strpos($profile, 'data-action="sync-simledger"') !== false
    && strpos($profile, 'api/drivers/sync-simledger') !== false);
check('profile mobile cell has id', strpos($profile, 'id="driverMobile"') !== false);

$restore();

// --- bulk sync (sync all) ----------------------------------------------------
// Snapshot every LMS mobile first: the bulk run rewrites to SIMLedger truth.
$before = $lms->query('SELECT id, mobile_number FROM drivers')->fetchAll(PDO::FETCH_KEY_PAIR);
$restoreAll = static function () use ($lms, $before): void {
    $upd = $lms->prepare('UPDATE drivers SET mobile_number = ? WHERE id = ?');
    foreach ($before as $bid => $mv) {
        $upd->execute([$mv, $bid]);
    }
};

[, $listHtml] = req($base . '/drivers');
check('drivers list has sync-all button',
    strpos($listHtml, 'data-action="sync-simledger-all"') !== false
    && strpos($listHtml, 'api/drivers/sync-simledger-all') !== false);

$bulkUrl = $base . '/api/drivers/sync-simledger-all';
[$status, $body] = req($bulkUrl, [
    'json' => json_encode([]),
    'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
]);
$bj = json_decode($body, true);
check('bulk POST -> 200/ok', $status === 200 && ($bj['ok'] ?? false) === true,
    'status=' . $status . ' body=' . substr($body, 0, 200));
check('bulk reports roster/checked/unchanged/missing counts',
    isset($bj['roster'], $bj['checked'], $bj['unchanged'], $bj['not_in_simledger']),
    substr($body, 0, 200));
$afterBulk = (string)$lms->query('SELECT mobile_number FROM drivers WHERE id = ' . $driverId)
    ->fetchColumn();
// The invariant that matters: after a bulk run the fixture row holds the
// SIMLedger value. (changed_badges may list a badge when the row was
// redundantly rewritten to the same value - data outcome is identical.)
check('bulk leaves the fixture row at the SIMLedger value',
    $afterBulk === $expectedMobile,
    "db=$afterBulk expected=$expectedMobile");

// Second bulk run must be a full no-op (everything already synced).
[$status, $body] = req($bulkUrl, [
    'json' => json_encode([]),
    'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
]);
$bj = json_decode($body, true);
check('bulk second run: 0 changed', $status === 200 && (int)($bj['changed'] ?? -1) === 0,
    substr($body, 0, 200));

$restoreAll();
$restore();

echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILURES') . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);