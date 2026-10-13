<?php
/**
 * Driver master-data governance: change requests, the approval workflow and
 * the master history tab.
 *
 *   php tools/driver-governance-test.php
 *
 * Builds its own throwaway driver and one fixture account per role, so real
 * data is never touched. Everything goes over HTTP through the real router,
 * because half of what this feature promises is "which key opens this door"
 * - a model-only test would pass happily while the route map was wrong.
 *
 * Covers:
 *   - the route map: drivers.edit files, drivers.change_request_approve
 *     decides, drivers.master_correct writes directly, drivers.history.view
 *     reads
 *   - one PENDING request per driver+field (the pending_key constraint)
 *   - approval applying the value to `drivers` and writing an
 *     approved_request history row + audit entry
 *   - rejection and cancellation leaving master data untouched
 *   - direct correction refused without a reason, recording a
 *     direct_correction history row with one
 *   - the GOVERNABLE whitelist
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/driver-governance-test.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Driver;

$pass  = 0;
$fail  = 0;
$issues = [];

function ok(bool $c, string $m, string $detail = ''): void
{
    global $pass, $fail, $issues;

    if ($c) {
        $pass++;
        printf("  ok    %s\n", $m);
        return;
    }

    $fail++;
    $issues[] = $m . ($detail === '' ? '' : '  [' . $detail . ']');
    printf("  FAIL  %s%s\n", $m, $detail === '' ? '' : "  [$detail]");
}

/** Label-first spelling of ok(), the shape the route matrix reads best with. */
function ck(string $label, bool $ok, string $detail = ''): void
{
    ok($ok, $label, $detail);
}

$base = 'http://127.0.0.1/lms';

/** One request against the real app. Returns [status, body]. */
function req(string $jar, string $method, string $path, array $post = []): array
{
    global $base;

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => true,
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    // curl_close() is a no-op in PHP 8, so the handle is only released - and
    // the cookie jar only WRITTEN - when this reference dies. Release it
    // before returning, or the next call reads a stale jar and the server
    // sees a POST carrying a CSRF token from a session it never received:
    // "Your session has expired", 403.
    unset($ch);

    return [$code, substr($raw, $size)];
}

/** Sign in to a private cookie jar and return it. */
function signIn(string $username, string $password): string
{
    // The GET has to go through req() as well: req() releases its handle
    // before returning, which is what flushes Set-Cookie to the jar file
    // that the POST below then reads. Hand-rolling the GET here leaves the
    // handle alive across the next call and the POST ships no session.
    $jar = tempnam(sys_get_temp_dir(), 'gov');

    [, $html] = req($jar, 'GET', '/login');
    $csrf = preg_match('/name="_csrf"[^>]*value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';

    [$loginCode] = req($jar, 'POST', '/login', [
        '_csrf'    => $csrf,
        'username' => $username,
        'password' => $password,
        'redirect' => '',
    ]);

    if ($loginCode !== 302) {
        fwrite(STDERR, "FAIL sign-in as {$username}: HTTP {$loginCode}\n");
    }

    return $jar;
}

/** The CSRF token the form on $path would submit. */
function csrfOf(string $jar, string $path): string
{
    [, $html] = req($jar, 'GET', $path);

    return preg_match('/name="_csrf"[^>]*value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

/* ------------------------------------------------------------- setup ------ */

echo "== setup ==\n";

// Idempotent: a run that died before cleanup leaves a throwaway driver and
// its governance rows behind, and the unique badge would not collide but the
// orphan checks later would. Sweep them first.
$stale = array_map('intval', array_column(
    Database::all("SELECT id FROM drivers WHERE badge_number LIKE 'ZZ-GOV-%'"),
    'id'
));

if ($stale !== []) {
    $in = implode(',', array_fill(0, count($stale), '?'));
    Database::run("DELETE FROM audit_logs WHERE table_name = 'driver_change_requests'
                     AND record_id IN (SELECT id FROM driver_change_requests
                                        WHERE driver_id IN ({$in}))", $stale);
    Database::run("DELETE FROM driver_history      WHERE driver_id IN ({$in})", $stale);
    Database::run("DELETE FROM driver_change_requests WHERE driver_id IN ({$in})", $stale);
    Database::run("DELETE FROM audit_logs WHERE table_name = 'drivers'
                     AND record_id IN ({$in})", $stale);
    Database::run("DELETE FROM drivers WHERE id IN ({$in})", $stale);
}

ok((int)Database::scalar(
    "SELECT COUNT(*) FROM drivers WHERE badge_number LIKE 'ZZ-GOV-%'"
) === 0, 'no stale throwaway driver from an earlier run');

$badge    = 'ZZ-GOV-' . substr(str_replace('.', '', uniqid('', true)), -6);
$origName = 'Governance Test Driver';

Database::run(
    'INSERT INTO drivers (badge_number, driver_name, iqama_number, status)
     VALUES (?, ?, ?, "active")',
    [$badge, $origName, 'IQ' . $badge]
);

$driverId = (int)Database::scalar(
    'SELECT id FROM drivers WHERE badge_number = ?', [$badge]
);
ok($driverId > 0, "throwaway driver created (badge $badge, id $driverId)");

// One fixture account per role. Kept separate from rbac-test's zrbac_ set so
// the two can run in either order.
Database::run("DELETE FROM users WHERE username LIKE 'zgov\\_%'");

$slugs = ['admin', 'hr', 'operations', 'viewer'];
$users = [];
$jars  = [];

foreach ($slugs as $slug) {
    $username = 'zgov_' . $slug;

    Database::run(
        'INSERT INTO users (username, password_hash, full_name, role, status,
                            created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
        [$username, password_hash('test123', PASSWORD_DEFAULT),
         'Governance ' . $slug, $slug, 'active']
    );

    $userId = (int)Database::scalar('SELECT id FROM users WHERE username = ?', [$username]);
    $roleId = (int)Database::scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);

    $users[$slug] = $userId;
    $jars[$slug]  = signIn($username, 'test123');
}

ok(count($users) === 4, 'one fixture account per role');
ok(count($jars) === 4, 'all four roles signed in');

/* ------------------------------------------------- the route map --------- */

echo "\n== 1. the route map ==\n";

// [path, expected status per role]  200 = allowed, 403 = denied.
$matrix = [
    // Filing/following a request needs drivers.edit OR the approve key.
    '/driver-changes' => ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 403],
    '/driver-changes?export=csv'
                       => ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 403],
    '/driver-changes/new'
                       => ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 403],
    // Reading history is a separate, deliberately wide right.
    "/drivers/{$driverId}/history"
                       => ['admin' => 200, 'hr' => 200, 'operations' => 200, 'viewer' => 200],
    // Writing master data directly is the narrow one.
    "/drivers/{$driverId}/edit"
                       => ['admin' => 200, 'hr' => 200, 'operations' => 403, 'viewer' => 403],
];

foreach ($matrix as $path => $expected) {
    foreach ($expected as $slug => $code) {
        [$actual] = req($jars[$slug], 'GET', $path);
        ck(sprintf('%-10s %-34s %d', $slug, $path, $code), $actual === $code, "got {$actual}");
    }
}

/* ----------------------------------------------------- the sidebar -------- */

echo "\n== 2. the sidebar ==\n";

[, $navOps]  = req($jars['operations'], 'GET', '/driver-changes');
[, $navView] = req($jars['viewer'], 'GET', '/dashboard');

ok(str_contains($navOps, 'driver-changes'),
    'operations sees the Change Requests shortcut', 'it holds drivers.edit');
ok(!str_contains($navView, 'driver-changes'),
    'viewer does not hold the Change Requests shortcut', 'it holds neither key');

// The History button lives on the driver PROFILE, gated on drivers.history.view.
[, $profileHr] = req($jars['hr'], 'GET', "/drivers/{$driverId}");
ok(str_contains($profileHr, "/drivers/{$driverId}/history"),
    'the driver profile offers the History tab to HR');

/* ------------------------------------------------------ the whitelist ----- */

echo "\n== 3. the GOVERNABLE whitelist ==\n";

ok(count(Driver::GOVERNABLE) === 17, '17 governed fields are declared');
ok(Driver::isGovernable('driver_name'), 'driver_name is governed');
ok(Driver::isGovernable('branch_id'), 'branch_id is governed');
ok(!Driver::isGovernable('remarks'), 'remarks stays out (free annotation, not master data)');
ok(!Driver::isGovernable('emergency_contact_name'),
    'the emergency-contact pair stays out of v1');
ok(!Driver::isGovernable('id'), 'a crafted id can never be filed as a field');

// Every declared field must be a real column, or the seed/history SQL would
// break on the first row it touched.
$columns = array_map('strval', array_column(Database::all('SHOW COLUMNS FROM drivers'), 'Field'));
$stray   = array_diff(array_keys(Driver::GOVERNABLE), $columns);
ok($stray === [], 'every governed field is a real column', implode(', ', $stray));

/* ------------------------------------------------ filing a request -------- */

echo "\n== 4. filing a change request ==\n";

$newPath  = "/driver-changes/new?driver_id={$driverId}";
$opsCsrf  = csrfOf($jars['operations'], $newPath);
ok($opsCsrf !== '', 'operations is offered a CSRF token on the request form');

[$code] = req($jars['operations'], 'POST', '/driver-changes', [
    '_csrf'         => $opsCsrf,
    'driver_id'     => $driverId,
    'field'         => 'driver_name',
    'new_value'     => 'Governance Renamed',
    'reason'        => 'Checked against the scanned iqama copy',
    'effective_date' => '',
]);
ok($code === 302, "the request was accepted (HTTP {$code})");

$reqId = (int)Database::scalar(
    'SELECT id FROM driver_change_requests
      WHERE driver_id = ? AND field_name = ?', [$driverId, 'driver_name']
);
ok($reqId > 0, "change request #{$reqId} is on file");

$req = Database::one('SELECT * FROM driver_change_requests WHERE id = ?', [$reqId]);
ok(($req['status'] ?? '') === 'pending', 'it opens as pending', (string)($req['status'] ?? ''));
ok($req['old_value'] === $origName, 'old_value is what was on file', (string)$req['old_value']);
ok($req['new_value'] === 'Governance Renamed', 'new_value is what was asked for');
ok((int)$req['requested_by'] === $users['operations'], 'it names operations as the requester');
ok($req['reason'] !== '' && $req['reason'] !== null, 'the reason is stored with it');

$audited = (int)Database::scalar(
    "SELECT COUNT(*) FROM audit_logs
      WHERE action = 'driver-change-requested'
        AND table_name = 'driver_change_requests' AND record_id = ?",
    [$reqId]
);
ok($audited === 1, 'filing the request was audited');

$early = (int)Database::scalar(
    "SELECT COUNT(*) FROM driver_history WHERE driver_id = ? AND source = 'approved_request'",
    [$driverId]
);
ok($early === 0, 'no history row is written while the request only waits');

// The reason is a gate, not decoration.
[$code] = req($jars['operations'], 'POST', '/driver-changes', [
    '_csrf'         => csrfOf($jars['operations'], $newPath),
    'driver_id'     => $driverId,
    'field'         => 'driver_name',
    'new_value'     => 'Rejected For Being Too Short',
    'reason'        => 'no',
    'effective_date' => '',
]);
$short = (int)Database::scalar(
    "SELECT COUNT(*) FROM driver_change_requests
      WHERE driver_id = ? AND status = 'pending'", [$driverId]
);
ok($short === 1, 'a reason shorter than 5 characters is refused', "pending={$short}");

// A posted field outside the whitelist never reaches SQL.
[$code] = req($jars['operations'], 'POST', '/driver-changes', [
    '_csrf'         => csrfOf($jars['operations'], $newPath),
    'driver_id'     => $driverId,
    'field'         => 'remarks',
    'new_value'     => 'should never be filed',
    'reason'        => 'crafting a field name',
    'effective_date' => '',
]);
$crafted = (int)Database::scalar(
    'SELECT COUNT(*) FROM driver_change_requests WHERE driver_id = ? AND field_name = ?',
    [$driverId, 'remarks']
);
ok($crafted === 0, 'a field outside GOVERNABLE is refused before it is stored');

// One PENDING request per driver+field - the reason pending_key exists.
[$code] = req($jars['operations'], 'POST', '/driver-changes', [
    '_csrf'         => csrfOf($jars['operations'], $newPath),
    'driver_id'     => $driverId,
    'field'         => 'driver_name',
    'new_value'     => 'Governance Renamed Twice',
    'reason'        => 'a second ask for the same field',
    'effective_date' => '',
]);
$pending = (int)Database::scalar(
    "SELECT COUNT(*) FROM driver_change_requests
      WHERE driver_id = ? AND field_name = 'driver_name' AND status = 'pending'",
    [$driverId]
);
ok($pending === 1, 'a second PENDING request for the same field is refused', "pending={$pending}");

/* ------------------------------------------- who may decide it ----------- */

echo "\n== 5. who may decide it ==\n";

foreach (['viewer', 'operations'] as $slug) {
    [$code] = req($jars[$slug], 'POST', "/driver-changes/{$reqId}/approve", [
        '_csrf'       => csrfOf($jars[$slug], "/driver-changes/{$reqId}"),
        'review_note' => 'should not be allowed',
    ]);
    ck(sprintf('%-10s POST /driver-changes/%d/approve  403', $slug, $reqId),
        $code === 403, "got {$code}");
}

$stillPending = (string)Database::scalar(
    'SELECT status FROM driver_change_requests WHERE id = ?', [$reqId]
);
ok($stillPending === 'pending', 'the request is still pending after the refused attempts');
$unchanged = (string)Database::scalar(
    'SELECT driver_name FROM drivers WHERE id = ?', [$driverId]
);
ok($unchanged === $origName, 'master data has not moved yet', $unchanged);

/* ------------------------------------------------------ approval ---------- */

echo "\n== 6. approval applies the change ==\n";

$viewPath = "/driver-changes/{$reqId}";
[$code]   = req($jars['hr'], 'GET', $viewPath);
ok($code === 200, "HR can open the request (HTTP {$code})");

$hrCsrf = csrfOf($jars['hr'], $viewPath);
ok($hrCsrf !== '', 'HR is offered a decision token');

[$code] = req($jars['hr'], 'POST', "{$viewPath}/approve", [
    '_csrf'         => $hrCsrf,
    'review_note'   => 'Matches the scanned iqama',
    'effective_date' => '',
]);
ok($code === 302, "the approval was accepted (HTTP {$code})");

$after = (string)Database::scalar('SELECT driver_name FROM drivers WHERE id = ?', [$driverId]);
ok($after === 'Governance Renamed', 'the drivers row now holds the approved value', $after);

$hist = Database::one(
    "SELECT * FROM driver_history
      WHERE request_id = ? AND source = 'approved_request'", [$reqId]
);
ok($hist !== null, 'an approved_request history row exists');
ok($hist['old_value'] === $origName, 'it records the value that was replaced', (string)$hist['old_value']);
ok($hist['new_value'] === 'Governance Renamed', 'it records the value that replaced it');
ok((int)$hist['changed_by'] === $users['hr'], 'it names HR as the one who applied it');
ok($hist['reason'] !== null && $hist['reason'] !== '', 'the reason travels with the history row');

$decided = Database::one(
    'SELECT status, reviewed_by FROM driver_change_requests WHERE id = ?', [$reqId]
);
ok($decided['status'] === 'approved', 'the request is approved', (string)$decided['status']);
ok((int)$decided['reviewed_by'] === $users['hr'], 'the request records who reviewed it');

$approved = (int)Database::scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE action = 'driver-change-approved'
       AND table_name = 'driver_change_requests' AND record_id = ?",
    [$reqId]
);
ok($approved >= 1, 'the approval was audited');

// Deciding twice must not double-write master data or history.
[$code] = req($jars['hr'], 'POST', "{$viewPath}/approve", [
    '_csrf'       => csrfOf($jars['hr'], $viewPath),
    'review_note' => 'again',
]);
$histCount = (int)Database::scalar(
    "SELECT COUNT(*) FROM driver_history WHERE request_id = ? AND source = 'approved_request'",
    [$reqId]
);
ok($histCount === 1, 'a second approval writes no extra history', "rows={$histCount}");
$finalName = (string)Database::scalar('SELECT driver_name FROM drivers WHERE id = ?', [$driverId]);
ok($finalName === 'Governance Renamed', 'and leaves the master value exactly as approved');

/* ------------------------------------------------------ rejection --------- */

echo "\n== 7. rejection and cancellation change nothing ==\n";

[$code] = req($jars['operations'], 'POST', '/driver-changes', [
    '_csrf'         => csrfOf($jars['operations'], $newPath),
    'driver_id'     => $driverId,
    'field'         => 'department',
    'new_value'     => 'Governance Dept',
    'reason'        => 'Asked for on the wrong driver',
    'effective_date' => '',
]);
$req2 = (int)Database::scalar(
    "SELECT id FROM driver_change_requests
      WHERE driver_id = ? AND field_name = 'department'", [$driverId]
);
ok($req2 > 0 && $code === 302, "a second request on another field (#{$req2})");

[$code] = req($jars['hr'], 'POST', "/driver-changes/{$req2}/reject", [
    '_csrf'       => csrfOf($jars['hr'], "/driver-changes/{$req2}"),
    'review_note' => 'Wrong driver - filing it again',
]);
ok($code === 302, "the rejection was accepted (HTTP {$code})");

$req2Row = Database::one('SELECT status, reviewed_by FROM driver_change_requests WHERE id = ?', [$req2]);
ok($req2Row['status'] === 'rejected', 'the request reads as rejected', (string)$req2Row['status']);
ok((int)$req2Row['reviewed_by'] === $users['hr'], 'the rejection names its reviewer');

$dept = Database::scalar('SELECT department FROM drivers WHERE id = ?', [$driverId]);
ok($dept === null, 'a rejected request leaves master data alone', var_export($dept, true));
$deptHist = (int)Database::scalar(
    "SELECT COUNT(*) FROM driver_history WHERE driver_id = ? AND field_name = 'department'
       AND source = 'approved_request'", [$driverId]
);
ok($deptHist === 0, 'and writes no approved_request history');

// The requester cancels their own ask.
[$code] = req($jars['operations'], 'POST', '/driver-changes', [
    '_csrf'         => csrfOf($jars['operations'], $newPath),
    'driver_id'     => $driverId,
    'field'         => 'position',
    'new_value'     => 'Governance Position',
    'reason'        => 'Filing then withdrawing',
    'effective_date' => '',
]);
$req3 = (int)Database::scalar(
    "SELECT id FROM driver_change_requests WHERE driver_id = ? AND field_name = 'position'",
    [$driverId]
);
ok($req3 > 0, "a third request is on file (#{$req3})");

[$code] = req($jars['operations'], 'POST', "/driver-changes/{$req3}/cancel", [
    '_csrf' => csrfOf($jars['operations'], "/driver-changes/{$req3}"),
]);
ok($code === 302, "the cancellation was accepted (HTTP {$code})");

$req3Row = Database::one('SELECT status FROM driver_change_requests WHERE id = ?', [$req3]);
ok($req3Row['status'] === 'cancelled', 'it reads as cancelled', (string)$req3Row['status']);
$pos = Database::scalar('SELECT position FROM drivers WHERE id = ?', [$driverId]);
ok($pos === null, 'a cancelled request leaves master data alone', var_export($pos, true));

/* ----------------------------------------------------- the history tab ---- */

echo "\n== 8. the master history tab ==\n";

$histPath = "/drivers/{$driverId}/history";
[$code, $histHtml] = req($jars['viewer'], 'GET', $histPath);
ok($code === 200, "viewer may read the tab (HTTP {$code})");
ok(str_contains($histHtml, 'Governance Renamed'),
    'the tab shows the approved change');
// The throwaway driver was created after the baseline seed ran, so it must
// have NO "Initial Driver Setup" row - which is also what proves the seed is
// one-shot rather than something that keeps re-stamping old drivers.
$baseline = (int)Database::scalar(
    "SELECT COUNT(*) FROM driver_history
      WHERE driver_id = ? AND source = 'initial'", [$driverId]
);
ok($baseline === 0, 'the seed does not re-stamp a driver created after it', "rows={$baseline}");

// The badge text is the end-to-end check on the source stored ABOVE: the view
// renders $sources[$row['source']], so an approval can only say "Approved
// request" if the row really carries source = approved_request. (The string
// "Initial Driver Setup" is always on this page - the source filter lists
// every label - so it can never be used to assert what rows exist.)
ok(str_contains($histHtml, 'Approved request'),
    'the tab badges the approval as "Approved request"');

$used = array_map(
    'strval',
    array_column(
        Database::all('SELECT DISTINCT field_name FROM driver_history WHERE driver_id = ?', [$driverId]),
        'field_name'
    )
);
ok(in_array('driver_name', $used, true), 'the governed field is represented in history', implode(', ', $used));

// The tab is a READ. Nothing on it may reach a write path.
[$code] = req($jars['viewer'], 'POST', '/driver-changes', [
    '_csrf'     => csrfOf($jars['viewer'], '/driver-changes/new'),
    'driver_id' => $driverId,
    'field'     => 'driver_name',
    'new_value' => 'viewer should not get here',
    'reason'    => 'viewer is read only by design',
]);
ok($code === 403, "viewer still cannot file anything (HTTP {$code})");

/* ------------------------------------------------- direct correction ------ */

echo "\n== 9. direct correction ==\n";

$editPath = '/drivers/' . $driverId . '/edit';
$row      = Driver::find($driverId);
ok($row !== null, 'the driver is re-loadable before the correction');

// Post the record back to itself, so every validated field carries a value
// that already passed validation once. Only driver_name moves.
$payload = ['_csrf' => csrfOf($jars['hr'], $editPath)];

foreach ($row as $col => $val) {
    if (in_array($col, ['id', 'created_at', 'updated_at'], true)) {
        continue;
    }
    $payload[$col] = $val === null ? '' : (string)$val;
}
$payload['driver_name'] = 'Governance Corrected';
unset($payload['reason']);

[$code] = req($jars['hr'], 'POST', $editPath, $payload);
ok($code === 302, "the form itself validated (HTTP {$code})");

$nameStill = (string)Database::scalar('SELECT driver_name FROM drivers WHERE id = ?', [$driverId]);
ok($nameStill === 'Governance Renamed',
    'a governed change without a reason is REFUSED', $nameStill);

$noReason = (int)Database::scalar(
    "SELECT COUNT(*) FROM driver_history
      WHERE driver_id = ? AND source = 'direct_correction'", [$driverId]
);
ok($noReason === 0, 'and it writes no history at all');

// Same payload, now with the mandatory reason.
$payload['_csrf'] = csrfOf($jars['hr'], $editPath);
$payload['reason'] = 'Badge reprint corrected the spelling';

[$code] = req($jars['hr'], 'POST', $editPath, $payload);
ok($code === 302, "the correction was accepted (HTTP {$code})");

$nameNow = (string)Database::scalar('SELECT driver_name FROM drivers WHERE id = ?', [$driverId]);
ok($nameNow === 'Governance Corrected', 'the master value moved', $nameNow);

$dc = Database::one(
    "SELECT * FROM driver_history
      WHERE driver_id = ? AND source = 'direct_correction' AND field_name = 'driver_name'",
    [$driverId]
);
ok($dc !== null, 'a direct_correction history row exists');
ok((string)$dc['reason'] === 'Badge reprint corrected the spelling',
    'it carries the reason that authorised it', (string)$dc['reason']);
ok((int)$dc['changed_by'] === $users['hr'], 'it names who made the correction');
ok($dc['request_id'] === null, 'a direct correction is deliberately NOT linked to a request');

// operations may not take this back door.
[$code] = req($jars['operations'], 'POST', $editPath, $payload);
ok($code === 403, "operations cannot write master data directly (HTTP {$code})");

/* --------------------------------------------------------- cleanup -------- */

echo "\n== cleanup ==\n";

// Order matters: both governance tables point at drivers ON DELETE RESTRICT,
// and driver_history points at driver_change_requests.
$reqIds = array_map('intval', array_column(
    Database::all('SELECT id FROM driver_change_requests WHERE driver_id = ?', [$driverId]),
    'id'
));

foreach ($reqIds as $rid) {
    Database::run(
        'DELETE FROM audit_logs WHERE table_name = ? AND record_id = ?',
        ['driver_change_requests', $rid]
    );
}
Database::run(
    'DELETE FROM audit_logs WHERE table_name = ? AND record_id = ?',
    ['drivers', $driverId]
);

$histRows = (int)Database::scalar(
    'SELECT COUNT(*) FROM driver_history WHERE driver_id = ?', [$driverId]
);
$crRows = count($reqIds);

Database::run('DELETE FROM driver_history WHERE driver_id = ?', [$driverId]);
Database::run('DELETE FROM driver_change_requests WHERE driver_id = ?', [$driverId]);
Database::run('DELETE FROM drivers WHERE id = ?', [$driverId]);

$driverGone = (int)Database::scalar(
    'SELECT COUNT(*) FROM drivers WHERE id = ?', [$driverId]
);
ok($driverGone === 0, "throwaway driver removed with its {$histRows} history and {$crRows} request rows");

$orphanHist = (int)Database::scalar(
    'SELECT COUNT(*) FROM driver_history dh
       LEFT JOIN drivers d ON d.id = dh.driver_id WHERE d.id IS NULL'
);
$orphanReq = (int)Database::scalar(
    'SELECT COUNT(*) FROM driver_change_requests dcr
       LEFT JOIN drivers d ON d.id = dcr.driver_id WHERE d.id IS NULL'
);
ok($orphanHist === 0 && $orphanReq === 0, 'no orphaned governance rows were left behind',
    "history={$orphanHist} requests={$orphanReq}");

Database::run('DELETE FROM user_roles WHERE user_id IN
    (SELECT id FROM users WHERE username LIKE "zgov\\_%")');
Database::run('DELETE FROM users WHERE username LIKE "zgov\\_%"');

$leftovers = (int)Database::scalar(
    'SELECT COUNT(*) FROM users WHERE username LIKE "zgov\\_%"'
);
ok($leftovers === 0, 'fixture accounts removed');
ok((int)Database::scalar(
    'SELECT COUNT(*) FROM drivers WHERE badge_number = ?', [$badge]
) === 0, 'and no throwaway driver survives');

foreach ($jars as $jar) {
    @unlink($jar);
}

// A governing rule this whole feature rests on: history is append-only. If
// either model ever grows an update()/delete() path, the audit trail stops
// being an audit trail - so assert it from the source, not from hope.
foreach (['DriverHistory', 'DriverChangeRequest'] as $cls) {
    $src = (string)file_get_contents(APP_ROOT . '/app/models/' . $cls . '.php');
    ok(
        preg_match('/function\s+update\s*\(/', $src) !== 1
        && preg_match('/function\s+delete\s*\(/', $src) !== 1,
        "{$cls} exposes no update()/delete() path"
    );
}

/* ---------------------------------------------------------- report -------- */

file_put_contents(
    dirname(__DIR__) . '/storage/logs/driver-governance-summary.txt',
    ($issues === [] ? "no failures\n" : implode("\n", $issues) . "\n")
    . "TOTAL {$pass} passed, {$fail} failed\n"
);

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);




