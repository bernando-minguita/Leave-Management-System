<?php
/**
 * Destination Country: the Settings-driven country dropdown on both leave
 * forms, and the rename that travels with it.
 *
 *   php C:/xampp/htdocs/lms/tools/destination-test.php
 *
 * What must stay true:
 *   1. The list is PLAIN - a country's name is both its value and its label,
 *      so the destination filter, the three exports and every row written
 *      before the field became a dropdown keep matching with no backfill.
 *   2. Settings owns the list (it seeds itself and validates the edit), and
 *      Lookup falls back to what shipped when that row is missing.
 *   3. Both forms render a <select> whose first option is "Not set".
 *   4. A stored destination the current list does not carry is still offered,
 *      rather than the form silently falling back to Not set and wiping it.
 *   5. Every screen says "Destination Country".
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\SettingsController;
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

$root = dirname(__DIR__);

/* ------------------------------------------------ 1. the shipped list -- */

echo "== the shipped list ==\n";

$shipped = Lookup::parsePlainList(Lookup::DESTINATION_COUNTRIES);

ok(count($shipped) >= 30, 'the shipped list carries ' . count($shipped) . ' countries');

foreach (['Saudi Arabia', 'India', 'Philippines', 'Pakistan'] as $home) {
    ok(in_array($home, $shipped, true), '"' . $home . '" is one of them');
}

// settings.setting_value is VARCHAR(500) and saveSettings() truncates there,
// so a list that does not fit would be cut mid-name with no warning.
ok(mb_strlen(Lookup::DESTINATION_COUNTRIES) <= 500,
    'it fits a setting (' . mb_strlen(Lookup::DESTINATION_COUNTRIES) . ' of 500 characters)');

/* ----------------------------------------------- 2. parsePlainList ----- */

echo "\n== parsePlainList ==\n";

ok(Lookup::parsePlainList('Saudi Arabia, India ,Philippines') === ['Saudi Arabia', 'India', 'Philippines'],
    'names split on commas and trim');
ok(Lookup::parsePlainList('India,') === ['India'], 'a trailing comma is not a mistake');
ok(Lookup::parsePlainList("India,\n\nBangladesh") === ['India', 'Bangladesh'], 'nor a blank line');

$errors = [];
ok(Lookup::parsePlainList('sa|Saudi Arabia', $errors) === [] && $errors !== [],
    'a value|Label pair is refused - that syntax belongs to the other two lists');

$errors = [];
Lookup::parsePlainList('India,india', $errors);
ok($errors !== [], 'a duplicate is refused, whatever the case');

$errors = [];
Lookup::parsePlainList(str_repeat('x', 121), $errors);
ok($errors !== [], 'a name longer than the 120 characters the column holds is refused');

$errors = [];
ok(Lookup::parsePlainList('', $errors) === [] && $errors !== [],
    'an empty list is refused');

$errors = [];
ok(Lookup::parsePlainList(implode(',', $shipped), $errors) === $shipped && $errors === [],
    'the shipped list survives its own round trip');


/* --------------------------------------------- 3. the Settings row ----- */

echo "\n== the settings row ==\n";

// The row is seeded by the System Settings screen itself, INSERT IGNORE, so
// an install that received the patch files without running any migration
// still gets an editable field - and so an admin's tuned list is never
// overwritten by visiting the page.
$seed = new ReflectionMethod(SettingsController::class, 'ensureDefaultSettings');
$seed->setAccessible(true);
$seed->invoke(new SettingsController());

$stored = (string) Lookup::setting('destination_countries');
ok($stored !== '', 'the destination list is on file in settings');

$countries = Lookup::destinationCountries();
ok($countries !== [], 'Lookup reads a usable list');
ok($countries === Lookup::parsePlainList($stored),
    'the list comes straight from settings, with no second hard-coded copy');

$unusable = array_filter(
    $countries,
    static fn (string $one): bool => str_contains($one, '|') || mb_strlen($one) > 120
);
ok($unusable === [], 'every country on file is a plain name of at most 120 characters');

$settingsSrc = (string) file_get_contents($root . '/app/controllers/SettingsController.php');
ok(str_contains($settingsSrc, "'destination_countries', Lookup::DESTINATION_COUNTRIES"),
    'Settings seeds the row itself, so an upgrade needs no migration');
ok(str_contains($settingsSrc, "'employment_statuses', 'operational_statuses', 'destination_countries'"),
    'and puts it through the same edit validation as the other two lists');

$systemSrc = (string) file_get_contents($root . '/app/views/settings/system.php');
ok(str_contains($systemSrc, "'destination_countries'"), 'the System Settings screen renders it');
ok(str_contains($systemSrc, '$isPlainList'), 'with its own wording, not the value|Label help text');

$seedSql = (string) file_get_contents($root . '/database/seed.sql');
ok(str_contains($seedSql, "('destination_countries',"), 'seed.sql covers a fresh install too');

/* ---------------------------------------------- 4. both forms ---------- */

echo "\n== both forms ==\n";

$forms = ['app/views/applications/form.php', 'app/views/leave/form.php'];

foreach ($forms as $view) {
    $src = (string) file_get_contents($root . '/' . $view);

    ok(str_contains($src, 'for="destination">Destination Country</label>'),
        $view . ' labels it Destination Country');

    // The attribute block straddles a PHP short tag, so match up to </select>
    // rather than to the first '>' - a plain [^>]* would stop early.
    ok(preg_match('/<select\b(?:(?!<\/select>).)*name="destination"/s', $src) === 1,
        $view . ' renders the field as a <select>');

    ok(substr_count($src, 'name="destination"') === 1,
        $view . ' has no free-text destination left behind it');

    ok(str_contains($src, '<option value="">— Not set —</option>'),
        $view . ' offers Not set first');

    ok(str_contains($src, 'Lookup::destinationCountries()'),
        $view . ' takes its options from Settings');
}

/* -------------------------- 5. a stored value outside the list --------- */

echo "\n== a stored value outside the list ==\n";

// The failure this guards is silent: with no option to match, the select
// falls back to "Not set" and the save writes NULL over a real destination.
foreach ($forms as $view) {
    $src = (string) file_get_contents($root . '/' . $view);

    ok(str_contains($src, "!in_array(\$val('destination'), \$countries, true)"),
        $view . ' still offers a value its list does not carry');
}

$js = (string) file_get_contents($root . '/assets/js/leave.js');
ok(str_contains($js, 'function fillDestination(value)'), 'leave.js has a destination-specific filler');
ok(str_contains($js, 'fillDestination(app.destination)'), 'the application picker routes through it');
ok(!str_contains($js, "fillIfEmpty('destination'"),
    'and no longer sends a select through fillIfEmpty, which would drop it');
ok(str_contains($js, 'new Option(String(value), String(value))'),
    'an unknown country is added rather than discarded');

/* ----------------------------- 6. pasted lists ------------------------- */

echo "\n== pasted lists ==\n";

$errors = [];
$pasted = Lookup::parsePlainList("Saudi Arabia\nIndia\r\nQatar", $errors);
ok($pasted === ['Saudi Arabia', 'India', 'Qatar'] && $errors === [],
    'a column pasted with line breaks becomes one country per line');

$errors = [];
$pasted = Lookup::parsePlainList("Saudi Arabia\tIndia", $errors);
ok($pasted === ['Saudi Arabia', 'India'], 'a pasted row splits on tabs too');

$errors = [];
$bad = Lookup::parsePlainList("Saudi\x0BArabia", $errors);
ok($bad === [] && $errors !== [], 'a control character inside a name is refused');

$errors = [];
$pairs = Lookup::parseOptionListString("active|Active\ninactive|Inactive", $errors);
ok($pairs === ['active' => 'Active', 'inactive' => 'Inactive'],
    'value|Label pairs may be pasted one per line as well');

/* ------------------- 7. removing a country still in use ---------------- */

echo "\n== removing a country leave rows still use ==\n";

$saveSettings = new ReflectionMethod(SettingsController::class, 'saveSettings');
$saveSettings->setAccessible(true);
$settingsController = new SettingsController();
$original = (string) Lookup::setting('destination_countries');

$result = $saveSettings->invoke($settingsController, ['settings' => ['destination_countries' => $original . ',ZZ Testland']]);
ok(($result['ok'] ?? null) === true, 'a country nothing uses can be added');

$result = $saveSettings->invoke($settingsController, ['settings' => ['destination_countries' => $original]]);
ok(($result['ok'] ?? null) === true && Lookup::setting('destination_countries') === $original,
    'and removed again with no complaint');

// A country real leave rows carry: the removal is ALLOWED, because history
// keeps its own value - but the save says so rather than staying silent.
$used = null;

foreach (Lookup::destinationCountries() as $country) {
    $rows = (int)\App\Core\Database::scalar(
        'SELECT COUNT(*) FROM leave_records WHERE LOWER(destination) = ?',
        [mb_strtolower($country)]
    );

    if ($rows > 0) {
        $used = $country;
        break;
    }
}

if ($used !== null) {
    $reduced = implode(',', array_values(array_filter(
        Lookup::destinationCountries(),
        static fn (string $c): bool => mb_strtolower($c) !== mb_strtolower((string)$used)
    )));

    $result = $saveSettings->invoke($settingsController, ['settings' => ['destination_countries' => $reduced]]);
    ok(($result['ok'] ?? null) === true, 'a country leave rows still use can be removed');
    ok(str_contains((string)($result['message'] ?? ''), 'keep their value'),
        'and the save reports that those rows keep it');

    $result = $saveSettings->invoke($settingsController, ['settings' => ['destination_countries' => $original]]);
    ok(($result['ok'] ?? null) === true && Lookup::setting('destination_countries') === $original,
        'the original list is restored');
} else {
    echo "  (skipped: no configured country is used by a leave row yet)\n";
}

/* ---------------------------------------- 6. the rename ---------------- */

echo "\n== every screen says Destination Country ==\n";

$displays = [
    'app/views/applications/view.php',
    'app/views/dashboard/index.php',
    'app/views/drivers/profile.php',
    'app/views/leave/current.php',
    'app/views/leave/records.php',
    'app/views/leave/returns.php',
    'app/views/leave/view.php',
    'app/views/partials/leave-history-table.php',
    'app/views/reports/leave.php',
];

$leftovers = [];

foreach ($displays as $view) {
    $src = (string) file_get_contents($root . '/' . $view);

    if (!str_contains($src, 'Destination Country')) {
        $leftovers[] = $view . ' (never renamed)';
        continue;
    }

    // >Destination< and >Destination: for markup, 'Destination' for a sort
    // header. Lower-case 'destination' is the field name and never matches.
    if (preg_match('/>Destination(?:<|:)|[\'"]Destination[\'"]/', $src)) {
        $leftovers[] = $view . ' (still shows a bare Destination)';
    }
}

ok($leftovers === [], 'all nine display surfaces renamed'
    . ($leftovers === [] ? '' : ' - ' . implode(', ', $leftovers)));

echo "\n== exports and validation ==\n";

$controllers = [
    'app/controllers/LeaveApplicationController.php',
    'app/controllers/LeaveController.php',
    'app/controllers/ReportController.php',
];

foreach ($controllers as $ctrl) {
    $src = (string) file_get_contents($root . '/' . $ctrl);

    ok(str_contains($src, "'Destination Country'"), $ctrl . ' exports the new header');
    ok(!preg_match('/[\'"]Destination[\'"]/', $src), $ctrl . ' has no bare Destination left');
    ok(!str_contains($src, "->maxLength('destination', 'Destination', 120)"),
        $ctrl . ' does not validate it under the old name');
}

ok(str_contains((string) file_get_contents($root . '/app/controllers/LeaveApplicationController.php'),
        "->maxLength('destination', 'Destination Country', 120)"),
    'the application form validates it under the new name');
ok(str_contains((string) file_get_contents($root . '/app/controllers/LeaveController.php'),
        "->maxLength('destination', 'Destination Country', 120)"),
    'the leave form validates it under the new name');

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
