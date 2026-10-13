<?php
/**
 * Round 6 - the four items that are not already pinned elsewhere.
 *
 *   1. Actual arrival date is editable on Edit Leave Record, and only while
 *      the record is already Returned (a crafted POST must not turn an
 *      on-leave record into a returned one - that is Mark Returned).
 *   2. A Resignation / Terminated application keeps no Expected Return.
 *   4. Duration columns on Leave Records + Leave Applications, and the
 *      Duration (info only) field on the application form.
 *   7. Vacation Applications counts every leave type except the departures.
 *
 * Items 3, 5 and 6 live in capacity-plan-test.php, where the forecast
 * fixtures already are:
 *
 *   3  planned trips are excluded from Expected Returns / Returns  (CAPF-06)
 *   5  pending resignations shrink the active head-count            (CAPF-04)
 *   6  movement rows carry Branch, Leave Type and Status            (section 9)
 *
 * Everything touched here is a fixture named R6 * and is removed at the end,
 * so the live data is left exactly as it was found.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Dashboard;
use App\Models\LeaveApplication;
use App\Models\LeaveRecord;

$pass = 0;
$fail = 0;

function ok(bool $c, string $m): void
{
    global $pass, $fail;

    if ($c) {
        $pass++;
        echo "  ok    $m\n";
    } else {
        $fail++;
        echo "  FAIL  $m\n";
    }
}

/** The fixture's own agency / branch, read by the closures below. */
$GLOBALS['r6'] = ['agency' => 0, 'branch' => 0, 'annual' => 0];

/* ------------------------------------------------------------- fixtures -- */

// Self-healing start: clear leftovers from an interrupted earlier run.
foreach (['leave_records', 'leave_applications'] as $table) {
    Database::run(
        "DELETE {$table} FROM {$table}
           JOIN drivers d ON d.id = {$table}.driver_id
          WHERE d.badge_number LIKE 'R6-%'"
    );
}
Database::run("DELETE FROM drivers WHERE badge_number LIKE 'R6-%'");
Database::run("DELETE FROM branches WHERE code LIKE 'R6%'");
Database::run("DELETE FROM agencies WHERE code LIKE 'R6%'");
Database::run("DELETE FROM leave_types WHERE name LIKE 'R6 FIX %'");

$GLOBALS['r6']['agency'] = (int)Database::insert('agencies', [
    'code' => 'R6AG', 'agency_name' => 'R6 AGENCY', 'status' => 'active',
]);
$GLOBALS['r6']['branch'] = (int)Database::insert('branches', [
    'code' => 'R6A', 'branch_name' => 'R6 BRANCH', 'status' => 'active',
]);

/** @return int driver id */
$mkDriver = static function (string $badge): int {
    return Database::insert('drivers', [
        'badge_number'  => $badge,
        'driver_name'   => 'R6 DRIVER ' . substr($badge, -2),
        'iqama_number'  => 'R6' . substr($badge, -2) . str_repeat('0', 8),
        'branch_id'     => $GLOBALS['r6']['branch'],
        'agency_id'     => $GLOBALS['r6']['agency'],
        'joining_date'  => '2020-01-01',
        'status'        => 'active',
    ]);
};

$typeId = static function (string $name): int {
    $id = Database::scalar('SELECT id FROM leave_types WHERE name = ?', [$name]);

    if ($id === null) {
        $id = Database::insert('leave_types', ['name' => $name, 'status' => 'active']);
    }

    return (int)$id;
};

$annual     = $typeId('Annual Vacation');
$terminated = $typeId('Terminated');
$resignation = $typeId('Resignation');
$absconded  = $typeId('Absconded');
$sick       = $typeId('R6 FIX SICK');   // a non-departure that is NOT a vacation

$GLOBALS['r6']['annual'] = $annual;

$dAnnual = $mkDriver('R6-01');
$dSick   = $mkDriver('R6-02');
$dTerm   = $mkDriver('R6-03');
$dBack   = $mkDriver('R6-04');   // returned record - item 1
$dAway   = $mkDriver('R6-05');   // still away     - item 1

/** @return int record id */
$mkRecord = static function (int $driver, string $start, string $expected, ?string $actual): int {
    $status = $actual !== null
        ? 'returned'
        : (strtotime($start) > strtotime(today()) ? 'planned' : 'on_leave');

    return Database::insert('leave_records', [
        'driver_id'             => $driver,
        'leave_type_id'         => $GLOBALS['r6']['annual'],
        'leave_start_date'      => $start,
        'expected_arrival_date' => $expected,
        'actual_arrival_date'   => $actual,
        'status'                => $status,
    ]);
};

/* =============================================== 1. Actual arrival date ==== */

echo "\n== 1. Actual arrival date on Edit Leave Record ==\n";

$backStart = date('Y-m-d', strtotime('-20 days'));
$backEnd   = date('Y-m-d', strtotime('-6 days'));
$backReal  = date('Y-m-d', strtotime('-7 days'));

$recordId = $mkRecord($dBack, $backStart, $backEnd, $backReal);
$record   = LeaveRecord::find($recordId);

ok($record !== null && (string)$record['status'] === 'returned',
    'the fixture record is Returned (got "' . ($record['status'] ?? 'null') . '")');
ok((string)$record['actual_arrival_date'] === $backReal,
    'and carries an actual arrival date');

$base = [
    'driver_id'             => $dBack,
    'leave_type_id'         => $annual,
    'leave_start_date'      => $backStart,
    'expected_arrival_date' => $backEnd,
];

// (a) a corrected date on a returned record IS written.
$corrected = date('Y-m-d', strtotime('-8 days'));
LeaveRecord::update($recordId, $base + ['actual_arrival_date' => $corrected]);
$record = LeaveRecord::find($recordId);

ok((string)$record['actual_arrival_date'] === $corrected,
    'editing a Returned record corrects the arrival date (got '
    . ($record['actual_arrival_date'] ?? 'null') . ')');
ok((string)$record['status'] === 'returned', 'and stays Returned');

// (b) posting nothing keeps the stored date - a blank must never clear it.
LeaveRecord::update($recordId, $base + ['actual_arrival_date' => null]);
$record = LeaveRecord::find($recordId);

ok((string)$record['actual_arrival_date'] === $corrected,
    'a blank field keeps the stored date (got '
    . ($record['actual_arrival_date'] ?? 'null') . ')');

// (c) the field only exists for a returned record, and the model enforces it:
//     a crafted POST cannot close somebody else's trip.
$awayStart = date('Y-m-d', strtotime('-5 days'));
$awayEnd   = date('Y-m-d', strtotime('+10 days'));
$awayId    = $mkRecord($dAway, $awayStart, $awayEnd, null);

LeaveRecord::update($awayId, [
    'driver_id'             => $dAway,
    'leave_type_id'         => $annual,
    'leave_start_date'      => $awayStart,
    'expected_arrival_date' => $awayEnd,
    'actual_arrival_date'   => date('Y-m-d'),   // as if the field had been posted
]);
$away = LeaveRecord::find($awayId);

ok($away['actual_arrival_date'] === null,
    'a non-Returned record ignores a posted arrival date (got '
    . ($away['actual_arrival_date'] ?? 'null') . ')');
ok((string)$away['status'] !== 'returned',
    'and is NOT silently closed by the edit (status "' . $away['status'] . '")');

/* =============================================== 2. No expected return ==== */

echo "\n== 2. Resignation / Terminated / Absconded carry no expected return ==\n";

$noReturn = LeaveApplication::noReturnTypeIds();

ok(in_array($resignation, $noReturn, true), 'Resignation has no expected return');
ok(in_array($terminated, $noReturn, true), 'Terminated has no expected return');
// Round 6 item 2 originally named only the two, leaving Absconded alone as a
// population exit rather than a date to suppress. It was folded in when the
// New Leave / Vacation form asked for the same three fields on all three
// departure types; NO_RETURN_PATTERNS and leave.js must stay in step.
ok(in_array($absconded, $noReturn, true),
    'Absconded carries no expected return either (revised: all three departures)');
ok(!in_array($annual, $noReturn, true), 'Annual Vacation keeps its arrival date');

ok(LeaveApplication::expectedArrivalFor($annual, '2031-06-20') === '2031-06-20',
    'an ordinary application keeps the posted date');
ok(LeaveApplication::expectedArrivalFor($resignation, '2031-06-20') === null,
    'a resignation drops it');
ok(LeaveApplication::expectedArrivalFor($terminated, '2031-06-20') === null,
    'a termination drops it');
ok(LeaveApplication::expectedArrivalFor($absconded, '2031-06-20') === null,
    'an absconded departure drops it too');
ok(LeaveApplication::expectedArrivalFor($annual, null) === null,
    'and a blank stays blank either way');

// The three rules have to travel together: leave.js hides the fields on both
// forms, NO_RETURN_PATTERNS decides what the server stores, and a mismatch
// would mean a hidden field whose value still reached the database.
$leaveJs = (string) file_get_contents(APP_ROOT . '/assets/js/leave.js');
ok(str_contains($leaveJs, 'NO_RETURN_TYPE = /resign|terminat|abscond/i'),
    'leave.js matches the same three names the server does');
ok((bool) preg_match('/resign|terminat|abscond/i', 'Absconded')
    && LeaveApplication::NO_RETURN_PATTERNS === ['%resign%', '%terminat%', '%abscond%'],
    'and the server list holds exactly those three');

/* --------------------- 2b. hiding the field does not block saving -------- */

// leave.js DISABLES the inputs it hides, so nothing at all is posted for
// them. If validate() still demanded an expected return, Resignation,
// Terminated and Absconded records could not be created at all - the fix
// would have broken the very screen it was fixing.
echo "\n== 2b. a no-return record is still savable ==\n";

$dResign = $mkDriver('R6-06');

$validate = new ReflectionMethod(\App\Controllers\LeaveController::class, 'validate');
$validate->setAccessible(true);
$controller = (new ReflectionClass(\App\Controllers\LeaveController::class))
    ->newInstanceWithoutConstructor();

$forType = static fn (int $type, int $driver) => [
    // Validator::text() returns '' for anything that is not a string, and
    // $_POST always arrives as strings - so the fixture has to mimic a real
    // submit rather than hand over ints.
    'driver_id'             => (string) $driver,
    'leave_type_id'         => (string) $type,
    'leave_start_date'      => today(),
    'last_working_date'     => null,
    'ticket_date'           => null,
    'expected_arrival_date' => '',
];

foreach (['Resignation' => $resignation, 'Terminated' => $terminated, 'Absconded' => $absconded]
    as $name => $type) {
    $v = $validate->invoke($controller, $forType($type, $dResign));
    ok(!$v->fails(), "a $name record needs no expected return (got: "
        . ($v->firstError() ?? 'none') . ')');
}

$v = $validate->invoke($controller, $forType($annual, $dAnnual));
ok($v->fails() && isset($v->errors()['expected_arrival_date']),
    'a vacation record still demands one, so the rule did not simply vanish');

// Fail closed: an unrecognised leave type must not slip through the gap.
$v = $validate->invoke($controller, $forType(999999, $dResign));
ok($v->fails(),
    'an unknown leave type still demands it (the rule fails closed)');

// And the record itself stores NULL rather than '' - (string)null would be
// handed to a DATE column, which strict mode rejects.
// (Named distinctly: $recordId belongs to item 1 further down, which fetches
// it over HTTP.)
$noReturnRecordId = LeaveRecord::create($forType($resignation, $dResign) + [
    'leave_start_date' => today(),
]);
$stored = Database::scalar(
    'SELECT expected_arrival_date FROM leave_records WHERE id = ?', [$noReturnRecordId]
);
ok($stored === null,
    'the record stores NULL for its expected return (got ' . var_export($stored, true) . ')');
ok(LeaveRecord::find((int)$noReturnRecordId)['status'] === \App\Core\Status::ON_LEAVE,
    'and it derives as On Leave until it is closed');

/* ------------------------ 2c. a blank start falls back to LWD + 1 -------- */

echo "\n== 2c. a blank Leave Start Date is the day after the last working day ==\n";

ok(LeaveRecord::defaultStart('2026-09-29') === '2026-09-30',
    'the day after the last working day');
ok(LeaveRecord::defaultStart('2026-10-31') === '2026-11-01',
    'across a month boundary');
ok(LeaveRecord::defaultStart('2026-12-31') === '2027-01-01',
    'across a year boundary');
ok(LeaveRecord::defaultStart(null) === null && LeaveRecord::defaultStart('  ') === null,
    'with nothing to derive from, nothing is invented');
ok(LeaveRecord::defaultStart('not a date') === null,
    'an unparseable value is not turned into a guess');
ok(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) LeaveRecord::defaultStart('2026-09-29')) === 1,
    'the derived value is a plain Y-m-d date');

// The submitted payload has to be defaulted BEFORE required() reads it, or a
// blank start is rejected rather than derived. withDefaultStart() is private,
// so the rule is pinned at both ends: the model function above, and the source
// that calls it before validating.
$leaveCtrl2 = (string) file_get_contents(APP_ROOT . '/app/controllers/LeaveController.php');
ok(str_contains($leaveCtrl2, '$input = $this->withDefaultStart($_POST);'),
    'save() derives the start before it validates');
ok(str_contains($leaveCtrl2, '$old = $this->withDefaultStart($old);'),
    'and the new-leave form arrives with the field already filled');

// The reported bug: leave.js disables what it hides, then the submit handler
// walked EVERY [required] input - including the disabled one - and blocked the
// save, blaming the driver picker that was perfectly fine.
$leaveJs2 = (string) file_get_contents(APP_ROOT . '/assets/js/leave.js');
ok(str_contains($leaveJs2, 'if (input.disabled) { return; }'),
    'the submit validator skips what leave.js has disabled');
ok(str_contains($leaveJs2, "Complete the highlighted field: ' + labelText"),
    'and the toast names the field that is actually wrong');

/* ------------------------- fixture for the prefill check ----------------- */

// An application prefill supplies the last working day but NOT the start date
// - that is exactly the shape the report came from.
$dPre     = $mkDriver('R6-07');
$appPre   = Database::insert('leave_applications', [
    'driver_id'         => $dPre,
    'leave_type_id'     => $annual,
    'application_date'  => '2031-07-01',
    'last_working_date' => '2031-07-20',
    'status'            => 'approved',
    'leave_status'      => 'not_started',
]);

/* --------------- 2d. Requirement 9 withdraws the action as well ---------- */

echo "\n== 2d. a deactivated driver is offered no new leave ==\n";

$appModel2 = (string) file_get_contents(APP_ROOT . '/app/models/LeaveApplication.php');
$appsIdx2  = (string) file_get_contents(APP_ROOT . '/app/views/applications/index.php');
$appsView2 = (string) file_get_contents(APP_ROOT . '/app/views/applications/view.php');
$leaveC4   = (string) file_get_contents(APP_ROOT . '/app/controllers/LeaveController.php');

ok(str_contains($appModel2, 'd.status AS driver_status'),
    'the applications query carries the driver employment status');
ok(str_contains($appsIdx2, 'Requirement 9 wins over requirement 5'),
    'the list withdraws Apply New Leave for a deactivated driver');
ok(str_contains($appsView2, 'only offers "Apply New Leave" while its driver is still employed'),
    'and so does the application view');
ok(substr_count($leaveC4, 'Leave can only be recorded for active drivers.') >= 2,
    'the New Leave screen turns a deactivated driver away, not just the save');

// Live, and non-vacuous: shown while active, withdrawn once deactivated.
$dOff   = $mkDriver('R6-08');
$appOff = Database::insert('leave_applications', [
    'driver_id'         => $dOff,
    'leave_type_id'     => $resignation,
    'application_date'  => '2031-08-01',
    'last_working_date' => '2031-08-20',
    'status'            => 'approved',
    'leave_status'      => 'not_started',
]);

/* ==================================================== 4. Duration ========= */

echo "\n== 4. Duration (info only) ==\n";

ok(app_duration('2026-10-01', '2026-10-15') === '14 days',
    '14 days from the 1st to the 15th (got "' . app_duration('2026-10-01', '2026-10-15') . '")');
ok(app_duration('2026-10-01', null) === '—',
    'no expected return means the span is unknown');
ok(app_duration(null, '2026-10-15') === '—',
    'no anchor date means the span is unknown');
ok(app_duration('2026-10-15', '2026-10-01') === '—',
    'an arrival before the start is not a negative duration');
ok(app_duration('2026-10-01', '2026-10-01') === '0 days',
    'the two dates meeting is a zero-length span');

/* ==================================================== 7. Vacations ========= */

echo "\n== 7. Vacation Applications counts every leave type but the departures ==\n";

// A window of its own (2031) so nothing in the live register can share it.
$yFrom = '2031-06-01';
$yTo   = '2031-06-30';

$mkApp = static function (int $driver, int $type, string $lwd): int {
    return Database::insert('leave_applications', [
        'driver_id'             => $driver,
        'leave_type_id'         => $type,
        'application_date'      => '2031-05-01',
        'last_working_date'     => $lwd,
        'expected_arrival_date' => date('Y-m-d', strtotime($lwd . ' +14 days')),
        'status'                => 'pending',
        'leave_status'          => 'not_started',
    ]);
};

$mkApp($dAnnual, $annual,     '2031-06-10');   // the old rule counted only this
$mkApp($dSick,   $sick,       '2031-06-11');   // now counted
$mkApp($dTerm,   $terminated, '2031-06-12');   // a departure: still out

$summary = Dashboard::arrivalSummary($yFrom, $yTo);
$vac     = (int)$summary['totals']['vacations'];
$res     = (int)$summary['totals']['resignations'];

$annualOnly = (int)Database::scalar(
    'SELECT COUNT(*) FROM leave_applications la
       JOIN leave_types lt ON lt.id = la.leave_type_id
      WHERE la.status <> "cancelled"
        AND LOWER(lt.name) LIKE "%vacation%"
        AND la.last_working_date BETWEEN ? AND ?',
    [$yFrom, $yTo]
);

ok($annualOnly === 1, "the old Annual-Vacation-only rule would count 1 (got {$annualOnly})");
ok($vac === 2,
    'Vacation Applications counts the sick request too: ' . $vac . ' = Annual + Sick');
ok($res === 0,
    'the termination is excluded - and it is not a "%resign%" filing either (got ' . $res . ')');

$rows   = Dashboard::summaryDrivers('vacations', '2031-06-01');
$badges = array_column($rows['rows'], 'badge_number');

ok($rows['total'] === 2,
    'the drill-down lists exactly the two drivers behind the cell (got ' . $rows['total'] . ')');
ok(in_array('R6-01', $badges, true) && in_array('R6-02', $badges, true),
    'the drill-down names the vacation and the sick driver');
ok(!in_array('R6-03', $badges, true),
    'and never the departing driver - aggregate and list agree');

/* ============================================ percentages, two decimals == */

echo "\n== Percentages read at two decimals (round 6) ==\n";

ok(pct_label(5.75) === '5.75%', '5.75 stays 5.75% (got ' . pct_label(5.75) . ')');
ok(pct_label(5.70) === '5.7%', '5.70 reads 5.7% - trailing zero trimmed (got ' . pct_label(5.70) . ')');
ok(pct_label(45) === '45%', 'a whole number stays 45% (got ' . pct_label(45) . ')');
ok(pct_label(0) === '0%', 'zero is 0%, never -0% (got ' . pct_label(0) . ')');
ok(pct_label(5.678) === '5.68%', 'rounded to two decimals first (got ' . pct_label(5.678) . ')');
ok(pct_label(100) === '100%', '100 does not lose its zeros (got ' . pct_label(100) . ')');

/* ====================================== 8. Returning By Month ============ */

echo "\n== 8. Returning By Month leaves planned trips out ==\n";

// A trip that has not started has no return to make; one under way does.
$plannedTrip = $mkRecord($dSick, date('Y-m-d', strtotime('+40 days')),
    date('Y-m-d', strtotime('+55 days')), null);
$runningTrip = $mkRecord($dAnnual, date('Y-m-d', strtotime('-5 days')),
    date('Y-m-d', strtotime('+10 days')), null);

ok((string)(LeaveRecord::find($plannedTrip)['status'] ?? '') === 'planned',
    'the fixture trip is stored as Planned');

$dueBack  = LeaveRecord::expectedArrivals(
    date('Y-m-d', strtotime('-5 days')),
    date('Y-m-d', strtotime('+55 days'))
);
$dueBadges = array_column($dueBack, 'badge_number');

ok(!in_array('R6-02', $dueBadges, true),
    'a planned trip is not "due back" - the driver has not left yet');
ok(in_array('R6-01', $dueBadges, true),
    'a trip that has started is still listed');

/* ============================================ the two lists, over HTTP ==== */

echo "\n== 1 + 4 on the screens (HTTP) ==\n";

define('R6_BASE', 'http://localhost/lms');
define('R6_SLUGS', ['admin', 'hr']);

function r6_fetch(string $url, array &$jar, ?string $post = null): array
{
    $headers = ['User-Agent: Mozilla/5.0 round6-test'];
    if ($jar !== []) {
        $p = [];
        foreach ($jar as $k => $v) {
            $p[] = "$k=$v";
        }
        $headers[] = 'Cookie: ' . implode('; ', $p);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    $body = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    foreach (preg_split('/\r?\n/', substr($body, 0, $size)) as $line) {
        if (stripos($line, 'set-cookie:') === 0) {
            $pair = explode('=', explode(';', substr($line, 11), 2)[0], 2);
            $jar[trim($pair[0])] = trim($pair[1] ?? '');
        }
    }

    curl_close($ch);

    return [$code, substr($body, $size)];
}

foreach (R6_SLUGS as $slug) {
    $username = 'r6_' . $slug;
    Database::run('DELETE FROM users WHERE username = ?', [$username]);
    Database::run(
        'INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
        [$username, password_hash('test123', PASSWORD_DEFAULT), 'R6 ' . $slug, $slug, 'active']
    );
    $userId = (int)Database::scalar('SELECT id FROM users WHERE username = ?', [$username]);
    $roleId = (int)Database::scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);
}

foreach (R6_SLUGS as $slug) {
    echo "\n-- {$slug} --\n";

    $jar = [];
    [, $login] = r6_fetch(R6_BASE . '/login', $jar);
    preg_match('/name="_csrf"\s+value="([^"]+)"/', $login, $m);

    r6_fetch(R6_BASE . '/login', $jar, http_build_query([
        'username' => 'r6_' . $slug,
        'password' => 'test123',
        '_csrf'    => $m[1] ?? '',
        'redirect' => '',
    ]));

    // Item 1: the field is offered only on a record that is already back.
    // Matched on the INPUT id, not the name - the Mark Returned and Change
    // Status modals carry name="actual_arrival_date" on every page.
    [, $backEdit] = r6_fetch(R6_BASE . '/leave/' . $recordId . '/edit', $jar);
    ok(str_contains($backEdit, 'id="actual_arrival_date"'),
        'Edit Leave Record offers Actual Arrival Date on a Returned record');

    [, $awayEdit] = r6_fetch(R6_BASE . '/leave/' . $awayId . '/edit', $jar);
    ok(!str_contains($awayEdit, 'id="actual_arrival_date"'),
        'and withholds it while the driver is still away');

    // Item 4: the Duration column and the Duration field.
    [, $appsPage] = r6_fetch(R6_BASE . '/leave-applications', $jar);
    ok(str_contains($appsPage, '>Duration<'),
        'Leave Applications carries the Duration column');

    [, $recordsPage] = r6_fetch(R6_BASE . '/leave/records', $jar);
    ok(str_contains($recordsPage, '>Duration<'),
        'Leave Records carries the Duration column');

    [, $newApp] = r6_fetch(R6_BASE . '/leave-applications/new', $jar);
    ok(str_contains($newApp, 'id="daysAway"'),
        'New Leave Application offers Duration (info only)');
    ok(str_contains($newApp, 'id="expectedArrivalWrap"'),
        'and wraps the arrival field it hides for Resignation / Terminated');

    // The regression was on this screen: none of its three wrappers carried
    // the marker, so leave.js selected nothing and every field stayed put.
    // Checked against the RENDERED page, not the view source.
    [, $newLeave] = r6_fetch(R6_BASE . '/leave/new', $jar);
    ok(str_contains($newLeave, 'id="leave_type_id"'),
        'New Leave / Vacation renders the Leave Type that drives the toggle');
    ok(str_contains($newLeave, 'id="ticketDateWrap"')
        && str_contains($newLeave, 'id="expectedArrivalWrap"')
        && str_contains($newLeave, 'id="daysAwayWrap"'),
        'it marks Ticket Date, Expected Return Date and Duration for hiding');
    ok(substr_count($newLeave, 'data-no-return-wrap') === 3,
        'exactly those three (got ' . substr_count($newLeave, 'data-no-return-wrap') . ')');
    ok(str_contains($newLeave, 'assets/js/leave.js'),
        'and loads the script that hides them');

    // The reported regression: coming from "Apply New Leave" the start date
    // was blank, so submit was blocked and the toast blamed the driver picker.
    [, $linked] = r6_fetch(
        R6_BASE . '/leave/new?application_id=' . $appPre . '&driver_id=' . $dPre,
        $jar
    );
    ok(str_contains($linked, 'value="2031-07-21"'),
        'a blank Leave Start Date arrives as the day after the last working day');
    ok(str_contains($linked, 'value="2031-07-20"'),
        'and the last working date it was derived from is still on the form');

    // Requirement 9 (the chosen reading): a deactivated driver is offered no
    // new leave at all - not the button, not the screen. Shown first while
    // active so the withdrawal below cannot pass vacuously.
    [, $viewOn] = r6_fetch(R6_BASE . '/leave-applications/' . $appOff, $jar);
    ok(str_contains($viewOn, 'Apply New Leave'),
        'an approved application offers Apply New Leave while the driver is active');

    Database::run('UPDATE drivers SET status = "inactive" WHERE id = ?', [$dOff]);

    [, $viewOff] = r6_fetch(R6_BASE . '/leave-applications/' . $appOff, $jar);
    ok(!str_contains($viewOff, 'Apply New Leave'),
        'and withdraws it once the driver is deactivated');

    [$offCode, $offBody] = r6_fetch(R6_BASE . '/leave/new?driver_id=' . $dOff, $jar);
    ok($offCode === 302 && !str_contains($offBody, 'id="leaveForm"'),
        'the New Leave screen itself turns them away (HTTP ' . $offCode . ')');

    Database::run('UPDATE drivers SET status = "active" WHERE id = ?', [$dOff]);
}

/* ------------------------------------------------------------- clean up --- */

foreach (R6_SLUGS as $slug) {
    Database::run('DELETE FROM users WHERE username = ?', ['r6_' . $slug]);
}

ok((int)Database::scalar("SELECT COUNT(*) FROM users WHERE username LIKE 'r6\\_%'") === 0,
    'throwaway users removed');

register_shutdown_function(static function (): void {
    foreach (['leave_records', 'leave_applications'] as $table) {
        Database::run(
            "DELETE {$table} FROM {$table}
               JOIN drivers d ON d.id = {$table}.driver_id
              WHERE d.badge_number LIKE 'R6-%'"
        );
    }
    Database::run("DELETE FROM drivers WHERE badge_number LIKE 'R6-%'");
    Database::run("DELETE FROM branches WHERE code LIKE 'R6%'");
    Database::run("DELETE FROM agencies WHERE code LIKE 'R6%'");
    Database::run("DELETE FROM leave_types WHERE name LIKE 'R6 FIX %'");
});

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

