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

