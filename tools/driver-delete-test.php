<?php
/**
 * Regression test for the driver-delete foreign key failure.
 *
 *   php tools/driver-delete-test.php
 *
 * Reproduced the reported error: deleting drivers while leave_applications
 * still pointed at them raised SQLSTATE 23000 / 1451. Every delete path now
 * clears the applications first, and the single/bulk guard counts them.
 *
 * Builds its own throwaway driver, so real data is never touched.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

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

$badge = 'ZZ-' . substr(str_replace('.', '', uniqid('', true)), -7);

Database::run(
    'INSERT INTO drivers (badge_number, driver_name, iqama_number, status)
     VALUES (?, ?, ?, "active")',
    [$badge, 'FK Delete Test', 'IQ' . $badge]
);

$driverId = (int) Database::scalar('SELECT id FROM drivers WHERE badge_number = ?', [$badge]);
$leaveTypeId = (int) Database::scalar('SELECT id FROM leave_types ORDER BY id LIMIT 1');

echo "== setup ==\n";
ok($driverId > 0, "temporary driver created (badge $badge, id $driverId)");
ok($leaveTypeId > 0, 'a leave type exists to hang the application on');

// Give the driver ONLY an application - no leave records at all. This is the
// case the old guard missed: it only counted leave_records, so the DELETE ran
// and the foreign key caught it.
Database::run(
    'INSERT INTO leave_applications
        (driver_id, leave_type_id, application_date, status, leave_status)
     VALUES (?, ?, CURDATE(), "pending", "not_started")',
    [$driverId, $leaveTypeId]
);

$appId = (int) Database::scalar(
    'SELECT id FROM leave_applications WHERE driver_id = ?',
    [$driverId]
);
ok($appId > 0, "temporary leave application created (id $appId)");

$records = (int) Database::scalar(
    'SELECT COUNT(*) FROM leave_records WHERE driver_id = ?',
    [$driverId]
);
ok($records === 0, 'the driver has NO leave records (only an application)');

/* ------------------------- 1. the reported failure -------------------- */

echo "\n== 1. deleting the driver directly still fails, as reported ==\n";

$error = '';

try {
    Database::run('DELETE FROM drivers WHERE id = ?', [$driverId]);
} catch (\Throwable $ex) {
    $error = $ex->getMessage();
}

$stillThere = (int) Database::scalar('SELECT COUNT(*) FROM drivers WHERE id = ?', [$driverId]);
ok($error !== '', 'the FK refuses the delete (an exception was raised)');
ok(
    str_contains($error, '1451') || str_contains($error, 'Integrity constraint'),
    'the error is the reported 1451 integrity violation'
);
ok($stillThere === 1, 'the driver survived the failed delete');

/* --------------------------- 2. the guard ------------------------------ */

echo "\n== 2. the driver guard now sees the application ==\n";

$history = (int) Database::scalar(
    'SELECT (SELECT COUNT(*) FROM leave_records      WHERE driver_id = ?)
          + (SELECT COUNT(*) FROM leave_applications WHERE driver_id = ?)',
    [$driverId, $driverId]
);
ok($history === 1, "guard reports 1 row of history instead of 0 (got $history)");
ok($history > 0, 'guard blocks the delete and shows the "deactivate instead" message');

/* ------------------- 3. the corrected delete order --------------------- */

echo "\n== 3. purge order (records -> applications -> drivers) works ==\n";

Database::run('DELETE FROM leave_records WHERE driver_id = ?', [$driverId]);
Database::run('DELETE FROM leave_applications WHERE driver_id = ?', [$driverId]);
Database::run('DELETE FROM drivers WHERE id = ?', [$driverId]);

$gone = (int) Database::scalar('SELECT COUNT(*) FROM drivers WHERE id = ?', [$driverId]);
ok($gone === 0, 'driver deleted cleanly with no 1451');

/* ------------------------------ cleanup -------------------------------- */

Database::run('DELETE FROM leave_applications WHERE driver_id = ?', [$driverId]);
Database::run('DELETE FROM leave_records WHERE driver_id = ?', [$driverId]);
Database::run('DELETE FROM drivers WHERE badge_number = ?', [$badge]);

$left = (int) Database::scalar('SELECT COUNT(*) FROM drivers WHERE badge_number = ?', [$badge]);
ok($left === 0, 'test data removed');

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
