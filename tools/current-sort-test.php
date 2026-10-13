<?php
/**
 * Regression test: Current Leave sorting.
 *
 *   php tools/current-sort-test.php
 *
 * The screen rendered sortable headers but currentlyAway() ignored $sort and
 * always emitted the same ORDER BY, so clicking a column flipped the arrow and
 * left the rows where they were.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

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

$filters = ['status' => ''];
$rows     = LeaveRecord::currentlyAway($filters, 100, 0);

if (count($rows) < 2) {
    echo '  SKIP  need at least 2 rows currently away (have ' . count($rows) . ")\n";
    printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
    exit(0);
}

echo '== sorting reorders the query == (' . count($rows) . " rows)\n";

$byDriverAsc  = LeaveRecord::currentlyAway($filters, 100, 0, ['driver_name', 'asc']);
$byDriverDesc = LeaveRecord::currentlyAway($filters, 100, 0, ['driver_name', 'desc']);
$byBadgeAsc   = LeaveRecord::currentlyAway($filters, 100, 0, ['badge_number', 'asc']);
$default      = LeaveRecord::currentlyAway($filters, 100, 0);

$names = static fn (array $list): array => array_map(
    static fn (array $r): string => (string) $r['driver_name'],
    $list
);

$asc  = $names($byDriverAsc);
$desc = $names($byDriverDesc);

ok($asc === array_values(array_unique($asc)), 'ascending name list has no surprises');

$sortedAsc = $asc;
sort($sortedAsc, SORT_STRING);
ok($asc === $sortedAsc, 'driver_name asc is actually ascending');

$sortedDesc = $desc;
rsort($sortedDesc, SORT_STRING);
ok($desc === $sortedDesc, 'driver_name desc is actually descending');

// The whole point: different headers must give different orders.
ok($byDriverAsc !== $byDriverDesc, 'asc and desc return different row orders');
ok($byDriverAsc !== $byBadgeAsc, 'sorting by driver_name differs from badge_number');
ok($byDriverAsc !== $default, 'an explicit sort differs from the default order');

$badges = array_map(static fn (array $r): string => (string) $r['badge_number'], $byBadgeAsc);
$sortedBadges = $badges;
sort($sortedBadges, SORT_STRING);
ok($badges === $sortedBadges, 'badge_number asc is actually ascending');

// Every sortable key must be reachable through the whitelist.
foreach (array_keys(LeaveRecord::SORTABLE) as $key) {
    $out = LeaveRecord::currentlyAway($filters, 5, 0, [$key, 'asc']);
    ok(is_array($out), "sortable key '$key' does not error");
}

// An unknown key must fall back, never reach the SQL.
$guarded = LeaveRecord::currentlyAway($filters, 5, 0, ["driver_name; DROP TABLE drivers --", 'asc']);
ok(is_array($guarded), 'an injected sort key is ignored, not executed');

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
