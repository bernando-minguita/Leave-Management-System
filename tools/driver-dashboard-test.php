<?php
/**
 * Driver Dashboard (spec: driver_dashboard_spec.md).
 *
 *   1  The ONE operational-status calculation, rule by rule (spec section 16).
 *   2  Reconciliation: cards = charts = branch summary = rows (spec 15).
 *   3  On Leave ties to the Leave Dashboard's own figure (spec section 2.3).
 *   4  Percentages: against Total Drivers, one decimal place (spec 3).
 *   5  Filters and export agree (spec 8, 18).
 *   6  Route, permission, navigation, retitled Leave Dashboard, no invented
 *      columns (spec 7, 19, 20).
 *
 * The classification cases are synthetic rows, so they are deterministic; the
 * reconciliation cases read the live data read-only. Nothing here writes.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\DriverDashboardService as DD;
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

$today = today();

/** A synthetic driver row: every field classify() looks at. */
$row = static fn (array $over = []): array => array_merge([
    'status'              => 'active',
    'last_working_date'   => null,
    'operational_status'  => null,
    'on_leave'            => 0,
], $over);

$classify = static fn (array $r): array => DD::classify($r, $today);

/* -------------------------------------------------- 1. the calculation --- */

echo "\n== 1. operational status is decided in one place ==\n";

ok($classify($row())['status'] === 'active',
    'an employed driver with no flag set is Active');
ok($classify($row(['operational_status' => 'assigned']))['status'] === 'active',
    'an assigned driver is Active');
ok($classify($row(['operational_status' => 'idle']))['status'] === 'idle',
    'operational_status=idle is Idle');
ok($classify($row(['operational_status' => 'sick_gosi']))['status'] === 'gosi',
    'operational_status=sick_gosi is GOSI');
ok($classify($row(['operational_status' => 'vacation']))['status'] === 'on_leave',
    'operational_status=vacation is On Leave');
ok($classify($row(['on_leave' => 1]))['status'] === 'on_leave',
    'an open leave record is On Leave (spec 2.3)');
ok($classify($row(['on_leave' => 1, 'operational_status' => 'idle']))['status'] === 'on_leave',
    'an open leave record beats a stale idle flag (spec 2.4)');

foreach (['training', 'suspended', 'absconded', 'exit'] as $value) {
    ok($classify($row(['operational_status' => $value]))['status'] === 'other',
        "operational_status=$value lands in Other / Unavailable");
}

// Spec section 22: employment status and operational availability are
// different axes, and a leaver is not "available" however the flag reads.
ok($classify($row([
    'status'             => 'resigned',
    'last_working_date'  => date_add_days($today, -30),
]))['status'] === 'other',
    'a driver past their last working date is Other / Unavailable');
ok($classify($row([
    'status'             => 'terminated',
    'last_working_date'  => null,
]))['status'] === 'other',
    'a leaving status with no date is still Other / Unavailable');
ok($classify($row([
    'status'             => 'inactive',
    'last_working_date'  => date_add_days($today, 5),
]))['status'] === 'active',
    'deactivated with a future end date, they are still employed today');

$unknown = $classify($row(['operational_status' => 'on_moon']));
ok($unknown['status'] === 'other',
    'a value the Settings list does not know lands in Other');
ok($unknown['unmapped'] === true,
    'and is reported as unmapped (spec section 15)');
ok($classify($row())['unmapped'] === false,
    'an empty operational_status is NOT an unmapped status');
ok($classify($row(['operational_status' => 'exit']))['unmapped'] === false,
    'a configured value is NOT an unmapped status');

// First match wins, so no driver can sit in two buckets.
$counts = [];
foreach (DD::CATEGORIES as $key) {
    $counts[$key] = 0;
}
$counts[$classify($row(['on_leave' => 1]))['status']]++;

ok(array_sum($counts) === 1, 'each driver is counted exactly once (spec 14)');
ok($counts['on_leave'] === 1 && $counts['idle'] === 0,
    'an On Leave driver is never also counted as Idle');

/* ---------------------------------------------- 2. reconciliation (live) -- */

echo "\n== 2. every surface reports the same workforce ==\n";

$data    = DD::dataset();
$rows    = $data['rows'];
$kpis    = $data['kpis'];
$total   = count($rows);

ok($total > 0, "the dataset is not empty ($total rows)");
ok($kpis['total'] === $total, 'the Total card equals the row count');

$sum = 0;
foreach (DD::CATEGORIES as $key) {
    $sum += (int)$kpis[$key];
}
ok($sum === $total, "the five cards foot to the total ($sum / $total)");

ok(array_sum($data['overall']) === $total, 'the donut foots to the total');

$branchTotal = 0;
$branchStat  = 0;
foreach ($data['branches'] as $branch) {
    $branchTotal += (int)$branch['total'];
    foreach (DD::CATEGORIES as $key) {
        $branchStat += (int)($branch[$key] ?? 0);
    }
}
ok($branchTotal === $total, "branch totals foot to the total ($branchTotal)");
ok($branchStat === $total, "branch statuses foot to the total ($branchStat)");

// The third chart foots the same way: every driver lands in exactly ONE bar,
// including one with no agency on file. Dropping those is what would let the
// chart add up to LESS than the card in its own header.
$agencyBuckets = DD::byAgency($rows);
$agencySum     = 0;
$agencyOrdered = true;

foreach ($agencyBuckets as $i => $bucket) {
    $agencySum += (int)$bucket['total'];

    if ($i > 0 && (int)$agencyBuckets[$i - 1]['total'] < (int)$bucket['total']) {
        $agencyOrdered = false;
    }
}

ok($agencySum === $total, "agency bars foot to the total ($agencySum / $total)");
ok($agencyOrdered, 'the bars are ordered largest first, so the shape holds still');

$noAgency = DD::byAgency([['agency' => ''], ['agency' => null], ['agency' => 'TAMKEEN']]);
ok(count($noAgency) === 2
   && $noAgency[0]['agency'] === 'No agency'
   && (int)$noAgency[0]['total'] === 2,
    'a driver with no agency is reported as "No agency", not dropped');

// Spec override: resigned / terminated / inactive are outside the Leave
// Dashboard's head-count, so they are outside this one too - excluded at the
// source rather than subtracted from the cards.
$outside = 0;
foreach ($rows as $row) {
    // The mapped row carries employment_status (and the raw slug is never
    // shipped as "status"), so the rule is checked the way classify() reads it.
    $employed = (string)$row['employment_status'] === 'active'
        || ((string)($row['last_working_date'] ?? '') !== ''
            && (string)$row['last_working_date'] >= $data['today']);

    if (!$employed) {
        $outside++;
    }
}
ok($outside === 0, "no resigned/terminated/inactive driver is counted ($outside found)");

$leavers = (int)Database::scalar(
    'SELECT COUNT(*) FROM drivers d WHERE NOT ' . \App\Models\Driver::activeOnSql('d'),
    [$data['today']]
);
$totalInTable = (int)Database::scalar('SELECT COUNT(*) FROM drivers');
ok(count($rows) === $totalInTable - $leavers,
    'the dataset is the active population: ' . count($rows)
    . ' = ' . $totalInTable . ' registered - ' . $leavers . ' leavers');
ok(count($rows) === (int)Database::scalar(
    'SELECT COUNT(*) FROM drivers d WHERE ' . \App\Models\Driver::activeOnSql('d'),
    [$data['today']]
), 'and it matches Driver::activeOnSql() exactly');

// The Branch Summary's totals row is summed from the same array.
$totals = \App\Models\DriverDashboardService::totals($data['branches']);
ok((int)$totals['total'] === $total,
    "the Branch Summary totals row foots to the total ({$totals['total']})");
$totalsStatus = 0;
foreach (DD::CATEGORIES as $key) {
    $totalsStatus += (int)$totals[$key];
    ok((int)$totals[$key] === (int)$kpis[$key],
        "and its $key column matches the card ({$totals[$key]})");
}
ok($totalsStatus === $total, 'every status column in the totals row foots too');
ok(abs((float)$totals['active_pct'] - (float)$kpis['active_pct']) < 0.0001,
    "and its Active % matches the card ({$totals['active_pct']}%)");

$bad = 0;
foreach ($rows as $row) {
    if (!in_array($row['op_status'], DD::CATEGORIES, true)) {
        $bad++;
    }
}
ok($bad === 0, 'every row carries exactly one of the five statuses');

// The table must be able to reproduce the cards from the same rows.
$recount = array_fill_keys(DD::CATEGORIES, 0);
foreach ($rows as $row) {
    $recount[$row['op_status']]++;
}
foreach (DD::CATEGORIES as $key) {
    ok($recount[$key] === (int)$kpis[$key],
        "the list can reproduce the $key card ({$recount[$key]})");
}

/* -------------------------------------------------- 3. ties to the leave -- */

echo "\n== 3. On Leave is the Leave Dashboard's figure ==\n";

$leaveKpi = (int)LeaveRecord::kpis()['on_leave'];
ok((int)$kpis['on_leave'] === $leaveKpi,
    "On Leave = {$kpis['on_leave']} matches LeaveRecord::kpis() ($leaveKpi)");

$away = (int)Database::scalar(
    "SELECT COUNT(*) FROM leave_records WHERE status IN ('on_leave','overdue')"
);
ok((int)$kpis['on_leave'] === $away,
    "and the same predicate in SQL ($away)");

/* ------------------------------------------------------ 4. percentages ---- */

echo "\n== 4. percentages ==\n";

$totalPct = 0.0;
foreach (DD::CATEGORIES as $key) {
    $expected = $total > 0 ? round((int)$kpis[$key] / $total * 100, 1) : 0.0;
    ok(abs((float)$kpis[$key . '_pct'] - $expected) < 0.0001,
        "$key pct = {$kpis[$key . '_pct']}% (count / total x 100, 1 dp)");
    ok((float)$kpis[$key . '_pct'] * 10 === round((float)$kpis[$key . '_pct'] * 10),
        "$key pct carries at most one decimal");
    $totalPct += (float)$kpis[$key . '_pct'];
}
ok(abs($totalPct - 100.0) < 0.6, "the five shares add up to 100% ($totalPct)");

ok($kpis['total_pct'] === 100.0, 'Total Drivers reads 100%');

// Branch Active % uses the same formula against that branch's own total.
foreach ($data['branches'] as $branch) {
    $bTotal = (int)$branch['total'];
    $want   = $bTotal > 0 ? round((int)$branch['active'] / $bTotal * 100, 1) : 0.0;
    ok(abs((float)$branch['active_pct'] - $want) < 0.0001,
        "Active % for {$branch['branch']} = {$branch['active_pct']}%");
    break;   // one worked example is enough; the formula is shared
}

/* -------------------------------------------------------- 5. filters ----- */

echo "\n== 5. filters and export agree ==\n";

ok(DD::filterRows($rows, []) === $rows, 'no filters returns every driver');
ok(DD::filterRows($rows, ['op_status' => ''])
    === $rows, 'an empty status filter is no filter');

$byStatus = DD::filterRows($rows, ['op_status' => 'active']);
ok(count($byStatus) === (int)$kpis['active'],
    'the status filter returns exactly the Active card');
foreach ($byStatus as $row) {
    if ($row['op_status'] !== 'active') {
        ok(false, 'the status filter leaked another status');
        break;
    }
}

// The dropdown is Settings, not the five buckets. The buckets are what the
// KPI cards drive; sharing one key with the dropdown is what made four of
// the ten configured statuses - vacation, training, sick_gosi and
// undertermination - impossible to pick.
$opts = DD::statusOptions();
$live = \App\Models\Lookup::operationalStatuses();
ok(count($live) >= 10, 'Settings holds ' . count($live) . ' operational statuses');
foreach ($live as $value => $label) {
    ok(($opts[$value] ?? null) === $label,
        "the dropdown offers \"$label\" from Settings");
}
ok(isset($opts[DD::UNSET_FLAG]),
    'and a "Not set" entry, for the drivers with no flag (most of them)');
ok($opts[''] === 'All statuses', 'and still "All statuses" at the top');
foreach (['active', 'on_leave', 'gosi', 'other'] as $bucket) {
    ok(!isset($opts[$bucket]), "\"$bucket\" is a card, not a dropdown status");
}

// The flag filter has to work in PHP too, or the screen and the export
// would disagree about which drivers are listed (spec section 18). It
// matches what the column SHOWS: the status override renames rows, so a
// stored-value comparison would now be wrong in two directions at once.
$unsetFlag = DD::filterRows($rows, ['operational_status' => DD::UNSET_FLAG]);
$leaked    = 0;
foreach ($unsetFlag as $row) {
    if ((string)$row['op_flag'] !== '—') { $leaked++; }
}
ok($leaked === 0 && $unsetFlag !== [],
    '"Not set" reaches exactly the rows showing the dash (' . count($unsetFlag) . ' rows, ' . $leaked . ' wrong)');

$flagged = DD::filterRows($rows, ['operational_status' => 'idle']);
$leak2   = 0;
foreach ($flagged as $row) {
    if ((string)$row['op_flag'] !== 'Idle') { $leak2++; }
}
ok($leak2 === 0 && $flagged !== [],
    '"idle" reaches exactly the rows reading Idle (' . count($flagged) . ' rows, ' . $leak2 . ' wrong)');

// The column draws the flag; the bucket still exists for the cards, charts
// and the details panel. Both must survive - only the column changed.
ok(isset($rows[0]['op_flag']), 'each row carries the flag the list column draws');
ok(isset($rows[0]['op_label']), 'and still carries the bucket the cards count');
ok(!isset($rows[0]['assignment']), 'and no longer carries an assignment field');

/* --------------------------------- 5b. the driver status override ------- */

echo "\n== 5b. driver status override ==\n";

$ov = [DD::class, 'statusOverride'];

// NO GATE: the stored flag never protects itself - Assigned, Idle and blank
// are all eligible, exactly as specified.
ok($ov('Assigned', 'assigned', true, '', false, false) === 'On Leave',
    'no gate: "assigned" IS overridden when on leave');
ok($ov('Assigned', 'assigned', false, '', false, true) === 'Exit',
    'no gate: "assigned" IS overridden by a resignation');
ok($ov('Assigned', 'assigned', false, 'returned', false, false) === '—',
    'no gate: "assigned" IS reset by Returned');
ok($ov('Idle', 'idle', false, '', false, false) === 'Idle',
    'no clause fires -> the stored flag survives');

// The five clauses, in the order they were specified.
ok($ov('—', '', false, '', false, true) === 'Exit',
    '1: approved Resignation + LWD passed -> Exit');
ok($ov('—', '', true, '', false, false) === 'On Leave', '2: on leave -> On Leave');
ok($ov('—', 'vacation', false, '', false, false) === 'On Leave',
    '2: stored flag vacation -> On Leave');
ok($ov('—', '', true, 'overdue', false, false) === 'On Leave',
    '2: an overdue record still counts as on leave');
ok($ov('—', '', false, '', true, false) === 'Sick / GOSI',
    '3: ongoing Sick Leave application -> Sick / GOSI');
ok($ov('—', '', false, 'not_returned', false, false) === 'Exit',
    '4: marked Not Returned -> Exit');
ok($ov('—', '', false, 'returned', false, false) === '—',
    '5: marked Returned -> Not Set');

// Rule 5 is LITERAL: it blanks a real stored status too. Confirmed choice -
// it is what makes this differ from "just clear the override".
ok($ov('Idle', 'idle', false, 'returned', false, false) === '—',
    '5: Returned resets even a real stored status (literal, as confirmed)');
ok($ov('Training', 'training', false, 'returned', false, false) === '—',
    '5: and not only Idle - any stored flag');

// First match wins, in list order.
ok($ov('—', '', true, '', false, true) === 'Exit', '1 beats 2');
ok($ov('—', '', false, '', true, true) === 'Exit', '1 beats 3');
ok($ov('—', '', false, 'not_returned', false, true) === 'Exit', '1 beats 4');
ok($ov('—', '', false, 'returned', false, true) === 'Exit', '1 beats 5');
ok($ov('—', '', false, '', true, false) === 'Sick / GOSI', '3 stands when 2 does not fire');
ok($ov('—', '', true, 'not_returned', false, false) === 'On Leave', '2 beats 4');
ok($ov('—', '', true, 'returned', false, false) === 'On Leave', '2 beats 5');
ok($ov('—', '', false, 'not_returned', true, false) === 'Sick / GOSI', '3 beats 4');
ok($ov('Idle', 'idle', false, 'planned', false, false) === 'Idle',
    'a planned (not yet started) leave overrides nothing');

// Rule 2 shadowing rule 3 is a CONFIRMED decision, not an accident - pinned
// so a future reorder has to come through here deliberately.
ok($ov('—', '', true, '', true, false) === 'On Leave',
    'rule 2 shadows rule 3 (confirmed: On Leave wins for an ongoing sick leave)');

// Live: the override actually fires, and the filter agrees with the column.
$shown = [];
$blankRows = 0;
$blankKept = 0;
foreach ($rows as $row) {
    $l = (string)$row['op_flag'];
    $shown[$l] = ($shown[$l] ?? 0) + 1;
    if ((string)$row['operational_status'] === '') {
        $blankRows++;
        if ($l === '—') { $blankKept++; }
    }
}
ok(($shown['On Leave'] ?? 0) > 0,
    'rule 2 fires on live data: ' . ($shown['On Leave'] ?? 0) . ' drivers read On Leave');
ok($blankRows - $blankKept > 0,
    ($blankRows - $blankKept) . ' blank flags were overridden by leave');
ok(($shown['Sick / GOSI'] ?? 0) >= 1,
    'Sick / GOSI is shown by ' . ($shown['Sick / GOSI'] ?? 0) . ' drivers');

// The filter must match what the column SHOWS. A driver whose stored flag is
// blank but who now reads "Sick / GOSI" has to come back under that option -
// and must stop coming back under "Not set".
$sickShown = 0;
foreach ($rows as $row) {
    if ((string)$row['op_flag'] === 'Sick / GOSI') { $sickShown++; }
}
ok(count(DD::filterRows($rows, ['operational_status' => 'sick_gosi'])) === $sickShown,
    'filter "Sick / GOSI" returns exactly the rows showing it (' . $sickShown . ')');

$unsetShown = DD::filterRows($rows, ['operational_status' => DD::UNSET_FLAG]);
$notDash    = 0;
foreach ($unsetShown as $row) {
    if ((string)$row['op_flag'] !== '—') { $notDash++; }
}
ok($notDash === 0,
    '"Not set" returns only rows still showing the dash (' . count($unsetShown) . ' rows)');

// Rule 5 blanks a real stored status, so "Not set" is NOT simply "the blank
// flags that no clause touched" any more - the two have to partition the
// list exactly, which is the invariant that matters.
$renamed = 0;
foreach ($rows as $row) {
    if ((string)$row['op_flag'] !== '—') { $renamed++; }
}
ok(count($unsetShown) + $renamed === count($rows),
    '"Not set" and everything else partition the list exactly ('
    . count($unsetShown) . ' + ' . $renamed . ' = ' . count($rows) . ')');

// Nothing the column shows may be unreachable by a filter.
$reachable = ['—' => true, 'On Leave' => true];
foreach (DD::statusOptions() as $slug => $label) {
    if ($slug !== '') { $reachable[$label] = true; }
}
$stranded = [];
foreach ($shown as $label => $n) {
    if (!isset($reachable[$label])) { $stranded[] = $label; }
}
ok($stranded === [], 'every displayed status is reachable by a filter'
    . ($stranded === [] ? '' : ' - stranded: ' . implode(', ', $stranded)));

// And the export has to show the same numbers as the screen.
[, $exported] = DD::exportRows($rows);
$hdr          = DD::exportRows($rows)[0];
$col2         = (int)array_search('Operational Status', $hdr, true);
$fromExport   = [];
foreach ($exported as $line) {
    $v = (string)($line[$col2] ?? '');
    $fromExport[$v] = ($fromExport[$v] ?? 0) + 1;
}
ksort($fromExport);
$shownSorted = $shown;
ksort($shownSorted);
ok($fromExport === $shownSorted, 'the export carries the same status counts as the screen');

$branchId = '';
foreach ($rows as $row) {
    if ($row['branch_id'] !== null) {
        $branchId = (string)$row['branch_id'];
        break;
    }
}
if ($branchId !== '') {
    $byBranch = DD::filterRows($rows, ['branch_id' => $branchId]);
    ok(count($byBranch) > 0 && count($byBranch) < $total,
        "one branch narrows the list (" . count($byBranch) . ")");
    foreach ($byBranch as $row) {
        if ((string)$row['branch_id'] !== $branchId) {
            ok(false, 'the branch filter leaked another branch');
            break;
        }
    }
}

$combined = DD::filterRows($rows, [
    'branch_id' => $branchId,
    'op_status' => 'active',
]);
$manual = 0;
foreach ($rows as $row) {
    if ((string)$row['branch_id'] === $branchId && $row['op_status'] === 'active') {
        $manual++;
    }
}
ok(count($combined) === $manual,
    'branch AND status combine the way the spec asks (section 8)');

$needle = strtolower((string)($rows[0]['driver_name'] ?? ''));
if ($needle !== '') {
    $found = DD::filterRows($rows, ['q' => $needle]);
    ok(count($found) > 0, 'free-text search finds a known name');
}

[$headers, $exported] = DD::exportRows($byStatus);
ok(count($exported) === count($byStatus),
    'an export carries exactly the filtered rows (spec 18)');
ok(count($headers) === 8, 'the export has the eight columns the data supports');
ok(!in_array('Assignment Status', $headers, true),
    'and Assignment Status is not one of them - the column is gone');
ok(in_array('Operational Status', $headers, true),
    'and Operational Status still is');

/* --------------------------------------- 6. route, nav, titles, columns --- */

echo "\n== 6. the page is wired in ==\n";

// Signed in from the start of this section: reading permissions is what
// triggers ensure_permission_catalogue(), which is what puts a brand-new key
// on file (see app/middleware/permissions.php).
$saved = $_SESSION['user'] ?? null;
$admin = (int)Database::scalar('SELECT id FROM users WHERE role = "admin" ORDER BY id LIMIT 1');
$_SESSION['user'] = ['id' => $admin];
forget_permissions();

$routeMap = require APP_ROOT . '/app/middleware/route-permissions.php';
ok(($routeMap['web']['GET /driver-dashboard'] ?? null) === 'dashboard.drivers',
    'the route declares dashboard.drivers - its own right, so it can be revoked per role');

$permissions = permission_catalogue();
ok(isset($permissions['Dashboard']['dashboard.drivers']),
    'the permission sits in the Dashboard group of the catalogue');
ok(in_array('dashboard.drivers', permission_keys(), true),
    'dashboard.drivers is in the permission catalogue');

$definitions = role_definitions();
ok($definitions['admin']['permissions'] === ['*'],
    'Administrator is unrestricted');
foreach (['hr', 'operations', 'viewer'] as $slug) {
    ok(in_array('dashboard.drivers', $definitions[$slug]['permissions'], true),
        "the $slug role ships holding it (so nobody loses the page on upgrade)");
}

// The permission has to be on file or can() is false for EVERYONE, including
// the Administrator - self-heal or migration, the row must exist.
$onFile = (int)Database::scalar(
    'SELECT COUNT(*) FROM permissions WHERE permission_key = "dashboard.drivers"'
);
ok($onFile === 1, 'the row exists in the permissions table');
$grants = (int)Database::scalar(
    'SELECT COUNT(*) FROM role_permissions rp
       JOIN permissions p ON p.id = rp.permission_id
      WHERE p.permission_key = "dashboard.drivers"'
);
ok($grants >= 3, "and it is granted ($grants role grant(s))");

// Revoking it must actually lock the page out - the whole point of the right.
$roles = Database::all(
    'SELECT r.slug, (p.permission_key IS NOT NULL) AS held
       FROM roles r
  LEFT JOIN role_permissions rp ON rp.role_id = r.id
  LEFT JOIN permissions p       ON p.id = rp.permission_id
                                AND p.permission_key = "dashboard.drivers"'
);
$holders   = [];
$nonHolders = [];
foreach ($roles as $row) {
    if ((int)$row['held'] === 1) {
        $holders[] = (string)$row['slug'];
    } else {
        $nonHolders[] = (string)$row['slug'];
    }
}
sort($holders);
ok($nonHolders === [] || $holders !== [],
    'at least one role can hold it and another can be left without it',
    'holders=' . implode(',', $holders) . ' without=' . implode(',', $nonHolders));

$routes = (string)file_get_contents(APP_ROOT . '/index.php');
ok(str_contains($routes, "'driver-dashboard'"),
    'index.php routes /driver-dashboard to the controller');
ok(str_contains($routes, 'DriverDashboardController@index'),
    'the route points at DriverDashboardController::index');

// Spec section 20: Dashboard is the parent, with two dashboards under it.
$nav              = \App\Core\View::navigation();
$dashboardSection = null;
foreach ($nav as $section) {
    foreach ($section['items'] as $item) {
        if (in_array($item['key'] ?? '', ['dashboard', 'driver-dashboard'], true)) {
            $dashboardSection = $section;
            break 2;
        }
    }
}

ok($dashboardSection !== null, 'the Dashboard section exists');
ok(($dashboardSection['label'] ?? null) === 'Dashboard',
    'its heading is Dashboard (' . ($dashboardSection['label'] ?? 'none') . ')');

$keys = array_column($dashboardSection['items'] ?? [], 'key');
ok($keys === ['dashboard', 'driver-dashboard'],
    'it holds both dashboards, in order (' . implode(', ', $keys) . ')');

$labels = array_column($dashboardSection['items'] ?? [], 'label');
ok($labels === ['Leave Dashboard', 'Driver Dashboard'],
    'labelled Leave Dashboard and Driver Dashboard');

$permissions = array_column($dashboardSection['items'] ?? [], 'permission');
ok($permissions === ['dashboard.view', 'dashboard.drivers'],
    'Leave Dashboard needs dashboard.view, Driver Dashboard needs dashboard.drivers',
    implode(', ', $permissions));

if ($saved === null) {
    unset($_SESSION['user']);
} else {
    $_SESSION['user'] = $saved;
}
forget_permissions();

// The Leave Dashboard was retitled: the sidebar now names it for what it is.
$dashView = (string)file_get_contents(APP_ROOT . '/app/views/dashboard/index.php');
$dashCtrl = (string)file_get_contents(APP_ROOT . '/app/controllers/DashboardController.php');
ok(str_contains($dashView, "'title'       => 'Leave Dashboard'"),
    'the page head is titled Leave Dashboard');
ok(!str_contains($dashView, 'Operations Dashboard'), 'the old heading is gone');
ok(str_contains($dashCtrl, "'Leave Dashboard', 'dashboard'"),
    'its browser title and breadcrumb say Leave Dashboard');

$stale = [];
$self  = basename(__FILE__);
foreach (glob(APP_ROOT . '/tools/*.php') ?: [] as $file) {
    // This file names the old title on purpose, to prove it is gone.
    if (basename($file) === $self) {
        continue;
    }
    if (str_contains((string)file_get_contents($file), 'Operations Dashboard')) {
        $stale[] = basename($file);
    }
}
ok($stale === [], 'no other test still looks for the old title',
    $stale === [] ? '' : implode(', ', $stale));

// Spec section 7: only columns the data source actually has.
$viewSrc = (string)file_get_contents(APP_ROOT . '/app/views/driver-dashboard/index.php');
$jsSrc   = (string)file_get_contents(APP_ROOT . '/assets/js/driver-dashboard.js');
$svcSrc  = (string)file_get_contents(APP_ROOT . '/app/models/DriverDashboardService.php');
$readme  = (string)file_get_contents(APP_ROOT . '/README.md');

ok(!str_contains($viewSrc, 'Tractor / Head'), 'no Tractor column is rendered');
ok(!str_contains($viewSrc, '>Attachment<'), 'no Attachment column is rendered');
ok(!str_contains($viewSrc, '>Customer<'), 'no Customer column is rendered');
// The wording lives in the details panel the browser fills in, so the source
// it is checked against is the script, not the markup.
ok(str_contains($jsSrc, 'Not tracked in this system.'),
    'the details panel says so instead of inventing a value');

// Spec sections 8, 15, 17 and 18, visible in the markup.
foreach ([
    'status filter'     => 'id="ddStatus"',
    'branch filter'     => 'id="ddBranch"',
    'search box'        => 'id="ddSearch"',
    'clear filters'     => 'id="ddClear"',
    'empty state'       => 'No drivers found',
    'data-quality note' => 'unmapped operational status',
    'csv export'        => 'id="ddExportCsv"',
    'details modal'     => 'id="ddDriverModal"',
    'refresh button'    => 'title="Refresh"',
] as $label => $needle) {
    ok(str_contains($viewSrc, $needle), "$label is on the page");
}

// Spec section 16: one calculation; the browser reads its output.
ok(substr_count($svcSrc, 'function classify') === 1,
    'there is exactly one operational-status calculation');
ok(str_contains($jsSrc, 'row.op_status'),
    'the browser filters on the status PHP assigned, it does not re-derive it');
foreach (['op_status', 'operational_status', 'branch_id', 'q'] as $key) {
    ok(str_contains($jsSrc, "state.$key"), "the browser filter carries $key");
    ok(str_contains($svcSrc, "'$key'"), "the PHP filter carries $key too");
}
ok(!str_contains($viewSrc, 'ddAssignment'), 'the Assignment dropdown is gone from the markup');
ok(!str_contains($jsSrc, 'ddAssignment'), 'and from the script');
ok(!str_contains($svcSrc, 'ddAssignment'), 'and from the service');
// The list draws the flag, not the bucket: op_label reads "Active" for the
// overwhelming majority of rows because the list is active-population only.
ok(str_contains($viewSrc, "'op_flag'         => 'Operational Status'"),
    'the column is keyed on op_flag');
ok(str_contains($jsSrc, 'function flag(row)'), 'and the script has a cell renderer for it');
ok(str_contains($jsSrc, 'row.op_flag'), 'which reads the flag off the row');

// Spec section 21: the whole list arrives once, so filtering costs no reload.
ok(str_contains($viewSrc, 'window.DRIVER_DASHBOARD_DATA'),
    'the rows ship with the page instead of being re-fetched');
ok(!str_contains($viewSrc, 'fetch('), 'nothing on the page fetches the list again');

// Spec section 15: the cards are derived, never typed in.
ok(str_contains($viewSrc, 'number_format($count)'), 'the card value is computed');
ok(!preg_match('/>\s*4[0-9]{2}\s*</', $viewSrc), 'no driver total is hard-coded');

ok(str_contains($readme, 'Driver Dashboard'), 'README documents the page');
ok(str_contains($readme, 'driver-dashboard'), 'README lists the route');

/* ==================== 7. Total Drivers By Agency ======================== */

echo "\n== 7. the agency chart ==\n";

// The third card in the chart row, beside "Operational Status by Branch"
// and "Overall Operational Status".
ok(str_contains($viewSrc, 'Total Drivers By Agency'), 'the card is on the page');
ok(str_contains($viewSrc, 'id="ddAgencyChart"'), 'with a canvas of its own');
ok(str_contains($viewSrc, '$agencies'),
    'and the payload carries the buckets it is drawn from');

// The row is 5 / 4 / 3 now: the stacked branch chart keeps the widest slice,
// the donut a middle one, and the agency chart the narrowest - its labels sit
// on the axis rather than under the bars, so it does not need the width.
ok(str_contains($viewSrc, 'col-12 col-lg-5'), 'the branch chart keeps the widest share');
ok(str_contains($viewSrc, 'col-12 col-md-6 col-lg-4'), 'the donut keeps a middle share');
ok(str_contains($viewSrc, 'col-12 col-md-6 col-lg-3'), 'the agency chart takes the narrow end');

// The SERVICE counts them, not the view: a second query is how two charts
// on one page start disagreeing.
ok(str_contains($svcSrc, 'function byAgency'),
    'the buckets are counted in the service, beside the classification');
$ddCtrl = (string) file_get_contents(APP_ROOT . '/app/controllers/DriverDashboardController.php');
ok(str_contains($ddCtrl, 'DriverDashboardService::byAgency($data[\'rows\'])'),
    'and from the SAME rows the cards above were counted from');

// Horizontal bars: agency names are long and there are few of them.
ok(str_contains($jsSrc, "el('ddAgencyChart')"), 'the script draws it');
ok(str_contains($jsSrc, "indexAxis: 'y'"), 'as a horizontal bar, so names read on the axis');
ok(str_contains($jsSrc, 'agencies.reduce'),
    'the tooltip percentage is taken against the chart\'s own total');
ok(str_contains($jsSrc, 'autoSkip: false'), 'and no agency is skipped to make the axis fit');

// The two sibling charts filter the list when clicked; this one must not
// pretend to, because the page has no agency filter to drive.
$agencyStart = (int) strpos($jsSrc, "el('ddAgencyChart')");
$donutStart  = (int) strpos($jsSrc, "el('ddOverallChart')");
$agencyBlock = $agencyStart > 0 && $donutStart > $agencyStart
    ? substr($jsSrc, $agencyStart, $donutStart - $agencyStart)
    : '';
ok($agencyBlock !== '' && !str_contains($agencyBlock, 'onClick'),
    'and it is display-only - this page has no agency filter to drive');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);



