<?php

declare(strict_types=1);

/**
 * Local data repair: give every driver without a branch one of the three real
 * branches (DMM / RUH / JED), spread so the Address Book, the dashboard branch
 * charts and the new Driver Capacity Planning page can all filter on
 * drivers.branch_id.
 *
 * Only rows whose branch_id IS NULL are touched - the drivers that already
 * carry a branch keep it. The seeded test branch (WFT - Workflow Test Branch)
 * never receives drivers. The spread is decided by driver id (MOD 20), so the
 * run is reproducible and can be repeated safely: a second run fills whatever
 * is still NULL and changes nothing else.
 *
 *   php tools/fill-driver-branches.php            # preview only
 *   php tools/fill-driver-branches.php --commit   # apply the changes
 *
 * A revert file listing the exact ids that were changed is written alongside
 * the run, so the whole change can be undone with one statement.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/fill-driver-branches.php [--commit]\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

/** The three production branches, resolved by code so ids never hard-code. */
$targets = [];
foreach (Database::all(
    'SELECT id, code, branch_name FROM branches
      WHERE code IN ("DMM","RUH","JED") AND status = "active"
      ORDER BY FIELD(code,"DMM","RUH","JED")'
) as $row) {
    $targets[(string)$row['code']] = [
        'id'   => (int)$row['id'],
        'name' => (string)$row['branch_name'],
    ];
}

$missing = array_diff(['DMM', 'RUH', 'JED'], array_keys($targets));
if ($missing !== []) {
    exit('Missing branch(es): ' . implode(', ', $missing) . " - create them in Settings > Branches first.\n");
}

/** id MOD 20 -> branch code: 9/7/4 slots = 45% / 35% / 20%. */
$bucketFor = static function (int $id): string {
    $slot = $id % 20;

    if ($slot <= 8) {
        return 'DMM';
    }

    return $slot <= 15 ? 'RUH' : 'JED';
};

$pending = Database::all(
    'SELECT id, badge_number, driver_name, status
       FROM drivers
      WHERE branch_id IS NULL
      ORDER BY id'
);

$plan = ['DMM' => [], 'RUH' => [], 'JED' => []];
foreach ($pending as $row) {
    $plan[$bucketFor((int)$row['id'])][] = (int)$row['id'];
}

$before = Database::all(
    'SELECT COALESCE(b.code, "(none)") AS code, COUNT(*) AS n
       FROM drivers d LEFT JOIN branches b ON b.id = d.branch_id
      GROUP BY b.code ORDER BY n DESC'
);

echo "Fill drivers.branch_id from the three real branches\n";
echo str_repeat('-', 60) . "\n";
echo "Drivers without a branch : " . count($pending) . "\n";
foreach ($plan as $code => $ids) {
    printf("  %-4s -> %s (id %d): %d driver(s)\n", $code, $targets[$code]['name'], $targets[$code]['id'], count($ids));
}
echo "\nBranch distribution before:\n";
foreach ($before as $row) {
    printf("  %-6s %d\n", $row['code'], (int)$row['n']);
}

if ($pending === []) {
    echo "\nNothing to do - every driver already has a branch.\n";
    exit(0);
}

if (!in_array('--commit', $argv, true)) {
    echo "\nPREVIEW ONLY - re-run with --commit to apply.\n";
    exit(0);
}

$changed = [];
foreach ($plan as $code => $ids) {
    if ($ids === []) {
        continue;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    Database::run(
        'UPDATE drivers SET branch_id = ? WHERE branch_id IS NULL AND id IN (' . $placeholders . ')',
        array_merge([$targets[$code]['id']], $ids)
    );

    foreach ($ids as $id) {
        $changed[$id] = $code;
    }
}

// Revert file: the exact ids this run touched, each still written back to NULL.
// It is generated from what actually happened, so it can never clear a branch
// the administrator assigned afterwards by hand.
//
// Written into storage/logs (the app's documented artifact directory) rather
// than a fixed absolute path - the previous C:/lms-scratch hard-coding made
// this silently create a literal "C:/lms-scratch" directory relative to the
// working directory on a Linux host, so the undo it promised was not where
// anyone would look for it.
$revertPath = dirname(__DIR__) . '/storage/logs/fill-driver-branches-revert.sql';
@mkdir(dirname($revertPath), 0775, true);
$lines = [
    '-- Revert of tools/fill-driver-branches.php',
    '-- Generated ' . date('Y-m-d H:i:s'),
    '-- Only these ids were changed; every other driver keeps its branch.',
    '',
];
foreach ($changed as $id => $code) {
    $lines[] = "UPDATE drivers SET branch_id = NULL WHERE id = {$id}; -- was filled as {$code}";
}
$lines[] = '';
file_put_contents($revertPath, implode("\n", $lines));

$after = Database::all(
    'SELECT COALESCE(b.code, "(none)") AS code, COUNT(*) AS n
       FROM drivers d LEFT JOIN branches b ON b.id = d.branch_id
      GROUP BY b.code ORDER BY n DESC'
);

echo "\nApplied " . count($changed) . " driver branch assignment(s).\n\n";
echo "Branch distribution after:\n";
foreach ($after as $row) {
    printf("  %-6s %d\n", $row['code'], (int)$row['n']);
}
echo "\nRevert file: {$revertPath}\n";
