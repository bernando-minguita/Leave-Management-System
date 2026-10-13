<?php
/**
 * In-place tables: the engine's contract, checked from source.
 *
 *   php tools/table-engine-test.php
 *
 * There is no browser or Node on the build box, so the JavaScript cannot be
 * executed here - what CAN be pinned is the wiring that has already gone
 * wrong once each, plus the markup every converted grid must carry. Each of
 * these is a bug that shipped and came back as a review comment:
 *
 *   - sorting counted only the sortable headers, so it read the "#" column
 *   - sorting reordered the DOM but not this.rows, so the page slice and the
 *     index renumbering used the pre-sort order
 *   - pagination() only emits a <nav> for multi-page tables, so a Rows change
 *     had nothing to fill
 *   - the Leave Application form showed a Leave Status it could not own, and
 *     kept Duration visible for a driver who never returns
 *   - sorting lived only in JS memory, so the first Apply dropped it and the
 *     table fell back to its default order
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

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

$root = APP_ROOT;
$js    = (string)file_get_contents($root . '/assets/js/table.js');
$leave = (string)file_get_contents($root . '/assets/js/leave.js');
$view  = (string)file_get_contents($root . '/app/views/applications/form.php');
$leaveForm = (string)file_get_contents($root . '/app/views/leave/form.php');
$funcs = (string)file_get_contents($root . '/app/helpers/functions.php');

echo "\n== the engine sorts the right cell ==\n";
ok(str_contains($js, 'th.cellIndex'),
    "the sort key is the header's real column index, not its position in the sortable list");
ok(!preg_match('/forEach\(function \(th, i\) \{\s*if \(th\.getAttribute\([^)]*\) === this\.sort\)\s*\{\s*index = i;/s', $js),
    'it no longer falls back to counting sortable headers');
ok(str_contains($js, 'row.cells[index]'),
    "the value still comes from the row's cell at that index");

echo "\n== the sort result is what the page shows ==\n";
ok(str_contains($js, 'this.rows = this.rows'),
    'sorting assigns back to this.rows, so paging and "#" renumbering follow it');
ok(str_contains($js, 'row.cells[0]'),
    'the "#" column is renumbered after every sort');
ok(str_contains($js, "textContent.trim() === '#'"),
    'the index column is recognised from its header');

echo "\n== dates sort as dates ==\n";
ok(str_contains($js, 'detectDayFirst'),
    'an ambiguous A/B/YYYY column has its order settled before comparing');
ok(str_contains($js, 'MONTHS[human[2].toLowerCase()]'),
    'the application default (d M Y) parses as a date');
ok(str_contains($js, 'Date.UTC(+iso[1]'),
    'ISO dates parse as dates');

echo "\n== a table that starts on one page still gets page links ==\n";
ok(str_contains($js, 'Engine.prototype.buildNav'),
    'the engine can build the nav pagination() omitted');
ok(str_contains($js, "this.pager.addEventListener('click'"),
    'page clicks are delegated on the pager, which may exist before the nav');

echo "\n== one toggle per click ==\n";
// A click on the anchor inside a sortable <th> bubbles up to the <th>.
// Binding the toggle handler to BOTH fired it twice, and two toggles of
//   dir = (same key && asc) ? 'desc' : 'asc'
// always land on 'desc': the first click sorted descending and every click
// after that toggled asc and straight back to desc, so ascending was
// unreachable with a mouse. Exactly one binding, on the <th> - the anchor
// only cancels its own navigation.
ok(substr_count($js, "addEventListener('click', run)") === 1,
    'the sort toggle is bound exactly once');
ok((bool) preg_match(
    "/link\.addEventListener\('click', function \(event\) \{\s*event\.preventDefault\(\);/",
    $js
), "the anchor's own listener only preventDefaults, never sorts");

echo "\n== every converted grid is marked ==\n";
foreach ([
    'app/views/leave/records.php',
    'app/views/leave/current.php',
    'app/views/applications/index.php',
    'app/views/drivers/index.php',
    'app/views/settings/audit.php',
    'app/views/leave/returns.php',
    'app/views/capacity/index.php',
] as $file) {
    $src = (string)file_get_contents($root . '/' . $file);
    ok(str_contains($src, 'data-dd-table'), basename($file) . ' is marked data-dd-table');
    ok(str_contains($src, 'data-dd-row'), basename($file) . ' marks its data rows');
    ok(!str_contains($src, '$rowNo = offset()'),
        basename($file) . ' numbers rows from 1, not from the URL');
}

echo "\n== Expected Returns sorts every board ==\n";
$returns = (string)file_get_contents($root . '/app/views/leave/returns.php');
ok(str_contains($funcs, 'function sort_header_client'),
    'a sortable header with no server-side link exists for lists that sort in place');
ok(substr_count($returns, '<?= sort_header_client(') === 8,
    'all eight sortable columns on Expected Returns use it (got '
    . substr_count($returns, '<?= sort_header_client(') . ')');
ok(!str_contains($returns, '<th>Driver</th>'),
    'no plain unsortable header is left on those boards');

// sort_header_client() emits no <a>, so it cannot borrow the browser's default
// pointer for anchors - every other list gets that from sort_header()'s link.
// Without an explicit rule these eight headers showed an arrow while table.js
// kept them clickable, and the screen was the only one that looked broken.
$css = (string)file_get_contents($root . '/assets/css/app.css');
ok((bool)preg_match('/th\.sortable[^{}]*\{[^}]*cursor:\s*pointer/', $css),
    'a sortable <th> carries the pointer cursor even with no anchor to borrow it from');

// The four short boards carry no pager, so they must page at their own row
// count - a default of 10 would hide rows with no page links to recover them.
ok(str_contains($returns, '$isMonth ? per_page() : max(1, count($rows))'),
    'the unpaged boards size to their own rows, so the engine hides nothing');

echo "\n== the Address Book grid has the same floor as the other lists ==\n";
$driversIndex = (string)file_get_contents($root . '/app/views/drivers/index.php');
ok(str_contains($driversIndex, 'table-responsive table-grid'),
    'a one-result search still has room for its row-action dropdown');

ok(str_contains($funcs, 'function table_row_cap'),
    'the controller-side row cap exists');
ok(str_contains($funcs, 'data-dd-pager'),
    'pagination() emits the pager marker');
ok(str_contains($funcs, 'data-dd-sort'),
    'sort_header() emits the sort key');

echo "\n== the Leave Application form ==\n";
ok(!str_contains($view, 'id="leave_status"'),
    'Leave Status is no longer a control on the form');
ok(str_contains($view, 'name="leave_status"'),
    'it still posts, so a create carries a value');
ok((bool)preg_match('/id="expectedArrivalWrap"[^>]*data-no-return-wrap/', $view),
    'Expected Return Date is marked as a no-return field');
ok((bool)preg_match('/id="daysAwayWrap"[^>]*data-no-return-wrap/', $view),
    'Duration (info only) is marked as a no-return field');
ok(str_contains($view, 'Duration (info only)'),
    'Duration is still rendered for types that do return');
ok(str_contains($leave, '[data-no-return-wrap], #expectedArrivalWrap'),
    'leave.js hides every marked field together');
ok(str_contains($leave, 'NO_RETURN_TYPE = /resign|terminat|abscond/i'),
    'and matches the same rule the server applies');
ok(str_contains($leave, "document.getElementById('daysAway')"),
    'a cleared arrival date clears the derived Duration');
ok(str_contains($leave, 'ticketInput'),
    'and Ticket Date is cleared alongside the arrival date');

/* -------------------------------- New Leave / Vacation ------------------ */
// The same three questions sit on the New Leave / Vacation screen too, and
// that is where the regression was: not one of its wrappers carried the
// marker, so leave.js selected nothing, skipped its ENTIRE block, and all
// three fields stayed on screen for every leave type - Resignation,
// Terminated and Absconded included.
echo "\n== the New Leave / Vacation form marks the same three fields ==\n";

ok((bool)preg_match('/id="ticketDateWrap"[^>]*data-no-return-wrap/', $leaveForm),
    'Ticket Date is marked as a no-return field');
ok((bool)preg_match('/id="expectedArrivalWrap"[^>]*data-no-return-wrap/', $leaveForm),
    'Expected Return Date is marked as a no-return field');
ok((bool)preg_match('/id="daysAwayWrap"[^>]*data-no-return-wrap/', $leaveForm),
    'Duration (info only) is marked as a no-return field');
ok(substr_count($leaveForm, 'data-no-return-wrap>') === 3,
    'exactly those three and no others (found '
    . substr_count($leaveForm, 'data-no-return-wrap>') . ')');
ok(str_contains($leaveForm, 'name="ticket_date"'),
    'Ticket Date is a field only this form has');

// Hiding must not make the record unsavable: leave.js disables the input, so
// nothing is posted, and validate() has to stop demanding it - but only for
// a type that genuinely has no expected return.
$leaveCtrl = (string)file_get_contents($root . '/app/controllers/LeaveController.php');
ok(str_contains($leaveCtrl, '!in_array($typeId, LeaveApplication::noReturnTypeIds(), true)'),
    'validate() demands an expected return only from a type that comes back');
ok(str_contains($leaveCtrl, "'expected_arrival_date' => LeaveApplication::expectedArrivalFor("),
    'and payload() drops anything posted for a no-return type');

/* ------------------------------- sort persistence ------------------------- */
// The sort used to live in JS memory alone: table.js preventDefaults the
// header's real link, and every filter form was built from its own named
// inputs - so the first Apply handed back a query string with ?sort= stripped
// out, sort_state() fell back to the default, and data-dd-initial re-seeded
// the engine with it. The URL is now the record of the choice, in both
// directions: the engine writes it, the forms carry it.
echo "\n== the sort survives a query ==\n";

ok(str_contains($funcs, 'function hold_sort'),
    'a hidden-input helper exists for the GET filter forms');
ok(str_contains($funcs, 'function sort_params'),
    'a query-merging helper exists for links built by hand');

// Exercised for real rather than matched in source: these read $_GET directly,
// exactly as they will on a request.
$_GET = ['sort' => 'badge_number', 'dir' => 'DESC'];
$held = hold_sort();
ok(str_contains($held, 'name="sort"') && str_contains($held, 'value="badge_number"'),
    'the sort key is submitted with the filters');
ok(str_contains($held, 'name="dir" value="desc"'),
    'the direction is normalised to asc|desc');
$_GET = ['sort' => '<script>alert(1)</script>', 'dir' => 'desc'];
ok(!str_contains(hold_sort(), '<script>'),
    'the key is escaped - it comes straight from the query string');
$_GET = ['sort' => 'badge_number'];
ok(!str_contains(hold_sort(), 'name="dir"'),
    'dir is only sent when the reader had one, so a controller default is not flipped');
$_GET = ['sort' => 'badge_number', 'dir' => 'sideways'];
ok(!str_contains(hold_sort(), 'name="dir"'),
    'an unusable direction is dropped for the same reason');
$_GET = [];
ok(hold_sort() === '',
    'nothing is emitted on a screen that was never sorted');

$_GET = ['sort' => 'driver_name', 'dir' => 'desc'];
$merged = sort_params(['q' => 'ahmed']);
ok(($merged['sort'] ?? '') === 'driver_name' && ($merged['dir'] ?? '') === 'desc',
    'sort_params() merges the URL sort into a hand-built query');
$_GET = [];
ok(sort_params(['q' => 'ahmed']) === ['q' => 'ahmed'],
    'and adds nothing when there is no sort in the URL');

// Every list filter form has to carry it: a GET form sends only the fields it
// holds, which is the whole reason the sort was disappearing.
$filterBar = (string)file_get_contents($root . '/app/views/partials/filter-bar.php');
$appIndex  = (string)file_get_contents($root . '/app/views/applications/index.php');
$changes   = (string)file_get_contents($root . '/app/views/drivers/changes.php');
$returnsSrc = (string)file_get_contents($root . '/app/views/leave/returns.php');

ok(substr_count($filterBar, '<?= hold_sort() ?>') === 1,
    'the shared filter bar carries the sort (Current Leave, Records, Returns)');
ok(str_contains($appIndex, 'hold_sort()'),
    'the Leave Applications filter form carries it');
ok(str_contains($driversIndex, 'hold_sort()'),
    'the Address Book filter form carries it');
ok(str_contains($changes, 'hold_sort()'),
    'the Driver Changes filter form carries it');
ok(substr_count($returnsSrc, 'hold_sort()') === 2,
    'Expected Returns carries it in both of its own forms - month and pager');

// Rendered, not just matched: with a sort in the URL the shared filter bar
// must produce the inputs that put it back on the way out.
$_GET = ['sort' => 'leave_start_date', 'dir' => 'desc'];
$rendered = \App\Core\View::partial('partials/filter-bar', [
    'action' => 'leave/records', 'filters' => [], 'branches' => [],
    'agencies' => [], 'leaveTypes' => [],
]);
ok(str_contains($rendered, 'name="sort" value="leave_start_date"')
    && str_contains($rendered, 'name="dir" value="desc"'),
    'a filtered GET re-sends the sort (rendered form, not source)');
$_GET = [];
$rendered = \App\Core\View::partial('partials/filter-bar', [
    'action' => 'leave/records', 'filters' => [], 'branches' => [],
    'agencies' => [], 'leaveTypes' => [],
]);
ok(!str_contains($rendered, 'name="sort"'),
    'and an unsorted screen stays free of the inputs');

// The links assembled from $filters by hand - status chips, export buttons -
// would otherwise hand back a register (or a file) ordered differently from
// the one on screen.
ok(str_contains($appIndex, 'sort_params(array_filter('),
    'the Leave Applications status chips keep the sort');
ok(str_contains($appIndex, "sort_params(array_merge(\$filters, ['export' => 'xls']"),
    'the Leave Applications export keeps it');
ok(str_contains($driversIndex, "sort_params(array_merge((array)\$filters, ['export' => 'csv']"),
    'the Address Book export keeps it');
ok(str_contains($changes, 'sort_params(array_diff_key($filters'),
    'the Driver Changes exports keep it');
ok(str_contains((string)file_get_contents($root . '/app/views/leave/records.php'),
        "sort_params(array_merge((array)\$filters, ['export' => 'xls']"),
    'the Leave Records export keeps it');
ok(str_contains((string)file_get_contents($root . '/app/views/leave/current.php'),
        "sort_params(array_merge((array)\$filters, ['export' => 'xls']"),
    'the Current Leave export keeps it');

// The engine's half: write the address bar, read it back, and never turn a
// sort into a navigation.
ok(str_contains($js, 'Engine.prototype.syncUrl'),
    'the engine writes the sort into the address bar');
ok(str_contains($js, 'window.history.replaceState'),
    'with replaceState, so sorting never becomes a Back-button entry');
ok(str_contains($js, "params.delete('page')"),
    'a new sort drops page=, because it starts again at page 1');
ok(str_contains($js, 'self.syncUrl()'),
    'every sort goes through it');
ok(str_contains($js, 'Engine.prototype.readSortFromUrl'),
    'and reads it back at boot, so the choice outlives the session');
ok(str_contains($js, "th.getAttribute('data-dd-sort') === key"),
    'a key this grid does not render is ignored, so the boards that share one '
    . 'query string cannot break each other');
ok((bool)preg_match('/this\.readSortFromUrl\(\);/', $js),
    'the URL is read before the first render, not after it');

$_GET = [];

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
