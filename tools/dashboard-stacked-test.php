<?php
/**
 * Stacked-bar modal on the three dashboard summary cards.
 *
 *   php C:/xampp/htdocs/lms/tools/dashboard-stacked-test.php
 *
 * The important assertion is the footing check: for every series and every
 * month, the sum of the stack segments must equal that month's Total column
 * in the table. Chart and table are fed from the same grid, so they cannot
 * drift - and this test fails if someone ever breaks that.
 *
 * There is no JS runtime on this box, so the script's element references are
 * cross-checked against the rendered page instead: an id it looks up but the
 * page never emits is the most likely way this feature breaks silently.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\Dashboard;

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

/* ----------------------------------------------------------- login ----- */

echo "== /dashboard markup ==\n";

$BASE = 'http://localhost/lms';
$jar  = [];

function get(string $url, array &$jar, ?string $post = null): array
{
    $headers = ['User-Agent: Mozilla/5.0 dashboard-stacked-test'];
    if ($jar !== []) {
        $p = [];
        foreach ($jar as $k => $v) {
            $p[] = $k . '=' . $v;
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
        // Without this the login POST is silently dropped and every later
        // assertion fails against a redirect instead of the real page.
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    $body  = (string) curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $split = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $head  = substr($body, 0, $split);

    foreach (preg_split('/\r?\n/', $head) as $line) {
        if (stripos($line, 'set-cookie:') === 0) {
            $pair  = explode('=', explode(';', substr($line, 11), 2)[0], 2);
            $jar[trim($pair[0])] = trim($pair[1] ?? '');
        }
    }

    curl_close($ch);

    return [$code, substr($body, $split)];
}

[$code, $login] = get("$BASE/login", $jar);
ok($code === 200, "login page HTTP $code");

preg_match('/name="_csrf"\s+value="([^"]+)"/', $login, $m);
get("$BASE/login", $jar, http_build_query([
    'username' => 'admin',
    'password' => getenv('LMS_TEST_PASS') ?: 'admin123',
    '_csrf'    => $m[1] ?? '',
    'redirect' => '',
]));

[$code, $dash] = get("$BASE/dashboard", $jar);
ok($code === 200, "dashboard HTTP $code");

/* ---------------------------------------------------------- buttons ---- */

// Three cards, each with its own series key.
$seriesKeys = [];
preg_match_all('/data-stacked-series="([^"]+)"/', $dash, $matches);
$seriesKeys = $matches[1];

ok(count($seriesKeys) === 3, 'three stacked-chart buttons (got ' . count($seriesKeys) . ')');
ok(
    array_diff(['arrivals', 'resignations', 'vacations'], $seriesKeys) === [],
    'buttons cover arrivals / resignations / vacations'
);
ok(substr_count($dash, 'bi-bar-chart-steps') >= 4,
    'each button plus the modal title uses the stacked icon');
ok(str_contains($dash, 'data-bs-target="#stackedModal"'), 'buttons target the shared modal');

/* ----------------------------------------------------------- modal ----- */

echo "\n== shared modal markup ==\n";

foreach ([
    'id="stackedModal"', 'id="stackedModalTitle"', 'id="smTitle"', 'id="smIcon"',
    'id="smRange"', 'id="smChartBox"', 'id="stackedChart"', 'id="smEmpty"',
] as $needle) {
    ok(str_contains($dash, $needle), "present: $needle");
}

// The explanatory footnotes were dropped on request - the modal should carry
// only the range line now.
$jsSource = (string) file_get_contents(dirname(__DIR__) . '/assets/js/dashboard.js');

foreach (['id="smOrder"', 'id="smNote"'] as $needle) {
    ok(!str_contains($dash, $needle), "absent: $needle");
}

ok(!str_contains($jsSource, 'getElementById(\'smOrder\')'), 'JS no longer writes smOrder');
ok(!str_contains($jsSource, 'getElementById(\'smNote\')'), 'JS no longer writes smNote');
ok(!str_contains($jsSource, 'Base to top'), 'the "Base to top" legend text is gone');
ok(!str_contains($jsSource, 'Extra branch columns stay in the stack'),
    'the stack-explainer text is gone');

/* ---------------------------------------------------------- payload ---- */

echo "\n== DASHBOARD_DATA.stacked ==\n";

preg_match('/window\.DASHBOARD_DATA = (\{.*?\});/s', $dash, $raw);
ok(isset($raw[1]), 'DASHBOARD_DATA assignment found');

$payload = json_decode($raw[1] ?? '', true);
ok(is_array($payload), 'payload decodes as JSON');

$stacked = $payload['stacked'] ?? [];
ok(isset($stacked['labels'], $stacked['branches'], $stacked['series'], $stacked['range']),
    'stacked carries labels / branches / series / range');

foreach (['arrivals', 'resignations', 'vacations'] as $key) {
    ok(isset($stacked['series'][$key]), "series present for $key");
}

/* ------------------------------------ the footing guarantee ---------- */

echo "\n== stack totals equal the table's Total column ==\n";

// The same range the controller defaults to: 3 months back through 3 forward.
$from   = date('Y-m-01', strtotime('-3 months'));
$to     = date('Y-m-t', strtotime('+3 months'));
$expect = Dashboard::arrivalSummary($from, $to);

$expectedLabels = array_map(
    static fn (array $r): string => (string) $r['label'],
    $expect['series']['arrivals']['months']
);

ok(($stacked['labels'] ?? []) === $expectedLabels,
    'month labels match the dashboard range (' . count($expectedLabels) . ' months)'
    . (($stacked['labels'] ?? []) === $expectedLabels
        ? ''
        : ' [got ' . json_encode($stacked['labels'] ?? [])
          . ' want ' . json_encode($expectedLabels) . ']'));

$codes = array_keys($stacked['branches'] ?? []);
ok($codes !== [], 'branch columns present');

foreach (['arrivals', 'resignations', 'vacations'] as $key) {
    $months = $expect['series'][$key]['months'];

    ok(count($stacked['series'][$key] ?? []) > 0, "$key has a branch series");

    // Every branch must carry one value per month, or the stack would be
    // shorter than the axis.
    foreach ($codes as $code) {
        $len = count($stacked['series'][$key][$code] ?? []);
        ok($len === count($expectedLabels),
            "$key / $code has $len values for " . count($expectedLabels) . ' months');
    }

    $footing = true;
    $detail  = '';

    foreach ($months as $i => $month) {
        $stackSum = 0;

        foreach ($codes as $code) {
            $stackSum += (int) ($stacked['series'][$key][$code][$i] ?? 0);
        }

        if ($stackSum !== (int) $month['total']) {
            $footing = false;
            $detail  = "month {$month['label']}: stack=$stackSum table={$month['total']}";
            break;
        }
    }

    ok($footing, "$key: every month's stack sums to its Total"
        . ($footing ? '' . ' (' . count($months) . ' months)' : " [$detail]"));
}

/* ------------------------------------------------- order and range ---- */

echo "\n== stacking order and range ==\n";

$names = array_values($stacked['branches']);
$lower = array_map(
    static fn (string $n): string => strtolower(trim($n)),
    $names
);

// Dammam (base), Riyadh, Jeddah - in that order - then anything else.
$expectedHead = [];
foreach (['dammam', 'riyadh', 'jeddah'] as $wanted) {
    if (in_array($wanted, $lower, true)) {
        $expectedHead[] = $wanted;
    }
}

$actualHead = array_slice($lower, 0, count($expectedHead));
ok($actualHead === $expectedHead,
    'base-to-top order is ' . implode(', ', $expectedHead)
    . ' (got ' . implode(', ', $actualHead) . ')');

$expectedRange = fmt_date($from, 'M Y') . ' – ' . fmt_date($to, 'M Y');
ok($stacked['range'] === $expectedRange,
    "range shown in the modal is the dashboard's (" . $stacked['range'] . ')');

/* -------------------------------------------- JS references resolve --- */

// There is no JS runtime here, so check the other failure mode: an element
// the script looks up that the page never emits.
echo "\n== every getElementById() target exists on the page ==\n";

$js   = (string) file_get_contents(dirname(__DIR__) . '/assets/js/dashboard.js');
$refs = [];
preg_match_all('/getElementById\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $js, $jsMatches);
$refs = array_values(array_unique($jsMatches[1]));

ok($refs !== [], 'dashboard.js looks up ' . count($refs) . ' ids');

$missing = array_values(array_filter(
    $refs,
    static fn (string $id): bool => !str_contains($dash, 'id="' . $id . '"')
));

ok($missing === [],
    $missing === []
        ? 'all ' . count($refs) . ' ids exist on the page'
        : 'MISSING on the page: ' . implode(', ', $missing));

// And the data-* attributes the modal reads must be emitted by the buttons.
preg_match_all('/getAttribute\(\s*[\'"]data-stacked-([^\'"]+)[\'"]\s*\)/', $js, $attrMatches);

foreach (array_unique($attrMatches[1] ?? []) as $attr) {
    ok(str_contains($dash, 'data-stacked-' . $attr . '="'),
        "button emits data-stacked-$attr");
}

/* --------------------------------------------------- axis headroom ----- */

// Without it the tallest stack sits exactly on the top gridline and reads as
// clipped (0,1,2,3 with a bar reaching 3). The scale must be capped above
// the tallest stack.
echo "\n== y-axis headroom ==\n";

ok(str_contains($js, 'var axisMax'), 'the stacked chart computes its own axis cap');
ok(str_contains($js, 'max: axisMax'), 'the y scale uses that cap');
ok(str_contains($js, "axisMax += Math.max(1, Math.ceil(axisMax / 4))"),
    'the cap always sits above the tallest stack');

// Recompute what the chart would choose and check it clears the data.
$headroomOk = true;
$headroomDetail = '';

foreach (['arrivals', 'resignations', 'vacations'] as $key) {
    $max = 0;

    foreach ($expectedLabels as $i => $label) {
        $total = 0;

        foreach ($codes as $code) {
            $total += (int) ($stacked['series'][$key][$code][$i] ?? 0);
        }

        $max = max($max, $total);
    }

    $cap = $max + max(1, (int) ceil($max / 4));

    if ($max > 0 && $cap <= $max) {
        $headroomOk = false;
        $headroomDetail = "$key: cap=$cap max=$max";
    }
}

ok($headroomOk, 'axis cap clears the tallest stack in every series'
    . ($headroomOk ? '' : " [$headroomDetail]"));

/* ------------------------------------ row spans (colspan vs header) --- */

// An empty-state row that spans fewer columns than its header leaves a
// phantom cell on the right: the block sits off-centre and reads as either
// stretched or truncated. Every dashboard row must span the full header.
echo "\n== every row spans its table's full column count ==\n";

$span = static function (string $cells): int {
    $n = 0;
    preg_match_all('/<(td|th)\b([^>]*)>/i', $cells, $found, PREG_SET_ORDER);

    foreach ($found as $one) {
        $n += preg_match('/colspan="(\d+)"/', $one[2], $x) ? (int) $x[1] : 1;
    }

    return $n;
};

preg_match_all('/<table\b[^>]*>(.*?)<\/table>/is', $dash, $tables);
$mismatches = [];

foreach ($tables[1] as $ti => $table) {
    if (!preg_match('/<thead[^>]*>(.*?)<\/thead>/is', $table, $head)) {
        continue;
    }

    $headCount = $span($head[1]);
    if ($headCount === 0) {
        continue;
    }

    if (!preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $table, $body)) {
        continue;
    }

    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $body[1], $rows);

    foreach ($rows[1] as $ri => $row) {
        $got = $span($row);

        if ($got !== $headCount) {
            $mismatches[] = "table #$ti row #$ri: $got vs $headCount";
        }
    }
}

ok($mismatches === [],
    $mismatches === []
        ? 'all dashboard rows fill their header (checked ' . count($tables[1]) . ' tables)'
        : 'ROW SPAN MISMATCH: ' . implode(' | ', array_slice($mismatches, 0, 6)));

/* ------------------------ summary row drill-down modal ------------------ */

// Clicking a month row must open one shared modal whose counts come from the
// same rules as the table itself - so the footing check below compares the
// drill-down list against the printed Total cell for every month.
echo "\n== summary row drill-down ==\n";

ok(str_contains($dash, 'id="summaryDrillModal"'), 'the shared drill-down modal is on the page');

preg_match_all('/data-summary-series="([^"]+)"/', $dash, $rowSeries);
ok(count($rowSeries[1]) > 0, 'month rows carry data-summary-series (got ' . count($rowSeries[1]) . ')');
ok(array_diff(['arrivals', 'resignations', 'vacations'], array_unique($rowSeries[1])) === [],
    'all three series are clickable');

preg_match_all('/data-summary-month="(\d{4}-\d{2})"/', $dash, $rowMonths);
ok(count($rowMonths[1]) > 0, 'month rows carry a YYYY-MM bucket (got ' . count($rowMonths[1]) . ')');

// The script's element references must exist in the page, or the modal opens
// blank with no error anywhere a human would see it.
preg_match_all('/getElementById\(\s*[\'"]sd([A-Za-z]+)[\'"]\s*\)/', $js, $sdRefs);
ok(($sdRefs[1] ?? []) !== [], 'dashboard.js looks up ' . count(array_unique($sdRefs[1] ?? [])) . ' sd* ids');

foreach (array_unique($sdRefs[1] ?? []) as $id) {
    ok(str_contains($dash, 'id="sd' . $id . '"'), "page emits #sd$id for the script");
}

ok(str_contains($js, 'summary-drill-row'), 'dashboard.js binds the row click');
ok(str_contains($js, '/api/dashboard/summary-drivers'), 'dashboard.js calls the drill-down endpoint');
ok(str_contains($dash, 'class="summary-drill-row"'), 'the view marks rows as drill-down controls');

/* ------------------------------------------------------ Excel export ---- */

// The modal carries an Excel button for anyone holding reports.export, and it
// points at the SAME rows the modal renders - same series, same month - so a
// downloaded file can never foot to a different number than the cell clicked.
ok(str_contains($dash, 'id="sdExport"'), 'the drill-down modal offers the Excel button');
// The rendered page can only show the OUTPUT of the gate, so the gate itself
// has to be read off the view source.
$modalView = (string) file_get_contents(APP_ROOT . '/app/views/dashboard/index.php');
ok(str_contains($modalView, "can('reports.export')"),
    'the button is gated on reports.export, not on viewing');
ok(str_contains($dash, 'btn-outline-success btn-sm disabled'),
    'it ships disabled until a list has actually loaded');
ok(str_contains($js, "setExport(url + '&export=xls')"),
    'dashboard.js points it at the slice that was fetched');
ok(str_contains($js, 'setExport(null)'),
    'and withdraws it whenever there is nothing to download');

$drillApi = (string) file_get_contents(APP_ROOT . '/app/api/DashboardApi.php');
ok(str_contains($drillApi, '($_GET[\'export\'] ?? \'\') === \'xls\''),
    'the drill endpoint takes an explicit export flag');
ok(str_contains($drillApi, "require_permission('reports.export')"),
    'the flag demands reports.export - the same right as every other Excel button');

/* -------------------------------------- one footer shape, every modal ----- */

// The same card opened two different ways depending on the screen you came
// from: here the pager sat at the BOTTOM of the body, right-aligned, with the
// label BEFORE the arrows; Capacity Planning puts it in the footer as
// prev / label / next with the buttons grouped on the right. Both now agree.
ok(preg_match('~<div class="modal-footer justify-content-between">\s*'
    . '<div class="d-none align-items-center gap-2" id="sdPager"~s', $modalView) === 1,
    'the pager leads the footer, exactly as both Capacity modals put it');
ok(strpos($modalView, 'id="sdPrev"') < strpos($modalView, 'id="sdPageLabel"')
   && strpos($modalView, 'id="sdPageLabel"') < strpos($modalView, 'id="sdNext"'),
    'and reads prev, label, next - the same order everywhere');
ok(strpos($modalView, 'id="sdPager"') < strpos($modalView, 'id="sdExport"'),
    'with the action buttons grouped after it, on the right');

// And the two screens label the same card the same way: a range card names
// ITS window, not a single date.
$dashModel = (string) file_get_contents(APP_ROOT . '/app/models/Dashboard.php');
ok(str_contains($dashModel, 'in_array($kpi, $rangeCards, true) && $from !== null'),
    'a range card is titled with the range it spans, not with today');
ok(str_contains($drillApi, 'export_xls($headers, $rows'),
    'and streams it through the shared Excel writer');

// export_format() must NOT be used for this flag: it reads an ABSENT value as
// csv, which is outside ['xls'] and would 400 the plain drill-down call.
ok(!str_contains($drillApi, "export_format(\$_GET['export']") && !str_contains($drillApi, 'export_format((string)($_GET'),
    'the flag is not routed through export_format(), which would break the plain call');

/* --------------------------------------- the card drill: whole list, pager - */

// Excel must carry the WHOLE list. The page the reader is on is not the data
// they asked for, so the export deliberately steps outside the 100-row
// ceiling that keeps the on-screen fetch cheap.
ok(str_contains($drillApi, '$isExport'),
    'the endpoint separates "download" from "draw" before it sizes the query');
ok(str_contains($drillApi, '? 100000') || str_contains($drillApi, '100000'),
    'the export asks for far more than one page');
ok(!preg_match('/\$size = min\(100.*\$/s', $drillApi),
    'the 100-row screen ceiling no longer applies to the download');

// A card whose list fits on one page must not show a pager at all. It used
// to: #sdPager carried d-flex, and Bootstrap's .d-flex is
// `display: flex !important`, which outranks the [hidden] attribute - so the
// element never hid, and because the label was only rewritten when the pager
// was on, the PREVIOUS card's "Page 1 of 3 - 24 row(s)" stayed up over the
// new card's two rows.
ok(str_contains($modalView, 'class="d-none align-items-center gap-2" id="sdPager"'),
    'the pager starts hidden with d-none, not with a [hidden] that d-flex beats');
ok(!preg_match('/id="sdPager"[^>]*\bhidden\b/', $modalView),
    'and no longer relies on the hidden attribute to do it');
ok(str_contains($js, "classList.toggle('d-flex', on)") && str_contains($js, "classList.toggle('d-none', !on)"),
    'setPager swaps the utility classes instead (d-flex XOR d-none)');
ok(str_contains($js, "stale.textContent = ''"),
    'and clears the previous card\'s page count rather than leaving it up');

// A reply for a drill the reader has already navigated away from must be
// dropped whole, or it repaints the new modal with the old rows.
ok(str_contains($js, 'var mine = drill;'), 'loadDrill remembers which drill it asked for');
ok(substr_count($js, 'if (drill !== mine) { return; }') >= 2,
    'both the success and the failure path drop a stale reply');

/* ------------------------------------------ Total Active Drivers columns -- */

// That card is a population: every row's event date is empty, so the Date
// column would be a stack of dashes. The modal drops it - header and cell
// together - and the download has to follow it or the two would not line up.
ok(str_contains($modalView, 'id="sdDateHead"'), 'the modal Date header is addressable');
ok(str_contains($js, "payload.kpi === 'total_drivers'"), 'the modal knows which card drops the date');
ok(str_contains($js, 'if (!noDate)'), 'and skips the cell to match');
ok(str_contains($drillApi, "\$result['kpi'] ?? ''") && str_contains($drillApi, '$withDate'),
    'the Excel writer drops the same column for the same card');
ok(str_contains($js, "dateHead.style.display = noDate ? 'none' : ''"),
    'the header is hidden in place, so the other cards keep it');

/* ---------------------------------------------------------- footing ----- */

// Same window the page could show: this month plus the next two.
$footFrom = date('Y-m-01');
$footTo   = date('Y-m-t', (int) strtotime('+2 months'));

$summary = Dashboard::arrivalSummary($footFrom, $footTo);
$checked = 0;
$probe   = null;

foreach (['arrivals', 'resignations', 'vacations'] as $key) {
    foreach ($summary['series'][$key]['months'] as $month) {
        $list   = Dashboard::summaryDrivers($key, (string) $month['key']);
        $cell   = (int) $month['total'];
        $checked++;

        ok($list['total'] === $cell,
            "$key {$month['key']}: drill-down list foots with the Total cell"
            . " ({$list['total']} vs $cell)");

        // And a single branch column must foot too - the aggregate groups on
        // the snapshot branch, the drill-down filters on the same expression.
        foreach ($month['cells'] as $code => $count) {
            if ((int) $count === 0) {
                continue;
            }

            $branchList = Dashboard::summaryDrivers($key, (string) $month['key'], (string) $code);
            ok($branchList['total'] === (int) $count,
                "$key {$month['key']} / $code: branch column foots ({$branchList['total']} vs " . (int) $count . ')');
            break; // one branch per month keeps the output readable
        }

        if ($key === 'arrivals' && $probe === null && $cell > 0) {
            $probe = $month;
        }
    }
}

ok($checked > 0, "footing checked at least one month (got $checked)");

// An unknown bucket must never invent rows: empty month = empty list.
$never = Dashboard::summaryDrivers('arrivals', '1999-01');
ok($never['total'] === 0, 'a month with no records drills down to zero rows');

/* ------------------------------------------------------ over HTTP ------- */

if ($probe !== null) {
    [$code, $body] = get(
        "$BASE/api/dashboard/summary-drivers?series=arrivals&month=" . urlencode((string) $probe['key']),
        $jar
    );
    $payload = json_decode($body, true);

    ok($code === 200 && ($payload['ok'] ?? false) === true,
        "the endpoint answers 200 with ok (got $code)");
    ok((int) ($payload['total'] ?? -1) === (int) $probe['total'],
        'the HTTP total equals the cell it came from');

    // The rows are the first PAGE of that total - 10 at a time - while
    // total/pages still describe the whole month the cell counted.
    $expectRows = min(10, (int) $probe['total']);
    ok(count($payload['rows'] ?? []) === $expectRows,
        "the HTTP rows array holds page 1 ($expectRows of "
        . (int) $probe['total'] . ', got ' . count($payload['rows'] ?? []) . ')');
    ok((int) ($payload['size'] ?? -1) === 10, 'the modal pages at 10 rows per page');
    ok((int) ($payload['pages'] ?? -1) === max(1, (int) ceil((int) $probe['total'] / 10)),
        'the page count covers the whole month');

    // Page 2 continues where page 1 stopped instead of repeating or
    // dropping rows: same month, same total, the remainder only.
    if ((int) $probe['total'] > 10) {
        [, $bodyP2] = get(
            "$BASE/api/dashboard/summary-drivers?series=arrivals&month="
            . urlencode((string) $probe['key']) . '&page=2',
            $jar
        );
        $page2 = json_decode($bodyP2, true);

        ok((int) ($page2['page'] ?? 0) === 2, 'page 2 reports itself as page 2');
        ok(count($page2['rows'] ?? []) === (int) $probe['total'] - 10,
            'page 2 holds exactly the rows page 1 did not show');
        ok((int) ($page2['total'] ?? -1) === (int) $probe['total'],
            'and its total still equals the clicked cell');
    }

    // Ordering: the modal shows rows chronologically by the date the series
    // buckets on - expected return date for arrivals, last working date for
    // the two application series.
    foreach (['arrivals', 'resignations', 'vacations'] as $seriesName) {
        [$code, $body] = get(
            "$BASE/api/dashboard/summary-drivers?series=$seriesName&month=" . urlencode((string) $probe['key']),
            $jar
        );
        $pl = json_decode($body, true);

        if ($code === 200 && (int) ($pl['total'] ?? 0) > 0) {
            $dates  = array_column($pl['rows'], 'event_date');
            $sorted = $dates;
            sort($sorted);
            ok($dates === $sorted,
                "$seriesName drill-down rows come back in date order ("
                . ($dates[0] ?? '?') . ' .. ' . (string) end($dates) . ')');
        }
    }

    // Bad input is refused, not silently answered with an empty list.
    [$code] = get("$BASE/api/dashboard/summary-drivers?series=bogus&month=2026-13", $jar);
    ok($code === 400, "an unknown series/month is refused with 400 (got $code)");

    // The Excel download answers the same slice the JSON above did.
    [$code, $body] = get(
        "$BASE/api/dashboard/summary-drivers?series=arrivals&month="
        . urlencode((string) $probe['key']) . '&export=xls',
        $jar
    );
    ok($code === 200, "the Excel export downloads (HTTP $code)");
    ok(str_contains($body, 'Excel.Sheet'), 'as SpreadsheetML, not a renamed HTML table');
    ok(str_contains($body, '<Data ss:Type="String">Badge</Data>')
        && str_contains($body, '<Data ss:Type="String">Application Status</Data>')
        && str_contains($body, '<Data ss:Type="String">Leave Status</Data>'),
        'it carries the same six headings the modal shows');
    $exportRows = preg_match_all('/<Row\b[^>]*>.*?<\/Row>/s', $body);
    ok($exportRows - 3 === (int) $probe['total'],
        'the file holds exactly the drivers the modal listed (' . ($exportRows - 3)
        . ' vs ' . (int) $probe['total'] . ')');
}

/* --------------------------------------------------------------- out --- */

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);


