<?php

declare(strict_types=1);

/**
 * End-to-end workflow test (CLI only).
 *
 *   php tools/workflow-test.php
 *   php tools/workflow-test.php http://localhost/lms
 *
 * Exercises the real user workflows over HTTP exactly as a browser would:
 *   login -> add driver -> search -> edit -> create leave -> view -> overlap
 *   check -> expected returns -> mark returned -> history -> dashboard ->
 *   reports -> cancel -> deactivate -> settings CRUD.
 *
 * Creates a clearly named test driver (badge W-9xx) that you can remove with:
 *   DELETE FROM leave_records WHERE driver_id = <id>; DELETE FROM drivers WHERE id = <id>;
 *
 * Exits non-zero on the first failed step.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/workflow-test.php\n");
}

$base   = rtrim($argv[1] ?? 'http://localhost/lms', '/');
$quiet  = in_array('--quiet', $argv, true);

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The curl PHP extension is required.\n");
    exit(1);
}

/** Cookie-jar HTTP client (session survives redirects). */
final class Http
{
    private string $jar;

    public function __construct()
    {
        $this->jar = rtrim((string)sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'lms-wf-' . bin2hex(random_bytes(6)) . '.txt';
    }

    public function __destruct()
    {
        if (is_file($this->jar)) {
            @unlink($this->jar);
        }
    }

    /** @return array{0:int,1:string,2:string} [status, body, finalUrl] */
    public function request(string $method, string $url, array|string $post = [], array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 6,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HEADER         => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_COOKIEJAR      => $this->jar,
        ]);

        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);

            if (is_string($post)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            } else {
                // Arrays containing an uploaded file must be sent as real
                // multipart/form-data, otherwise cURL drops the file part.
                $hasFile = false;
                foreach ($post as $value) {
                    if ($value instanceof \CURLFile) {
                        $hasFile = true;
                        break;
                    }
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $post : http_build_query($post));
            }
        }

        $body = (string)curl_exec($ch);
        $info = [
            (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            $body,
            (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        ];
        curl_close($ch);

        return $info;
    }

    /**
     * Hidden form value from an HTML page.
     *
     * For the CSRF token the <meta name="csrf-token"> tag is preferred, since
     * some screens (and all AJAX calls) carry the token only there.
     */
    public function hidden(string $html, string $name): string
    {
        if ($name === '_csrf'
            && preg_match('#<meta\s+name="csrf-token"\s+content="([^"]+)"#', $html, $m) === 1
        ) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('#name="' . preg_quote($name, '#') . '"\s+value="([^"]*)"#', $html, $m) === 1) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        return '';
    }
}

$http   = new Http();
$failures = [];
$step     = 0;

function say(string $msg): void
{
    echo $msg . "\n";
}

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $step, $quiet;
    $step++;

    // STDERR is unbuffered in PHP's CLI SAPI, so progress shows up live even
    // when stdout is redirected to a file or pipe.
    if ($condition) {
        if (!$quiet) {
            fwrite(STDERR, sprintf("%2d. PASS  %s\n", $step, $label));
        }

        return;
    }

    $clean = str_replace(["\r", "\n"], ' ', $detail);
    fwrite(STDERR, sprintf("%2d. FAIL  %s  %s\n", $step, $label, $clean));
    $failures[] = $label . ($clean !== '' ? ' [' . $clean . ']' : '');
}

/** GET helper returning the body only. */
function get(string $path): string
{
    global $http, $base;

    [, $body] = $http->request('GET', $base . $path);

    return $body;
}

/** POST a form, returning the (possibly redirected) body. */
function post(string $path, array $fields): string
{
    global $http, $base;

    [, $body] = $http->request('POST', $base . $path, $fields);

    return $body;
}

/** Assert an alert/flash message is present anywhere in the body. */
function flashed(string $body, string $needle): bool
{
    return strpos($body, $needle) !== false;
}

/** Date offset helper: day(+7) -> 2026-10-01 style. */
function day(int $offset): string
{
    return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
}

/* --------------------------------------------------------------- login -- */

$html = get('/login');
$csrf = $http->hidden($html, '_csrf');
ok('login page renders with a CSRF token', $csrf !== '', 'no token');

$body = post('/login', [
    '_csrf'    => $csrf,
    'username' => 'admin',
    'password' => getenv('LMS_TEST_PASS') ?: 'admin123',
    'redirect' => '',
]);
ok('login authenticates and lands on the dashboard',
    flashed($body, 'Leave Dashboard'), 'no dashboard');

/* ------------------------------------------------------ add a driver ---- */

$badge = 'W-' . random_int(100, 999);
$iqama = '9' . random_int(100000000, 999999999);

$html = get('/drivers/create');
$csrf = $http->hidden($html, '_csrf');
ok('driver form renders', $csrf !== '', 'no token');

// Use whichever agency/branch this installation actually has. Hard-coding "1"
// broke every run after a restore renumbered the ids (agencies started at 6):
// existsIn() rejected the driver, the re-rendered form still carried the badge
// so step 4 "passed", and everything downstream cascaded on driverId = 0.
$agencyId = '';
$branchId = '';
if (preg_match('#name="agency_id"[^>]*>.*?<option value="(\d+)"#s', $html, $m) === 1) {
    $agencyId = $m[1];
}
if (preg_match('#name="branch_id"[^>]*>.*?<option value="(\d+)"#s', $html, $m) === 1) {
    $branchId = $m[1];
}

$body = post('/drivers/create', [
    '_csrf'                 => $csrf,
    'badge_number'          => $badge,
    'driver_name'           => 'Workflow Test Driver',
    'iqama_number'          => $iqama,
    'mobile_number'         => '0551234567',
    'nationality'           => 'India',
    'agency_id'             => $agencyId,
    'branch_id'             => $branchId,
    'department'            => 'Transport',
    'position'              => 'Heavy Truck Driver',
    'joining_date'          => '2024-01-15',
    'emergency_contact_name'=> 'Test Contact',
    'emergency_contact_number' => '0559876543',
    'address'               => '12 Test Street',
    'city'                  => 'Dammam',
    'remarks'               => 'Created by tools/workflow-test.php',
    'status'                => 'active',
]);
ok('driver is created and profile opens', flashed($body, $badge), 'badge not on profile');

// Grab the driver id from the profile URL we landed on.
$driverId = 0;
if (preg_match('#/drivers/(\d+)#', (string)$http->request('GET', $base . '/drivers?q=' . urlencode($badge))[2], $m) === 1) {
    $driverId = (int)$m[1];
}
if ($driverId === 0 && preg_match('#/drivers/(\d+)#', (string)$http->request('GET', $base . '/dashboard')[2], $m) === 1) {
    $driverId = 0;
}

// Resolve the id deterministically through the address book search results.
$search = get('/drivers?q=' . urlencode($badge));
if (preg_match('#href="[^"]*/drivers/(\d+)"#', $search, $m) === 1) {
    $driverId = (int)$m[1];
}
ok('driver appears in address book search', $driverId > 0 && flashed($search, $badge),
    'id=' . $driverId);

/* ------------------------------------------------------- edit driver ---- */

$html = get('/drivers/' . $driverId . '/edit');
$csrf = $http->hidden($html, '_csrf');
ok('edit form renders', $csrf !== '', 'no token');

$body = post('/drivers/' . $driverId . '/edit', [
    '_csrf'                  => $csrf,
    'badge_number'           => $badge,
    'driver_name'            => 'Workflow Test Driver',
    'iqama_number'           => $iqama,
    'mobile_number'          => '0551234567',
    'alternate_mobile'       => '0557654321',
    'nationality'            => 'India',
    'agency_id'              => $agencyId,
    'branch_id'              => $branchId,
    'department'             => 'Transport',
    'position'               => 'Heavy Truck Driver',
    'joining_date'           => '2024-01-15',
    'emergency_contact_name' => 'Test Contact',
    'emergency_contact_number' => '0559876543',
    'address'                => '12 Test Street',
    'city'                   => 'Al Khobar',
    'remarks'                => 'Edited by tools/workflow-test.php',
    'status'                 => 'active',
]);
ok('driver is updated', flashed($body, 'Driver details updated'), 'no success flash');

/* ------------------------------------------------------- create leave --- */

$html  = get('/leave/new');
$csrf  = $http->hidden($html, '_csrf');
$ltId  = '1';
if (preg_match('#<option value="(\d+)">#', $html, $m) === 1) {
    $ltId = $m[1];
}
ok('new leave form renders with leave types', $csrf !== '' && $ltId !== '',
    'csrf=' . strlen($csrf) . ' lt=' . $ltId);

/**
 * A new leave is filed against an approved application, so the fixture driver
 * needs one before the form will accept any record. Filed and approved over
 * HTTP exactly as a user would - this test deliberately has no database
 * handle. Two records are created below, and one application backs at most
 * one record, so each gets its own.
 */
$approvedApplication = static function (string $lastWorking, string $back)
    use ($http, $driverId, $badge, $ltId): int {
    $form  = get('/leave-applications/new?driver_id=' . $driverId);
    $token = $http->hidden($form, '_csrf');

    post('/leave-applications/new', [
        '_csrf'                 => $token,
        'driver_id'             => (string)$driverId,
        'leave_type_id'         => $ltId,
        'application_date'      => day(0),
        'last_working_date'     => $lastWorking,
        'expected_arrival_date' => $back,
        'destination'           => 'India',
        'reason'                => 'Workflow test application',
    ]);

    $list = get('/leave-applications?q=' . urlencode($badge));

    if (preg_match('#href="[^"]*/leave-applications/(\d+)"#', $list, $m) !== 1) {
        return 0;
    }

    $appId = (int)$m[1];
    $view  = get('/leave-applications/' . $appId);
    $token = $http->hidden($view, '_csrf');

    post('/leave-applications/' . $appId . '/status', [
        '_csrf'  => $token,
        'status' => 'approved',
    ]);

    return $appId;
};

$plannedApp = $approvedApplication(day(29), day(40));
$overdueApp = $approvedApplication(day(-25), day(-5));

ok('approved applications are on file for the fixture driver',
    $plannedApp > 0 && $overdueApp > 0,
    'planned=' . $plannedApp . ' overdue=' . $overdueApp);

// The rule itself: the SAME payload with the link left out must come back
// refused rather than quietly filing an application-less leave.
$refused = post('/leave/new', [
    '_csrf'                => $csrf,
    'driver_id'            => (string)$driverId,
    'leave_type_id'        => $ltId,
    'last_working_date'    => day(29),
    'leave_start_date'     => day(30),
    'ticket_date'          => day(30),
    'expected_arrival_date'=> day(40),
    'destination'          => 'India',
    'reason'               => 'Annual vacation',
    'remarks'              => 'No application behind this one',
]);
ok('a leave with no approved application is refused',
    flashed($refused, 'cannot be created without one'), 'no refusal message');

// (A) Planned leave in the future.
$body = post('/leave/new', [
    '_csrf'                => $csrf,
    'driver_id'            => (string)$driverId,
    'leave_type_id'        => $ltId,
    'leave_application_id' => (string)$plannedApp,
    'last_working_date'    => day(29),
    'leave_start_date'     => day(30),
    // Rule 2: ticket must be on/after the leave start (day(30)), and rule 3
    // requires the arrival to fall after it too.
    'ticket_date'          => day(30),
    'expected_arrival_date'=> day(40),
    'destination'          => 'India',
    'reason'               => 'Annual vacation',
    'remarks'              => 'Workflow test - planned',
]);
ok('planned leave is created', flashed($body, 'Leave record created'), 'no success flash');

$leaveId = 0;
$records = get('/leave/records?q=' . urlencode($badge));
if (preg_match('#/leave/(\d+)#', $records, $m) === 1) {
    $leaveId = (int)$m[1];
}
ok('leave appears in Leave Records', $leaveId > 0 && flashed($records, $badge),
    'id=' . $leaveId);

$view = get('/leave/' . $leaveId);
ok('leave record opens', flashed($view, 'Leave Record #') && flashed($view, $badge));

// Overlap check: the same window must report a clash, a new window must not.
[$st, $overlapBody] = $http->request('GET',
    $base . '/api/leave/overlap-check?driver_id=' . $driverId
    . '&start=' . day(30) . '&end=' . day(40));
$overlap = json_decode($overlapBody, true);
ok('overlap check flags the existing leave window',
    $st === 200 && is_array($overlap) && !empty($overlap['overlap']),
    substr($overlapBody, 0, 100));

[$st, $overlapBody] = $http->request('GET',
    $base . '/api/leave/overlap-check?driver_id=' . $driverId
    . '&start=' . day(200) . '&end=' . day(210));
$overlap = json_decode($overlapBody, true);
ok('overlap check allows a free window',
    $st === 200 && is_array($overlap) && empty($overlap['overlap']),
    substr($overlapBody, 0, 100));

// (B) Leave that is already in the past -> becomes Overdue automatically.
$html = get('/leave/new');
$csrf = $http->hidden($html, '_csrf');

$body = post('/leave/new', [
    '_csrf'                => $csrf,
    'driver_id'            => (string)$driverId,
    'leave_type_id'        => $ltId,
    'leave_application_id' => (string)$overdueApp,
    'last_working_date'    => day(-25),
    'leave_start_date'     => day(-25),
    // Rule 2: ticket on/after the leave start, so day(-25) not day(-26).
    'ticket_date'          => day(-25),
    'expected_arrival_date'=> day(-5),
    'destination'          => 'India',
    'reason'               => 'Workflow test overdue',
    'remarks'              => 'Workflow test - overdue',
]);
ok('overdue leave is created', flashed($body, 'Leave record created'), 'no success flash');

$overdueId = 0;
foreach (preg_match_all('#/leave/(\d+)#', $records = get('/leave/records?q=' . urlencode($badge)), $mm) ? $mm[1] : [] as $candidate) {
    $candidate = (int)$candidate;
    $detail = get('/leave/' . $candidate);
    if (flashed($detail, 'past the expected return date')) {
        $overdueId = $candidate;
        break;
    }
}
ok('derived status is Overdue for a past expected return', $overdueId > 0, 'no overdue record');

/* -------------------------------------------------- expected returns ---- */

$returns = get('/leave/returns');
ok('Expected Returns lists the overdue driver', flashed($returns, $badge),
    'driver missing from returns board');

$current = get('/leave/current');
ok('Current Leave shows the away driver', flashed($current, $badge),
    'driver missing from current leave');
// Same .table-grid floor as Leave Records: a short table still shows its
// row actions in full instead of clipping them at the container edge.
ok('Current Leave table keeps the 300px floor',
    str_contains($current, 'table-responsive table-grid'),
    'no table floor on /leave/current');

/* ------------------------------------------------------ mark returned --- */

[$st, $returnBody] = $http->request('POST', $base . '/api/leave/mark-returned',
    json_encode([
        'id'                 => $overdueId,
        'actual_arrival_date'=> day(-5),
        'actual_arrival_time'=> '14:30',
        'return_remarks'     => 'Workflow test return',
    ]),
    ['Content-Type: application/json', 'X-CSRF-Token: ' . $http->hidden(get('/leave/' . $overdueId), '_csrf')]);

$returnJson = json_decode($returnBody, true);
ok('driver is marked returned via the API',
    $st === 200 && is_array($returnJson) && !empty($returnJson['ok']),
    substr($returnBody, 0, 160));

$records = get('/leave/records?q=' . urlencode($badge));
ok('returned status shows in Leave Records', flashed($records, 'Returned'),
    'no Returned badge');

$history = get('/drivers/' . $driverId . '/leave-history');
ok('driver leave history lists both records', flashed($history, 'Returned'),
    'history missing the returned record');

/* --------------------------------------------------------- dashboard ---- */

$dash = get('/dashboard');
ok('dashboard renders KPI cards after the workflow',
    flashed($dash, 'Leave Dashboard') && flashed($dash, 'Overdue Returns'),
    'dashboard incomplete');

/* ------------------------------------------------------------ reports --- */

$report = get('/reports/return?q=' . urlencode($badge));
ok('return report includes the driver', flashed($report, $badge), 'driver missing');

$branchReport = get('/reports/branch');
ok('branch report renders totals', flashed($branchReport, 'Total Leaves'), 'no totals');

/* ------------------------------------------- deactivate guard + cancel -- */

// The driver still has an open (planned) leave record, so deactivation must
// be refused - this is the business rule from spec section 10. The flow now
// also collects the Last Working Date (automated deactivation rules), and a
// missing date is reported before anything else.
$html = get('/drivers/' . $driverId);
$csrf = $http->hidden($html, '_csrf');
$body = post('/drivers/' . $driverId . '/deactivate', ['_csrf' => $csrf]);
ok('deactivation asks for the last working date first',
    flashed($body, 'Last Working Date'), 'no prompt for the end date');

$body = post('/drivers/' . $driverId . '/deactivate',
    ['_csrf' => $csrf, 'last_working_date' => date('Y-m-d')]);
ok('deactivation is blocked while a leave record is open',
    flashed($body, 'open leave record'), 'no guard message');

// Cancel the planned leave, then deactivation should be allowed.
$html = get('/leave/' . $leaveId);
$csrf = $http->hidden($html, '_csrf');
[$st, $cancelBody] = $http->request('POST', $base . '/api/leave/cancel',
    json_encode(['id' => $leaveId, 'cancel_reason' => 'Workflow test cancellation']),
    ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);

$cancelJson = json_decode($cancelBody, true);
ok('leave record is cancelled',
    $st === 200 && is_array($cancelJson) && !empty($cancelJson['ok']),
    substr($cancelBody, 0, 160));

$records = get('/leave/records?q=' . urlencode($badge));
ok('cancelled status is visible', flashed($records, 'Cancelled'), 'no Cancelled badge');

// With no open leave left, deactivation now succeeds - and it must carry the
// last working date the modal asks for.
$html = get('/drivers/' . $driverId);
$csrf = $http->hidden($html, '_csrf');
$body = post('/drivers/' . $driverId . '/deactivate',
    ['_csrf' => $csrf, 'last_working_date' => date('Y-m-d')]);
ok('driver deactivates once no leave is open',
    flashed($body, 'has been deactivated'), 'not deactivated');
ok('the flash records the last working date',
    flashed($body, 'Last working date'), 'no end date in the confirmation');

// Put the test driver back to active so the data stays usable.
$html = get('/drivers/' . $driverId);
$csrf = $http->hidden($html, '_csrf');
$body = post('/drivers/' . $driverId . '/activate', ['_csrf' => $csrf]);
ok('driver can be reactivated', flashed($body, 'is active again'), 'not reactivated');
ok('reactivation clears the last working date',
    flashed($body, 'last working date has been cleared'), 'the end date was left behind');

/* ---------------------------------------------------------- settings ---- */

$html = get('/settings/branches');
$csrf = $http->hidden($html, '_csrf');
$body = post('/settings/save', [
    '_csrf'      => $csrf,
    'kind'       => 'branch',
    'return_to'  => 'settings/branches',
    'id'         => '0',
    'branch_name'=> 'Workflow Test Branch',
    'code'       => 'WFT',
    'status'     => 'active',
]);
ok('settings: branch is created',
    flashed($body, 'Branches added') || flashed($body, 'already exists'),
    'no success flash');

$html = get('/settings/leave-types');
$csrf = $http->hidden($html, '_csrf');
$body = post('/settings/save', [
    '_csrf'          => $csrf,
    'kind'           => 'leave_type',
    'return_to'      => 'settings/leave-types',
    'id'             => '0',
    'name'           => 'Workflow Test Type',
    'description'    => 'Created by tools/workflow-test.php',
    'status'         => 'active',
]);
ok('settings: leave type is created',
    flashed($body, 'Leave Types added') || flashed($body, 'already exists'),
    'no success flash');

$audit = get('/settings/audit');
ok('audit log recorded the workflow',
    flashed($audit, 'leave-created') && flashed($audit, 'driver-created'),
    'expected audit entries missing');

/* ------------------------------------------------------------ exports ---- */

[$st, $csv] = $http->request('GET', $base . '/drivers?export=csv');
ok('driver CSV export streams', $st === 200 && strpos($csv, 'Badge Number') !== false,
    'HTTP ' . $st);

[$st, $jsonExport] = $http->request('GET', $base . '/leave/records?export=json');
$decoded = json_decode($jsonExport, true);
ok('leave JSON export decodes', $st === 200 && is_array($decoded) && isset($decoded['rows']),
    'HTTP ' . $st);

/* ------------------------------------------------------ CSV import ----- */

// 1. Download the template so the header row is exactly what the importer expects.
[$st, $template] = $http->request('GET', $base . '/import?template=1');
$lines = preg_split('/\r\n|\r|\n/', trim($template)) ?: [];
$header = $lines[0] ?? '';
$importBadge = 'W-' . random_int(100, 999);

ok('import template downloads with a header row',
    $st === 200 && strpos($header, 'badge_number') !== false,
    'HTTP ' . $st);

// 2. Build a CSV with one new driver and upload it for preview.
$exampleRow = $lines[1] ?? '';
$cells      = str_getcsv($exampleRow);
$cells[0]   = $importBadge;                       // badge_number
$cells[1]   = 'Imported Test Driver';             // driver_name
$cells[2]   = '9' . random_int(100000000, 999999999); // iqama_number

$tmp = rtrim((string)sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
    . 'lms-import-' . $importBadge . '.csv';
file_put_contents($tmp, $header . "\n" . implode(',', array_map(static function ($c): string {
    return str_contains((string)$c, ',') ? '"' . str_replace('"', '""', (string)$c) . '"' : (string)$c;
}, $cells)) . "\n");

$html = get('/import');
$csrf = $http->hidden($html, '_csrf');

[$st, $preview] = $http->request('POST', $base . '/import', [
    '_csrf'   => $csrf,
    'csv_file'=> new \CURLFile($tmp, 'text/csv', basename($tmp)),
]);
ok('CSV upload produces a preview',
    $st === 200 && strpos($preview, $importBadge) !== false && strpos($preview, 'Confirm import') !== false,
    'HTTP ' . $st);

// 3. Confirm the import.
$csrf = $http->hidden($preview, '_csrf');
[$st, $summary] = $http->request('POST', $base . '/import', [
    '_csrf' => $csrf,
    'step'  => 'confirm',
    'mode'  => 'skip',
]);
ok('import is confirmed with a summary',
    strpos($summary, 'Import finished') !== false || strpos($summary, 'import summary') !== false,
    substr(strip_tags($summary), 0, 140));

$afterImport = get('/drivers?q=' . urlencode($importBadge));
ok('imported driver appears in the address book',
    strpos($afterImport, $importBadge) !== false, 'driver missing');

@unlink($tmp);

// Remove the imported driver so the run leaves no residue.
if (preg_match('#href="[^"]*/drivers/(\d+)"#', $afterImport, $m) === 1) {
    $importedId = (int)$m[1];
    $html  = get('/drivers/' . $importedId . '/edit');
    $csrf  = $http->hidden($html, '_csrf');
    post('/drivers/' . $importedId . '/edit', [
        '_csrf'           => $csrf,
        'badge_number'    => $importBadge,
        'driver_name'     => 'Imported Test Driver',
        'iqama_number'    => preg_match('/\b9\d{9}\b/', $afterImport, $mm) ? $mm[0] : '',
        'nationality'     => 'India',
        'status'          => 'inactive',
        'remarks'         => 'Temporary import test row',
    ]);
}

/* ------------------------------------------------------------ summary ---- */

echo "\n";
echo 'steps: ' . $step . '   failed: ' . count($failures) . "\n";
echo 'test driver badge: ' . $badge . '  (driver id ' . $driverId . ")\n";

if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
    exit(1);
}

echo "All workflow steps passed.\n";
exit(0);



