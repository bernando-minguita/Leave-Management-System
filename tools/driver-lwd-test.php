<?php
/**
 * Last Working Date on the driver record (automated deactivation rules).
 *
 *   php C:/xampp/htdocs/lms/tools/driver-lwd-test.php
 *
 * What must stay true:
 *   1. The column and its index exist (database/schema.sql).
 *   2. The population rule is date-aware: a driver deactivated WITH a last
 *      working date still counts in the totals up to and including that day
 *      and drops out the day after; one deactivated WITHOUT a date behaves
 *      exactly as before the column existed (Driver::activeOnSql).
 *   3. Export and CSV import carry the field, with the same two validations
 *      the form uses (a real date, never before joining).
 *   4. The screens that ask for the date are wired up.
 *
 * Fixtures wear badge LWD* / LWDCSV* and are removed whatever happens.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Driver;
use App\Models\DriverImport;
use App\Models\LeaveRecord;

$pass = 0;
$fail = 0;

function ok(bool $cond, string $msg): void
{
    global $pass, $fail;

    if ($cond) {
        $pass++;
        echo "  ok    $msg\n";
    } else {
        $fail++;
        echo "  FAIL  $msg\n";
    }
}

/* ---------------------------------------------------------- fixtures --- */

$cleanup = static function (): void {
    Database::run("DELETE FROM drivers WHERE badge_number LIKE 'LWD%'");
};

$cleanup();
register_shutdown_function($cleanup);

$mkDriver = static function (string $badge): int {
    return Database::insert('drivers', [
        'badge_number' => $badge,
        'driver_name'  => 'LWD FIX DRIVER ' . $badge,
        'iqama_number' => 'IQ' . $badge,
        'status'       => 'active',
        'joining_date' => '2021-05-05',
    ]);
};

/** One day in the population on a given date? */
$countOn = static function (int $driverId, string $day): int {
    return (int)Database::scalar(
        'SELECT COUNT(*) FROM drivers d WHERE ' . Driver::activeOnSql('d') . ' AND d.id = ?',
        [$day, $driverId]
    );
};

/* ----------------------------------------------------------- 1. schema -- */

echo "== 1. schema ==\n";

$col = Database::one("SHOW COLUMNS FROM drivers LIKE 'last_working_date'");
ok($col !== null, 'drivers.last_working_date exists');
ok($col !== null && stripos((string)$col['Type'], 'date') === 0,
    'it is a DATE column (got ' . (string)($col['Type'] ?? 'missing') . ')');
ok($col !== null && stripos((string)$col['Null'], 'yes') === 0,
    'and it is nullable - no date on file means no invented end date');

$indexed = false;

foreach (Database::all('SHOW INDEX FROM drivers') as $row) {
    if ((string)($row['Key_name'] ?? '') === 'idx_drivers_lwd') {
        $indexed = true;
        break;
    }
}

ok($indexed, 'idx_drivers_lwd is on the table');

/* --------------------------------------------- 2. the population rule --- */

echo "\n== 2. activeOnSql: counted through the last working date ==\n";

$withDate = $mkDriver('LWD-01');

// Still employed: counted on any day, whatever an empty end date says.
ok($countOn($withDate, '2026-11-12') === 1, 'an active driver counts on any day');

Database::run(
    'UPDATE drivers SET status = "inactive", last_working_date = "2026-11-12" WHERE id = ?',
    [$withDate]
);
ok($countOn($withDate, '2026-11-11') === 1, 'deactivated with a date: counted the day before it');
ok($countOn($withDate, '2026-11-12') === 1, 'deactivated with a date: counted ON it');
ok($countOn($withDate, '2026-11-13') === 0, 'deactivated with a date: gone the day after');

// A deactivation with no date keeps the old behaviour: out straight away.
$withoutDate = $mkDriver('LWD-02');
Database::run('UPDATE drivers SET status = "inactive" WHERE id = ?', [$withoutDate]);
ok($countOn($withoutDate, date('Y-m-d')) === 0,
    'a deactivation with no date is excluded immediately (no number moves)');
ok($countOn($withoutDate, '2026-11-12') === 0,
    'and on no day at all - the application never invents an end date');

/* ---------------------------------------------------- 3. the KPI rule --- */

echo "\n== 3. the dashboard head-count uses the same rule ==\n";

$kpisDriver = $mkDriver('LWD-03');
$before     = (int)LeaveRecord::kpis()['total_drivers'];

// A future last working date still counts TODAY (they are still at work).
Database::run(
    'UPDATE drivers SET status = "inactive", last_working_date = ? WHERE id = ?',
    [date('Y-m-d', strtotime('+7 days')), $kpisDriver]
);
$whileLeaving = (int)LeaveRecord::kpis()['total_drivers'];
ok($whileLeaving === $before,
    "today's total is unchanged while the driver is inside their notice period ({$before} -> {$whileLeaving})");

// Yesterday's last working date: not part of today's head-count.
Database::run(
    'UPDATE drivers SET status = "inactive", last_working_date = ? WHERE id = ?',
    [date('Y-m-d', strtotime('-1 day')), $kpisDriver]
);
$afterLeft = (int)LeaveRecord::kpis()['total_drivers'];
ok($afterLeft === $before - 1,
    "today's total drops once the last working date has passed ({$before} -> {$afterLeft})");

Database::run('UPDATE drivers SET last_working_date = NULL WHERE id = ?', [$kpisDriver]);

/* ---------------------------------------------------------- 4. export --- */

echo "\n== 4. export carries the end date ==\n";

Database::run(
    'UPDATE drivers SET status = "inactive", last_working_date = "2026-11-12" WHERE badge_number = ?',
    ['LWD-01']
);

$exported = Driver::allForExport(['q' => 'LWD-01']);
ok($exported !== [], 'the fixture is exportable');
ok($exported !== [] && array_key_exists('last_working_date', $exported[0]),
    'the export row carries last_working_date');
ok($exported !== [] && (string)$exported[0]['last_working_date'] === '2026-11-12',
    'and it holds the stored date');

/* ----------------------------------------------------------- 5. import -- */

echo "\n== 5. CSV import accepts and validates the column ==\n";

$headers = DriverImport::HEADERS;
ok(in_array('last_working_date', $headers, true),
    'the canonical header list includes last_working_date');

$template = DriverImport::template();
ok(count($template[1]) === count($template[0]),
    'the sample row still lines up with the header row ('
    . count($template[0]) . ' headers / ' . count($template[1]) . ' cells)');

$idx    = array_flip($headers);
$mkRow  = static function (array $cells, array $over) use ($idx): array {
    $row = array_fill(0, count($cells), '');

    foreach ($over as $field => $value) {
        $row[$idx[$field]] = $value;
    }

    return $row;
};

$base = [
    'badge_number' => 'LWDCSV-01',
    'driver_name'  => 'LWDCSV DRIVER',
    'iqama_number' => 'IQ-LWDCSV-01',
    'joining_date' => '2024-01-10',
    'status'       => 'inactive',
];

$okRow = DriverImport::plan($headers, [
    $mkRow($headers, $base + ['last_working_date' => '2026-11-12']),
]);
ok($okRow['errors'] === [],
    'a real date on a leaving driver imports cleanly');

$badDate = DriverImport::plan($headers, [
    $mkRow($headers, $base + ['last_working_date' => 'sometime in November']),
]);
ok($badDate['errors'] !== [] && stripos(implode(' ', $badDate['errors']), 'last working date') !== false,
    'a date that is not a date is refused by name');

$beforeJoin = DriverImport::plan($headers, [
    $mkRow($headers, $base + ['last_working_date' => '2023-12-31']),
]);
ok($beforeJoin['errors'] !== [], 'an end date before the joining date is refused');

// Committed rows keep the date for a leaver and drop it for an active one.
$keep = DriverImport::plan($headers, [
    $mkRow($headers, array_merge($base, ['badge_number' => 'LWDCSV-02', 'iqama_number' => 'IQ-LWDCSV-02',
        'last_working_date' => '2026-11-12'])),
    $mkRow($headers, ['badge_number' => 'LWDCSV-03', 'driver_name' => 'LWDCSV ACTIVE',
        'iqama_number' => 'IQ-LWDCSV-03', 'joining_date' => '2024-01-10', 'status' => 'active',
        'last_working_date' => '2026-11-12']),
]);
DriverImport::commit($keep['rows'], 'update');

$leaver = Database::one('SELECT last_working_date, status FROM drivers WHERE badge_number = ?', ['LWDCSV-02']);
ok($leaver !== null && (string)$leaver['last_working_date'] === '2026-11-12',
    'a leaving driver keeps the imported end date');

$activeRow = Database::one('SELECT last_working_date, status FROM drivers WHERE badge_number = ?', ['LWDCSV-03']);
ok($activeRow !== null && $activeRow['last_working_date'] === null,
    'an active driver never stores a stray end date from a spreadsheet');

/* ------------------------------------------------- 6. the screens ------- */

echo "\n== 6. the screens that ask for the date are wired up ==\n";

$form = (string)file_get_contents(APP_ROOT . '/app/views/drivers/form.php');
ok(str_contains($form, 'name="last_working_date"'), 'the Edit Driver form posts the field');
ok(str_contains($form, 'lastWorkingGroup'), 'the field is shown only for a leaving status');

$modals = (string)file_get_contents(APP_ROOT . '/app/views/partials/modals.php');
ok(str_contains($modals, 'deactivateDriverModal'), 'the deactivation modal exists');
ok(str_contains($modals, 'name="last_working_date"'), 'and it collects the Last Working Date');

$appJs = (string)file_get_contents(APP_ROOT . '/assets/js/app.js');
ok(str_contains($appJs, 'data-action="deactivate-driver"'), 'app.js handles the modal trigger');

$grid = (string)file_get_contents(APP_ROOT . '/app/views/drivers/index.php');
ok(str_contains($grid, 'data-action="deactivate-driver"'), 'the Address Book row opens the modal');
ok(str_contains($grid, 'leave-applications/new'),
    'the Address Book "+" opens an APPLICATION, not a record (requirement 4)');

$profile = (string)file_get_contents(APP_ROOT . '/app/views/drivers/profile.php');
ok(str_contains($profile, "last_working_date"), 'the profile shows the Last Working Date');
ok(str_contains($profile, 'leave-applications/new'),
    'the profile button opens an application too');

$import = (string)file_get_contents(APP_ROOT . '/app/controllers/LeaveController.php');
ok(str_contains($import, "status'] !== 'active'"),
    'recording leave for a deactivated driver is refused server-side (requirement 9)');

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

