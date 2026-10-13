<?php

declare(strict_types=1);

/**
 * Column preferences (show/hide + resize) and the Date Created column.
 *
 *   php tools/table-prefs-test.php
 *
 * Two features, one file because they ship together:
 *
 *   A. Leave Applications gained a "Date Created" column, on the list AND in
 *      the CSV, kept in step with the header count so the file cannot drift.
 *   B. Four tables opted into column show/hide and resize, persisted per
 *      table in localStorage. Source-level checks here - there is no browser
 *      in a CLI run - plus one live HTTP pass over the rendered page.
 *
 * Fixture-free: everything is read from the shipped sources and the live
 * page, so it cannot leave rows behind.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/table-prefs-test.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\LeaveApplication;

$pass = 0;
$fail = 0;

function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok    $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
    }
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

$root = APP_ROOT;
$view  = (string) file_get_contents($root . '/app/views/applications/index.php');

/* -------------------------------------------- A. the Date Created column -- */

section('A. the Date Created column');

ok(str_contains($view, "sort_header('created_at', 'Date Created'"),
    'the header is Date Created and it is sortable');
ok(str_contains($view, 'fmt_date_time($row[\'created_at\']'),
    'the cell renders created_at through fmt_date_time');
ok(in_array('created_at', array_keys(LeaveApplication::SORTABLE), true),
    'created_at is a whitelisted sort column');

// Header count and the empty-state colspan must agree, or a filtered-empty
// search spans the wrong number of cells. Most headers are emitted by
// sort_header(), not written as literal <th>, so count both sources.
preg_match('/<thead[^>]*>(.*?)<\/thead>/s', $view, $th);
$head    = $th[1] ?? '';
// Drop PHP comment lines first. A comment may legitimately MENTION a th tag -
// the two auxiliary headers below explain why they are hidden, and say so - and
// a bare preg_match_all would count that prose as a header, making the
// colspan check below fail for a reason that has nothing to do with markup.
// The sort_header() CALLS are emitted by PHP and are not comments, so they
// survive this strip.
//
// Deliberately free of the PHP close-tag sequence: that ends a // comment and
// turns the remainder of the file into printed HTML (it did, briefly - every
// assertion after it was echoed instead of running).
$head    = preg_replace('/^[ \t]*\/\/.*$/m', '', $head);
$thCount = (int) preg_match_all('/<th\b/', $head)
         + (int) preg_match_all('/\bsort_header\s*\(/', $head);
preg_match('/<td colspan="(\d+)">/', $view, $cs);
$colspan = isset($cs[1]) ? (int) $cs[1] : 0;

ok($thCount === 15, "the list now has 15 columns (found $thCount)");
ok($colspan === $thCount,
    "the empty state spans them all (colspan $colspan vs $thCount headers)");

// It is the LAST data column, so no earlier column moved index - which is
// what keeps saved column widths pointing at the right column.
$lastData = strpos($head, "sort_header('created_at'");
$actions  = strpos($head, '>Actions<');
ok($lastData !== false && $actions !== false && $lastData < $actions,
    'Date Created sits immediately before Actions');

// The export carries the same value, in the same place.
$ctrl = (string) file_get_contents($root . '/app/controllers/LeaveApplicationController.php');
ok(str_contains($ctrl, "'Date Created'"),
    'the CSV export has a Date Created header');
ok(str_contains($ctrl, 'fmt_date_time($r[\'created_at\']'),
    'the CSV cell uses the same helper as the screen');
ok(strpos($ctrl, "'Leave Status', 'Date Created'")
    !== false || strpos($ctrl, "'Leave Status',\n            'Date Created'")
    !== false || (bool) preg_match("/'Leave Status',\s*'Date Created'/", $ctrl),
    'the export keeps Date Created right after Leave Status, as on screen');

/* ------------------- A2. Last Updated + Created By, hidden by default ---- */

section('A2. the two auxiliary columns');

$recView = (string) file_get_contents($root . '/app/views/leave/records.php');
$recCtrl = (string) file_get_contents($root . '/app/controllers/LeaveController.php');

// Both screens, not just Leave Applications - the request covered both. They
// are SORTABLE now, so the header is a sort_header() call handed the inline
// style: that style is what table-prefs.js seeds the default from, and a bare
// <th> could never have carried the sort link.
foreach (['Leave Applications' => $view, 'Leave Records' => $recView] as $name => $src) {
    ok(str_contains($src, "sort_header('updated_at', 'Last Updated'")
        && str_contains($src, "sort_header('created_by_name', 'Created By'"),
        "$name offers Last Updated and Created By, sortable");
    ok(str_contains($src, "'Last Updated', \$sort, \$query ?? [], 'display:none'")
        && str_contains($src, "'Created By', \$sort, \$query ?? [], 'display:none'"),
        "$name still ships both HIDDEN, so nothing flashes before the script runs");
    ok(str_contains($src, "fmt_date_time(\$row['updated_at']"),
        "$name renders Last Updated through the same helper as Date Created");
    ok(str_contains($src, '$row[\'created_by_name\']'),
        "$name renders who created the record");
}

// The HELPER has to be what emits the style, or the columns would come back
// visible on the first paint and there would be no default to seed from.
$helper = (string) file_get_contents($root . '/app/helpers/functions.php');
ok(str_contains($helper, 'e($style)') && str_contains($helper, '$style = \'\''),
    'sort_header() takes an optional style and emits it (escaped)');

// Both keys are whitelisted - ONE map per model, and both controllers read it,
// so there is no second list that could silently reject the sort.
$recModel = (string) file_get_contents($root . '/app/models/LeaveRecord.php');
$appModel = (string) file_get_contents($root . '/app/models/LeaveApplication.php');
ok(str_contains($recModel, "'updated_at'") && str_contains($recModel, "'created_by_name'"),
    'Leave Records whitelists both sort keys');
ok(str_contains($recModel, "'created_by_name'       => 'cu.full_name'"),
    'and sorts Created By on the JOINED name, not an unjoined alias');
ok(str_contains($appModel, "'updated_at'") && str_contains($appModel, "'created_by_name'"),
    'Leave Applications whitelists both sort keys');
ok(str_contains($appModel, "'created_by_name'       => 'cu.full_name'"),
    'and sorts it the same way, on cu.full_name');

// Appended, never inserted: saved widths and hidden choices are keyed by
// column position, so a column in the middle would silently re-point them.
$recHead = '';
if (preg_match('/<thead[^>]*>(.*?)<\/thead>/s', $recView, $m)) {
    $recHead = preg_replace('/^[ \t]*\/\/.*$/m', '', $m[1]);
}
$createdPos = strpos($recHead, "sort_header('created_at'");
$updatedPos = strpos($recHead, "sort_header('updated_at'");
ok($createdPos !== false && $updatedPos !== false && $createdPos < $updatedPos,
    'Leave Records appends after Created, so no earlier column moved');

// The export carries them too, as Date Created already did.
$recData = '';
if (preg_match('/\$data = array_map\(.*?\}, \$rows\);/s', $recCtrl, $m)) {
    $recData = $m[0];
}
ok(str_contains($recCtrl, "'Last Updated', 'Created By'")
    && str_contains($recData, '$r[\'updated_at\']'),
    'the Leave Records export carries both, in step with the screen');

/* --------------------------------------- B. show/hide + resize wiring ---- */

section('B. column visibility and resizing');

$targets = [
    'leave-applications' => 'app/views/applications/index.php',
    'current-leave'      => 'app/views/leave/current.php',
    'leave-records'      => 'app/views/leave/records.php',
    'drivers'            => 'app/views/drivers/index.php',
];
$keys = [];

foreach ($targets as $key => $path) {
    $src = (string) file_get_contents($root . '/' . $path);
    ok(str_contains($src, 'data-dd-table'),
        basename($path) . ' still runs on the shared engine');
    ok(str_contains($src, 'data-dd-prefs="' . $key . '"'),
        basename($path) . ' opts in as "' . $key . '"');
    $keys[] = $key;
}

ok(count(array_unique($keys)) === count($keys),
    'every table has its own storage key, so screens cannot clobber each other');

// Loaded by the layout rather than by each view, so no screen can forget it.
$layout = (string) file_get_contents($root . '/app/views/layouts/app.php');
ok(str_contains($layout, 'assets/js/table-prefs.js'),
    'the layout loads table-prefs.js');

// From the HEAD, before the body exists. The reader's saved layout has to be
// applied while the grid is still being parsed - the browser must never be
// handed a frame with the columns they hid already showing. That is the flash
// on load, and again on every filtered query (the filter is a GET, so it is a
// full reload).
// The cut is on "\n<body>" rather than "<body": a comment that MENTIONS the
// body tag would otherwise end the head early and hide the script tag.
$head = substr($layout, 0, (int) strpos($layout, "\n<body>"));
ok(strpos($head, 'assets/js/table-prefs.js') !== false,
    'from the HEAD, so it runs before a single table exists');
ok(strpos($head, 'assets/js/table.js') === false,
    'while table.js still loads at the end of the body');
ok(strpos($layout, 'assets/js/table-prefs.js') < strpos($layout, 'assets/js/table.js'),
    'and therefore BEFORE table.js, which it no longer waits for');

$js = (string) file_get_contents($root . '/assets/js/table-prefs.js');
ok($js !== '', 'table-prefs.js exists');

// The flash fix. Two halves: the layout loads the script from the head, and
// the script applies what it reads from a MutationObserver while the parser
// is still working - an observer callback is a microtask, so it lands before
// the browser's rendering step rather than after the first paint.
ok(str_contains($js, 'new MutationObserver'),
    'the saved layout is applied from a MutationObserver while parsing');
ok(str_contains($js, 'observer.observe(document.documentElement, { childList: true, subtree: true })'),
    'watching the whole document for tables arriving');
ok(str_contains($js, 'observer.disconnect()'),
    'and handed over to boot() at DOMContentLoaded');
ok(str_contains($js, 'function collect()') && str_contains($js, 'function applyGroups('),
    'both passes share one scan and one apply, so they cannot drift');
ok(str_contains($js, "if (!document.querySelector('table')) { return; }"),
    'the parse-time pass does nothing before the first table exists');
ok(str_contains($js, 'lms.tableprefs.'),
    'prefs live under a namespaced localStorage key');
ok(str_contains($js, 'localStorage.setItem') && str_contains($js, 'localStorage.getItem'),
    'it reads and writes localStorage');
ok(str_contains($js, 'PREFIX + key'),
    'and the table name is part of the key - preferences stay per table');
ok(str_contains($js, 'LOCKED_LABELS') && str_contains($js, "'actions'"),
    'the Actions column is locked and cannot be hidden');
ok(str_contains($js, 'span.textContent = label'),
    'column labels are written as textContent, never as HTML');
ok(!preg_match('/span\.innerHTML|\.innerHTML\s*=\s*[^\'"]*label/', $js),
    'and never through innerHTML');
ok(str_contains($js, 'prefs.widths'), 'widths are persisted');
ok(str_contains($js, 'prefs.hidden'), 'hidden columns are persisted');
ok(str_contains($js, 'Reset this table'), 'the reader can reset a table');
ok(str_contains($js, 'Math.max(40'), 'a column cannot be dragged to nothing');

// The reader asked for the Columns control in the PAGE HEADER, on the same
// row as Print/Excel - not stranded below the filter bar. That is one
// decision in one place, so assert the decision itself exists.
ok(str_contains($js, 'Prefs.prototype.anchorFor'),
    'placement is decided in one place: anchorFor()');
ok(str_contains($js, "document.querySelector('.page-head')"),
    'it prefers the page header');
ok(str_contains($js, "head.querySelector('.btn-toolbar') || head"),
    'using the action row when the screen has one, the header itself when it has none');
ok(str_contains($js, 'grid || this.table.parentNode'),
    'and keeps the old spot above the grid as the fallback');
ok(str_contains($js, 'target.into'),
    'a header anchor is FILLED, the grid fallback is inserted before');
ok(str_contains($js, 'dropdown-menu-end'),
    'in the header the menu opens inwards instead of off-screen');
ok(str_contains($js, "bar.style.flex = '0 0 auto'"),
    'and the bar is pinned so it cannot shove the Excel button around');
ok(str_contains($js, "getAttribute('data-dd-prefs-bar') === this.key"),
    'one bar per table key, so booting twice cannot duplicate it');
ok(str_contains($js, 'input[type="checkbox"]'),
    'the bulk-select column is detected structurally, not by name');

// UNIFIED CONTROL. The opt-in is gone: a table joins by existing, so a grid
// added later needs no key, no id and no anchor of its own.
ok(str_contains($js, "document.querySelectorAll('table')"),
    'every table boots automatically - there is no per-table opt-in left');
// ...but a transient list can opt OUT. The drill-down modal's header is
// rewritten per card, and applyAll() writing display:'' back over it is what
// left the Date header standing above rows that had dropped the cell.
ok(str_contains($js, "hasAttribute('data-dd-skip')"),
    'and a table may opt out with data-dd-skip');
$modal = (string) file_get_contents($root . '/app/views/dashboard/index.php');
ok(str_contains($modal, 'id="sdTable" data-dd-skip'),
    'the drill-down modal table does opt out');
ok(str_contains($modal, 'id="sdDateHead"'),
    'so the Date header it hides for Total Active Drivers stays hidden');
ok(str_contains($js, "meta[name=\"page-key\"]"),
    'the control is keyed by the SCREEN, read from the layout meta tag');
ok(str_contains($js, 'function groupOf'),
    'grids on one screen are grouped together');
ok(str_contains($js, 'setHiddenByLabel'),
    'a change is spoken in labels, then translated per grid to its own index');

// THE TICK ITSELF USED TO DO NOTHING. Clicking the checkbox runs the
// browser's PRE-click activation (flipping it) BEFORE the handler runs, and
// preventDefault() reverts it AFTER the handler returns. So reading
// cb.checked inside the handler got the transient value, which re-applied
// whatever was already true: the row appeared to work while a click on the
// checkbox itself never changed the column - "cannot tick or untick".
// The handler must ask the MODEL, and re-sync once dispatch has finished.
ok(!preg_match('/setHiddenByLabel\(\s*label\s*,\s*cb\.checked\s*\)/', $js),
    'the handler never reads the tick, which is mid-activation on a click');
ok(str_contains($js, 'group.locate(label)'),
    'it asks the group which grid holds that column');
ok(str_contains($js, '!at.member.isHidden(at.index)'),
    'and inverts the MODEL state - the same answer whether row or tick was hit');
ok(str_contains($js, 'setTimeout(function () { group.syncMenu(); }, 0)'),
    'and re-syncs after dispatch, because the browser reverts the tick last');
ok(str_contains($js, 'group.labels()'),
    'the one menu is built from the union of every grid\'s columns');
ok(str_contains($js, 'window.ddPrefsBoot = boot'),
    'a grid that re-renders its rows can ask to be re-read (hidden stays hidden)');
ok(str_contains($js, 'storeKey'),
    'storage stays PER TABLE, so two grids cannot overwrite each other');

// HIDDEN BY DEFAULT. The server renders display:none so nothing flashes, but
// applyHidden() writes an explicit display on every cell - so without this the
// first paint would simply un-hide them again.
ok(str_contains($js, 'Prefs.prototype.seedDefaults'),
    'a server-shipped default column is adopted into the stored preference');
ok(str_contains($js, 'hasStored(this.storeKey)'),
    'and only while NOTHING is stored - a later choice is never overruled');
ok(str_contains($js, "th.style.display !== 'none'"),
    'the default is read off the header cell, so no extra attribute is needed');
ok(str_contains($js, 'this.seedDefaults()'),
    'the constructor runs it after build() has the column indexes');

// THE labelOf regression. sort_header() nests the label inside <a>:
//     <th class="sortable" ...><a class="link-dark ...">Driver <i .../></a></th>
// Reading only direct text nodes returned "" and the menu said
// "Column 2, Column 3" instead of the real names. The markup is authored in
// the helper, not in the view, so that is where it has to be asserted.
$helper = (string) file_get_contents($root . '/app/helpers/functions.php');
ok(str_contains($helper, '<th class="sortable'),
    'sort_header() emits a sortable <th>');
ok(str_contains($helper, '<a class="link-dark text-decoration-none"'),
    'and nests the label inside an <a> - the markup labelOf must see through');
ok(str_contains($js, "(th.textContent || '')"),
    'labelOf reads th.textContent, which sees through the <a>');
ok(!str_contains($js, 'nodeType === 3'),
    'and no longer walks only direct text nodes');
ok(str_contains($js, "'Column ' + (i + 1)"),
    'a numbered fallback remains, but only for a genuinely unnamed column');

// table.js addresses cells by index, and a display:none cell is still in the
// DOM - so hiding a column must not take a column out of the sort order.
$engine = (string) file_get_contents($root . '/assets/js/table.js');
ok(str_contains($engine, 'data-dd-table'),
    'the sorting engine is unchanged and still boots off data-dd-table');
ok(!str_contains($js, 'removeChild'),
    'the prefs script never removes a cell - sorting still sees every column');

$css = (string) file_get_contents($root . '/assets/css/app.css');
ok(str_contains($css, '.dd-resize'), 'the drag handle is styled');
ok(str_contains($css, '.dd-prefs-bar'), 'and so is the Columns bar');


/* --------------------------------- C. live: the page actually renders it -- */

section('C. the live page');

$base = 'http://localhost/lms';
$jar  = tempnam(sys_get_temp_dir(), 'prefs');

$call = static function (string $url, array $post = []) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => false,
    ]);
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => (string) $body];
};

$login = $call("$base/login");
preg_match('/name="_csrf"\s+value="([^"]+)"/', $login['body'], $m);
ok(($m[1] ?? '') !== '', 'a CSRF token was offered');

$call("$base/login", [
    '_csrf'    => $m[1] ?? '',
    'username' => 'admin',
    'password' => getenv('LMS_TEST_PASS') ?: 'admin123',
    'redirect' => '',
]);

$page = $call("$base/leave-applications");
ok($page['code'] === 200, "the list opens (HTTP {$page['code']})");
ok(!str_contains($page['body'], 'Something went wrong'), 'and renders without a 500');
ok(str_contains($page['body'], 'Date Created'), 'the Date Created header renders');

// The two auxiliary headers must still be hidden AFTER render - sort_header()
// builds the markup, so if it dropped the style the columns would flash on the
// first paint and there would be no default for the seeding to pick up.
// NOTE: the label sits INSIDE the sort anchor, so match the whole <th> rather
// than expecting ">" to be followed straight by the label.
$thFor = static function (string $html, string $key): string {
    return preg_match('/<th\b[^>]*data-dd-sort="' . preg_quote($key, '/') . '"[^>]*>.*?<\/th>/s', $html, $m)
        ? $m[0]
        : '';
};
$shipsHidden = static function (string $html) use ($thFor): bool {
    foreach (['updated_at' => 'Last Updated', 'created_by_name' => 'Created By'] as $k => $label) {
        $th = $thFor($html, $k);
        if ($th === '' || !str_contains($th, 'style="display:none"') || !str_contains($th, $label)) {
            return false;
        }
    }
    return true;
};

ok($shipsHidden($page['body']),
    'the rendered page still ships Last Updated and Created By hidden');

// And the sort has to be ACCEPTED, not merely linked: both controllers read
// ?sort= against the same SORTABLE whitelist the header was built from.
foreach (['updated_at', 'created_by_name', 'created_at'] as $key) {
    $asc  = $call("$base/leave-applications?sort=$key&dir=asc");
    $desc = $call("$base/leave-applications?sort=$key&dir=desc");
    ok($asc['code'] === 200 && $desc['code'] === 200
        && !str_contains($asc['body'], 'Something went wrong'),
        "sort=$key is accepted (HTTP {$asc['code']}/{$desc['code']})");
}

// Same on Leave Records, which has its own whitelist and controller.
$recPage = $call("$base/leave/records");
ok($recPage['code'] === 200, "Leave Records opens (HTTP {$recPage['code']})");
ok($shipsHidden($recPage['body']),
    'and ships both auxiliary columns hidden too');
foreach (['updated_at', 'created_by_name'] as $key) {
    $s1 = $call("$base/leave/records?sort=$key&dir=asc");
    $s2 = $call("$base/leave/records?sort=$key&dir=desc");
    ok($s1['code'] === 200 && $s2['code'] === 200
        && !str_contains($s1['body'], 'Something went wrong'),
        "records sort=$key is accepted (HTTP {$s1['code']}/{$s2['code']})");
}
ok(str_contains($page['body'], 'data-dd-prefs="leave-applications"'),
    'and the table is opted into column preferences');
ok(str_contains($page['body'], 'assets/js/table-prefs.js'),
    'the page ships the prefs script');
ok(str_contains($page['body'], 'name="page-key"'),
    'and publishes its screen key, which is what the Columns control groups on');

// anchorFor() looks for .page-head .btn-toolbar. If the partial ever stops
// rendering it the button silently drops back below the table, which is the
// regression this whole change exists to prevent - so pin it server-side.
ok(str_contains($page['body'], 'mb-3 page-head"'),
    'the header row the Columns control moves into is rendered');
ok(str_contains($page['body'], 'btn-toolbar gap-2 flex-wrap'),
    'and it has an action toolbar for the control to join');
ok(substr_count($page['body'], 'mb-3 page-head"') === 1,
    'exactly one header on the screen, so the first match is this screen\'s ('
    . substr_count($page['body'], 'mb-3 page-head"') . ' found)');

// Address Book is the screen that PROVED the toolbar-less path was needed:
// all three of its header buttons sit behind can(drivers.create/import/
// export), so a role holding none of them renders a bare header with no
// .btn-toolbar at all. page-head must still be there, because anchorFor()
// falls back to it and would otherwise strand the control below the filters.
$drivers = $call("$base/drivers");
ok($drivers['code'] === 200, "Address Book opens (HTTP {$drivers['code']})");
ok(str_contains($drivers['body'], 'mb-3 page-head"'),
    'Address Book renders a header row for the control to move into');
ok(str_contains($drivers['body'], 'data-dd-prefs="drivers"'),
    'and its table is opted into column preferences');
ok(str_contains($drivers['body'], 'btn-toolbar gap-2 flex-wrap'),
    'this role has header actions, so a toolbar exists here too'
    . ' - the toolbar-less path is covered by the harness');

// The CSV must carry the same column count on every line, or a spreadsheet
// splits the fields across cells.
$csv = $call("$base/leave-applications/export?export=csv");
ok($csv['code'] === 200, "the export runs (HTTP {$csv['code']})");

$lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv['body']))));
$head  = str_getcsv((string) ($lines[0] ?? ''));

ok(array_search('Date Created', $head, true) !== false,
    'Date Created is in the CSV header');
ok(count($head) === 16,
    'the CSV header has 16 columns (found ' . count($head) . ')');
ok(array_search('Last Updated', $head, true) !== false
    && array_search('Created By', $head, true) !== false,
    'the export carries both auxiliary columns');

$bad = [];
$n   = 0;
foreach (array_slice($lines, 1) as $i => $line) {
    if (trim($line) === '') { continue; }
    $cells = str_getcsv($line);
    if (count($cells) === count($head)) {
        $n++;
    } else {
        $bad[] = 'row ' . ($i + 1) . '=' . count($cells);
    }
}
ok($bad === [], "every CSV row matches the header ($n aligned)"
    . ($bad === [] ? '' : ' -> ' . implode(', ', array_slice($bad, 0, 3))));

/* ------------------------ D. the screens that withdraw the control ------- */

section('D. KPI screens withdraw the Columns control');

// Three screens are DRILL pages: Leave Dashboard, Driver Dashboard and
// Capacity Planning. Their grids sit behind KPI cards and inside modals, so
// ONE "Columns" button in their header would list columns from several
// unrelated tables at once and broadcast a change to whichever grid booted
// first. page-head takes $no_prefs and renders data-dd-prefs-none; addMenu()
// returns before it builds anything.
//
// The distinction that matters: only the BUTTON goes. If the guard ever
// crept up into boot() the grids would also lose their resizers and their
// stored hidden columns, which nobody asked for.
$head = (string) file_get_contents($root . '/app/views/partials/page-head.php');
ok(str_contains($head, '$no_prefs    = $no_prefs ?? false'),
    'page-head accepts $no_prefs and defaults it to false');
ok(str_contains($head, "' data-dd-prefs-none'"),
    'and emits data-dd-prefs-none only when asked to');

ok(substr_count($js, "document.querySelector('[data-dd-prefs-none]')") === 1,
    'table-prefs.js has exactly one guard for it');

$atAddMenu = strpos($js, 'Prefs.prototype.addMenu = function ()');
$atGuard   = strpos($js, "document.querySelector('[data-dd-prefs-none]')");
ok($atAddMenu !== false && $atGuard !== false && $atGuard > $atAddMenu,
    'the guard sits inside addMenu() - the right place to stop');
ok(str_contains($js, 'p.addResizers()') && str_contains($js, 'group.applyAll()'),
    'and boot() still runs addResizers() and applyAll(): only the button is withdrawn');

// Each of the three must opt out, and must still render a normal header -
// the fix was to remove one control, not to give up on the page.
$kpi = [
    '/dashboard'         => 'Leave Dashboard',
    '/driver-dashboard'  => 'Driver Dashboard',
    '/capacity-planning' => 'Capacity Planning',
];
foreach ($kpi as $path => $label) {
    $p = $call("$base$path");
    ok($p['code'] === 200, "$label opens (HTTP {$p['code']})");
    ok(str_contains($p['body'], 'data-dd-prefs-none'),
        "$label withdraws the Columns control");
    ok(str_contains($p['body'], 'mb-3 page-head"'),
        "$label still renders its header - only the button is gone");
}

// And it must be exactly those three. An ordinary list screen keeps its
// control, which is also the proof that the attribute is opt-in per screen
// rather than switched off globally.
$stay = [
    '/drivers'          => 'Address Book',
    '/leave/current'    => 'Current leave',
    '/leave-applications' => 'Applications',
];
foreach ($stay as $path => $label) {
    $p = $call("$base$path");
    ok($p['code'] === 200, "$label opens (HTTP {$p['code']})");
    ok(!str_contains($p['body'], 'data-dd-prefs-none'),
        "$label still offers its Columns control");
}

@unlink($jar);

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

