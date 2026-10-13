<?php

declare(strict_types=1);

/**
 * Account security schema: profile fields, TOTP 2FA, recovery codes,
 * login rate limiting and audit user agents.
 *
 *   php tools/migrate-account.php        apply
 *   php tools/migrate-account.php --dry  show what would change
 *
 * Idempotent - every statement is guarded, so it is safe to run repeatedly.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/migrate-account.php\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$dry = in_array('--dry', $argv, true);

$statements = [];

/* ---- users columns ----------------------------------------------------- */

$existing = array_map(
    static fn (array $r): string => (string) $r['Field'],
    Database::all('SHOW COLUMNS FROM users')
);

$addColumns = [
    // 'name' => 'definition'
    'email'                => 'VARCHAR(160) NULL AFTER full_name',
    'password_changed_at'  => 'DATETIME NULL AFTER last_login_at',
    // Encrypted with APP_KEY (AES-256-GCM); not returned to the browser.
    'totp_secret'          => 'VARCHAR(255) NULL AFTER password_changed_at',
    'totp_enabled_at'      => 'DATETIME NULL AFTER totp_secret',
    'two_factor_required'  => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_enabled_at',
];

foreach ($addColumns as $name => $definition) {
    if (!in_array($name, $existing, true)) {
        $statements[] = "ALTER TABLE users ADD COLUMN {$name} {$definition}";
    }
}

$indexKeys = array_map(
    static fn (array $r): string => (string) $r['Key_name'],
    Database::all('SHOW INDEX FROM users')
);

if (!in_array('idx_users_email', $indexKeys, true)) {
    $statements[] = 'ALTER TABLE users ADD INDEX idx_users_email (email)';
}

/* ---- audit_logs.user_agent -------------------------------------------- */

$auditCols = array_map(
    static fn (array $r): string => (string) $r['Field'],
    Database::all('SHOW COLUMNS FROM audit_logs')
);

if (!in_array('user_agent', $auditCols, true)) {
    $statements[] = 'ALTER TABLE audit_logs ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address';
}

/* ---- recovery codes ---------------------------------------------------- */

$statements[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_recovery_user (user_id),
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

/* ---- rate limiting ----------------------------------------------------- */

$statements[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(40) NOT NULL,
    bucket VARCHAR(190) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rate_scope_bucket (scope, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

/* ---- apply ------------------------------------------------------------- */

echo 'Pending statements: ' . count($statements) . "\n";

foreach ($statements as $sql) {
    echo '  - ' . implode(' ', array_slice(explode(' ', preg_replace('/\s+/', ' ', trim($sql))), 0, 5)) . " ...\n";
}

if ($dry) {
    echo "\n(dry run - nothing applied)\n";
    exit(0);
}

foreach ($statements as $sql) {
    Database::run($sql);
}

echo "\nApplied.\n";
echo 'users columns: ' . implode(', ', array_map(
    static fn (array $r): string => (string) $r['Field'],
    Database::all('SHOW COLUMNS FROM users')
)) . "\n";
echo 'tables: ' . implode(', ', array_map(
    static fn (array $r): string => (string) array_values($r)[0],
    Database::all('SHOW TABLES')
)) . "\n";
