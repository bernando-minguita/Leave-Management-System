<?php

declare(strict_types=1);

/**
 * Record the branch backfill in driver_history.
 *
 * tools/fill-driver-branches.php assigned a branch to drivers that had none,
 * which is a change to a GOVERNED field made outside the application. The
 * baseline seed had already run by then (and skipped them, correctly - it
 * never invents a value), so those drivers ended up holding a branch with no
 * provenance at all: exactly the question this feature exists to answer.
 *
 * Writes one `initial` row per affected driver through DriverHistory::record(),
 * so the GOVERNABLE check, the no-op guard and the append-only rule all still
 * apply. The reason names the tool, so the row reads as what it is rather than
 * pretending the value was always there.
 *
 *   php tools/record-branch-backfill.php           apply
 *   php tools/record-branch-backfill.php --dry     show what would change
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/record-branch-backfill.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Driver;
use App\Models\DriverHistory;

$dry = in_array('--dry', $argv, true);

$reason = 'Branch backfilled by tools/fill-driver-branches.php';

$gap = Database::all(
    'SELECT d.id, d.branch_id
       FROM drivers d
      WHERE d.branch_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM driver_history h
                         WHERE h.driver_id = d.id AND h.field_name = "branch_id")
      ORDER BY d.id'
);

echo 'Branch backfill provenance (' . ($dry ? '--dry' : 'apply') . ")\n";
echo str_repeat('-', 64) . "\n";

if ($gap === []) {
    echo "every driver with a branch already has a history row for it\n";
    echo "nothing to do\n";
    exit(0);
}

$written = 0;
$bytes   = 0;

foreach ($gap as $row) {
    $id     = (int)$row['id'];
    $branch = (int)$row['branch_id'];

    echo sprintf(
        "  driver %-5d branch %-3d -> %-10s\n",
        $id,
        $branch,
        Driver::displayGovernedValue('branch_id', $branch)
    );

    if ($dry) {
        $written++;
        continue;
    }

    // Old value is NULL: there was genuinely nothing here before, which is
    // why the baseline seed skipped these drivers in the first place.
    $bytes += DriverHistory::record(
        $id,
        'branch_id',
        null,
        $branch,
        'initial',
        $reason
    );

    $written++;
}

echo str_repeat('-', 64) . "\n";
echo ($dry ? '[dry]  would write ' : "wrote ") . $written . " row(s)\n";

if (!$dry) {
    $total = (int)Database::scalar('SELECT COUNT(*) FROM driver_history');
    $left  = (int)Database::scalar(
        'SELECT COUNT(*) FROM drivers d
          WHERE d.branch_id IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM driver_history h
                             WHERE h.driver_id = d.id AND h.field_name = "branch_id")'
    );

    echo "driver_history now holds {$total} row(s)\n";
    echo "drivers still missing branch provenance: {$left} (must be 0)\n";

    $orphans = (int)Database::scalar(
        'SELECT COUNT(*) FROM driver_history dh
           LEFT JOIN drivers d ON d.id = dh.driver_id WHERE d.id IS NULL'
    );
    echo "orphaned history rows: {$orphans} (must be 0)\n";
}
