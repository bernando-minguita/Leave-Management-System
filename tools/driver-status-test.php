<?php
/**
 * Address Book status split: Employment Status vs Operational Status, both
 * driven by the two Settings option lists.
 *
 *   php C:/xampp/htdocs/lms/tools/driver-status-test.php
 *
 * What must stay true:
 *   1. drivers.status is a VARCHAR and operational_status exists (migration).
 *   2. The option lists parse as "value|Label" and the employment list can
 *      never lose "active" - every active-driver KPI counts that exact slug.
 *   3. Filtering, validation, export and CSV import all speak both lists.
 *   4. The screens show LABELS, never stored slugs.
 *   5. A value drivers still hold cannot be removed from a list, and a driver
 *      whose stored value left the list keeps it through an edit.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Driver;
use App\Models\DriverImport;
use App\Models\Lookup;

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

/* ------------------------------------------------- 1. column state ----- */

echo "== schema ==\n";

$statusCol = Database::one("SHOW COLUMNS FROM drivers LIKE 'status'");
ok($statusCol !== null, 'drivers.status exists');
ok($statusCol !== null && !str_starts_with(strtolower((string) $statusCol['Type']), 'enum'),
    'drivers.status left the ENUM behind (got ' . (string) ($statusCol['Type'] ?? 'missing') . ')');

$opCol = Database::one("SHOW COLUMNS FROM drivers LIKE 'operational_status'");
ok($opCol !== null, 'drivers.operational_status exists');
ok($opCol !== null && str_contains(strtolower((string) $opCol['Type']), 'varchar'),
    'operational_status is a VARCHAR (got ' . (string) ($opCol['Type'] ?? 'missing') . ')');

/* ----------------------------------------------- 2. option lists ------- */

echo "\n== option lists ==\n";

$parsed = Lookup::parseOptionListString('active|Active,inactive|Inactive');
ok($parsed === ['active' => 'Active', 'inactive' => 'Inactive'],
    'value|Label pairs parse to value => label');

$bad = [];
Lookup::parseOptionListString('Active|Active', $bad);
ok($bad !== [], 'an uppercase value is refused (slugs must be lowercase)');

$bad = [];
Lookup::parseOptionListString('active|Active,active|Again', $bad);
ok($bad !== [], 'a duplicate value is refused');

$bad = [];
Lookup::parseOptionListString('', $bad);
ok($bad !== [], 'an empty list is refused');

$employment = Lookup::employmentStatuses();
ok($employment !== [], 'the employment list is available');
ok(array_key_exists('active', $employment),
    'the employment list keeps "active" - the KPIs count that slug');
ok(Lookup::employmentLabel('active') === ($employment['active'] ?? ''),
    'employment labels resolve through Settings');
ok(Lookup::employmentLabel('') === '—', 'an unset employment status shows a dash');

$operational = Lookup::operationalStatuses();
ok($operational !== [], 'the operational list is available');
ok(Lookup::operationalLabel('') === '—', 'an unset operational status shows a dash');
ok(!array_key_exists('', $operational), 'no empty option sneaks into a list');

/* --------------------------------------------------- 3. filtering ------ */

echo "\n== filtering ==\n";

[$whereSql, $whereParams] = Driver::where(['operational_status' => 'suspended']);
ok(str_contains($whereSql, 'd.operational_status IN'),
    'the WHERE clause can filter on operational status');

[$whereSql, $whereParams] = Driver::where(['operational_status' => 'suspended,absconded']);
ok(count($whereParams) === 2, 'a multi-select list becomes two bound parameters');

$sortable = array_keys(Driver::SORTABLE);
ok(in_array('operational_status', $sortable, true), 'operational status is sortable');
/* ---------------------------------------------------- 4. import -------- */

echo "\n== CSV import ==\n";

ok(in_array('operational_status', DriverImport::HEADERS, true),
    'the import accepts an operational_status column');

[$header, $row] = DriverImport::template();
ok(count($header) === count($row),
    'the template row has one value per header (' . count($header) . ' vs ' . count($row) . ')');

// The page used to offer the same download twice - once in the toolbar as
// "Download template", once in the upload card as "Template" - with different
// icons and labels, so it read as two different downloads. One link only.
$importView = (string) file_get_contents(APP_ROOT . '/app/views/drivers/import.php');
$templateLinks = substr_count($importView, 'import?template=1');
ok($templateLinks === 1,
    'the Import / Export page offers the template exactly once (got ' . $templateLinks . ')');

$plan = DriverImport::plan(
    ['badge_number', 'driver_name', 'iqama_number', 'status', 'operational_status'],
    [
        ['B-T1', 'Test Driver One', '9000000001', 'active', 'suspended'],
        ['B-T2', 'Test Driver Two', '9000000002', 'moonlighter', ''],
        ['B-T3', 'Test Driver Three', '9000000003', 'active', 'flying'],
    ]
);
ok(count($plan['rows']) === 1, 'one valid row plans cleanly');
ok(count($plan['errors']) === 2, 'two rows are rejected with a reason each');
ok(str_contains(implode(' ', $plan['errors']), 'Employment status'),
    'an unknown employment status is named as such');
ok(str_contains(implode(' ', $plan['errors']), 'Operational status'),
    'an unknown operational status is named as such');

/* --------------------------------------------- 5. save validation ------ */

echo "\n== Settings save validation ==\n";

$saveSettings = new ReflectionMethod(\App\Controllers\SettingsController::class, 'saveSettings');
$saveSettings->setAccessible(true);
$controller = new \App\Controllers\SettingsController();

$before  = Lookup::setting('employment_statuses');
$opBefore = Lookup::setting('operational_statuses');

// (a) uppercase slug refused
$result = $saveSettings->invoke($controller, [
    'settings' => ['employment_statuses' => 'Active|Active,inactive|Inactive'],
]);
ok(($result['ok'] ?? null) === false, 'an uppercase value is refused on save');

// (b) the "active" invariant: dropping it breaks every KPI
$result = $saveSettings->invoke($controller, [
    'settings' => ['employment_statuses' => 'employed|Employed,off|Off duty'],
]);
ok(($result['ok'] ?? null) === false && str_contains((string) ($result['message'] ?? ''), 'active'),
    'a list without "active" is refused (got: ' . (string) ($result['message'] ?? '') . ')');

// (c) a blank list is refused too
$result = $saveSettings->invoke($controller, [
    'settings' => ['operational_statuses' => '  '],
]);
ok(($result['ok'] ?? null) === false, 'a blank option list is refused');

// (d) a valid list saves - writing the original values back changes nothing.
$result = $saveSettings->invoke($controller, [
    'settings' => [
        'employment_statuses'  => $before,
        'operational_statuses' => $opBefore,
    ],
]);
ok(($result['ok'] ?? null) === true, 'the configured lists save as-is');
ok(Lookup::setting('employment_statuses') === $before, 'the employment list is unchanged');
ok(Lookup::setting('operational_statuses') === $opBefore, 'the operational list is unchanged');

/* ------------------------ 6. removing a value drivers still hold -------- */

echo "\n== removing an option drivers still hold ==\n";

// Leftovers from an interrupted run would refuse the removals below for the
// wrong reason, so clear them first.
Database::run("DELETE FROM drivers WHERE badge_number LIKE 'ZZSTAT-%' OR badge_number LIKE 'ZZORPH-%'");

$suffix = substr((string)time(), -6);
$empFixture = 'zz_emp_' . $suffix;
$opFixture  = 'zz_op_' . $suffix;

$fixtureId = Database::insert('drivers', [
    'badge_number'       => 'ZZSTAT-' . $suffix,
    'driver_name'        => 'ZZ Status Fixture',
    'iqama_number'       => '9' . $suffix,
    'status'             => $empFixture,
    'operational_status' => $opFixture,
]);

$empWithFixture = $before . ',' . $empFixture . '|ZZ Emp';
$opWithFixture  = $opBefore . ',' . $opFixture . '|ZZ Op';

$result = $saveSettings->invoke($controller, ['settings' => ['employment_statuses' => $empWithFixture]]);
ok(($result['ok'] ?? null) === true, 'adding a value is allowed');

$result = $saveSettings->invoke($controller, ['settings' => ['operational_statuses' => $opWithFixture]]);
ok(($result['ok'] ?? null) === true, 'and so is adding an operational value');

// The fixture now holds both. Removing either must be refused, by name.
$result = $saveSettings->invoke($controller, ['settings' => ['employment_statuses' => $before]]);
ok(($result['ok'] ?? null) === false && str_contains((string)($result['message'] ?? ''), $empFixture),
    'an employment value a driver still holds cannot be removed');
ok(str_contains((string)($result['message'] ?? ''), 'still use'), 'and the message says why');

$result = $saveSettings->invoke($controller, ['settings' => ['operational_statuses' => $opBefore]]);
ok(($result['ok'] ?? null) === false && str_contains((string)($result['message'] ?? ''), $opFixture),
    'nor an operational one');
ok(Lookup::setting('operational_statuses') === $opWithFixture, 'the refused save wrote nothing');

// Reassign (delete the fixture) and the same removal goes through.
Database::run('DELETE FROM drivers WHERE id = ?', [$fixtureId]);

$result = $saveSettings->invoke($controller, ['settings' => [
    'employment_statuses'  => $before,
    'operational_statuses' => $opBefore,
]]);
ok(($result['ok'] ?? null) === true, 'once nobody holds them, both removals are allowed');
ok(Lookup::setting('employment_statuses') === $before && Lookup::setting('operational_statuses') === $opBefore,
    'and the lists are back to what they were');

/* --------------- 7. a stored value the list no longer carries ----------- */

echo "\n== a value the list no longer carries ==\n";

$orphanEmp = 'zz_gone_' . $suffix;
$orphanOp  = 'zz_gone_op_' . $suffix;

$orphanId = Database::insert('drivers', [
    'badge_number'       => 'ZZORPH-' . $suffix,
    'driver_name'        => 'ZZ Orphan Fixture',
    'iqama_number'       => '8' . $suffix,
    'status'             => $orphanEmp,
    'operational_status' => $orphanOp,
]);

$validate = new ReflectionMethod(\App\Controllers\DriverController::class, 'validate');
$validate->setAccessible(true);
$driverController = new \App\Controllers\DriverController();

$input = [
    'badge_number'       => 'ZZORPH-' . $suffix,
    'driver_name'        => 'ZZ Orphan Fixture',
    'iqama_number'       => '8' . $suffix,
    'status'             => $orphanEmp,
    'operational_status' => $orphanOp,
];

// The silent failure this guards: with no option to match, the browser submits
// the FIRST option and an unrelated edit rewrites the status (or wipes the
// operational flag), because validation refused the driver's own value.
$v = $validate->invoke($driverController, $input, $orphanId);
ok($v->passes(), 'an edit saves the value the driver already stores');

$v = $validate->invoke($driverController, $input, null);
ok(!$v->passes(), 'a NEW driver may still not use a value the list does not offer');

Database::run('DELETE FROM drivers WHERE id = ?', [$orphanId]);

$formSrc = (string) file_get_contents(dirname(__DIR__) . '/app/views/drivers/form.php');
ok(substr_count($formSrc, 'no longer in the list') === 2,
    'both selects render a stale value as its own option');
ok(str_contains($formSrc, "!array_key_exists(\$val('status', 'active'), \$empStatuses)"),
    'the employment select checks the stored value against the list');
ok(str_contains($formSrc, "!array_key_exists(\$val('operational_status'), \$opStatuses)"),
    'the operational select does too');

$settingsSrc = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/SettingsController.php');
ok(str_contains($settingsSrc, 'function optionListUsage(): array'), 'usage counts exist on the controller');
ok(str_contains($settingsSrc, 'Reassign those drivers first'), 'and feed the refusal');
$systemSrc = (string) file_get_contents(dirname(__DIR__) . '/app/views/settings/system.php');
ok(str_contains($systemSrc, 'In use by records:'), 'the screen shows them beside each list');

/* ------------------------------------------------- 6. the screens ------ */

echo "\n== screens ==\n";

$index = (string) file_get_contents(dirname(__DIR__) . '/app/views/drivers/index.php');
ok(str_contains($index, "'label' => 'Employment Status'"), 'the filter is labelled Employment Status');
ok(str_contains($index, 'operational_status'), 'the list filters on operational status');
ok(str_contains($index, "sort_header('operational_status'"), 'operational status has a sortable header');

$form = (string) file_get_contents(dirname(__DIR__) . '/app/views/drivers/form.php');
ok(str_contains($form, 'for="status">Employment Status'), 'the form labels the employment select');
ok(str_contains($form, 'name="operational_status"'), 'the form posts an operational status');
ok(str_contains($form, 'employmentStatuses()'), 'the employment options come from Settings');
ok(str_contains($form, 'operationalStatuses()'), 'the operational options come from Settings');

$profile = (string) file_get_contents(dirname(__DIR__) . '/app/views/drivers/profile.php');
ok(str_contains($profile, 'Operational Status'), 'the profile shows the operational status');

$controllerSrc = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/DriverController.php');
ok(str_contains($controllerSrc, "'Operational Status'"), 'the export carries an Operational Status column');
ok(str_contains($controllerSrc, 'employmentLabel'), 'the export shows labels, not slugs');

/* -------------------------------------------------------------- out --- */

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

