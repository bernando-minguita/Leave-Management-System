<?php

declare(strict_types=1);

/**
 * Leave <-> Leave Application linking (leave_records.leave_application_id).
 *
 *   php tools/migrate-leave-application-link.php         apply
 *   php tools/migrate-leave-application-link.php --dry   show what would happen
 *
 * Idempotent - safe to run repeatedly. Fresh installs already get this column
 * from database/schema.sql.
 *
 * The link is one-way: the record points at its application, and the
 * application's leave_status follows the record (see
 * LeaveApplication::syncFromRecord()). Deleting an application unlinks the
 * record (ON DELETE SET NULL) rather than destroying leave history.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/migrate-leave-application-link.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$dry     = in_array('--dry', $argv, true);
$pending = [];

$columns = array_map(
    static fn (array $r): string => (string) array_values($r)[0],
    Database::all('SHOW COLUMNS FROM leave_records')
);

if (!in_array('leave_application_id', $columns, true)) {
    $pending[] = [
        'label' => 'leave_records.leave_application_id INT UNSIGNED NULL',
        'sql'   => 'ALTER TABLE leave_records ADD COLUMN leave_application_id INT UNSIGNED NULL AFTER leave_type_id',
    ];
}

$indexed = false;
foreach (Database::all('SHOW INDEX FROM leave_records') as $row) {
    if ((string)($row['Key_name'] ?? '') === 'uq_leave_application') {
        $indexed = true;
        break;
    }
}

if (!$indexed) {
    $pending[] = [
        'label' => 'leave_records.uq_leave_application (one application -> one record)',
        'sql'   => 'ALTER TABLE leave_records ADD UNIQUE KEY uq_leave_application (leave_application_id)',
    ];
}

// A foreign key constraint does NOT appear in SHOW INDEX when it reuses an
// index that already exists (which uq_leave_application does), so SHOW CREATE
// TABLE is the reliable, information_schema-free way to see whether it is
// already on the table. SHOW CREATE TABLE returns two columns - Table and
// Create Table - and it is the second one that carries the DDL.
$createRow = Database::one('SHOW CREATE TABLE leave_records') ?? [];
$ddl       = (string) ($createRow['Create Table'] ?? $createRow['create table'] ?? '');
$constraint = $ddl !== '' && str_contains($ddl, 'fk_leave_application');

if (!$constraint) {
    $pending[] = [
        'label' => 'FK fk_leave_application -> leave_applications (ON DELETE SET NULL)',
        'sql'   => 'ALTER TABLE leave_records
                    ADD CONSTRAINT fk_leave_application
                    FOREIGN KEY (leave_application_id) REFERENCES leave_applications (id)
                    ON DELETE SET NULL ON UPDATE CASCADE',
    ];
}

if ($pending === []) {
    echo "leave_records.leave_application_id is up to date. Nothing to do.\n";
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
