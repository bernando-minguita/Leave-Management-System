<?php

declare(strict_types=1);


/**
 * Set (or reset) the admin password.
 *
 *   php tools/create-admin.php
 *   php tools/create-admin.php myusername mypassword "Full Name"
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool must be run from the command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? 'admin123';
$fullName = $argv[3] ?? 'System Administrator';
$role     = 'admin';

$hash = password_hash($password, PASSWORD_DEFAULT);

\App\Core\Database::run(
    'INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
        password_hash = VALUES(password_hash),
        full_name     = VALUES(full_name),
        role          = VALUES(role),
        status        = "active",
        updated_at    = NOW()',
    [$username, $hash, $fullName, $role, 'active']
);

echo "Admin account ready: {$username} / {$password}\n";
