<?php

declare(strict_types=1);

/**
 * Remove data created by tools/workflow-test.php.
 *
 *   php tools/remove-test-data.php        remove test drivers, branch, leave type
 *   php tools/remove-test-data.php --dry  only report what would be removed
 *
 * Never touches the SAMPLE DATA rows (badge B-0xx) or your real records.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/remove-test-data.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$dry = in_array('--dry', $argv, true);

/* ------------------------------------------------------ test drivers --- */

$drivers = Database::all(
    "SELECT id, badge_number FROM drivers
      WHERE driver_name = 'Workflow Test Driver'
         OR driver_name IN ('Imported Test Driver', 'Import Probe Driver')
         OR remarks LIKE 'Created by tools/workflow-test.php%'
         OR remarks LIKE 'Edited by tools/workflow-test.php%'
         OR remarks LIKE 'Temporary import%'"
);

$ids = array_map(static fn(array $r): int => (int)$r['id'], $drivers);

echo 'test drivers: ' . count($ids)
    . ($ids !== [] ? ' (' . implode(', ', array_map(static fn(array $r): string => (string)$r['badge_number'], $drivers)) . ')' : '')
    . "\n";

if ($ids !== []) {
    $ph    = implode(',', $ids);
    $leave = (int)Database::scalar("SELECT COUNT(*) FROM leave_records WHERE driver_id IN ({$ph})");

    echo "  their leave records: {$leave}\n";

    if (!$dry) {
        // Both leave registers are ON DELETE RESTRICT, so applications have to
        // go before the drivers - otherwise the DELETE dies on the foreign key.
        Database::run("DELETE FROM leave_records WHERE driver_id IN ({$ph})");
        Database::run("DELETE FROM leave_applications WHERE driver_id IN ({$ph})");
        Database::run("DELETE FROM drivers WHERE id IN ({$ph})");
        echo "  removed\n";
    }
}

/* ------------------------------------------------- test branch / type --- */

$branch = (int)Database::scalar("SELECT COUNT(*) FROM branches WHERE code = 'WFT'");
echo "test branch (WFT): {$branch}\n";
if ($branch > 0 && !$dry) {
    Database::run("DELETE FROM branches WHERE code = 'WFT'");
    echo "  removed\n";
}

$type = (int)Database::scalar("SELECT COUNT(*) FROM leave_types WHERE name = 'Workflow Test Type'");
echo "test leave type: {$type}\n";
if ($type > 0 && !$dry) {
    Database::run("DELETE FROM leave_types WHERE name = 'Workflow Test Type'");
    echo "  removed\n";
}

/* -------------------------------------------------------------- report -- */

echo "\nCurrent totals:\n";

foreach (['branches', 'agencies', 'leave_types', 'drivers', 'leave_records', 'users', 'audit_logs'] as $table) {
    printf("  %-15s %d\n", $table, (int)Database::scalar('SELECT COUNT(*) FROM `' . $table . '`'));
}

if ($dry) {
    echo "\n(dry run - nothing deleted)\n";
}
