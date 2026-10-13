<?php

declare(strict_types=1);


/**
 * Remove the sample drivers / leave records created by database/seed.sql.
 *
 *   php tools/remove-sample-data.php          remove sample leave + drivers
 *   php tools/remove-sample-data.php --leave  leave records only
 *   php tools/remove-sample-data.php --dry    show what would be removed
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool must be run from the command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$flags   = array_slice($argv, 1);
$dry     = in_array('--dry', $flags, true);
$onlyLeave = in_array('--leave', $flags, true);

/* ---------------------------------------------------------- leave rows  */
$sampleDrivers = Database::all(
    "SELECT id, badge_number, driver_name FROM drivers WHERE remarks LIKE '%SAMPLE DATA%'"
);

$driverIds = array_map(static fn (array $r): int => (int)$r['id'], $sampleDrivers);
$placeholders = $driverIds === [] ? '0' : implode(',', $driverIds);

$leaveCount = (int)Database::scalar(
    "SELECT COUNT(*) FROM leave_records WHERE driver_id IN ($placeholders)"
);

echo "Sample drivers: " . count($driverIds) . "\n";
echo "Leave records:  $leaveCount\n";

if ($dry) {
    echo "(dry run - nothing deleted)\n";
    exit(0);
}

if ($leaveCount > 0) {
    Database::run("DELETE FROM leave_records WHERE driver_id IN ($placeholders)");
    echo "Deleted $leaveCount leave record(s).\n";
}

if ($onlyLeave) {
    echo "Sample drivers kept.\n";
    exit(0);
}

if ($driverIds !== []) {
    // fk_app_driver is ON DELETE RESTRICT too, so applications must be cleared
    // before the drivers they point at.
    $appCount = (int) Database::scalar(
        "SELECT COUNT(*) FROM leave_applications WHERE driver_id IN ($placeholders)"
    );

    if ($appCount > 0) {
        Database::run("DELETE FROM leave_applications WHERE driver_id IN ($placeholders)");
        echo "Deleted $appCount leave application(s).\n";
    }

    Database::run("DELETE FROM drivers WHERE id IN ($placeholders)");
    echo 'Deleted ' . count($driverIds) . " sample driver(s).\n";
}

audit_log('sample-data-removed', 'drivers', null, ['drivers' => count($driverIds)], null);
echo "Done.\n";
