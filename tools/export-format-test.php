<?php
/**
 * Export formats: what each ?export= endpoint actually emits.
 *
 *   php C:/xampp/htdocs/lms/tools/export-format-test.php
 *
 * Pins the four fixes:
 *   1. CSV formula-injection guard covers = + - @ tab CR, headers included.
 *   2. .xls is SpreadsheetML, not a renamed HTML table (Excel opens it with no
 *      format/extension warning, and every cell carries an explicit ss:Type
 *      so nothing is ever re-parsed as a formula).
 *   3. Unknown ?export= values are rejected with 400 instead of silently
 *      returning CSV.
 *   4. The dead "GET leave/export" route is gone (it could only 500).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

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

const BASE = 'http://localhost/lms';

function fetch(string $url, array &$jar, ?string $post = null): array
{
    $headers = ['User-Agent: Mozilla/5.0 export-format-test'];
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

    $ctype = '';
    foreach (preg_split('/\r?\n/', substr($body, 0, $size)) as $line) {
        if (stripos($line, 'content-type:') === 0) {
            $ctype = strtolower(trim(substr($line, 13)));
        }
    }

    curl_close($ch);

    return [$code, substr($body, $size), $ctype];
}

/* --------------------------------------------- 1. CSV injection guard --- */

echo "== csv_safe() neutralises every formula prefix ==\n";

$payloads = [
    '=1+1',
    "=cmd|'/c calc'!A1",
    '@SUM(A1:A9)',
    '+2-5',
    '-2+3',
    "\tstarts-with-tab",
    "\rstarts-with-cr",
];

foreach ($payloads as $input) {
    ok(csv_safe($input) === "'" . $input,
        'prefixed: ' . var_export($input, true));
}

foreach (['Pakistan', 'Annual Vacation', '2026-09-28', '170582', '', 'A-1'] as $safe) {
    ok(csv_safe($safe) === $safe, 'untouched: ' . var_export($safe, true));
}

ok(csv_safe(null) === '', 'null becomes an empty cell');
ok(csv_safe(123) === 123, 'integers pass through unquoted');
ok(csv_safe(4.5) === 4.5, 'floats pass through unquoted');

/* --------------------------------------------- 2. SpreadsheetML cells ---- */

echo "\n== xls_cell() types every value explicitly ==\n";

ok(str_contains(xls_cell('=1+1'), 'ss:Type="String"'),
    'a formula-looking value is typed String (Excel will not evaluate it)');
ok(xls_cell('=1+1') === '<Cell><Data ss:Type="String">=1+1</Data></Cell>',
    'and is written through verbatim as text, not mangled');
ok(str_contains(xls_cell(42), 'ss:Type="Number"'), 'integers are typed Number');
ok(str_contains(xls_cell('<script>'), '&lt;script&gt;'),
    'markup is XML-escaped');
ok(str_contains(xls_cell('A&B'), '&amp;'), 'ampersand is XML-escaped');
ok(str_contains(xls_cell(null), 'ss:Type="String">'), 'null renders an empty cell');
ok(!preg_match('/[\x00-\x08]/', xls_cell("bad\x00\x07ctl")),
    'control characters XML forbids are stripped');

/* ---------------------- 2b. widths are fitted in pixels, emitted in points -- */

echo "\n== the column fit is measured in pixels, then emitted in POINTS ==\n";

// ss:Width is in POINTS while Excel's fit is in pixels. Emitting the raw
// character count made a 10-character column 10pt = 13px - under two
// characters - and every export opened as a row of slivers with dates
// clipped to "20" and Status to "Pa". At 96dpi 1px = 0.75pt, so Excel's
// 64px default column is exactly 48pt.
ok(xls_col_points(64) === 48,
    '64px (Excel default) -> ' . xls_col_points(64) . 'pt, not 64pt');
ok(xls_col_points(XLS_COL_MIN_PX) >= 40 && xls_col_points(XLS_COL_MIN_PX) <= 55,
    'the floor column (' . XLS_COL_MIN_PX . 'px) is ' . xls_col_points(XLS_COL_MIN_PX) . 'pt - about one default column');
ok(xls_col_points(XLS_COL_MAX_PX) > 300 && xls_col_points(XLS_COL_MAX_PX) < 340,
    'the clamp ceiling (' . XLS_COL_MAX_PX . 'px) is ' . xls_col_points(XLS_COL_MAX_PX) . 'pt - wide, not absurd');
ok(xls_col_points(200) > xls_col_points(100),
    'wider content still means a wider column');

echo "\n== xls_text_px() prices glyphs, not characters ==\n";

// The regression this replaces: 37 characters of capital letters needs 349px,
// while 19 characters of date needs 128px. A character count cannot tell the
// two apart - it sized the driver column 53px short and clipped every long
// name in the export.
ok(xls_text_px('MUHAMMAD MASOOD AHMAD GHULAM MUHAMMAD') > 340,
    '37 capitals -> ' . xls_text_px('MUHAMMAD MASOOD AHMAD GHULAM MUHAMMAD') . 'px (Excel fits 349px)');
ok(xls_text_px('2026-09-30 14:17:55') < 130,
    "the 19-character timestamp is only " . xls_text_px('2026-09-30 14:17:55') . 'px (Excel fits 128px)');
ok(xls_text_px('MUHAMMAD MASOOD AHMAD GHULAM MUHAMMAD') > xls_text_px('2026-09-30 14:17:55'),
    'the shorter string is the wider one - character counts invert the order');
ok(xls_text_px('MMMMMMMMMM') > xls_text_px('iiiiiiiiii') * 3,
    "M is >3x i: " . xls_text_px('MMMMMMMMMM') . 'px vs ' . xls_text_px('iiiiiiiiii') . 'px');
ok(xls_text_px('1111111111') === xls_text_px('9999999999'),
    'digits are tabular, all 7.3px each');
ok(xls_text_px('') === 0.0 && xls_text_px(null) === 0.0,
    'empty and null have no width');
ok(xls_text_px('W', true) > xls_text_px('W'),
    'bold widens a glyph that has an override: ' . xls_text_px('W') . 'px -> ' . xls_text_px('W', true) . 'px');
ok(xls_text_px('A', true) === xls_text_px('A'),
    'a glyph with no override is unchanged by bold');

echo "\n== a column always clears the content it has to show ==\n";

// Padding + clamp: every value Excel will render must fit inside the column
// we ask for. These are the real strings from the leave-records export, with
// the width Excel itself reports when it AutoFits them.
$fitted = [
    ['MUHAMMAD MASOOD AHMAD GHULAM MUHAMMAD', false, 349],
    ['2026-09-30 14:17:55', false, 128],
    ['Emergency Leave', false, 115],
    ['Expected Return', true, 109],
    ['Last Working', true, 87],
    ['Dammam', false, 66],
    ['33010340', false, 63],
];
foreach ($fitted as [$text, $bold, $excelPx]) {
    $px = xls_col_px(xls_text_px($text, $bold));
    ok($px >= $excelPx,
        'column ' . $px . 'px >= the ' . $excelPx . 'px Excel needs for ' . var_export($text, true));
}
ok(xls_col_px(0.0) === XLS_COL_MIN_PX, 'an empty column still gets the floor');
ok(xls_col_px(9999.0) === XLS_COL_MAX_PX, 'a runaway value is clamped, not opened wide');
ok(xls_col_px(xls_text_px('abc')) < xls_col_px(xls_text_px('abcabcabc')),
    'wider content still means a wider column');

/* --------------------------------------------- 3. what the wire carries -- */

echo "\n== each endpoint emits the format it promises ==\n";

$jar = [];
fetch(BASE . '/login', $jar);
[, $login] = fetch(BASE . '/login', $jar);
preg_match('/name="_csrf"\s+value="([^"]+)"/', $login, $m);
fetch(BASE . '/login', $jar, http_build_query([
    'username' => 'admin',
    'password' => getenv('LMS_TEST_PASS') ?: 'admin123',
    '_csrf'    => $m[1] ?? '',
    'redirect' => '',
]));

$targets = [
    ['drivers CSV',       '/drivers?export=csv',                       'text/csv'],
    ['drivers XLS',       '/drivers?export=xls',                       'application/vnd.ms-excel'],
    ['records CSV',       '/leave/records?export=csv',                 'text/csv'],
    ['records XLS',       '/leave/records?export=xls',                 'application/vnd.ms-excel'],
    ['records JSON',      '/leave/records?export=json',                'application/json'],
    ['current XLS',       '/leave/current?export=xls',                 'application/vnd.ms-excel'],
    ['applications CSV',  '/leave-applications/export?export=csv',     'text/csv'],
    ['applications XLS',  '/leave-applications/export?export=xls',     'application/vnd.ms-excel'],
    ['report leave CSV',  '/reports/leave?export=csv',                 'text/csv'],
    ['report leave XLS',  '/reports/leave?export=xls',                 'application/vnd.ms-excel'],
    ['report branch XLS', '/reports/branch?export=xls',                'application/vnd.ms-excel'],
    ['report agency CSV', '/reports/agency?export=csv',                'text/csv'],
];

$firstXls = '';

foreach ($targets as [$label, $path, $wantType]) {
    [$code, $body, $ctype] = fetch(BASE . $path, $jar);

    ok($code === 200, "$label: HTTP $code");
    ok(str_contains($ctype, $wantType), "$label: Content-Type is $wantType (got " . substr($ctype, 0, 45) . ')');

    if (str_contains($wantType, 'text/csv')) {
        ok(substr($body, 0, 3) === "\xEF\xBB\xBF", "$label: UTF-8 BOM present");
    } elseif (str_contains($wantType, 'ms-excel')) {
        ok(str_contains($body, '<?mso-application'), "$label: SpreadsheetML declaration present");
        ok(str_contains($body, '<Workbook'), "$label: <Workbook> present");
        ok(str_contains($body, 'ss:Type="String"'), "$label: cells carry an explicit type");
        ok(!str_contains($body, '<html'), "$label: NOT an HTML table");
        ok(!str_contains($body, 'ss:Type="Number">='), "$label: no formula ever typed as a number");
        $firstXls = $firstXls === '' ? $body : $firstXls;
    } else {
        $decoded = json_decode($body, true);
        ok(is_array($decoded) && isset($decoded['rows']), "$label: decodes as JSON with rows");
    }
}

echo "\n== the XLS is well-formed XML (a real spreadsheet) ==\n";

$xml = simplexml_load_string($firstXls);
ok($xml !== false, 'simplexml parses the workbook');
if ($xml !== false) {
    ok($xml->getName() === 'Workbook', 'root element is Workbook');
    ok(isset($xml->Worksheet->Table->Row), 'Worksheet/Table/Row structure present');
    $rows = count($xml->Worksheet->Table->Row);
    ok($rows > 1, "header + $rows row(s) present");
}

/* ------------------------- 3b. the sheet is laid out for reading -------- */

echo "\n== the export carries a title, a timestamp and formatting ==\n";

// The raw body rather than the parsed tree: these are string facts about the
// document, and reading them through SimpleXML would need namespace handling
// that proves nothing extra.
ok(str_contains($firstXls, '<WorksheetOptions'), 'the worksheet carries options');
ok(str_contains($firstXls, '<DoNotDisplayGridlines/>'),
    'gridlines are switched off - the borders are the only rules');
ok(str_contains($firstXls, '<Column ss:AutoFitWidth="0" ss:Width="'),
    'every column carries a width fitted to its own content');
ok(str_contains($firstXls, 'ss:MergeAcross='),
    'the title is merged across the table, not left sitting in column A');
ok(str_contains($firstXls, '<Borders>'), 'header and data cells are bordered');
ok(str_contains($firstXls, 'ss:Size="16" ss:Bold="1"'),
    'the title style is big and bold');
ok(str_contains($firstXls, 'ss:Size="11" ss:Bold="1"'), 'the header row is bold');
ok(str_contains($firstXls, '<Interior ss:Color='), 'the header row is filled');
ok(str_contains($firstXls, '>Exported '), 'row 2 records when it was exported');
ok(str_contains($firstXls, '>Address Book - Drivers<'),
    'the heading is the report name, not the machine filename');

// The row order is the contract: title, exported-at, headings, then data.
preg_match_all('~<Row\b[^>]*>(.*?)</Row>~s', $firstXls, $found);
$sheet = $found[1] ?? [];

ok(count($sheet) >= 4, 'there are at least four rows (got ' . count($sheet) . ')');
ok(($sheet[0] ?? '') !== '' && str_contains($sheet[0], 'ss:StyleID="Title"'),
    'row 1 is the title');
ok(($sheet[1] ?? '') !== '' && str_contains($sheet[1], 'ss:StyleID="Meta"'),
    'row 2 is the export date');
ok(($sheet[2] ?? '') !== '' && str_contains($sheet[2], 'ss:StyleID="Header"'),
    'row 3 is the column headings');
ok(($sheet[3] ?? '') !== '' && str_contains($sheet[3], 'ss:StyleID="Data"'),
    'the data starts on row 4');
ok(!str_contains($sheet[0] ?? '', 'ss:StyleID="Data"'),
    'and the title row holds no data');

/* --------------------------------------------- 4. unknown formats 400 --- */

echo "\n== unknown ?export= values are rejected, not silently made CSV ==\n";

foreach ([
    '/reports/leave?export=pdf',
    '/reports/leave?export=bogus',
    '/drivers?export=zip',
    '/leave/records?export=xlsx',
    '/leave-applications/export?export=xml',
] as $path) {
    [$code, $body, $ctype] = fetch(BASE . $path, $jar);

    ok($code === 400, "$path -> HTTP $code (expected 400)");
    ok(str_contains($body, 'Unsupported export format'),
        "$path -> explains the rejection");
    ok(!str_contains($ctype, 'text/csv') && substr($body, 0, 3) !== "\xEF\xBB\xBF",
        "$path -> did not hand back CSV data");
}

echo "\n== the summary reports' export link keeps ONE base path ==\n";

// url() was applied twice: $action already IS url('reports/agency'), so the
// second call prefixed the base again and the Excel button pointed at
// /lms/lms/reports/agency - which 404s. Both summary reports share the
// partial, so both were wrong.
//
// The base comes from BASE, not base_path(): this runs from tools/, so
// base_path() here resolves to "/lms/tools" and would look for a doubled
// string the page can never contain.
$base = (string) parse_url(BASE, PHP_URL_PATH);

if ($base !== '' && $base !== '/') {
    foreach (['/reports/agency', '/reports/branch'] as $path) {
        [$code, $report] = fetch(BASE . $path, $jar);

        ok($code === 200, "$path renders (HTTP $code)");
        ok(!str_contains($report, $base . $base),
            "$path: no doubled base path in the rendered links");
        ok(str_contains($report, e($base . $path . '?date_field=')),
            "$path: the Excel link targets the page itself");
    }
} else {
    echo "  skip  (installed at the domain root - no base path to double)\n";
}

/* --------------------------------------------- 5. dead route removed ---- */

echo "\n== the old leave/export route is gone ==\n";

[$code] = fetch(BASE . '/leave/export?export=csv', $jar);
ok($code === 404, "GET /leave/export -> HTTP $code (expected 404, was 500)");

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
