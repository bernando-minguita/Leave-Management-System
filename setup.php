<?php

declare(strict_types=1);

/**
 * LMS - one-time web installer.
 *
 *   https://your-domain/lms/setup.php?key=D41F8C27A95E6B30-6E2B9F41
 *
 * Enter the database name, user and password. It creates the database, applies
 * the schema and seeds, builds the RBAC and account-security tables, creates
 * the admin account, and writes app/config/config.php for you.
 *
 * On success it DELETES ITSELF. Delete it manually if you abort.
 *
 * Why this exists: tools/install.php is CLI-only on purpose (it can DROP a
 * database) and .htaccess blocks /tools/ from HTTP. This file lives at the web
 * root, takes no destructive action, and never prints a password back.
 */

const INSTALL_KEY = 'D41F8C27A95E6B30-6E2B9F41';

/** Split a .sql file into statements - same approach as tools/install.php.
 *  None of schema.sql / seed*.sql contain DELIMITER, triggers or routines. */
function sql_statements(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException('Missing file: ' . basename($file));
    }

    $raw = file_get_contents($file);

    if ($raw === false) {
        throw new RuntimeException('Unreadable file: ' . basename($file));
    }

    $raw = preg_replace('/^\s*--.*$/m', '', $raw);
    $out = [];

    foreach (preg_split('/;\s*(?:\r?\n|$)/', $raw) ?: [] as $chunk) {
        $chunk = trim($chunk);
        if ($chunk !== '') {
            $out[] = $chunk;
        }
    }

    return $out;
}

/** Run a .sql file. Returns the number of statements executed. */
function run_sql_file(PDO $pdo, string $file): int
{
    $n = 0;

    foreach (sql_statements($file) as $sql) {
        $pdo->exec($sql);
        $n++;
    }

    return $n;
}

/**
 * Write the five DB_* constants into app/config/config.php, leaving every
 * other line untouched. Returns false WITHOUT writing if any define is
 * missing, so the config can never be left half-updated.
 */
function write_config(array $db): bool
{
    $path = __DIR__ . '/app/config/config.php';
    $src  = @file_get_contents($path);

    if ($src === false) {
        return false;
    }

    foreach ($db as $key => $value) {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);
        $src = preg_replace(
            "/define\\(\\s*'{$key}'\\s*,\\s*'[^']*'\\s*\\)/",
            "define('{$key}', '{$escaped}')",
            $src,
            1,
            $count
        );

        if ($count !== 1) {
            return false;
        }
    }

    $tmp = $path . '.new';

    if (@file_put_contents($tmp, $src) === false) {
        return false;
    }

    return @rename($tmp, $path);
}

/** Minimal standalone page - no app assets, so it works before install. */
function page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>'
       . htmlspecialchars($title) . '</title><style>'
       . 'body{font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;background:#f2f5f9;color:#1f2937;margin:0;padding:32px 16px}'
       . '.card{max-width:640px;margin:0 auto;background:#fff;border-radius:10px;padding:26px 28px;box-shadow:0 1px 3px rgba(0,0,0,.09)}'
       . 'h1{font-size:1.3rem;margin:0 0 4px;color:#16233a}'
       . 'p.sub{color:#6b7280;margin:0 0 18px;font-size:.9rem}'
       . 'label{display:block;font-weight:600;font-size:.8rem;margin:14px 0 5px;color:#374151}'
       . 'input[type=text],input[type=password]{width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:6px;font:inherit;box-sizing:border-box}'
       . 'input:focus{outline:2px solid #2563eb55;border-color:#2563eb}'
       . 'button{margin-top:20px;width:100%;padding:11px;background:#1e4b7a;color:#fff;border:0;border-radius:6px;font:inherit;font-weight:600;cursor:pointer}'
       . 'button:hover{background:#16385a}'
       . '.err{background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;padding:11px 13px;border-radius:6px;font-size:.88rem;margin:16px 0}'
       . '.ok{background:#dcfce7;border:1px solid #86efac;color:#14532d;padding:11px 13px;border-radius:6px;font-size:.88rem;margin:16px 0}'
       . '.warn{background:#fef3c7;border:1px solid #fcd34d;color:#78350f;padding:11px 13px;border-radius:6px;font-size:.88rem;margin:16px 0}'
       . 'pre{background:#0f172a;color:#e2e8f0;padding:14px;border-radius:6px;overflow:auto;font-size:.8rem;max-height:320px}'
       . '.chk{display:flex;gap:8px;align-items:flex-start;margin-top:14px;font-size:.9rem}'
       . 'code{background:#eef2f7;padding:1px 5px;border-radius:4px;font-size:.87em}'
       . '.foot{max-width:640px;margin:16px auto 0;color:#9ca3af;font-size:.78rem;text-align:center}'
       . '</style></head><body><div class="card"><h1>'
       . htmlspecialchars($title) . '</h1>' . $body . '</div>'
       . '<div class="foot">LMS setup &middot; this page deletes itself when finished'
       . '</div></body></html>';
    exit;
}

/* ============================ request gate ============================= */

// The key may arrive in the URL or as a hidden field posted by the form.
$key = (string) ($_REQUEST['key'] ?? '');

if (!hash_equals(INSTALL_KEY, $key)) {
    http_response_code(403);
    page('Forbidden',
        '<p class="sub">This installer is locked.</p>'
        . '<div class="err"><strong>Missing or incorrect install key.</strong><br>'
        . 'Open it as <code>setup.php?key=' . htmlspecialchars(INSTALL_KEY) . '</code></div>');
}

ini_set('display_errors', '1');
error_reporting(E_ALL);

/* ================================ the form ============================= */

// Pure helper functions: permission catalogue + role definitions, so the
// installer seeds RBAC from the same source the application authorises with.
require_once __DIR__ . '/app/middleware/permissions.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $prefillHost = htmlspecialchars((string) ($_GET['host'] ?? 'localhost'));
    $prefillName = htmlspecialchars((string) ($_GET['db'] ?? ''));

    page('LMS · Setup',
        '<p class="sub">Creates the database, tables, roles and the admin '
        . 'account. Nothing is deleted &mdash; this installer contains no DROP.</p>'

        . '<div class="warn"><strong>Before you start:</strong> '
        . 'create the empty database in your control panel first '
        . '(cPanel names are usually <code>youruser_databasename</code>), '
        . 'and make sure the database user has privileges on it.</div>'

        . '<form method="post" action="">'
        . '<input type="hidden" name="key" value="' . htmlspecialchars(INSTALL_KEY) . '">'

        . '<label for="db_host">Database host</label>'
        . '<input type="text" id="db_host" name="db_host" value="localhost" required>'

        . '<label for="db_name">Database name</label>'
        . '<input type="text" id="db_name" name="db_name" value="' . $prefillName
        . '" placeholder="youruser_lms" required autofocus>'

        . '<label for="db_user">Database user</label>'
        . '<input type="text" id="db_user" name="db_user" placeholder="youruser_lms" required>'

        . '<label for="db_pass">Database password</label>'
        . '<input type="password" id="db_pass" name="db_pass" autocomplete="off">'

        . '<label for="admin_user">Admin username</label>'
        . '<input type="text" id="admin_user" name="admin_user" value="admin" required>'

        . '<label for="admin_pass">Admin password (min 8 characters)</label>'
        . '<input type="password" id="admin_pass" name="admin_pass" minlength="8" required>'

        . '<div class="chk"><input type="checkbox" id="seed" name="seed" value="1" checked>'
        . '<label for="seed" style="margin:0;font-weight:400;display:inline">'
        . 'Load sample data &mdash; branches, agencies, leave types, 50 demo drivers '
        . 'and demo leave records. Uncheck for an empty, production-ready install.'
        . '</label></div>'

        . '<button type="submit">Install LMS</button>'
        . '</form>'

        . '<p class="sub" style="margin-top:18px;font-size:.82rem">'
        . 'Writes <code>app/config/config.php</code> for you, then deletes '
        . '<code>setup.php</code>. Your database password is never echoed back.'
        . '</p>');
}

/* ================================ install ============================== */

$host      = trim((string) ($_POST['db_host'] ?? 'localhost'));
$name      = trim((string) ($_POST['db_name'] ?? ''));
$user      = trim((string) ($_POST['db_user'] ?? ''));
$pass      = (string) ($_POST['db_pass'] ?? '');
$adminUser = trim((string) ($_POST['admin_user'] ?? ''));
$adminPass = (string) ($_POST['admin_pass'] ?? '');
$wantSeed  = isset($_POST['seed']);

$errors = [];

if ($host === '') {
    $errors[] = 'Database host is required.';
}
if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    $errors[] = 'Database name is required - letters, digits and underscore only.';
}
if ($user === '') {
    $errors[] = 'Database user is required.';
}
if ($adminUser === '' || preg_match('/^[A-Za-z0-9_.@-]{2,50}$/', $adminUser) !== 1) {
    $errors[] = 'Admin username is required (2-50 characters).';
}
if (strlen($adminPass) < 8) {
    $errors[] = 'Admin password must be at least 8 characters.';
}

if ($errors !== []) {
    $html = '<div class="err"><strong>Please correct the following:</strong><ul>'
          . '<li>' . implode('</li><li>', array_map('htmlspecialchars', $errors)) . '</li></ul></div>'
          . '<form method="post" action="">'
          . '<input type="hidden" name="key" value="' . htmlspecialchars(INSTALL_KEY) . '">'
          . '<label for="db_host">Database host</label>'
          . '<input type="text" id="db_host" name="db_host" value="' . htmlspecialchars($host) . '" required>'
          . '<label for="db_name">Database name</label>'
          . '<input type="text" id="db_name" name="db_name" value="' . htmlspecialchars($name) . '" required>'
          . '<label for="db_user">Database user</label>'
          . '<input type="text" id="db_user" name="db_user" value="' . htmlspecialchars($user) . '" required>'
          . '<label for="db_pass">Database password</label>'
          . '<input type="password" id="db_pass" name="db_pass" autocomplete="off">'
          . '<label for="admin_user">Admin username</label>'
          . '<input type="text" id="admin_user" name="admin_user" value="' . htmlspecialchars($adminUser) . '" required>'
          . '<label for="admin_pass">Admin password (min 8 characters)</label>'
          . '<input type="password" id="admin_pass" name="admin_pass" minlength="8" required>'
          . ($wantSeed ? '<input type="hidden" name="seed" value="1">' : '')
          . '<button type="submit">Install LMS</button></form>';

    page('Check the form', $html);
}

$steps = [];

/** Show how far we got, so a failure is never a blank page. */
$fail = function (string $title, string $detail) use (&$steps, $host, $name, $user, $adminUser, $wantSeed): void {
    $list = $steps === [] ? '' : '<div class="warn"><strong>Completed before the error:</strong><ul><li>'
        . implode('</li><li>', $steps) . '</li></ul></div>';

    page($title,
        $list
        . '<div class="err"><strong>' . htmlspecialchars($title) . '</strong><br>'
        . htmlspecialchars($detail) . '</div>'
        . '<p class="sub">Nothing was deleted. Every step is safe to submit again.</p>'
        . '<form method="post" action="">'
        . '<input type="hidden" name="key" value="' . htmlspecialchars(INSTALL_KEY) . '">'
        . '<input type="hidden" name="db_host" value="' . htmlspecialchars($host) . '">'
        . '<input type="hidden" name="db_name" value="' . htmlspecialchars($name) . '">'
        . '<input type="hidden" name="db_user" value="' . htmlspecialchars($user) . '">'
        . '<input type="hidden" name="admin_user" value="' . htmlspecialchars($adminUser) . '">'
        . ($wantSeed ? '<input type="hidden" name="seed" value="1">' : '')
        . '<label for="db_pass">Database password</label>'
        . '<input type="password" id="db_pass" name="db_pass" autocomplete="off" required>'
        . '<label for="admin_pass">Admin password (min 8 characters)</label>'
        . '<input type="password" id="admin_pass" name="admin_pass" minlength="8" required>'
        . '<button type="submit">Try again</button></form>');
};

// 1. Connect - no database selected yet.
try {
    $pdo = new PDO("mysql:host={$host};port=3306;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $steps[] = 'Connected to MySQL on ' . $host;
} catch (PDOException $e) {
    $fail('Could not connect to MySQL', $e->getMessage());
}

// 2. Create the database if allowed; otherwise it must already exist.
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4");
    $steps[] = 'Database `' . $name . '` is ready';
} catch (PDOException $e) {
    $steps[] = 'Could not create the database (it may already exist): ' . $e->getMessage();
}

try {
    $pdo->exec("USE `{$name}`");
} catch (PDOException $e) {
    $fail('Database `' . $name . '` is not usable',
        $e->getMessage() . ' - create it in your control panel and grant this user access.');
}

// 3. Schema - every CREATE TABLE uses IF NOT EXISTS, so re-running is safe.
try {
    $steps[] = 'Schema applied - ' . run_sql_file($pdo, __DIR__ . '/database/schema.sql') . ' statements';
} catch (Throwable $e) {
    $fail('Schema failed', $e->getMessage());
}

/* --------- admin account MUST exist before the seeds ------------------
 * leave_records.created_by is a foreign key to users(id), so a brand new
 * database would fail seed-leave.sql without this row. tools/install.php
 * does the same thing for the same reason.
 * -------------------------------------------------------------------- */

try {
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $find = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $find->execute([$adminUser]);
    $existingId = $find->fetchColumn();

    if ($existingId !== false) {
        $pdo->prepare('UPDATE users SET password_hash = ?, role = ?, status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$hash, 'admin', 'active', (int) $existingId]);
        $steps[] = "Admin account `{$adminUser}` ready (password set)";
    } else {
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$adminUser, $hash, 'Administrator', 'admin', 'active']);
        $steps[] = "Admin account `{$adminUser}` created";
    }

    // user_roles needs the RBAC tables, which do not exist yet on a first
    // run - the RBAC step below links every account, and links again if it
    // finds this table already present (a re-run).
    if ($pdo->query("SHOW TABLES LIKE 'user_roles'")->fetch() !== false) {
        $pdo->exec(
            'INSERT IGNORE INTO user_roles (user_id, role_id)
             SELECT u.id, r.id FROM users u JOIN roles r ON r.slug = u.role'
        );
    }
} catch (Throwable $e) {
    $fail('Admin account setup failed', $e->getMessage());
}

// 4. Optional sample data (INSERT IGNORE / NOT EXISTS guarded).
if ($wantSeed) {
    foreach (['seed.sql', 'seed-drivers.sql', 'seed-leave.sql'] as $seed) {
        try {
            $steps[] = $seed . ' - ' . run_sql_file($pdo, __DIR__ . '/database/' . $seed) . ' statements';
        } catch (Throwable $e) {
            $fail('Seed failed: ' . $seed, $e->getMessage());
        }
    }
} else {
    $steps[] = 'Sample data skipped (clean install)';
}

/* ================================== RBAC =============================== *
 * Same tables and rows tools/migrate-rbac.php creates, seeded from the very
 * permission_catalogue() / role_definitions() the application authorises with.
 * All statements are IF NOT EXISTS or upsert, so re-running is safe.
 * ====================================================================== */

try {
    $rbacDdl = [
        'CREATE TABLE IF NOT EXISTS roles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(60) NOT NULL, name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uq_roles_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS permissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            permission_key VARCHAR(80) NOT NULL, label VARCHAR(160) NOT NULL,
            group_name VARCHAR(40) NOT NULL DEFAULT \'general\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uq_permissions_key (permission_key),
            KEY idx_permissions_group (group_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT UNSIGNED NOT NULL, permission_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (role_id, permission_id), KEY idx_rp_permission (permission_id),
            CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS user_roles (
            user_id INT UNSIGNED NOT NULL, role_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, role_id), KEY idx_user_roles_role (role_id),
            CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles (id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];

    foreach ($rbacDdl as $sql) {
        $pdo->exec($sql);
    }

    $col = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
    )->fetchColumn();

    if ($col !== false && stripos((string) $col, 'varchar(60)') === false) {
        $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(60) NOT NULL DEFAULT 'viewer'");
        $steps[] = 'users.role widened to hold a role slug';
    }

    // 25 permissions, upserted.
    $insPerm = $pdo->prepare(
        'INSERT INTO permissions (permission_key, label, group_name) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE label = VALUES(label), group_name = VALUES(group_name)'
    );
    $permCount = 0;
    foreach (permission_catalogue() as $group => $perms) {
        foreach ($perms as $permKey => $label) {
            $insPerm->execute([$permKey, $label, $group]);
            $permCount++;
        }
    }
    $steps[] = "{$permCount} permissions upserted";

    // 4 roles, upserted.
    $insRole = $pdo->prepare(
        'INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
    );
    foreach (role_definitions() as $slug => $def) {
        $insRole->execute([$slug, $def['name'], $def['description']]);
    }
    $steps[] = count(role_definitions()) . ' roles upserted';

    // Role -> permission links. admin holds '*', meaning every permission.
    $allKeys = permission_keys();
    $insLink = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
    $selRole = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
    $selPerm = $pdo->prepare('SELECT id FROM permissions WHERE permission_key = ?');
    $linkCount = 0;

    foreach (role_definitions() as $slug => $def) {
        $selRole->execute([$slug]);
        $roleId = (int) $selRole->fetchColumn();
        if ($roleId === 0) {
            continue;
        }

        $wanted = $def['permissions'] === ['*']
            ? $allKeys
            : array_values(array_intersect($allKeys, $def['permissions']));

        foreach ($wanted as $permKey) {
            $selPerm->execute([$permKey]);
            $permId = (int) $selPerm->fetchColumn();
            if ($permId > 0) {
                $insLink->execute([$roleId, $permId]);
                $linkCount++;
            }
        }
    }
    $steps[] = "{$linkCount} role-permission links written";

    // Link any existing user to its role, and keep users.role in step.
    $linked = $pdo->exec(
        'INSERT IGNORE INTO user_roles (user_id, role_id)
         SELECT u.id, r.id FROM users u JOIN roles r ON r.slug = u.role'
    );
    $steps[] = ($linked ?: 0) . ' user(s) linked to a role';
} catch (Throwable $e) {
    $fail('RBAC setup failed', $e->getMessage());
}

/* ========================= account security ============================ *
 * Same schema tools/migrate-account.php adds. ALTER ... ADD COLUMN is NOT
 * idempotent, so every statement is guarded - re-running cannot fail halfway.
 * ====================================================================== */

try {
    $hasColumn = function (string $table, string $column) use ($pdo): bool {
        $s = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $s->execute([$table, $column]);

        return (int) $s->fetchColumn() > 0;
    };

    $hasIndex = function (string $table, string $index) use ($pdo): bool {
        $s = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $s->execute([$table, $index]);

        return (int) $s->fetchColumn() > 0;
    };

    // users: profile + 2FA fields. Order matters - each AFTER clause must exist.
    foreach ([
        ['users', 'email',               'VARCHAR(160) NULL AFTER full_name'],
        ['users', 'password_changed_at', 'DATETIME NULL AFTER last_login_at'],
        ['users', 'totp_secret',         'VARCHAR(255) NULL AFTER password_changed_at'],
        ['users', 'totp_enabled_at',     'DATETIME NULL AFTER totp_secret'],
        ['users', 'two_factor_required', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_enabled_at'],
        ['audit_logs', 'user_agent',     'VARCHAR(255) NULL AFTER ip_address'],
    ] as [$table, $column, $definition]) {
        if (!$hasColumn($table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            $steps[] = "{$table}.{$column} added";
        }
    }

    if (!$hasIndex('users', 'idx_users_email')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_email (email)');
        $steps[] = 'index idx_users_email added';
    }

    foreach ([
        'user_recovery_codes' =>
            'CREATE TABLE IF NOT EXISTS user_recovery_codes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_recovery_user (user_id),
                CONSTRAINT fk_recovery_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

        'rate_limits' =>
            'CREATE TABLE IF NOT EXISTS rate_limits (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                scope VARCHAR(40) NOT NULL,
                bucket VARCHAR(190) NOT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL,
                last_attempt_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rate_scope_bucket (scope, bucket)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ] as $table => $sql) {
        $pdo->exec($sql);
        $steps[] = "table {$table} ready";
    }

    $steps[] = 'Account security schema ready';
} catch (Throwable $e) {
    $fail('Account security migration failed', $e->getMessage());
}

/* ============================ write config ============================= */

// Point the application at this database so it works without editing anything.
if (!write_config([
    'DB_HOST' => $host,
    'DB_PORT' => '3306',
    'DB_NAME' => $name,
    'DB_USER' => $user,
    'DB_PASS' => $pass,
])) {
    // Deliberately NOT deleting the installer: the user still needs it.
    $fail('Could not write app/config/config.php',
        'The file must be writable by the web server. Make app/config/config.php '
        . 'writable (chmod 644 or 664), then submit again.');
}

$steps[] = 'app/config/config.php updated';

/* ============================= self delete ============================= */

// Only now - after everything succeeded - does the installer remove itself.
$removed = @unlink(__FILE__);
$steps[] = $removed
    ? 'setup.php deleted itself'
    : 'setup.php still exists - please delete it manually';

/* =============================== success =============================== */

$list = '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $steps)) . '</li></ul>';

$leftovers = [];

if (!$wantSeed) {
    $leftovers[] = 'No sample data was loaded. Create at least one <strong>leave type</strong> '
        . '(Settings &rarr; Leave Types) before recording leave, plus a branch and agency.';
}

$leftovers[] = 'If your app is in a sub-folder, set <code>RewriteBase</code> in '
    . '<code>.htaccess</code> to that folder (for example <code>/lms/</code>). '
    . 'Wrong RewriteBase is the usual cause of 404s on clean URLs.';
$leftovers[] = 'Sign in as <code>' . htmlspecialchars($adminUser) . '</code>, then change the '
    . 'password immediately under <strong>My Profile &rarr; Change password</strong>.';

page('Installation complete',
    '<div class="ok"><strong>LMS is installed.</strong></div>'
    . '<div class="warn"><strong>What happened:</strong>' . $list . '</div>'
    . '<p class="sub"><strong>Before you go live:</strong></p><ul>'
    . '<li>' . implode('</li><li>', $leftovers) . '</li></ul>'
    . '<p class="sub">This installer has deleted itself. If the file is still '
    . 'there, remove it by hand.</p>');



