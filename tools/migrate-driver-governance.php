<?php

declare(strict_types=1);

/**
 * Driver master-data governance: change requests + driver history.
 *
 * Creates the two governance tables, seeds a baseline "Initial Driver Setup"
 * history row for every governed field already on file, and puts the three new
 * permission keys (plus the re-labelled `drivers.edit`) on file.
 *
 *   php tools/migrate-driver-governance.php           apply
 *   php tools/migrate-driver-governance.php --dry     show what would change
 *   php tools/migrate-driver-governance.php --verify  report only, write nothing
 *
 * Idempotent - every statement is guarded, so it is safe to run repeatedly.
 * Fresh installs get both tables from database/schema.sql; this tool exists for
 * a database that predates the feature. It is also the ONLY thing that writes
 * the baseline seed - a fresh install starts with an empty History tab until a
 * driver is first edited, which is honest rather than invented history.
 *
 * Nothing here ever touches `drivers`, `leave_records`, `leave_applications` or
 * `audit_logs`. The only data written is the baseline history seed, and that
 * runs on the single run where driver_history is empty.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/migrate-driver-governance.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Driver;

$dry    = in_array('--dry', $argv, true);
$verify = in_array('--verify', $argv, true);
$mode   = $verify ? '--verify' : ($dry ? '--dry' : 'apply');

$say = static function (string $msg) use ($dry, $verify): void {
    if (!$verify) {
        echo ($dry ? '[dry]  ' : '') . $msg . "\n";
    }
};

// SHOW TABLES returns the column as "Tables_in_<db>" and, unlike SELECT, takes
// no placeholder under native prepares (PDO with EMULATE_PREPARES=false sends
// the literal '?' and MySQL 1064s). Read the whole list - it is cheap, and the
// list must not be cached because sections 1 and 2 create tables mid-run.
$tableExists = static function (string $table): bool {
    $names = array_map(
        static fn (array $r): string => (string)array_values($r)[0],
        Database::all('SHOW TABLES')
    );

    return in_array($table, $names, true);
};

echo "Driver master-data governance migration (mode: {$mode})\n";
echo str_repeat('-', 64) . "\n";

/* ---- 0. pre-flight: branch backfill ------------------------------------ */

$nullBranch = (int)Database::scalar(
    'SELECT COUNT(*) FROM drivers WHERE branch_id IS NULL'
);

if ($nullBranch > 0) {
    echo "NOTE  {$nullBranch} driver(s) still have no branch.\n";
    echo "      Branch is a governed field from now on, so these show as\n";
    echo "      '-' -> value in a change request rather than a transfer.\n";
    echo "      Backfill first if you want transfers to be real:\n";
    echo "        php tools/fill-driver-branches.php --commit\n";
} else {
    $say('branch backfill: every driver already has a branch');
}

/* ---- 1. driver_change_requests ------------------------------------------ */

if (!$tableExists('driver_change_requests')) {
    $say('creating driver_change_requests');

    if (!$dry && !$verify) {
        Database::exec(<<<'SQL'
CREATE TABLE driver_change_requests (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id      INT UNSIGNED NOT NULL,
    field_name     VARCHAR(60)  NOT NULL,
    old_value      TEXT         NULL,
    new_value      TEXT         NULL,
    reason         VARCHAR(500) NOT NULL,
    status         ENUM('pending','approved','rejected','cancelled')
                   NOT NULL DEFAULT 'pending',
    requested_by   INT UNSIGNED NULL,
    requested_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by    INT UNSIGNED NULL,
    reviewed_at    DATETIME     NULL,
    review_note    VARCHAR(500) NULL,
    effective_date DATE         NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    pending_key    VARCHAR(90)  GENERATED ALWAYS AS
                   (IF(status = 'pending',
                       CONCAT(driver_id, ':', field_name), NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dcr_pending (pending_key),
    KEY idx_dcr_driver (driver_id, status),
    KEY idx_dcr_status (status, created_at),
    KEY idx_dcr_requested_by (requested_by),
    CONSTRAINT fk_dcr_driver FOREIGN KEY (driver_id)
        REFERENCES drivers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_dcr_requested_by FOREIGN KEY (requested_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_dcr_reviewed_by FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
} else {
    $say('driver_change_requests already exists');
}

/* ---- 2. driver_history --------------------------------------------------- */

if (!$tableExists('driver_history')) {
    $say('creating driver_history');

    if (!$dry && !$verify) {
        Database::exec(<<<'SQL'
CREATE TABLE driver_history (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id      INT UNSIGNED NOT NULL,
    field_name     VARCHAR(60)  NOT NULL,
    old_value      TEXT         NULL,
    new_value      TEXT         NULL,
    reason         VARCHAR(500) NULL,
    source         ENUM('initial','approved_request','direct_correction',
                        'import','deactivation','reactivation')
                   NOT NULL DEFAULT 'direct_correction',
    request_id     INT UNSIGNED NULL,
    effective_date DATE         NULL,
    changed_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dh_driver (driver_id, created_at),
    KEY idx_dh_changed_by (changed_by),
    KEY idx_dh_request (request_id),
    CONSTRAINT fk_dh_driver FOREIGN KEY (driver_id)
        REFERENCES drivers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_dh_changed_by FOREIGN KEY (changed_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_dh_request FOREIGN KEY (request_id)
        REFERENCES driver_change_requests (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
} else {
    $say('driver_history already exists');
}

/* ---- 3. baseline history seed ------------------------------------------- */
/*
 * One row per governed field that already holds a value, so every driver has
 * a populated History tab the moment the feature ships. Runs only while
 * driver_history is empty - re-running must never duplicate the baseline.
 */

$seeded = 0;

if ($tableExists('driver_history') && !$verify) {
    $have = (int)Database::scalar('SELECT COUNT(*) FROM driver_history');

    if ($have === 0) {
        $fields  = array_keys(Driver::GOVERNABLE);
        $columns = implode(', ', array_map(
            static fn (string $f): string => '`' . $f . '`',
            $fields
        ));

        $drivers = Database::all(
            'SELECT id, joining_date, ' . $columns . ' FROM drivers'
        );

        $chunks = [];
        $params = [];
        $now    = date('Y-m-d H:i:s');

        foreach ($drivers as $d) {
            $id  = (int)$d['id'];
            $eff = $d['joining_date'] !== null ? (string)$d['joining_date'] : null;

            foreach ($fields as $field) {
                $value = $d[$field] ?? null;

                if ($value === null || $value === '') {
                    continue;   // never invent a value the address book lacks
                }

                // 9 columns in the INSERT below: driver_id, field_name,
                // old_value, new_value, reason, source, effective_date,
                // changed_by, created_at. Two of them (old_value, changed_by)
                // are deliberately NULL, so the tuple carries 7 placeholders
                // for the 7 values pushed onto $params.
                $chunks[] = '(?, ?, NULL, ?, ?, ?, ?, NULL, ?)';
                array_push($params,
                    $id, $field, (string)$value, 'Initial Driver Setup',
                    'initial', $eff, $now
                );
                $seeded++;
            }
        }

        if ($chunks !== []) {
            $say("seeding {$seeded} baseline history row(s) across "
                . count($drivers) . ' driver(s)');

            if (!$dry) {
                $sql = 'INSERT INTO driver_history
                     (driver_id, field_name, old_value, new_value, reason,
                      source, effective_date, changed_by, created_at)
                     VALUES ' . implode(', ', $chunks);

                // Cheap invariant, but it turns an opaque 21S01 from MySQL
                // into a sentence that says which side is wrong.
                $ph = substr_count(implode('', $chunks), '?');

                if ($ph !== count($params)) {
                    throw new RuntimeException(
                        "driver_history seed is inconsistent: {$ph} placeholder(s) "
                        . 'for ' . count($params) . ' bound value(s)'
                    );
                }

                Database::run($sql, $params);
            }
        } else {
            $say('baseline seed: no drivers on file, nothing to seed');
        }
    } else {
        $say("driver_history already has {$have} row(s) - baseline seed skipped");
    }
}


/* ---- 4. permissions ------------------------------------------------------ */
/*
 * ensure_permission_catalogue() inserts NEW keys on the next web request, but
 * it never re-labels a key that is already on file - so the repurposed
 * drivers.edit would keep showing its old wording in Settings -> Roles for
 * ever. Refreshing the label here is the difference between an honest screen
 * and a silent one.
 *
 * Three NEW keys, deliberately. `drivers.change_request` is not one of them:
 * drivers.edit (re-labelled below) already means "request a change", so two
 * keys for the same action is how a settings screen starts to lie.
 *
 *   drivers.edit (re-labelled)        -> open/submit/cancel a change request
 *   drivers.master_correct            -> write master data directly
 *   drivers.change_request_approve    -> decide requests
 *   drivers.history.view              -> read the History tab
 */

$newLabels = [
    'drivers.master_correct'
        => 'Correct driver master data directly (reason required, recorded in Driver History)',
    'drivers.change_request_approve'
        => 'Approve or reject driver change requests',
    'drivers.history.view'
        => 'View driver history and its filters',
];

if (!$verify) {
    $oldLabel = (string)Database::scalar(
        'SELECT label FROM permissions WHERE permission_key = ?',
        ['drivers.edit']
    );

    if ($oldLabel === 'Edit driver master information') {
        $say('re-labelling drivers.edit: "' . $oldLabel
            . '" -> "Request changes to driver master information"');

        if (!$dry) {
            Database::run(
                "UPDATE permissions SET label = ?, group_name = 'Address Book'
                  WHERE permission_key = ?",
                ['Request changes to driver master information', 'drivers.edit']
            );
        }
    } elseif ($oldLabel !== '') {
        $say('drivers.edit label is already "' . $oldLabel . '" - left alone');
    }
}

foreach ($newLabels as $key => $label) {
    $onFile = Database::scalar(
        'SELECT permission_key FROM permissions WHERE permission_key = ?',
        [$key]
    );

    if ($onFile === null) {
        $say("adding permission {$key}");

        if (!$dry) {
            Database::run(
                'INSERT IGNORE INTO permissions (permission_key, label, group_name)
                 VALUES (?, ?, ?)',
                [$key, $label, 'Address Book']
            );
        }
    }
}

// Seed the shipped grants so the feature is reachable straight after the
// upgrade - the same rule ensure_permission_catalogue() applies to new keys.
// Administrator holds ['*'] and gets nothing inserted here; that is right,
// because ensure_permission_catalogue() treats '*' as holding every key.
//
// drivers.edit belongs in this list even though it is NOT a new key. The row
// already existed (as "Edit driver master information"), and
// ensure_permission_catalogue() only ever acts on a key that is missing
// entirely - so it would never grant the re-purposed key to Operations, which
// now asks for it in role_definitions() and would 403 on the first request it
// tried to file. Scope stays limited to this feature's keys: a blanket sync
// against role_definitions() would silently overwrite deliberate revocations
// an administrator made in Settings -> Roles.
$grantKeys = array_keys($newLabels);
$grantKeys[] = 'drivers.edit';

if (!$dry && !$verify) {
    $roleIds = [];
    foreach (Database::all('SELECT id, slug FROM roles') as $r) {
        $roleIds[(string)$r['slug']] = (int)$r['id'];
    }

    foreach (role_definitions() as $slug => $def) {
        if (!isset($roleIds[$slug]) || $def['permissions'] === ['*']) {
            continue;
        }

        foreach (array_intersect($grantKeys, $def['permissions']) as $key) {
            Database::run(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id)
                 SELECT ?, id FROM permissions WHERE permission_key = ?',
                [$roleIds[$slug], $key]
            );
        }
    }
}


/* ---- 5. verify ----------------------------------------------------------- */

echo str_repeat('-', 64) . "\n";

foreach (['driver_change_requests', 'driver_history'] as $t) {
    if (!$tableExists($t)) {
        echo sprintf("%-26s MISSING\n", $t);
        continue;
    }

    $rows = (int)Database::scalar('SELECT COUNT(*) FROM `' . $t . '`');
    echo sprintf("%-26s present, %d row(s)\n", $t, $rows);

    if (!$dry && !$verify) {
        foreach (Database::all('SHOW INDEX FROM `' . $t . '`') as $ix) {
            echo '    key: ' . (string)$ix['Key_name']
                . ((int)($ix['Non_unique'] ?? 1) === 0 ? ' (UNIQUE)' : '') . "\n";
        }
    }
}

// The relationships this feature exists to protect must stay unbroken.
$orphans = (int)Database::scalar(
    'SELECT COUNT(*) FROM leave_records lr
       LEFT JOIN drivers d ON d.id = lr.driver_id WHERE d.id IS NULL'
) + (int)Database::scalar(
    'SELECT COUNT(*) FROM leave_applications la
       LEFT JOIN drivers d ON d.id = la.driver_id WHERE d.id IS NULL'
);

echo sprintf("%-26s %d orphaned transaction(s) (must be 0)\n",
    'orphan check', $orphans);

$snap = (int)Database::scalar(
    'SELECT COUNT(*) FROM leave_records WHERE branch_id IS NULL'
);
echo sprintf("%-26s %d record(s) without a branch snapshot\n",
    'leave branch snapshots', $snap);

if (!$verify) {
    foreach ($newLabels as $key => $label) {
        $on = (string)Database::scalar(
            'SELECT label FROM permissions WHERE permission_key = ?', [$key]
        );
        echo sprintf("%-26s %s\n", $key, $on === '' ? 'MISSING' : 'on file');
    }

    echo sprintf("%-26s %s\n", 'drivers.edit',
        (string)Database::scalar(
            'SELECT label FROM permissions WHERE permission_key = ?',
            ['drivers.edit']
        ));
}

echo "\nmode: {$mode}\n";

if ($dry) {
    echo "Re-run without --dry to apply.\n";
}

