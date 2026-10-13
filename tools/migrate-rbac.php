<?php

declare(strict_types=1);

/**
 * RBAC migration (CLI only, idempotent).
 *
 *   php tools/migrate-rbac.php
 *
 * Creates the role/permission tables, seeds them from permission_catalogue()
 * and role_definitions(), and links every existing user to a role. Safe to run
 * repeatedly: permissions are upserted, and a user who already has a role is
 * never reassigned.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/migrate-rbac.php\n");
}

require __DIR__ . '/../app/config/config.php';
require __DIR__ . '/../app/core/Database.php';
require __DIR__ . '/../app/middleware/permissions.php';

use App\Core\Database;

echo "RBAC migration\n";

/* ---------------------------------------------------------------- tables -- */

$ddl = [
    'roles' => 'CREATE TABLE IF NOT EXISTS roles (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug        VARCHAR(60)  NOT NULL,
        name        VARCHAR(120) NOT NULL,
        description VARCHAR(255) NULL,
        is_system   TINYINT(1)   NOT NULL DEFAULT 0,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_roles_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    'permissions' => 'CREATE TABLE IF NOT EXISTS permissions (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        permission_key VARCHAR(80)  NOT NULL,
        label          VARCHAR(160) NOT NULL,
        group_name     VARCHAR(40)  NOT NULL DEFAULT \'general\',
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_permissions_key (permission_key),
        KEY idx_permissions_group (group_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    'role_permissions' => 'CREATE TABLE IF NOT EXISTS role_permissions (
        role_id       INT UNSIGNED NOT NULL,
        permission_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (role_id, permission_id),
        KEY idx_rp_permission (permission_id),
        CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    'user_roles' => 'CREATE TABLE IF NOT EXISTS user_roles (
        user_id INT UNSIGNED NOT NULL,
        role_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, role_id),
        KEY idx_user_roles_role (role_id),
        CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users (id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles (id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
];

foreach ($ddl as $table => $sql) {
    Database::exec($sql);
    echo "  table {$table} ready\n";
}

// users.role was ENUM('admin','user') and now has to hold a role slug
// ("operations", "viewer", ...). MODIFY is a no-op once it is already VARCHAR.
Database::exec("ALTER TABLE users MODIFY role VARCHAR(60) NOT NULL DEFAULT 'viewer'");
echo "  users.role widened to hold a role slug\n";

/* ------------------------------------------------------------ permissions -- */

$permissions = 0;

foreach (permission_catalogue() as $group => $entries) {
    foreach ($entries as $key => $label) {
        Database::run(
            'INSERT INTO permissions (permission_key, label, group_name)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), group_name = VALUES(group_name)',
            [(string) $key, (string) $label, (string) $group]
        );
        $permissions++;
    }
}
echo "  {$permissions} permissions upserted\n";

/* ------------------------------------------------------------------ roles -- */

$roleIds = [];

foreach (role_definitions() as $slug => $definition) {
    Database::run(
        'INSERT INTO roles (slug, name, description, is_system)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)',
        [(string) $slug, (string) $definition['name'], (string) $definition['description']]
    );

    $roleIds[$slug] = (int) Database::scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
}
echo '  ' . count($roleIds) . " roles upserted\n";

/* ------------------------------------------------------- role permissions -- */

$allKeys = permission_keys();
$granted  = 0;

foreach (role_definitions() as $slug => $definition) {
    $roleId  = $roleIds[$slug];
    $wanted  = $definition['permissions'] === ['*']
        ? $allKeys
        : array_values(array_intersect($definition['permissions'], $allKeys));

    Database::run('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);

    foreach ($wanted as $key) {
        $permissionId = (int) Database::scalar('SELECT id FROM permissions WHERE permission_key = ?', [$key]);

        if ($permissionId > 0) {
            Database::run(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                [$roleId, $permissionId]
            );
            $granted++;
        }
    }
}
echo "  {$granted} role-permission links written\n";

/* -------------------------------------------------------- existing users -- */

// Legacy users.role values mapped onto the new catalogue. Anything that is
// not recognised falls back to the read-only role (least privilege).
$legacyMap = [
    'admin'        => 'admin',
    'administrator'=> 'admin',
    'hr'           => 'hr',
    'hr/personnel' => 'hr',
    'operations'   => 'operations',
    'ops'          => 'operations',
    'viewer'       => 'viewer',
    'management'   => 'viewer',
    'user'         => 'viewer',
];

$linked = 0;
$note   = [];

$users = Database::all('SELECT id, username, role FROM users ORDER BY id');

foreach ($users as $user) {
    $existing = (int) Database::scalar(
        'SELECT COUNT(*) FROM user_roles WHERE user_id = ?',
        [(int) $user['id']]
    );

    if ($existing > 0) {
        continue;   // already assigned - never silently reassign
    }

    $legacy = strtolower(trim((string) ($user['role'] ?? '')));
    $slug   = $legacyMap[$legacy] ?? 'viewer';
    $roleId = $roleIds[$slug] ?? null;

    if ($roleId === null) {
        continue;
    }

    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [(int) $user['id'], $roleId]);
    Database::run('UPDATE users SET role = ? WHERE id = ?', [$slug, (int) $user['id']]);
    $linked++;

    if ($legacy !== $slug) {
        $note[] = '  ' . $user['username'] . ': role "' . $legacy . '" -> ' . $slug;
    }
}

echo "  {$linked} user(s) linked to a role\n";

foreach ($note as $line) {
    echo $line . "\n";
}

/* ------------------------------------------------------------- summary ---- */

echo "\nSummary\n";
foreach (Database::all('SELECT slug, name FROM roles ORDER BY id') as $role) {
    $count = (int) Database::scalar(
        'SELECT COUNT(*) FROM role_permissions WHERE role_id = (SELECT id FROM roles WHERE slug = ?)',
        [(string) $role['slug']]
    );
    printf("  %-12s %-22s %2d permissions\n", $role['slug'], $role['name'], $count);
}

printf(
    "\npermissions=%d roles=%d role_links=%d user_links=%d\n",
    (int) Database::scalar('SELECT COUNT(*) FROM permissions'),
    (int) Database::scalar('SELECT COUNT(*) FROM roles'),
    (int) Database::scalar('SELECT COUNT(*) FROM role_permissions'),
    (int) Database::scalar('SELECT COUNT(*) FROM user_roles')
);

echo "done\n";
exit(0);

