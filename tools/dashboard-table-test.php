<?php
/**
 * Every dashboard table must have its "#" header INSIDE the first <tr>, and
 * the header count must equal the body cell count.
 */
declare(strict_types=1);

$path = 'C:/xampp/htdocs/lms/app/views/dashboard/index.php';
$html = (string) file_get_contents($path);

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

preg_match_all('~<thead[^>]*>(.*?)</thead>~s', $html, $heads);
$bodies = [];
preg_match_all('~<tbody[^>]*>(.*?)</tbody>~s', $html, $bodies);

ok(count($heads[1]) === 6, '6 table heads: monthly summary + drill modal + 4 panels (got ' . count($heads[1]) . ')');
ok(count($bodies[1]) === 6, '6 table bodies (got ' . count($bodies[1]) . ')');

foreach ($heads[1] as $n => $head) {
    $rows = [];
    preg_match_all('~<tr[^>]*>(.*?)</tr>~s', $head, $rows);

    ok(count($rows[1]) === 1, 'table ' . ($n + 1) . ': exactly one header row (got ' . count($rows[1]) . ')');

    $row = $rows[1][0] ?? '';

    // Nothing may sit between </tr> and </thead> - that is exactly how the
    // "#" header ended up outside its row.
    $outer = preg_replace('~<tr[^>]*>.*?</tr>~s', '', $head);
    ok(trim(strip_tags((string) $outer)) === '',
        'table ' . ($n + 1) . ': no stray markup outside the header row');

    // The four panel tables and the drill modal all start with a "#"
    // column; the monthly summary table starts with "Month" and has no
    // row-number column by design.
    if (str_contains($row, '<th>Badge</th>')) {
        ok(str_contains($row, '<th class="text-center">#</th>'),
            'table ' . ($n + 1) . ': # header is INSIDE the row');

        $h = substr_count($row, '<th');
        $b = 0;
        $hasColspan = false;

        if (isset($bodies[1][$n])) {
            preg_match('~<tr[^>]*>(.*?)</tr>~s', $bodies[1][$n], $first);
            $bodyRow   = (string) ($first[1] ?? '');
            $hasColspan = str_contains($bodyRow, 'colspan');
            $b          = substr_count($bodyRow, '<td');
        }

        // The drill-down modal ships an EMPTY body: dashboard.js fills it the
        // moment a drill opens, so there is nothing to compare against yet.
        // Assert the header's own shape instead - "#" plus the six columns the
        // modal draws (Badge, Driver, Branch, Date, Application Status, Leave
        // Status) - which is the part that is static.
        $jsFilled = !str_contains((string) ($bodies[1][$n] ?? ''), '<tr');

        if ($jsFilled) {
            ok($h === 7,
                'table ' . ($n + 1) . ": # + 6 columns in the drill modal (got $h)");

            // The rename is the spec: the summary modals show the two status
            // axes by name, and both headers are addressable so dashboard.js
            // can toggle them per drill shape (month vs KPI card).
            ok(str_contains($row, '<th id="sdAppStatusHead">Application Status</th>'),
                'table ' . ($n + 1) . ': Status column is renamed Application Status');
            ok(str_contains($row, '<th id="sdLeaveStatusHead">Leave Status</th>'),
                'table ' . ($n + 1) . ': the new Leave Status column is present');
            continue;
        }

        // An empty panel renders one colspan cell instead of the real row.
        ok($hasColspan || $h === $b,
            'table ' . ($n + 1) . ": $h header cells vs $b body cells"
            . ($hasColspan ? ' (empty state)' : ''));
    }
}

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
