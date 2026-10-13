<?php
/**
 * Driver Capacity Planning - the spec's section 64 checklist (15 tests),
 * plus the section 61 leave-type rule and the normalise() guards.
 *
 * Everything it touches is a fixture named CAP FIX * and is removed at the
 * end, so the live data is left exactly as it was found.
 */
declare(strict_types=1);

require 'C:/xampp/htdocs/lms/app/bootstrap.php';

use App\Core\Database;
use App\Core\Status;
use App\Models\CapacityPlanningService as Capacity;
use App\Models\Driver;

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

/* ------------------------------------------------------------- fixtures -- */

// Self-healing start: clear leftovers from an interrupted earlier run.
foreach (['leave_records', 'leave_applications'] as $table) {
    Database::run(
        "DELETE {$table} FROM {$table}
           JOIN drivers d ON d.id = {$table}.driver_id
          WHERE d.badge_number LIKE 'CAPF-%'"
    );
}
Database::run("DELETE FROM drivers WHERE badge_number LIKE 'CAPF-%'");
Database::run("DELETE FROM branches WHERE code LIKE 'CAPF%'");
Database::run("DELETE FROM agencies WHERE code LIKE 'CAPF%'");
Database::run("DELETE FROM leave_types WHERE name LIKE 'CAP FIX %'");

$mkBranch = static function (string $code, string $name): int {
    $existing = Database::scalar('SELECT id FROM branches WHERE code = ?', [$code]);

    return $existing !== null
        ? (int)$existing
        : Database::insert('branches', [
            'code' => $code, 'branch_name' => $name, 'status' => 'active',
        ]);
};

$agencyId = Database::scalar('SELECT id FROM agencies WHERE code = ?', ['CAPF1']);
if ($agencyId === null) {
    $agencyId = Database::insert('agencies', [
        'code' => 'CAPF1', 'agency_name' => 'CAP FIX AGENCY', 'status' => 'active',
    ]);
}
$agencyId = (int)$agencyId;

$fb1 = $mkBranch('CAPF1', 'CAP FIX BRANCH ONE');
$fb2 = $mkBranch('CAPF2', 'CAP FIX BRANCH TWO');
$fb3 = $mkBranch('CAPF3', 'CAP FIX BRANCH EMPTY');   // deliberately has no drivers

$mkType = static function (string $name): int {
    $existing = Database::scalar('SELECT id FROM leave_types WHERE name = ?', [$name]);

    return $existing !== null
        ? (int)$existing
        : Database::insert('leave_types', ['name' => $name, 'status' => 'active']);
};

$typeA   = $mkType('CAP FIX ANNUAL');
$typeB   = $mkType('CAP FIX SICK');
$typeRes = $mkType('CAP FIX RESIGNATION');   // matches the engine's %resign% rule

/** @return int driver id */
$mkDriver = static function (string $badge, int $branch, int $agency): int {
    $existing = Database::scalar('SELECT id FROM drivers WHERE badge_number = ?', [$badge]);
    if ($existing !== null) {
        Database::run('UPDATE drivers SET status = "active", branch_id = ?, agency_id = ? WHERE id = ?',
            [$branch, $agency, (int)$existing]);

        return (int)$existing;
    }

    return Database::insert('drivers', [
        'badge_number'  => $badge,
        'driver_name'   => 'CAP FIX DRIVER ' . substr($badge, -2),
        'iqama_number'  => 'IQ' . $badge,
        'agency_id'     => $agency,
        'branch_id'     => $branch,
        'joining_date'  => '2020-01-01',
        'status'        => 'active',
    ]);
};

$d1 = $mkDriver('CAPF-01', $fb1, $agencyId);
$d2 = $mkDriver('CAPF-02', $fb1, $agencyId);
$d3 = $mkDriver('CAPF-03', $fb1, $agencyId);
$d4 = $mkDriver('CAPF-04', $fb1, $agencyId);
$d5 = $mkDriver('CAPF-05', $fb2, $agencyId);   // second branch: filter proof

/** @return int record id */
$mkLeave = static function (int $driver, int $type, string $start, string $expected,
    string $status = 'planned'): int {
    // Snapshot the branch the way LeaveRecord::payload() does: movement shows
    // the branch recorded ON the row, never the driver's current one (round 6,
    // item 6). Without it the column would read "—" for every fixture row,
    // while live rows always carry the snapshot.
    $branch = Database::scalar('SELECT branch_id FROM drivers WHERE id = ?', [$driver]);

    return Database::insert('leave_records', [
        'driver_id'             => $driver,
        'leave_type_id'         => $type,
        'branch_id'             => $branch !== null ? (int)$branch : null,
        'leave_start_date'      => $start,
        'expected_arrival_date' => $expected,
        'status'                => $status,
    ]);
};

// r1 is stored "on_leave": every assertion in sections 1-2 describes a trip
// that is under way, and round 6 item 3 keys Expected Returns on that stored
// status - a row left at "planned" means "has not started yet" (the state
// section 538 covers instead). r2/r3/r5 keep their derived "planned": they
// have not begun as of the injected today, which is exactly what the bridge
// section further down relies on.
$r1 = $mkLeave($d1, $typeA, '2026-10-10', '2026-10-20', 'on_leave');  // 10 -> 20 Oct
$r2 = $mkLeave($d2, $typeA, '2026-10-12', '2026-10-18');
$r3 = $mkLeave($d4, $typeB, '2026-10-14', '2026-10-16');
$r5 = $mkLeave($d5, $typeA, '2026-10-10', '2026-10-20');  // other branch

// Fixtures are removed when the script ends whatever happens - a failed
// assertion must never leave CAP FIX rows behind for the next test to trip on.
register_shutdown_function(static function (): void {
    foreach (['leave_records', 'leave_applications'] as $table) {
        Database::run(
            "DELETE {$table} FROM {$table}
               JOIN drivers d ON d.id = {$table}.driver_id
              WHERE d.badge_number LIKE 'CAPF-%'"
        );
    }
    Database::run("DELETE FROM drivers WHERE badge_number LIKE 'CAPF-%'");
    Database::run("DELETE FROM branches WHERE code LIKE 'CAPF%'");
    Database::run("DELETE FROM agencies WHERE code LIKE 'CAPF%'");
    Database::run("DELETE FROM leave_types WHERE name LIKE 'CAP FIX %'");
});

/* --------------------------------------------------------------- runs --- */

$run = static function (array $raw, ?string $today = '2026-10-01'): array {
    return Capacity::calculate(Capacity::normalise($raw), $today);
};

$byDate = static function (array $payload): array {
    return array_column($payload['days'], null, 'date');
};

$base = ['from' => '2026-10-01', 'to' => '2026-10-31', 'branch_id' => (string)$fb1];

echo "== 1. Normal vacation: 10 Oct -> 20 Oct ==\n";

$p      = $run($base);
$days   = $byDate($p);
$active = $days['2026-10-09']['active'] ?? -1;

ok(($days['2026-10-09']['on_leave'] ?? -1) === 0, '9 Oct: nobody away yet');
ok(($days['2026-10-10']['on_leave'] ?? -1) === 1, '10 Oct: leave starts, driver counted');
ok(($days['2026-10-11']['on_leave'] ?? -1) === 1, '11 Oct: still away');
ok(($days['2026-10-19']['on_leave'] ?? -1) === 1, '19 Oct: still away the day before return');
ok(($days['2026-10-20']['on_leave'] ?? -1) === 0, '20 Oct: returned - not counted');
ok(($days['2026-10-20']['returns'] ?? -1) === 1, '20 Oct: one expected return');
ok(($days['2026-10-21']['on_leave'] ?? -1) === 0, '21 Oct: still home');
ok($active === 4, "denominator is the filtered population (got {$active})");
ok($p['today_row']['active'] === 4, "today's row carries the same population (got {$p['today_row']['active']})");
ok(($days['2026-10-10']['starts'] ?? -1) === 1, '10 Oct: one leave starts (the dashboard counts this too)');
ok(($days['2026-10-14']['starts'] ?? -1) === 1, '14 Oct: one leave starts');

echo "\n== 2. Changed expected return: 20 Oct -> 25 Oct ==\n";

Database::run('UPDATE leave_records SET expected_arrival_date = ? WHERE id = ?', ['2026-10-25', $r1]);
$days = $byDate($run($base));

ok(($days['2026-10-20']['on_leave'] ?? -1) === 1, '20 Oct: still away after the change');
ok(($days['2026-10-20']['returns'] ?? -1) === 0, '20 Oct: no return any more');
ok(($days['2026-10-24']['on_leave'] ?? -1) === 1, '24 Oct: still away');
ok(($days['2026-10-25']['on_leave'] ?? -1) === 0, '25 Oct: home');
ok(($days['2026-10-25']['returns'] ?? -1) === 1, '25 Oct: the return moved with it');

Database::run('UPDATE leave_records SET expected_arrival_date = ? WHERE id = ?', ['2026-10-20', $r1]);
$days = $byDate($run($base));
ok(($days['2026-10-20']['returns'] ?? -1) === 1, 'restored: the 20 Oct return is back');

echo "\n== 4. Multiple overlapping vacations ==\n";

ok(($days['2026-10-13']['on_leave'] ?? -1) === 2, '13 Oct: two drivers away at once');
ok(($days['2026-10-14']['on_leave'] ?? -1) === 3, '14 Oct: three drivers away at once');
ok(($days['2026-10-14']['available'] ?? -1) === 1, '14 Oct: available = active - away');
ok(($days['2026-10-14']['on_leave_pct'] ?? -1) === 75.0, '14 Oct: on-leave percentage 75.0');
ok(($days['2026-10-14']['available_pct'] ?? -1) === 25.0, '14 Oct: available percentage 25.0');

echo "\n== 10. Branch filter (denominator AND numerator) ==\n";

ok(($days['2026-10-14']['active'] ?? -1) === 4, 'branch one denominator is its own drivers only');
ok(($days['2026-10-14']['on_leave'] ?? -1) === 3, 'branch one numerator counts its own leaves only');

$other = $byDate($run(['from' => '2026-10-01', 'to' => '2026-10-31', 'branch_id' => (string)$fb2]));
ok(($other['2026-10-14']['active'] ?? -1) === 1, 'branch two denominator = 1');
ok(($other['2026-10-14']['on_leave'] ?? -1) === 1, 'branch two numerator = 1');

$all = $byDate($run(['from' => '2026-10-01', 'to' => '2026-10-31']));
ok(($all['2026-10-14']['active'] ?? -1) > 5, 'without a branch filter the whole company is the denominator');
ok(($all['2026-10-14']['on_leave'] ?? -1) >= 4, 'without a branch filter every away driver counts');

echo "\n== 11. Agency filter (denominator AND numerator) ==\n";

$byAgency = $byDate($run(['from' => '2026-10-01', 'to' => '2026-10-31', 'agency_id' => (string)$agencyId]));
ok(($byAgency['2026-10-14']['active'] ?? -1) === 5, 'agency denominator = 5 fixture drivers');
ok(($byAgency['2026-10-14']['on_leave'] ?? -1) === 4, 'agency numerator = 4 away (both branches)');

echo "\n== 61. Leave type filter shows the effect, not a pretend world ==\n";

$typed = $run($base + ['leave_type_id' => (string)$typeA]);
$tDay  = $byDate($typed)['2026-10-14'] ?? [];

ok(($tDay['on_leave'] ?? -1) === 2, 'annual leave only: 2 away on 14 Oct');
ok(($tDay['away'] ?? -1) === 3, 'every leave still counted as away');
ok(($tDay['available'] ?? -1) === 1, 'available still subtracts the other leave type');
$qualityInfo = array_filter($typed['quality'], static fn (array $q): bool => $q['state'] === 'info');
ok($qualityInfo !== [], 'the page says the leave type filter is active');

echo "\n== 22. Drill-down: drivers behind one date ==\n";

$rows = Capacity::driversAwayOn('2026-10-14', Capacity::normalise($base), '2026-10-01');
ok(count($rows) === 3, 'three drivers on 14 Oct (got ' . count($rows) . ')');
$names = array_column($rows, 'driver_name');
ok(in_array('CAP FIX DRIVER 01', $names, true), 'driver 01 is listed');
ok(in_array('CAP FIX DRIVER 04', $names, true), 'driver 04 is listed');
ok(!in_array('CAP FIX DRIVER 05', $names, true), 'the other-branch driver is not listed');
ok(isset($rows[0]['expected_arrival_date']), 'the row carries the leave facts');

echo "\n== 8. Overdue follows the existing leave-record rules ==\n";

// The engine must agree with Status::derive() evaluated on that same date.
ok(Status::derive('2026-09-01', '2026-09-20', null, false, '2026-10-01') === Status::OVERDUE,
    'the app itself calls this state overdue');

$r4 = $mkLeave($d3, $typeA, '2026-09-01', '2026-09-20');   // past expected, no arrival
$days = $byDate($run($base));

ok(($days['2026-10-05']['on_leave'] ?? -1) === 1, '5 Oct: the overdue driver is still away');
ok(($days['2026-10-05']['overdue'] ?? -1) === 1, '5 Oct: flagged overdue');
ok(($days['2026-10-31']['overdue'] ?? -1) === 1, '31 Oct: still overdue - no invented return');
ok($run($base)['today_row']['overdue'] === 1, "today's row carries the overdue case");

Database::run('DELETE FROM leave_records WHERE id = ?', [$r4]);

echo "\n== 23/24. Forecast keeps the plan, Actual shows the reality ==\n";

// Under way on purpose: the record carries a real arrival, so it is not a
// trip that has "not started" (round 6, item 3 keeps those out of returns).
$r6 = $mkLeave($d3, $typeB, '2026-10-05', '2026-10-10', 'on_leave');
Database::run('UPDATE leave_records SET actual_arrival_date = ? WHERE id = ?', ['2026-10-13', $r6]);

$fDays = $byDate($run($base + ['mode' => 'forecast']));
$aDays = $byDate($run($base + ['mode' => 'actual']));

ok(($fDays['2026-10-11']['on_leave'] ?? -1) === 1, 'forecast: home on the expected day (only 10-20 Oct away)');
ok(($fDays['2026-10-10']['returns'] ?? -1) === 0,
    'forecast: an already-returned driver is not an expected return');
ok(($aDays['2026-10-11']['on_leave'] ?? -1) === 2, 'actual: still away - the driver came back late');
ok(($aDays['2026-10-12']['overdue'] ?? -1) === 1, 'actual: overdue between expected and real arrival');
ok(($aDays['2026-10-13']['returns'] ?? -1) === 1, 'actual: the return lands on the real date');
ok(($aDays['2026-10-13']['on_leave'] ?? -1) === 2, 'actual: home on the real arrival date');

Database::run('DELETE FROM leave_records WHERE id = ?', [$r6]);

echo "\n== Today outside the range: the live cards still answer ==\n";

// The KPI cards always answer for TODAY, even when the window starts after
// it or ends before it - and the answer must not depend on where the window
// sits. (Range 30 Sep -> 30 Nov with today 29 Sep used to clip every trip
// that started before 30 Sep and report On Leave = 0 while the forecast
// below it showed a dozen drivers away.)
$inside = $run(['from' => '2026-10-01', 'to' => '2026-10-31', 'branch_id' => (string) $fb1], '2026-10-12');
$late   = $run(['from' => '2026-10-13', 'to' => '2026-12-12', 'branch_id' => (string) $fb1], '2026-10-12');
$early  = $run(['from' => '2026-08-01', 'to' => '2026-10-11', 'branch_id' => (string) $fb1], '2026-10-12');

$insideToday = (int) $inside['today_row']['on_leave'];
ok($insideToday >= 1, "today (12 Oct) has drivers away (got {$insideToday})");
ok((int) $late['today_row']['on_leave'] === $insideToday,
    'range starting AFTER today keeps today\'s On Leave (got ' . (int) $late['today_row']['on_leave'] . ')');
ok((int) $early['today_row']['on_leave'] === $insideToday,
    'range ending BEFORE today keeps today\'s On Leave (got ' . (int) $early['today_row']['on_leave'] . ')');
ok((int) $late['today_row']['active'] === (int) $inside['today_row']['active']
    && (int) $early['today_row']['active'] === (int) $inside['today_row']['active'],
    'the active population does not depend on where the range sits');
ok(isset($late['days']) && $late['days'] !== [], 'the forecast rows still cover the chosen window');

echo "\n== 3/5/6/7. Resignation last working dates ==\n";

$mkAppFor = static function (int $driver, string $status, string $lwd) use ($typeRes): int {
    return Database::insert('leave_applications', [
        'driver_id'             => $driver,
        'leave_type_id'         => $typeRes,
        'application_date'      => '2026-09-25',
        'last_working_date'     => $lwd,
        'expected_arrival_date' => '2026-09-25',
        'status'                => $status,
    ]);
};

$mkApp = static function (string $status, string $lwd) use ($mkAppFor, $d3): int {
    return $mkAppFor($d3, $status, $lwd);
};

// Round 6, item 5: a PENDING resignation shrinks the forecast now, so the
// pending filing goes on its OWN driver (CAPF-04) - that keeps CAPF-03's
// approved 15 Oct exit readable instead of both landing on one person and
// the earliest date winning. Cancelled and rejected still change nothing.
$apps = [
    $mkAppFor($d4, 'pending',   '2026-10-12'),
    $mkAppFor($d4, 'cancelled', '2026-10-12'),
    $mkAppFor($d4, 'rejected',  '2026-10-12'),
];

$days = $byDate($run($base));
ok(($days['2026-10-13']['active'] ?? -1) === 3,
    'a PENDING filing shrinks the population, cancelled and rejected do not (got '
    . ($days['2026-10-13']['active'] ?? -1) . ')');
ok(($days['2026-10-12']['active'] ?? -1) === 4,
    'the driver is still inside the population ON the last working date');
ok(($days['2026-10-12']['resignations'] ?? -1) === 2,
    'pending and rejected filings count as expected resignations (cancelled excluded)');
ok(($days['2026-10-13']['resignations'] ?? -1) === 0,
    'the filing lands on its last working date, not the day after');

$apps[] = $mkApp('approved', '2026-10-15');

$p    = $run($base);
$days = $byDate($p);

ok(($days['2026-10-15']['active'] ?? -1) === 3, '15 Oct: the second driver is still inside the population');
ok(($days['2026-10-16']['active'] ?? -1) === 2, '16 Oct: the approved filing takes them out too');
ok(($days['2026-10-15']['resignations'] ?? -1) === 1, 'the resignation event is dated on the last working date');
ok($p['forecast']['expected_resignations'] === 3,
    'every non-cancelled filing counts: pending + rejected + approved (got '
    . (int) $p['forecast']['expected_resignations'] . ')');

$exitEvent = array_values(array_filter(
    $p['movement'],
    static fn (array $e): bool => $e['date'] === '2026-10-15' && $e['impact'] === '-1 Active'
));
ok($exitEvent !== [], 'capacity movement lists the exit as -1 Active');
ok($exitEvent !== [] && $exitEvent[0]['event'] === 'Resignation last working date',
    'the event is named after the last working date');

// Round 6, item 5: the PENDING filing is an exit event as well - the forecast
// deducts it from Active the same way, from the day after its LWD.
$pendingExit = array_values(array_filter(
    $p['movement'],
    static fn (array $e): bool => $e['date'] === '2026-10-12' && $e['impact'] === '-1 Active'
));
ok($pendingExit !== [],
    'a pending resignation filing is an exit event too (round 6, item 5)');

foreach ($apps as $appId) {
    Database::run('DELETE FROM leave_applications WHERE id = ?', [$appId]);
}

echo "\n== 9. Not Returned is closed, not forecast leave ==\n";

$r7 = $mkLeave($d3, $typeA, '2026-10-01', '2026-10-31', 'not_returned');
$p  = $run($base);
$days = $byDate($p);

ok(($days['2026-10-20']['on_leave'] ?? -1) === 0, 'a closed Not Returned case is not forecast leave');
ok(($days['2026-10-20']['active'] ?? -1) === 4, 'the driver still counts as active population');
$closed = array_values(array_filter(
    $p['quality'],
    static fn (array $q): bool => $q['label'] === 'Closed records excluded'
));
ok($closed !== [] && (int)$closed[0]['count'] >= 1, 'the quality panel reports the excluded record');

Database::run('DELETE FROM leave_records WHERE id = ?', [$r7]);

echo "\n== 49. Invalid date ranges are reported, not counted ==\n";

$r8 = $mkLeave($d3, $typeB, '2026-10-10', '2026-10-05');   // expected BEFORE start
$p    = $run($base);
$days = $byDate($p);

$invalid = array_values(array_filter(
    $p['quality'],
    static fn (array $q): bool => $q['label'] === 'Invalid leave date ranges'
));
ok($invalid !== [] && (int)$invalid[0]['count'] >= 1, 'the invalid range is reported');
ok(($days['2026-10-12']['on_leave'] ?? -1) === 2, 'the broken record counts for zero days');

Database::run('DELETE FROM leave_records WHERE id = ?', [$r8]);

echo "\n== 12/13. Monthly average and peak come from the daily rows ==\n";

$p     = $run($base);
$oct   = array_values(array_filter(
    $p['days'],
    static fn (array $d): bool => substr((string)$d['date'], 0, 7) === '2026-10'
));
$month = array_values(array_filter(
    $p['monthly'],
    static fn (array $m): bool => $m['month'] === '2026-10'
))[0] ?? [];

$pcts    = array_column($oct, 'on_leave_pct');
$counts  = array_column($oct, 'on_leave');
$actives = array_column($oct, 'active');

ok(count($oct) === 31, 'every day of the month is present (got ' . count($oct) . ')');
ok($month['avg_on_leave_pct'] === round(array_sum($pcts) / count($pcts), 2),
    'monthly average = the average of the daily percentages');
ok($month['avg_on_leave'] === (int) round(array_sum($counts) / count($counts)),
    'monthly average on leave = the average of the daily counts');
ok($month['avg_active'] === (int) round(array_sum($actives) / count($actives)),
    'monthly average active = the average of the daily populations');
ok($month['peak_on_leave'] === max($counts), 'monthly peak = the maximum daily count');
ok($month['peak_on_leave_pct'] === max($pcts), 'monthly peak % = the maximum daily percentage');
ok($month['returns'] === array_sum(array_column($oct, 'returns')),
    'monthly returns = the sum of the daily returns');
ok($month['starts'] === 3, 'monthly Leave Starts counts the three fixture trips (got ' . $month['starts'] . ')');
ok($month['starts'] === array_sum(array_column($oct, 'starts')),
    'monthly leave starts = the sum of the daily starts');

echo "\n== 12b. Leave slots: how many more may go before the ceiling ==\n";

// One read of leave_percentage, carried on the payload so the column, its
// header and the export can never name two different ceilings.
$targetPct = (float) ($p['leave_target_pct'] ?? 0);
ok($targetPct > 0, 'the payload carries the configured leave ceiling (got ' . $targetPct . ')');

$expCeiling = $targetPct > 0 && (int) $month['avg_active'] > 0
    ? (int) floor(($targetPct / 100) * (int) $month['avg_active'])
    : null;

ok(($month['leave_ceiling'] ?? null) === $expCeiling,
    "the ceiling is $targetPct% of the month's average active fleet, rounded down"
    . ' (got ' . var_export($month['leave_ceiling'] ?? null, true)
    . ', expected ' . var_export($expCeiling, true) . ')');

if ($expCeiling !== null) {
    ok(($month['leave_slots'] ?? null) === max(0, $expCeiling - (int) $month['peak_on_leave']),
        "slots left = ceiling minus the month's PEAK on leave, floored at 0"
        . ' (got ' . var_export($month['leave_slots'] ?? null, true)
        . ', expected ' . max(0, $expCeiling - (int) $month['peak_on_leave']) . ')');

    ok((int) ($month['leave_slots'] ?? -1) >= 0,
        'a month already at or over its ceiling reports 0, never a minus');

    // The peak is what makes the figure safe to act on: an average-based
    // answer would still leave room to approve on the busiest day of the month.
    $avgBased = max(0, $expCeiling - (int) $month['avg_on_leave']);
    ok((int) $month['leave_slots'] <= $avgBased,
        'measured against the peak, so it is never more optimistic than the average'
        . ' (' . (int) $month['leave_slots'] . ' vs ' . $avgBased . ')');

    ok((int) $month['leave_slots'] <= $expCeiling,
        'and never exceeds the ceiling itself');
}

// The branch-filtered fixture above holds a handful of drivers, so 7% of it
// floors to zero: that proves the degenerate path but never makes the peak
// subtraction bite. Run the same month across the WHOLE fleet so the ceiling
// is a real number and the column has something to subtract from.
$wide     = $run(['from' => '2026-10-01', 'to' => '2026-10-31']);
$wideMon  = array_values(array_filter(
    $wide['monthly'],
    static fn (array $m): bool => $m['month'] === '2026-10'
))[0] ?? [];
$widePct  = (float) ($wide['leave_target_pct'] ?? 0);
$wideCeil = $widePct > 0 && (int) ($wideMon['avg_active'] ?? 0) > 0
    ? (int) floor(($widePct / 100) * (int) $wideMon['avg_active'])
    : null;

ok($wideCeil !== null && $wideCeil > 0,
    'unfiltered, the ceiling is a real number (got ' . var_export($wideCeil, true)
    . ' across ' . (int) ($wideMon['avg_active'] ?? 0) . ' active drivers)');
ok(($wideMon['leave_ceiling'] ?? null) === $wideCeil,
    'the fleet-wide ceiling uses the same calculation');
ok(($wideMon['leave_slots'] ?? null) === max(0, $wideCeil - (int) $wideMon['peak_on_leave']),
    'fleet-wide slots = ceiling minus peak on leave'
    . ' (' . var_export($wideMon['leave_slots'] ?? null, true)
    . ' = ' . $wideCeil . ' - ' . (int) $wideMon['peak_on_leave'] . ')');
ok((int) ($wideMon['leave_slots'] ?? -1) >= 0, 'and still never negative');
ok((int) ($wideMon['leave_slots'] ?? -1) <= $wideCeil,
    'nor above the ceiling it is measured from');

echo "\n== 17. Forecast cards aggregate the same daily rows ==\n";

$fc = $p['forecast'];

ok($fc['days'] === 31, 'the forecast covers every day in range (got ' . $fc['days'] . ')');
ok($fc['avg_active'] === (int) round(array_sum($actives) / count($actives)),
    'average active = the mean of the daily populations');
ok($fc['avg_on_leave'] === (int) round(array_sum($counts) / count($counts)),
    'average on leave = the mean of the daily counts');
ok($fc['avg_active'] === (int) $fc['avg_active']
    && $fc['avg_on_leave'] === (int) $fc['avg_on_leave'],
    'averages are whole numbers - no decimals on the cards');
ok($fc['avg_on_leave_pct'] === round(array_sum($pcts) / count($pcts), 2),
    'average on-leave % = the mean of the daily percentages');
ok($fc['peak_on_leave'] === max($counts), 'peak = the maximum daily count');
ok($fc['peak_on_leave_pct'] === max($pcts), 'peak % = the maximum daily percentage');
ok($fc['expected_returns'] === array_sum(array_column($oct, 'returns')),
    'expected returns = the sum of the daily returns');
ok($fc['avg_on_leave_pct'] === $month['avg_on_leave_pct']
    && $fc['peak_on_leave'] === $month['peak_on_leave'],
    'the forecast cards foot with the monthly table for a one-month range');
ok(!isset($p['kpis']['expected_returns']),
    'period figures are kept out of the current-status KPIs');

echo "\n== Returns: dashboard worklist vs this page's plan ==\n";

// One driver back on time (twice), one past due who never came back - the
// cases that make the dashboard's "Expected Returns" and this page's
// "Expected Returns" part company.
$r9  = $mkLeave($d3, $typeA, '2026-10-01', '2026-10-05', 'returned');
$r11 = $mkLeave($d3, $typeA, '2026-10-09', '2026-10-11', 'returned');
$r10 = $mkLeave($d4, $typeA, '2026-10-01', '2026-10-08', 'on_leave');
Database::run('UPDATE leave_records SET actual_arrival_date = ? WHERE id = ?', ['2026-10-05', $r9]);
Database::run('UPDATE leave_records SET actual_arrival_date = ? WHERE id = ?', ['2026-10-11', $r11]);

$rp   = $run($base, '2026-10-12');           // 12 Oct: r10 is already past due
$rb   = $rp['returnBridge'];
$plan = (int)$rp['forecast']['expected_returns'];

// Only r1 is under way; r2 (starts 12 Oct) and r3 (14 Oct) are still
// "planned" in the fixture, and round 6 item 3 keeps a planned trip out of
// every arrival count. So the worklist holds r1 (due back 20 Oct) plus the
// past-due r10 that never landed.
ok($rb['dashboard_arrivals'] === 2,
    'the dashboard worklist counts 2 (got ' . $rb['dashboard_arrivals'] . ')');
ok($rb['shared_ahead'] === 1, 'one return is still ahead on both screens');
ok($rb['confirmed'] === 2, 'two returns are already recorded - the dashboard drops them');
ok($rb['overdue_unreturned'] === 1, 'one past-due return never happened - the dashboard keeps it');
ok($plan === 1, "this page's plan counts only returns still ahead (got {$plan})");
ok($plan === $rb['shared_ahead'],
    'plan = still ahead; already-returned drivers are excluded from Expected Returns');
ok($rb['dashboard_arrivals'] === $rb['shared_ahead'] + $rb['overdue_unreturned'],
    'dashboard = still ahead + past due');
ok($rb['dashboard_arrivals'] !== $plan, 'the two screens genuinely differ for this scenario');

// Unfiltered, the mirror must be the dashboard's own number - same predicate,
// same rows, so the card can be checked against it directly.
$mirror = $run(['from' => '2026-10-01', 'to' => '2026-10-31'], '2026-10-12');
$trend  = \App\Models\Dashboard::arrivalsTrend('2026-10-01', '2026-10-31');
ok($mirror['returnBridge']['dashboard_arrivals'] === array_sum($trend['arrivals']),
    'unfiltered, the mirror equals the dashboard number for the same range');

foreach ([$r9, $r10, $r11] as $fixtureId) {
    Database::run('DELETE FROM leave_records WHERE id = ?', [$fixtureId]);
}

echo "\n== Resignations: dashboard filings vs this page's exits ==\n";

$ra1 = $mkApp('pending',   '2026-10-20');
$ra2 = $mkApp('approved',  '2026-10-25');
$ra3 = $mkApp('cancelled', '2026-10-22');
$ra4 = $mkApp('rejected',  '2026-10-26');

$rq  = $run($base, '2026-10-01');
$rb2 = $rq['resignBridge'];
$planRes = (int)$rq['forecast']['expected_resignations'];

ok($rb2['dashboard_applications'] === 3,
    'the dashboard filing list counts 3 (got ' . $rb2['dashboard_applications'] . ') - cancelled excluded');
ok($rb2['pending'] === 1, 'one is still pending');
ok($rb2['rejected'] === 1, 'one was rejected - the dashboard keeps it');
ok($planRes === 3, "the card counts every non-cancelled filing (got {$planRes})");
ok($planRes === $rb2['dashboard_applications'],
    'the card equals the dashboard total - pending, approved and rejected together');
ok($rb2['pending'] + $rb2['approved_filed'] + $rb2['rejected'] === $planRes,
    'total = pending + approved + rejected');

// Unfiltered, the mirror must be the dashboard's own total for the range.
$rm = $run(['from' => '2026-10-01', 'to' => '2026-10-31'], '2026-10-01');
$dashTotal = (int)\App\Models\Dashboard::arrivalSummary('2026-10-01', '2026-10-31')['totals']['resignations'];
ok($rm['resignBridge']['dashboard_applications'] === $dashTotal,
    'unfiltered, the mirror equals the dashboard number for the same range');

foreach ([$ra1, $ra2, $ra3, $ra4] as $fixtureApp) {
    Database::run('DELETE FROM leave_applications WHERE id = ?', [$fixtureApp]);
}

echo "\n== Dashboard reconciliation (the KPI bridge) ==\n";

// Recreate the one case where the two screens disagree: a driver who left
// before today, due back today, whose return has not been recorded yet.
Database::run(
    'UPDATE leave_records SET status = "on_leave", leave_start_date = ?, expected_arrival_date = ? WHERE id = ?',
    ['2026-09-25', '2026-10-01', $r1]
);

$bridgePayload = $run(['from' => '2026-10-01', 'to' => '2026-10-01', 'branch_id' => (string)$fb1]);
$bridge        = $bridgePayload['bridge'];

ok(($bridgePayload['today_row']['on_leave'] ?? -1) === 0,
    'the forecast counts the expected return day as the return day');
ok(($bridge['status_away'] ?? -1) === 1, 'the dashboard rule still counts that driver as away');
ok(($bridge['due_back_unconfirmed'] ?? -1) === 1, 'the whole difference is the unconfirmed return');
ok(
    (int)$bridge['status_away']
        === (int)$bridgePayload['today_row']['on_leave'] + (int)$bridge['due_back_unconfirmed'],
    'the two screens reconcile exactly: dashboard = this page + unconfirmed returns'
);

Database::run(
    'UPDATE leave_records SET status = "planned", leave_start_date = ?, expected_arrival_date = ? WHERE id = ?',
    ['2026-10-10', '2026-10-20', $r1]
);
$restored = $run(['from' => '2026-10-01', 'to' => '2026-10-01', 'branch_id' => (string)$fb1]);
ok($restored['bridge']['status_away'] === 0, 'the fixture is restored');

echo "\n== 14. Zero active drivers: no division by zero ==\n";

$zero = $byDate($run(['from' => '2026-10-01', 'to' => '2026-10-31', 'branch_id' => (string)$fb3]));
$bad  = array_filter(
    $zero,
    static fn (array $d): bool => $d['active'] !== 0 || $d['on_leave_pct'] !== 0.0 || $d['available_pct'] !== 0.0
);

ok($zero !== [], 'a branch without drivers still produces every calendar day');
ok($bad === [], 'every percentage is 0 with nobody to divide (no PHP error)');

echo "\n== Planned leaves count as unavailable capacity ==\n";

// A planned trip is the SAME future absence as one that has started: it
// subtracts from Available, adds to On leave, appears in the drill-down with
// a "Planned" chip and in the movement - while today's cards stay untouched,
// because nobody is away on a trip that has not begun.
$planDriver = null;
$planRec    = null;

// Its own branch: the numbers below have to describe THIS driver, not
// whatever other sections of the file have in flight at the same time.
$fbLwd = $mkBranch('CAPF4', 'CAP FIX BRANCH LWD');
$planDriver = $mkDriver('CAPF-06', $fbLwd, $agencyId);
$planRec    = $mkLeave($planDriver, $typeA, '2026-11-05', '2026-11-10');  // planned by default

$november  = ['from' => '2026-11-01', 'to' => '2026-11-30', 'branch_id' => (string)$fbLwd];
$nov       = $byDate($run($november, '2026-11-01'));
$novFull   = $run($november, '2026-11-01');

ok(($nov['2026-11-04']['on_leave'] ?? -1) === 0, '4 Nov: the planned trip has not started');
ok(($nov['2026-11-05']['on_leave'] ?? -1) === 1, '5 Nov: a planned trip counts as away');
ok(($nov['2026-11-09']['away'] ?? -1) >= 1, '9 Nov: still counted as away (day before return)');
ok(
    ($nov['2026-11-09']['available'] ?? -1)
        === ($nov['2026-11-09']['active'] ?? -1) - ($nov['2026-11-09']['away'] ?? -1),
    'Available = Active - Away, with the planned trip inside it'
);
ok(($nov['2026-11-10']['on_leave'] ?? -1) === 0, '10 Nov: the expected return is the return day');
ok(($novFull['today_row']['on_leave'] ?? -1) === 0,
    'today (1 Nov) is untouched - a future trip is not away today');

$planHit = null;

foreach (($novFull['details']['2026-11-07'] ?? []) as $row) {
    if ((int)($row['driver_id'] ?? 0) === $planDriver) {
        $planHit = $row;
    }
}

ok($planHit !== null, 'the drill-down lists the planned driver for 7 Nov');
ok($planHit !== null && ($planHit['status'] ?? '') === 'planned'
    && ($planHit['status_label'] ?? '') === 'Planned',
    'the drill-down chip says Planned, not a bare "On leave"');

$startMoves = array_filter(
    $novFull['movement'],
    static fn (array $m): bool => ($m['event'] ?? '') === 'Leave start'
        && ($m['badge'] ?? '') === 'CAPF-06'
);
$returnMoves = array_filter(
    $novFull['movement'],
    static fn (array $m): bool => stripos((string)($m['event'] ?? ''), 'return') !== false
        && ($m['badge'] ?? '') === 'CAPF-06'
);
ok($startMoves !== [], 'Capacity Movement shows the planned Leave start');
ok($returnMoves === [],
    'and NO return event: a trip that has not begun has no arrival to expect (round 6, item 3)');

// Round 6, item 6: every movement row carries the three facts behind it.
$mvRow = $novFull['movement'][0] ?? [];
ok(($mvRow['branch'] ?? null) !== null
    && ($mvRow['leave_type'] ?? null) !== null
    && ($mvRow['status'] ?? null) !== null,
    'movement rows carry Branch, Leave Type and Status (got '
    . json_encode(array_intersect_key($mvRow, array_flip(['branch', 'leave_type', 'status']))) . ')');
ok(($mvRow['branch'] ?? '') === 'CAP FIX BRANCH LWD',
    'the branch is the snapshot on the row (got "' . ($mvRow['branch'] ?? '') . '")');
ok(($mvRow['leave_type'] ?? '') === 'CAP FIX ANNUAL',
    'the leave type comes with it (got "' . ($mvRow['leave_type'] ?? '') . '")');
ok(($mvRow['status'] ?? '') === 'planned',
    'and the status of the row it came from (got "' . ($mvRow['status'] ?? '') . '")');

$plannedLine = array_values(array_filter(
    $novFull['quality'],
    static fn (array $q): bool => ($q['label'] ?? '') === 'Planned leaves counted as unavailable'
));
ok($plannedLine !== [], 'the Forecast Data Quality panel says planned leaves are counted');
ok($plannedLine !== [] && (int)$plannedLine[0]['count'] >= 5,
    'it reports the planned trip-days in this range (5-9 Nov, got '
    . ($plannedLine[0]['count'] ?? 'none') . ')');

// Round 6, item 3: the same planned trip has no arrival to EXPECT, so it is
// absent from the return counts on both screens - while staying an absence
// above (the five trip-days just asserted).
ok(($nov['2026-11-10']['returns'] ?? -1) === 0,
    'the day it was due back counts no expected return (round 6, item 3)');
ok(($novFull['forecast']['expected_returns'] ?? -1) === 0,
    'the forecast card shows no return for a trip that has not begun');
$novArrivals = \App\Models\Dashboard::summaryDrivers('arrivals', '2026-11-05');
ok(!in_array('CAPF-06', array_column($novArrivals['rows'], 'badge_number'), true),
    'the dashboard Expected Returns list leaves the planned trip out too');

echo "\n== Deactivation with a last working date keeps the driver in the totals ==\n";

// Requirement 6: a driver deactivated WITH an end date is not erased from
// the total driver count for dates on which they were still working - they
// count through the date and drop out the day after, exactly like an
// approved resignation filing's last working date.
//
// Stretch the trip past the end date first: the driver leaves before it
// ends, so days after their last working date must fall out of BOTH the
// denominator and the numerator instead of showing a departing driver as
// away forever.
Database::run(
    'UPDATE leave_records SET expected_arrival_date = "2026-11-20" WHERE id = ?',
    [$planRec]
);

$before = $byDate($run($november, '2026-11-01'));

Database::run(
    'UPDATE drivers SET status = "inactive", last_working_date = "2026-11-12" WHERE id = ?',
    [$planDriver]
);

$after = $byDate($run($november, '2026-11-01'));

ok(($after['2026-11-12']['active'] ?? -1) === ($before['2026-11-12']['active'] ?? -2),
    'still counted in the head-count on their last working date');
ok(($after['2026-11-13']['active'] ?? -1) === ($before['2026-11-13']['active'] ?? -2) - 1,
    'gone from the head-count the day after it');
ok(($after['2026-11-07']['on_leave'] ?? -1) === 1,
    'their leave before the last working date still counts as away');
ok(($after['2026-11-13']['on_leave'] ?? -1) === 0,
    'their leave after it is outside the population - left out of the numerator too');

$onDay  = (int)Database::scalar(
    'SELECT COUNT(*) FROM drivers d WHERE ' . Driver::activeOnSql('d') . ' AND d.id = ?',
    ['2026-11-12', $planDriver]
);
$dayAfter = (int)Database::scalar(
    'SELECT COUNT(*) FROM drivers d WHERE ' . Driver::activeOnSql('d') . ' AND d.id = ?',
    ['2026-11-13', $planDriver]
);
ok($onDay === 1, 'activeOnSql counts a deactivated driver ON the last working date');
ok($dayAfter === 0, 'and not from the day after');

// A deactivation with NO date on file behaves exactly as it did before the
// column existed: excluded straight away, never invented an end date.
$plainDriver = $mkDriver('CAPF-07', $fbLwd, $agencyId);
$plainBefore = $byDate($run($november, '2026-11-01'));

Database::run('UPDATE drivers SET status = "inactive" WHERE id = ?', [$plainDriver]);

$plainAfter = $byDate($run($november, '2026-11-01'));

ok(($plainAfter['2026-11-13']['active'] ?? -1) === ($plainBefore['2026-11-13']['active'] ?? -2) - 1,
    'a driver with no date is excluded immediately (no number moves for them)');
ok(
    (int)Database::scalar(
        'SELECT COUNT(*) FROM drivers d WHERE ' . Driver::activeOnSql('d') . ' AND d.id = ?',
        ['2026-11-13', $plainDriver]
    ) === 0,
    'activeOnSql keeps a no-date deactivation out of every day'
);

// Leave the fixtures as they were found for the sections that follow.
Database::run(
    'UPDATE drivers SET status = "active", last_working_date = NULL WHERE badge_number LIKE "CAPF-%"'
);

echo "\n== 16/22. Expected Leave: applications not yet a record ==\n";

// Its own branch and its own month: nothing else in this file is anywhere
// near December 2026, so every number below describes these applications.
$fbExp   = $mkBranch('CAPF5', 'CAP FIX BRANCH EXPECTED');
$expA    = $mkDriver('CAPF-08', $fbExp, $agencyId);    // pending   -> expected
$expB    = $mkDriver('CAPF-09', $fbExp, $agencyId);    // approved  -> expected (type B)
$expX    = $mkDriver('CAPF-10', $fbExp, $agencyId);    // rejected + cancelled -> nothing
$expOut  = $mkDriver('CAPF-11', $fb1, $agencyId);      // other branch -> filtered
$expLink = $mkDriver('CAPF-12', $fbExp, $agencyId);    // linked    -> its record shows Leave start

$mkApp = static function (int $driver, string $lwd, string $status, int $type): int {
    return Database::insert('leave_applications', [
        'driver_id'             => $driver,
        'leave_type_id'         => $type,
        'application_date'      => '2026-11-20',
        'last_working_date'     => $lwd,
        'expected_arrival_date' => date('Y-m-d', strtotime($lwd . ' +10 days')),
        'status'                => $status,
        'leave_status'          => 'not_started',
    ]);
};

$appPending   = $mkApp($expA, '2026-12-09', 'pending', $typeA);    // expected 10 Dec
$appApproved  = $mkApp($expB, '2026-12-14', 'approved', $typeB);   // approved + not started -> 15 Dec
$appRejected  = $mkApp($expX, '2026-12-19', 'rejected', $typeA);   // never leaves
$appCancelled = $mkApp($expX, '2026-12-20', 'cancelled', $typeA);  // never leaves
$appOther     = $mkApp($expOut, '2026-12-11', 'pending', $typeA);  // outside the branch
$appLinked    = $mkApp($expLink, '2026-12-16', 'approved', $typeA);
$linkedRec    = $mkLeave($expLink, $typeA, '2026-12-17', '2026-12-27');
Database::run(
    'UPDATE leave_records SET leave_application_id = ? WHERE id = ?',
    [$appLinked, $linkedRec]
);

// The legacy pair the user caught in the wild: a driver whose record already
// covers the trip, with the application NOT linked to it. One trip, one event
// - the record's "Leave start" wins and no Expected Leave may appear.
$expDup  = $mkDriver('CAPF-13', $fbExp, $agencyId);
$appDup  = $mkApp($expDup, '2026-12-04', 'pending', $typeA);   // would be 5 Dec
$dupRec  = $mkLeave($expDup, $typeA, '2026-12-05', '2026-12-08');  // covers it, unlinked
                                                     // (ends early so it cannot
                                                     //  cloud 10 Dec assertions)

// A pending RESIGNATION is included like any other application (requirement
// 2) - it keeps its Expected Resignations column (on the LWD) and its
// Expected Leave the day after.
//
// Production never stores an expected return for a no-return type:
// LeaveApplication::expectedArrivalFor() drops the field (round 6, item 2),
// so the generic $mkApp value has to be cleared here for the fixture to say
// what the real data says - and what the capacity movement now does with it.
$expRes    = $mkDriver('CAPF-14', $fbExp, $agencyId);
$appResign = $mkApp($expRes, '2026-12-17', 'pending', $typeRes);
Database::run('UPDATE leave_applications SET expected_arrival_date = NULL WHERE id = ?', [$appResign]);

// Requirement 4: approved but the leave itself has already begun - the record
// tells that story, so it must not appear as an expectation (would be 20 Dec).
$expGone  = $mkDriver('CAPF-15', $fbExp, $agencyId);
$appBegun = $mkApp($expGone, '2026-12-19', 'approved', $typeA);
Database::run('UPDATE leave_applications SET leave_status = "on_leave" WHERE id = ?', [$appBegun]);

$december = ['from' => '2026-12-01', 'to' => '2026-12-31', 'branch_id' => (string)$fbExp];
$dec      = $run($december, '2026-12-01');
$decDays  = $byDate($dec);

$expEvents = array_values(array_filter(
    $dec['movement'],
    static fn (array $m): bool => ($m['event'] ?? '') === 'Expected Leave'
));

ok(count($expEvents) === 2,
    'pending and approved-not-started count; the resignation is carried by its exit (got '
    . count($expEvents) . ')');

$byBadge = array_column($expEvents, 'date', 'badge');
ok(($byBadge['CAPF-08'] ?? '') === '2026-12-10',
    'a pending application is expected the day AFTER its last working date');
ok(($byBadge['CAPF-09'] ?? '') === '2026-12-15',
    'approved + Not Started counts (requirement 1)');
ok(!isset($byBadge['CAPF-10']),
    'cancelled and rejected are the only approval statuses excluded (requirement 3)');
ok(!isset($byBadge['CAPF-11']), 'another branch is filtered out');
ok(!isset($byBadge['CAPF-12']),
    'a linked application is shown by its record instead - never twice');
ok(!isset($byBadge['CAPF-13']),
    'an UNLINKED record already covering the trip wins too - no Leave start / Expected Leave pair');
ok(!isset($byBadge['CAPF-14']),
    'one application, one movement row: a resignation raises no Expected Leave beside its exit');
$capf14Moves = array_values(array_filter(
    $dec['movement'],
    static fn (array $m): bool => ($m['badge'] ?? '') === 'CAPF-14'
));
ok(count($capf14Moves) === 1
    && ($capf14Moves[0]['event'] ?? '') === 'Resignation last working date'
    && ($capf14Moves[0]['date'] ?? '') === '2026-12-17'
    && ($capf14Moves[0]['impact'] ?? '') === '-1 Active',
    'its single movement row is the exit, dated on the last working date');
ok(!isset($byBadge['CAPF-15']),
    'approved whose leave already began is excluded (requirement 4)');
ok(($decDays['2026-12-17']['resignations'] ?? -1) === 1,
    'the resignation keeps its Expected Resignations column, on its LWD');
ok(($decDays['2026-12-18']['expected_leave'] ?? -1) === 1,
    'plus its Expected Leave the day after (requirement 2 - column, not movement)');

$dupStart = array_values(array_filter(
    $dec['movement'],
    static fn (array $m): bool => ($m['event'] ?? '') === 'Leave start'
        && ($m['badge'] ?? '') === 'CAPF-13'
));
ok($dupStart !== [], 'the legacy pair still shows its record Leave start');
ok(($decDays['2026-12-05']['starts'] ?? -1) === 1 && ($decDays['2026-12-05']['expected_leave'] ?? -1) === 0,
    '5 Dec counts the trip once: as a leave start, never as expected leave too');

usort($expEvents, static fn (array $a, array $b): int => [$a['date'], $a['driver']]
    <=> [$b['date'], $b['driver']]);
ok(($expEvents[0]['impact'] ?? '') === '+1 Expected',
    'the impact reads as expected, never as taken');

ok(($decDays['2026-12-09']['expected_leave'] ?? -1) === 0,
    'the last working date itself counts nothing');
ok(($decDays['2026-12-10']['expected_leave'] ?? -1) === 1,
    'the day after the LWD counts the expected leave');
ok(($decDays['2026-12-15']['expected_leave'] ?? -1) === 1,
    'the approved, not-started application fills its own day (requirement 1)');
ok(($decDays['2026-12-20']['expected_leave'] ?? -1) === 0,
    'approved-but-already-began leaves no entry on its event day (requirement 4)');
ok(($decDays['2026-12-21']['expected_leave'] ?? -1) === 0,
    'rejected/cancelled applications leave no entry on their event day either');

// Always-project: the pending trip counts as away from its expected leave
// day through the day before its expected return (spec section 22).
ok(($decDays['2026-12-09']['on_leave'] ?? -1) === 0,
    'the day before the expected leave still counts nobody');
ok(($decDays['2026-12-10']['on_leave'] ?? -1) === 1,
    'the expected leave day counts the pending trip as away');
ok(($decDays['2026-12-10']['available'] ?? -1) === ($decDays['2026-12-10']['active'] ?? -2) - 1,
    'Available drops by exactly the projected trip');
ok(($decDays['2026-12-18']['on_leave'] ?? -1) >= 1,
    'still away on the last day before the expected return (19 Dec)');
ok((int)($dec['projected_days'] ?? -1) >= 10,
    'the payload states how many trip-days were projected');
ok((int)($dec['projected_today'] ?? -1) === 0,
    'today (1 Dec) carries no projection: the live card is untouched');
$decDetail10 = array_column($dec['details']['2026-12-10'] ?? [], 'status_label', 'badge_number');
ok(($decDetail10['CAPF-08'] ?? '') === 'Expected Return',
    'the drill-down labels the projected driver Expected Return');
$decDetail19 = array_column($dec['details']['2026-12-19'] ?? [], 'status_label', 'badge_number');
ok(!isset($decDetail19['CAPF-08']),
    'and the projection stops on the expected return day');

$decMonth = null;
foreach ($dec['monthly'] as $monthRow) {
    if ((string)$monthRow['month'] === '2026-12') {
        $decMonth = $monthRow;
    }
}
ok($decMonth !== null && (int)$decMonth['expected_leave'] === 3,
    'the monthly summary sums the expected leaves');

[$dailyHeaders, $dailyRows] = Capacity::exportDaily($dec);
ok(in_array('Expected Leave', $dailyHeaders, true), 'the daily export carries the column');
ok($dailyRows !== [] && count($dailyHeaders) === count($dailyRows[0]),
    'daily export rows match the header width');

[$monthlyHeaders, $monthlyRows] = Capacity::exportMonthly($dec);
ok(in_array('Expected Leave', $monthlyHeaders, true), 'the monthly export carries the column');
ok($monthlyRows !== [] && count($monthlyHeaders) === count($monthlyRows[0]),
    'monthly export rows match the header width');

/* ---- the leave-slots column: screen, export, and the unconfigured case -- */

$slotIdx = null;
foreach ($monthlyHeaders as $i => $header) {
    if (str_starts_with((string) $header, 'Leave Slots')) {
        $slotIdx = $i;
    }
}

ok($slotIdx !== null, 'the monthly export carries the leave-slots column');
ok($slotIdx !== null
    && $monthlyHeaders[$slotIdx]
       === 'Leave Slots to ' . pct_label((float) $dec['leave_target_pct']),
    'and it names the ceiling it applies, with exactly one percent sign'
    . ' (got ' . var_export($monthlyHeaders[$slotIdx] ?? null, true) . ')');
ok($slotIdx !== null && !str_contains((string) $monthlyHeaders[$slotIdx], '%%'),
    'and never doubles the percent sign');
ok($slotIdx !== null && array_key_exists($slotIdx, $monthlyRows[0]),
    'every exported row fills it');

// Nothing is reported as limited until a limit exists: with no ceiling
// configured the header goes neutral and the cell empties, so a missing
// setting can never be misread as "0 approvals left".
$unconfigured = $dec;
$unconfigured['leave_target_pct'] = 0.0;
$unconfigured['monthly'] = array_map(
    static function (array $m): array {
        $m['leave_slots'] = null;

        return $m;
    },
    $dec['monthly']
);
[$bareHeaders, $bareRows] = Capacity::exportMonthly($unconfigured);

$bareIdx = null;
foreach ($bareHeaders as $i => $header) {
    if (str_starts_with((string) $header, 'Leave Slots')) {
        $bareIdx = $i;
    }
}

ok($bareIdx !== null && $bareHeaders[$bareIdx] === 'Leave Slots',
    'without a configured ceiling the header drops the percentage'
    . ' (got ' . var_export($bareHeaders[$bareIdx] ?? null, true) . ')');
ok($bareIdx !== null && $bareRows !== [] && $bareRows[0][$bareIdx] === null,
    'and the cell is empty rather than a 0 that reads as "no approvals left"');
ok(count($bareHeaders) === count($bareRows[0]),
    'the neutral header keeps the export rectangular');

// The screen is checked from source rather than over HTTP: this suite is a
// unit test, and the header/colspan pair is exactly the thing that drifts.
$capView = (string) file_get_contents(APP_ROOT . '/app/views/capacity/index.php');
$from    = strpos($capView, '$slotsLabel');
$seg     = $from === false ? '' : substr($capView, $from, 6000);

preg_match('~<thead[^>]*>(.*?)</thead>~s', $seg, $th);
$headCells = isset($th[1]) ? substr_count($th[1], '<th') : -1;
preg_match('~<tr><td colspan="(\d+)">~', $seg, $cs);
$colspan = isset($cs[1]) ? (int) $cs[1] : -1;

ok($from !== false, 'the monthly summary builds its column label from the setting');
ok($headCells === 11, "the monthly table now has 11 columns (got $headCells)");
ok($colspan === 11, "the empty state spans 11 columns (got $colspan)");
ok($headCells === $colspan, 'header count and empty-state colspan agree');

$decTypeA = $run($december + ['leave_type_id' => (string)$typeA], '2026-12-01');
$typeFiltered = array_values(array_filter(
    $decTypeA['movement'],
    static fn (array $m): bool => ($m['event'] ?? '') === 'Expected Leave'
));
ok(count($typeFiltered) === 1,
    'a leave-type filter narrows the events (got ' . count($typeFiltered) . ')');
// Projections follow the same filter rule as records: matching types count
// as on leave, EVERY expectation still subtracts from Available (spec
// section 61, parallel rule).
$decTA = $byDate($decTypeA);
ok(($decTA['2026-12-15']['on_leave'] ?? -1) === 1,
    'type filter: only matching expectations count as on leave');
ok(($decTA['2026-12-15']['away'] ?? -1) === 2,
    'type filter: Available still subtracts every expectation (got '
    . ($decTA['2026-12-15']['away'] ?? -1) . ', on_leave '
    . ($decTA['2026-12-15']['on_leave'] ?? -1) . ')');

// Every event family follows the filter now. The exits were the one that did
// not: filtering to Annual Vacation still listed CAPF-14's resignation in a
// table whose Leave Type column said "Resignation" beside it.
$exitEvent = static fn (array $m): bool => ($m['event'] ?? '') === 'Resignation last working date';
ok(array_filter($dec['movement'], $exitEvent) !== [],
    'sanity: the December fixture does produce a resignation exit');
ok(array_filter($decTypeA['movement'], $exitEvent) === [],
    'a leave-type filter no longer lists resignation exits (got '
    . count(array_filter($decTypeA['movement'], $exitEvent)) . ')');

// The population walk is deliberately NOT filtered - Active still drops for
// those exits - so only the row goes, never the head-count.
ok(($decTA['2026-12-18']['active'] ?? -1) === ($decDays['2026-12-18']['active'] ?? -2),
    'type filter: Active is unchanged, so the exit still lands in the head-count (filtered '
    . ($decTA['2026-12-18']['active'] ?? 'missing') . ', unfiltered '
    . ($decDays['2026-12-18']['active'] ?? 'missing') . ')');

echo "\n== drill-down badges share Capacity Movement's palette ==\n";
$funcsSrc  = (string)file_get_contents(APP_ROOT . '/app/helpers/functions.php');
$capJs     = (string)file_get_contents(APP_ROOT . '/assets/js/capacity.js');
$serviceSrc = (string)file_get_contents(APP_ROOT . '/app/models/CapacityPlanningService.php');

// The modal used to hardcode on_leave as text-bg-warning while
// status_badge() - which Capacity Movement renders - used text-bg-primary.
// Same status, two colours, depending on which screen you were looking at.
ok(str_contains($funcsSrc, 'function status_badge_class'),
    'the palette lives in one helper both sides read');
ok(str_contains($capJs, "on_leave: 'primary'"),
    "the modal's fallback map agrees: on_leave is primary, not warning");
ok(str_contains($capJs, 'row.badge_class || badgeClass'),
    'the modal prefers the tone the server sent over any local map');
ok(str_contains($serviceSrc, "'badge_class'"),
    'the drill-down rows carry that tone from the service');
ok(str_contains($capJs, 'rounded-pill') && str_contains($capJs, 'status-badge'),
    'the modal chip is shaped like the server-rendered badge, not a plain pill');
ok(str_contains((string)file_get_contents(APP_ROOT . '/app/views/capacity/index.php'), 'modal-xl'),
    'the drill-down is wide enough for the status text');

ok(!array_key_exists('expected_leave', $dec['forecast']),
    'the forecast KPI cards gained no new figure - expectations stay off the cards');

$viewSrc = (string)file_get_contents(APP_ROOT . '/app/views/capacity/index.php');
ok(substr_count($viewSrc, '<th class="text-end">Expected Leave</th>') === 2,
    'both the day-by-day and the monthly table carry the column');

echo "\n== 17/47. Filter normalisation guards ==\n";

$swap = Capacity::normalise(['from' => '2026-11-05', 'to' => '2026-10-05']);
ok($swap['from'] === '2026-10-05' && $swap['to'] === '2026-11-05', 'an inverted range is swapped');

$long = Capacity::normalise(['from' => '2026-01-01', 'to' => '2027-12-31']);
ok($long['clamped'] === true, 'a range beyond the cap is flagged as clamped');
ok(((int)floor((strtotime($long['to']) - strtotime($long['from'])) / 86400) + 1) === 400,
    'the cap is ' . \App\Models\CapacityPlanningService::MAX_DAYS . ' days');

$mode = Capacity::normalise(['mode' => 'banana']);
ok($mode['mode'] === 'forecast', 'an unknown mode falls back to forecast');

$defaults = Capacity::normalise([]);
ok($defaults['from'] === date('Y-m-01'), 'default window opens on the first of this month');
ok($defaults['to'] === date('Y-m-t', strtotime('+2 months')), 'default window closes two months ahead');

$monthFix = Capacity::normalise(['daily_month' => '2026-99', 'from' => '2026-10-01', 'to' => '2026-10-31']);
ok($monthFix['daily_month'] === '2026-10', 'an impossible month falls back inside the range');

echo "\n== 15. Permissions gate the page ==\n";

$map = require APP_ROOT . '/app/middleware/route-permissions.php';

ok(($map['web']['GET /capacity-planning'] ?? null) === 'reports.capacity',
    'the page route declares reports.capacity');
ok(($map['api']['GET /capacity/drivers'] ?? null) === 'reports.capacity',
    'the drill-down route declares reports.capacity');
ok(permission_for_route('web', 'GET', 'capacity-planning') === ['reports.capacity'],
    'the permission resolves for the real path');
ok(in_array('reports.capacity', permission_keys(), true),
    'reports.capacity is in the permission catalogue');

$savedUser = $_SESSION['user'] ?? null;
$adminId   = (int)Database::scalar('SELECT id FROM users WHERE role = "admin" ORDER BY id LIMIT 1');

// A signed-in user whose role holds nothing at all is refused.
$_SESSION['user'] = ['id' => 999999999];
forget_permissions();
ok(can('reports.capacity') === false, 'a user without reports.capacity cannot open it');
$needed = permission_for_route('web', 'GET', 'capacity-planning');
ok($needed !== null && can_any($needed) === false, 'the gate evaluates that user to a denial');

// The administrator, who holds every permission, is let through.
$_SESSION['user'] = ['id' => $adminId];
forget_permissions();
$needed = permission_for_route('web', 'GET', 'capacity-planning');
ok($needed !== null && can_any($needed) === true, 'the administrator can open it');

if ($savedUser === null) {
    unset($_SESSION['user']);
} else {
    $_SESSION['user'] = $savedUser;
}
forget_permissions();

echo "\n== sidebar entry ==\n";

// navigation() drops anything the signed-in user may not see, so show it as
// the administrator and put the session back afterwards.
$_SESSION['user'] = ['id' => $adminId];
forget_permissions();

$nav = \App\Core\View::navigation();
$capacitySection = array_values(array_filter(
    $nav,
    static fn (array $section): bool => $section['label'] === 'Planning & Capacity'
));
ok($capacitySection !== [], 'the Planning & Capacity section exists');
ok($capacitySection !== [] && ($capacitySection[0]['items'][0]['url'] ?? '') === 'capacity-planning',
    'it links to capacity-planning');
ok($capacitySection !== [] && ($capacitySection[0]['items'][0]['permission'] ?? '') === 'reports.capacity',
    'the entry declares its permission, not a role');

// Administrator-only rule, straight from the grants table: no other role may
// hold reports.capacity (granted in role_definitions()).
$holders = array_column(
    Database::all(
        'SELECT DISTINCT r.slug AS slug
           FROM role_permissions rp
           JOIN roles r       ON r.id = rp.role_id
           JOIN permissions p ON p.id = rp.permission_id
          WHERE p.permission_key = "reports.capacity"'
    ),
    'slug'
);
sort($holders);
ok($holders === ['admin'], 'only the Administrator role holds reports.capacity',
    $holders === [] ? 'none' : implode(', ', $holders));

// The Dashboard section carries an empty label, so find both by item key.
$dashboardIndex = null;
$capacityIndex  = null;
foreach ($nav as $i => $section) {
    foreach ($section['items'] as $item) {
        if (($item['key'] ?? '') === 'dashboard') {
            $dashboardIndex = $i;
        }
        if (($item['key'] ?? '') === 'capacity-planning') {
            $capacityIndex = $i;
        }
    }
}
ok($capacityIndex !== null && $dashboardIndex !== null && $capacityIndex === $dashboardIndex + 1,
    'the section sits immediately below Dashboard');

echo "\n== Chart series selector ==\n";

$capacityViewSrc = (string) file_get_contents(dirname(__DIR__) . '/app/views/capacity/index.php');
$capacityJsSrc   = (string) file_get_contents(dirname(__DIR__) . '/assets/js/capacity.js');
$capacityCtrlSrc = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/CapacityController.php');

ok(str_contains($capacityViewSrc, 'id="capacityChartMetric"'),
    'the chart card offers a series dropdown');
ok(str_contains($capacityViewSrc, 'value="returns"'),
    'the returns series is offered');
ok(!str_contains($capacityViewSrc, 'value="apps"'),
    'the removed Vacation / Exit Applications option is gone');
ok(str_contains($capacityViewSrc, 'capacityChartTitleText'),
    'the chart title can follow the selection');
ok(str_contains($capacityJsSrc, 'METRICS'), 'the script knows the selectable series');
ok(str_contains($capacityJsSrc, 'capacityChartMetric'), 'the script follows the dropdown');
ok(str_contains($capacityJsSrc, 'metric.yTitle'), 'the axis label follows the selected series');
ok(str_contains($capacityJsSrc, 'capacityChartTitleText'),
    'the script renames the chart title');
ok(str_contains($capacityCtrlSrc, "'expectedReturns'")
    && !str_contains($capacityCtrlSrc, "'terminations'"),
    'the payload carries the offered series only');
ok(str_contains($capacityCtrlSrc, "dy_size'] ?? 10")
    && str_contains($capacityCtrlSrc, '? $dySize : 10'),
    'the day-by-day table defaults to 10 rows like Capacity Movement');

/* ---- the tooltip also reports the headcount the percentage stands for ---- */

ok((bool) preg_match("/'onLeave'\s*=>\s*\[\]/", $capacityCtrlSrc),
    'the chart payload declares an on-leave headcount series');
ok(str_contains($capacityCtrlSrc, "\$chart['onLeave'][]"),
    'and fills it for every day alongside the percentage');
ok(str_contains($capacityJsSrc, 'data.onLeave || []'),
    'the on-leave tooltip reads that series');
ok(str_contains($capacityJsSrc, 'return [line,'),
    'and returns TWO lines, so the count lands under the percentage');
ok(str_contains($capacityJsSrc, 'metric.tooltip(ctx.parsed.y, ctx.dataIndex)'),
    'the callback is handed the day index it needs to find the count');
ok(str_contains($capacityJsSrc, "(n === 1 ? '' : 's')"),
    'the headcount is pluralised rather than always printed as "driver(s)"');
ok(str_contains($capacityJsSrc, 'counts[index] === null')
    && str_contains($capacityJsSrc, "typeof counts[index] === 'undefined'"),
    'a payload without the count degrades to the percentage instead of throwing');

// The percentage alone is what the reader could not use on its own: without
// the fleet size, 6.19% could be any number of people. The two lines together
// are the whole point of the change.
ok(str_contains($capacityJsSrc, "' of drivers on leave'")
    && str_contains($capacityJsSrc, "' on leave'"),
    'the tooltip words both the share and the count');

/* ------------------------------------- Forecast Data Quality: admin only -- */

echo "\n== Forecast Data Quality is administrator-only ==\n";

ok(str_contains($capacityViewSrc, 'if (is_administrator())'),
    'the quality panel is wrapped in an administrator check');
ok(str_contains($capacityViewSrc, 'endif; // is_administrator()'),
    'the check closes around the whole panel');

// Functional probe of the check itself (no login needed - the helper reads
// the session's user id straight from user_roles).
$probeSaved = $_SESSION['user'] ?? null;

$_SESSION['user'] = ['id' => $adminId];
forget_permissions();
ok(is_administrator() === true, 'the administrator sees the quality panel');

unset($_SESSION['user']);
forget_permissions();
ok(is_administrator() === false, 'a signed-out visitor does not');

$probeUser = 'zqual_' . date('His');
Database::run(
    'INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
    [$probeUser, password_hash('probe', PASSWORD_DEFAULT), 'Quality Probe', 'viewer', 'active']
);
$probeId    = (int) Database::scalar('SELECT id FROM users WHERE username = ?', [$probeUser]);
$viewerRole = (int) Database::scalar('SELECT id FROM roles WHERE slug = "viewer"');
Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$probeId, $viewerRole]);

$_SESSION['user'] = ['id' => $probeId];
forget_permissions();
ok(is_administrator() === false, 'a signed-in non-administrator does not');

Database::run('DELETE FROM user_roles WHERE user_id = ?', [$probeId]);
Database::run('DELETE FROM users WHERE id = ?', [$probeId]);

if ($probeSaved === null) {
    unset($_SESSION['user']);
} else {
    $_SESSION['user'] = $probeSaved;
}
forget_permissions();

if ($savedUser === null) {
    unset($_SESSION['user']);
} else {
    $_SESSION['user'] = $savedUser;
}
forget_permissions();

/* ================== On Leave card: View All =============================== */

echo "\n== On Leave card offers View All ==\n";

// Read from source: this suite is a unit test, and the wiring that matters is
// which class the link carries and which listener answers it.
$onLeaveView  = (string) file_get_contents(APP_ROOT . '/app/views/capacity/index.php');
$kpiCardSrc   = (string) file_get_contents(APP_ROOT . '/app/views/partials/kpi-card.php');
$capJsSrc2    = (string) file_get_contents(APP_ROOT . '/assets/js/capacity.js');
$dashboardSrc = (string) file_get_contents(APP_ROOT . '/app/views/dashboard/index.php');

// Double-quoted deliberately with \$ - a bare $today here would interpolate
// as an undefined variable in THIS file and match nothing.
ok(str_contains($onLeaveView, "'drill' => (string)(\$today ?? '')"),
    'the On Leave card passes today as its drill date');
ok(str_contains($onLeaveView, "'linkLabel' => 'View All'"),
    'and labels the link View All');
ok(str_contains($onLeaveView, 'id="capDriversModal"'),
    'the modal it opens is the same one the day rows use');
ok(str_contains($onLeaveView, '$card[\'drill\'] ?? \'\''),
    'the card loop forwards drill (so the other cards stay plain)');

// The link must reuse capacity's ONE delegated listener rather than adding a
// second list mechanism with its own rules.
ok(str_contains($kpiCardSrc, 'cap-drill'),
    'kpi-card renders .cap-drill when a drill date is given');
ok(str_contains($kpiCardSrc, 'data-date='),
    'and carries the date the modal opens on');
ok(str_contains($capJsSrc2, "closest('.cap-drill')"),
    'capacity.js already handles .cap-drill (one delegated listener)');
ok(str_contains($kpiCardSrc, "e(\$linkLabel)"),
    'the link text still renders through e(), never raw');

// Every other caller keeps its plain link: the href branch is untouched, so
// the Leave Dashboard's KPI markup cannot have changed.
ok(str_contains($kpiCardSrc, "elseif (\$href !== '')"),
    'the plain href branch is still there for every other card');
ok(str_contains($kpiCardSrc, "\$hint !== '' || \$href !== '' || \$drill !== ''"),
    'the foot still renders for hint-only cards (no link needed)');
ok(preg_match("/'drill'\s*=>/", $dashboardSrc) !== 1,
    'the Leave Dashboard passes no drill date, so its cards are unchanged');

/* ============ Drivers-on-leave modal: columns and who is in it =========== */

echo "\n== Drivers on leave modal ==\n";

$capView2 = (string) file_get_contents(APP_ROOT . '/app/views/capacity/index.php');
$capJs3   = (string) file_get_contents(APP_ROOT . '/assets/js/capacity.js');
$capSvc   = (string) file_get_contents(APP_ROOT . '/app/models/CapacityPlanningService.php');

// Item 3: the column is named for what it is, and Actual is gone - a driver
// with an arrival on record is already out of this list, so the cell could
// only ever have been a dash.
ok(str_contains($capView2, '<th>Expected Return</th>'),
    'the modal column reads Expected Return');
ok(!str_contains($capView2, '<th>Actual</th>'),
    'the Actual column is gone from the modal');
ok(!str_contains($capJs3, 'row.actual_arrival_date ? fmtDate'),
    'and capacity.js no longer renders an Actual cell');
ok(substr_count($capJs3, 'fmtDate(row.expected_arrival_date)') === 1,
    'the Expected Return cell is rendered exactly once');

// Item 3, third part: a driver who has an actual arrival must not be listed.
// endFor() is what enforces it - forecast mode takes the EARLIER of actual and
// expected, so an early return closes the away window immediately.
ok(str_contains($capSvc, 'min($actual, $expected)'),
    'endFor() ends the away window at the earlier of actual and expected');
ok(str_contains($capSvc, "\$actual !== null"),
    'so a recorded arrival is honoured before the plan is');
$awayNever = <<<'PHP'
                if ($end !== null && $end !== self::OPEN && $end <= $start) {
                    continue;  // zero-length: returned the day it started
PHP;
ok(str_contains($capSvc, trim($awayNever)),
    'a trip that ended on or before its start is skipped entirely');

// The synthetic projection chip is the one renamed.
ok(str_contains($capSvc, "'status_label'          => 'Expected Return'"),
    'the projected-absence chip reads Expected Return');

/* ============ Expected Returns / Resignations cards: a MODAL ============= */

echo "\n== The two range cards open a modal instead of navigating ==\n";

// Neither card may still point at a page: the reader is standing in front of
// a forecast, and a navigation loses the range, the filters and the
// comparison they were making.
ok(!str_contains($onLeaveView, "'href' => url('leave/returns')"),
    'Expected Returns no longer navigates to the returns board');
ok(str_contains($onLeaveView, "'capKpi'   => 'expected_arrivals'"),
    'it opens its rows through the shared KPI endpoint instead');
ok(str_contains($onLeaveView, "'capKpi'   => 'resignation_applications'"),
    'Expected Resignations opens its rows the same way');
ok(substr_count($onLeaveView, "'capRange' => (string)(\$filters['from'] ?? '')")
    === 2,
    'both carry the page range, so the modal lists THAT window');

// The trigger has to be capacity's own, because dashboard.js - which owns
// .sd-kpi - is not loaded on this page.
ok(str_contains($kpiCardSrc, 'cap-kpi'),
    'kpi-card renders .cap-kpi for a page that does not load dashboard.js');
ok(str_contains($kpiCardSrc, 'data-kpi-range'),
    'and carries the range alongside the key');
ok(str_contains($capJsSrc2, "closest('.cap-kpi')"),
    'capacity.js owns the listener that opens it');
ok(str_contains($capJsSrc2, 'function kpiLoad'),
    'and fetches, renders, pages and exports the rows itself');

// The modal is on the page, and it must stay OUT of the column-preference
// machinery: applyAll() writes display:'' back over header cells, which is
// exactly what left the dashboard's Date header standing over shifted rows.
ok(str_contains($onLeaveView, 'id="capKpiModal"'),
    'the range-card modal is on the page');
ok(str_contains($onLeaveView, 'id="capKpiTable" data-dd-skip'),
    'it opts out of table-prefs so its header stays under its control');
ok(str_contains($onLeaveView, "url('api/dashboard/kpi-drivers')"),
    'and points at the same endpoint the Leave Dashboard uses');

/* ---------------------- one footer shape, every modal -------------------- */

// Both Capacity modals and the dashboard's drill modal must read the same
// way: pager leading a justify-content-between footer as prev / label / next,
// action buttons grouped after it. Three loose children pushed Excel into the
// middle of the footer, which is what made the two screens look unrelated.
echo "\n== All three drill modals share one footer shape ==\n";

// A PHP comment may sit between the footer and the pager - what may NOT is
// another element, because then the pager would no longer lead the footer.
ok(preg_match('~<div class="modal-footer justify-content-between">\s*'
    . '(?:<\?php.*?\?>\s*)*<div[^>]*id="capKpiPager"~s', $onLeaveView) === 1,
    'the range-card pager leads its footer');
$capPrev = (int) strpos($onLeaveView, 'id="capKpiPrev"');
$capLbl  = (int) strpos($onLeaveView, 'id="capKpiPageLabel"');
$capNext = (int) strpos($onLeaveView, 'id="capKpiNext"');
ok($capPrev > 0 && $capPrev < $capLbl && $capLbl < $capNext,
    'and reads prev, label, next - the same order as the dashboard');
$capExp = (int) strpos($onLeaveView, 'id="capKpiExport"');
ok($capExp > $capNext, 'with Excel grouped after it, on the right');

$dayP   = (int) strpos($onLeaveView, 'id="capModalPager"');
$dayExp = (int) strpos($onLeaveView, 'id="capModalExport"');
ok($dayP > 0 && $dayExp > $dayP,
    'the day-row modal groups Excel and Close on the right as well');
ok(!str_contains($onLeaveView, 'id="capModalCount"'),
    'and its duplicate "Showing 1-10 of 25" line is gone - one list, one count');

/* --------------- the day-row modal matches its two neighbours ------------ */

echo "\n== Drivers on leave: #, sub-heading, Excel ==\n";

ok(str_contains($onLeaveView, '<th class="text-center">#</th>'),
    'the table carries a # index column, like the other two');
ok(str_contains($onLeaveView, 'id="capModalMeta"'),
    'and a sub-heading where the date used to sit inside the title');
ok(!str_contains($onLeaveView, 'id="capModalDate"'),
    'the bracketed date is out of the title');
ok(str_contains($onLeaveView, 'id="capModalExport"'),
    'and the modal offers Excel');

$capJsSrc3 = (string) file_get_contents(APP_ROOT . '/assets/js/capacity.js');
ok(str_contains($capJsSrc3, "(offset + i + 1)"),
    'the # cell is numbered across pages, not per page');
ok(str_contains($capJsSrc3, "' row(s)'"),
    "the pager reads \"Page 1 of 3 - 25 row(s)\", the wording the others use");
ok(str_contains($capJsSrc3, 'function setDayExport'),
    'the Excel button is withdrawn while a day loads');
ok(str_contains($capJsSrc3, "id='capModalMeta'") === false
   || str_contains($capJsSrc3, "getElementById('capModalMeta')"),
    'and the sub-heading is written when the rows land');

$capApiSrc = (string) file_get_contents(APP_ROOT . '/app/api/CapacityApi.php');
// Single quotes: a double-quoted string here would interpolate $_GET from the
// TEST's own request, and the needle would never match the source.
ok(str_contains($capApiSrc, '($_GET[\'export\'] ?? \'\') === \'xls\''),
    'the endpoint takes an explicit export flag');
ok(str_contains($capApiSrc, "require_permission('reports.export')"),
    'the download demands reports.export');
ok(str_contains($capApiSrc, "'#', 'Driver', 'Badge', 'Leave Type', 'Start'"),
    'and writes the same seven columns the modal draws');

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);




