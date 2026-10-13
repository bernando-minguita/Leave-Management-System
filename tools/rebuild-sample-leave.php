<?php

declare(strict_types=1);

/**
 * Rebuild the sample leave records so the Leave Trend chart has real data.
 *
 *   php tools/rebuild-sample-leave.php          rebuild
 *   php tools/rebuild-sample-leave.php --dry    show the plan only
 *
 * Only rows tagged "SAMPLE DATA" are touched - user-created records are never
 * modified or removed. Drivers are assigned so no two records for the same
 * driver overlap, which is the same rule LeaveRecord::findOverlap() enforces
 * when leave is created through the UI.
 *
 * Coverage: Jan 2026 -> today (history for the trend) plus a forward runway
 * of planned trips out to Jan 2027, which is what the Expected Returns month
 * picker lists.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/rebuild-sample-leave.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Status;

$dry = in_array('--dry', $argv, true);

$today = today();

$drivers = array_map(
    static fn (array $r): int => (int) $r['id'],
    Database::all('SELECT id FROM drivers ORDER BY id')
);
$types = array_map(
    static fn (array $r): int => (int) $r['id'],
    Database::all('SELECT id FROM leave_types ORDER BY id')
);

if ($drivers === [] || $types === []) {
    exit("Need at least one driver and one leave type.\n");
}

/** Windows already promised per driver: driver_id => list of [start, end]. */
$taken = [];

/** First driver with no clash - preferring one that has no records yet. */
$pick = static function (string $start, string $end) use (&$taken, $drivers): ?int {
    $reuse = null;

    foreach ($drivers as $id) {
        $clash = false;

        foreach ($taken[$id] ?? [] as [$s, $e]) {
            if ($start <= $e && $end >= $s) {
                $clash = true;
                break;
            }
        }

        if ($clash) {
            continue;
        }

        if (!isset($taken[$id])) {
            return $id; // an untouched driver wins
        }

        $reuse = $reuse ?? $id;
    }

    return $reuse;
};

$destinations = ['Philippines', 'India', 'Pakistan', 'Nepal', 'Bangladesh', 'Egypt', 'Indonesia', 'Sri Lanka'];
$reasons      = ['Annual home leave', 'Family function', 'Home leave', 'Personal work', 'Family holiday'];

$rows = [];
$n    = 0;

/** Append one record, assigning a non-clashing driver. */
$add = static function (
    string $start,
    string $end,
    string $status,
    ?string $actual,
    string $reason,
    array &$rows,
    &$taken,
    $pick,
    array $types,
    array $dests,
    array $reasons,
    int &$n
): void {
    $id = $pick($start, $end);

    if ($id === null) {
        return;
    }

    $taken[$id][] = [$start, $end];
    $n++;

    $rows[] = [
        'driver_id'             => $id,
        'leave_type_id'         => $types[$n % count($types)],
        'last_working_date'     => date('Y-m-d', strtotime($start . ' -1 day')),
        'leave_start_date'      => $start,
        'ticket_date'           => date('Y-m-d', strtotime($start . ' -2 day')),
        'expected_arrival_date' => $end,
        'actual_arrival_date'   => $actual,
        'destination'           => $dests[$n % count($dests)],
        'reason'                => $reason !== '' ? $reason : $reasons[$n % count($reasons)],
        'remarks'               => 'SAMPLE DATA',
        'status'                => $status,
        'created_by'            => 1,
        'updated_by'            => 1,
        'created_at'            => now(),
        'updated_at'            => now(),
    ];
};

/* ---- 1. history: Jan 2026 -> Aug 2026, all returned -------------------- */

$monthly = [
    '2026-01' => 4, '2026-02' => 3, '2026-03' => 4, '2026-04' => 4,
    '2026-05' => 5, '2026-06' => 6, '2026-07' => 7, '2026-08' => 6,
];

foreach ($monthly as $ym => $count) {
    for ($i = 0; $i < $count; $i++) {
        $start  = sprintf('%s-%02d', $ym, 1 + ($i * 3));
        $end    = date('Y-m-d', strtotime($start . ' +' . (12 + ($i % 5)) . ' days'));
        $actual = date('Y-m-d', strtotime($end . ' +' . ($i % 2) . ' days'));

        $add($start, $end, Status::RETURNED, $actual, '', $rows, $taken, $pick,
            $types, $destinations, $reasons, $n);
    }
}

/* ---- 2. current month: keeps the dashboard KPIs honest ----------------- */

$t    = $today;
$d    = static fn (string $expr): string => date('Y-m-d', strtotime($expr));
$live = [
    // [start, expected, actual, status]
    [$d("$t -24 days"), $d("$t -14 days"), $d("$t -13 days"), Status::RETURNED],
    [$d("$t -22 days"), $d("$t -12 days"), $d("$t -11 days"), Status::RETURNED],
    [$d("$t -20 days"), $d("$t -9 days"),  $d("$t -9 days"),  Status::RETURNED],
    [$d("$t -8 days"),  $t,                null,              Status::ON_LEAVE], // due back today
    [$d("$t -7 days"),  $t,                null,              Status::ON_LEAVE], // due back today
    [$d("$t -6 days"),  $d("$t +1 day"),   null,              Status::ON_LEAVE],
    [$d("$t -5 days"),  $d("$t +1 day"),   null,              Status::ON_LEAVE],
    [$d("$t -4 days"),  $d("$t +3 days"),  null,              Status::ON_LEAVE],
    [$d("$t -16 days"), $d("$t -3 days"),  null,              Status::OVERDUE],
    [$d("$t -18 days"), $d("$t -5 days"),  null,              Status::OVERDUE],
    [$d("$t -2 days"),  $d("$t +10 days"), null,              Status::ON_LEAVE],
    [$d("$t -1 day"),   $d("$t +12 days"), null,              Status::ON_LEAVE],
];

foreach ($live as [$start, $end, $actual, $status]) {
    $add($start, $end, $status, $actual, '', $rows, $taken, $pick,
        $types, $destinations, $reasons, $n);
}

/* ---- 3. forward runway: planned trips out to Jan 2027 ------------------ */

foreach (['2026-10', '2026-11', '2026-12', '2027-01'] as $ym) {
    foreach ([5, 15, 25] as $day) {
        $start = sprintf('%s-%02d', $ym, $day);
        $end   = date('Y-m-d', strtotime($start . ' +14 days'));

        $add($start, $end, Status::PLANNED, null, '', $rows, $taken, $pick,
            $types, $destinations, $reasons, $n);
    }
}

/* ---- 4. cancelled, for status coverage -------------------------------- */

foreach ([['2026-09-02', '2026-09-14'], ['2026-09-04', '2026-09-16'],
          ['2026-09-07', '2026-09-19'], ['2026-09-09', '2026-09-21']] as [$start, $end]) {
    $before = count($rows);

    $add($start, $end, Status::CANCELLED, null, 'Trip postponed', $rows, $taken, $pick,
        $types, $destinations, $reasons, $n);

    if (count($rows) > $before) {
        $rows[count($rows) - 1]['cancelled_reason'] = 'Trip postponed by the driver';
    }
}

/* ---- report ------------------------------------------------------------ */

$byMonth = [];
foreach ($rows as $r) {
    if ($r['status'] === Status::CANCELLED) {
        continue;
    }
    $ym = substr((string) $r['leave_start_date'], 0, 7);
    $byMonth[$ym] = ($byMonth[$ym] ?? 0) + 1;
}
ksort($byMonth);

$existing = (int) Database::scalar("SELECT COUNT(1) FROM leave_records WHERE remarks LIKE '%SAMPLE DATA%'");

echo 'Plan: ' . count($rows) . " sample records (replacing {$existing})\n";
echo 'Drivers used: ' . count($taken) . ' of ' . count($drivers) . "\n";
echo "Starts per month:\n";
foreach ($byMonth as $ym => $c) {
    echo "  {$ym}: {$c}\n";
}

if ($dry) {
    echo "\n(dry run - nothing written)\n";
    exit(0);
}

Database::run("DELETE FROM leave_records WHERE remarks LIKE '%SAMPLE DATA%'");

foreach ($rows as $r) {
    Database::insert('leave_records', $r);
}

Status::sync();

echo "\nInserted " . count($rows) . " sample leave record(s).\n";
echo 'Total leave_records now: ' . (int) Database::scalar('SELECT COUNT(1) FROM leave_records') . "\n";

