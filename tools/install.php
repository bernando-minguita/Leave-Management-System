<?php

declare(strict_types=1);

/**
 * LMS installer (CLI only).
 *
 *   php tools/install.php            create database + schema + seed data
 *   php tools/install.php --schema   schema only (no sample data)
 *   php tools/install.php --drop     drop the database first
 *   php tools/install.php --admin    (re)set the admin password
 *
 * Uses PDO because mysql.exe is usually not on PATH in XAMPP.
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool must be run from the command line.\n");
}

$root = dirname(__DIR__);
require $root . '/app/config/config.php';

$flags = array_slice($argv, 1);
$wantsDrop     = in_array('--drop', $flags, true);
$wantsSeed     = !in_array('--schema', $flags, true);
$wantsAdmin    = in_array('--admin', $flags, true);

/**
 * Split a SQL file into individual statements (schema/seed contain no
 * semicolons inside string literals).
 */
function sql_statements(string $file): array
{
    if (!is_file($file)) {
        fwrite(STDERR, "Missing file: $file\n");
        exit(1);
    }

    $raw    = file_get_contents($file);
    $raw    = preg_replace('/^\s*--.*$/m', '', $raw); // strip -- comments
    $chunks = preg_split('/;\s*(?:\r?\n|$)/', $raw) ?: [];

    $statements = [];
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if ($chunk !== '') {
            $statements[] = $chunk;
        }
    }

    return $statements;
}

function say(string $message): void
{
    echo $message . "\n";
}

/* ---------------------------------------------------------------- connect */
try {
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $ex) {
    fwrite(STDERR, 'Cannot connect to MySQL at ' . DB_HOST . ':' . DB_PORT . "\n" . $ex->getMessage() . "\n");
    exit(1);
}

$server->exec("SET time_zone = '" . APP_TZ_OFFSET . "'");

/* ------------------------------------------------------------- --drop -- */
if ($wantsDrop) {
    say('Dropping database ' . DB_NAME . ' ...');
    $server->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
}

/* --------------------------------------------------------- create + use  */
say('Ensuring database ' . DB_NAME . ' exists ...');
$server->exec(
    'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` '
    . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);

$server->exec('USE `' . DB_NAME . '`');

/* ---------------------------------------------------------------- schema */
say('Applying schema ...');
foreach (sql_statements($root . '/database/schema.sql') as $statement) {
    $server->exec($statement);
}
say('  schema OK');

/* --------------------------------------------------------------- --admin */
if ($wantsAdmin) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $server->prepare(
        'INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE updated_at = updated_at'
    );
    $stmt->execute(['admin', $hash, 'System Administrator', 'admin', 'active']);
    say('  admin password reset to: admin123');
}

/* ------------------------------------------------ default admin account */
// Must exist BEFORE seeding: leave_records.created_by references users(id).
if (!$wantsAdmin) {
    $exists = (int)$server->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
    if ($exists === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $server->prepare(
            'INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute(['admin', $hash, 'System Administrator', 'admin', 'active']);
        say('Created default admin / admin123');
    }
}

/* ------------------------------------------------------------------ seed */
// Three focused seed files, applied in a fixed order. Each statement is
// idempotent (INSERT IGNORE / NOT EXISTS) so re-running is safe.
$seedFiles = ['seed.sql', 'seed-drivers.sql', 'seed-leave.sql'];

if ($wantsSeed) {
    foreach ($seedFiles as $seedFile) {
        $seeded = true;

        foreach (sql_statements($root . '/database/' . $seedFile) as $statement) {
            try {
                $server->exec($statement);
            } catch (PDOException $ex) {
                // Duplicate key on a re-run is fine; anything else is fatal.
                if ((string)$ex->getCode() === '1062') {
                    continue;
                }
                fwrite(STDERR, "Seed failed in {$seedFile}: " . $ex->getMessage() . "\n");
                fwrite(STDERR, $statement . "\n");
                $seeded = false;
                break;
            }
        }

        if (!$seeded) {
            exit(1);
        }

        say('  ' . $seedFile . ' OK');
    }
}

/* --------------------------------------------------------------- report  */
$tables = $server->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
say('Tables: ' . implode(', ', $tables));

foreach (['drivers', 'branches', 'agencies', 'leave_types', 'leave_records', 'users'] as $table) {
    if (in_array($table, $tables, true)) {
        $count = (int)$server->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        say(sprintf('  %-14s %d rows', $table, $count));
    }
}

say('');
say('Done. Open http://localhost/lms/ and sign in with  admin / admin123');
