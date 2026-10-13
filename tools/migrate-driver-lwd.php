<?php

declare(strict_types=1);

/**
 * Last Working Date for drivers (automated deactivation rules).
 *
 *   php tools/migrate-driver-lwd.php         apply
 *   php tools/migrate-driver-lwd.php --dry   show what would happen
 *
 * Idempotent - safe to run repeatedly. Fresh installs already get this column
 * from database/schema.sql.
 *
 * A driver with a last working date still counts in the active population up
 * to and including that day (capacity, dashboard KPIs, pickers). NULL means
 * no end date on file, which is exactly how every driver behaved before this
 * migration - so applying it moves no existing number.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/migrate-driver-lwd.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$dry     = in_array('--dry', $argv, true);
$pending = [];

if (Database::all("SHOW COLUMNS FROM drivers LIKE 'last_working_date'") === []) {
    $pending[] = [
        'label' => 'drivers.last_working_date DATE NULL',
        'sql'   => 'ALTER TABLE drivers ADD COLUMN last_working_date DATE NULL AFTER operational_status',
    ];
}

// SHOW INDEX's LIKE clause filters on the TABLE name, not the index name -
// filter on Key_name here so the check can never report a false positive.
$indexed = false;
foreach (Database::all('SHOW INDEX FROM drivers') as $row) {
    if ((string)($row['Key_name'] ?? '') === 'idx_drivers_lwd') {
        $indexed = true;
        break;
    }
}

if (!$indexed) {
    $pending[] = [
        'label' => 'drivers.idx_drivers_lwd (index on last_working_date)',
        'sql'   => 'ALTER TABLE drivers ADD KEY idx_drivers_lwd (last_working_date)',
    ];
}

if ($pending === []) {
    echo "drivers.last_working_date is up to date. Nothing to do.\n";
    exit(0);
}

foreach ($pending as $p) {
    echo ($dry ? 'Would apply: ' : 'Applying: ') . $p['label'] . "\n";

    if (!$dry) {
        Database::run($p['sql']);
    }
}

if ($dry) {
    echo "\n(dry run - nothing applied)\n";
    exit(0);
}

echo "done. Re-run to confirm it reports 'up to date'.\n";
exit(0);
