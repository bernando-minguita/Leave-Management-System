<?php

declare(strict_types=1);

/**
 * RBAC reconciliation (CLI, read-only).
 *
 *   php tools/rbac-verify.php
 *
 * Compares the shipped role matrix (role_definitions() in
 * app/middleware/permissions.php) against the live grants in
 * role_permissions and reports every missing or extra grant without
 * changing anything. Exit 0 = matrix and database agree; exit 1 =
 * differences that must be reconciled (Settings -> Roles) or explicitly
 * accepted before a release. See README > Testing > Release gate.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$defs = role_definitions();

$filed = array_column(
    \App\Core\Database::all('SELECT permission_key FROM permissions'),
    'permission_key'
);
$filedSet = array_flip($filed);

$live = \App\Core\Database::all(
    'SELECT r.slug AS role, p.permission_key AS perm
       FROM role_permissions rp
       JOIN roles r      ON r.id = rp.role_id
       JOIN permissions p ON p.id = rp.permission_id'
);
$byRole = [];
foreach ($live as $row) {
    $byRole[(string)$row['role']][] = (string)$row['perm'];
}

$issues = 0;

// Catalogue keys the permissions table does not even carry yet.
$catalogue = [];
foreach ($defs as $def) {
    if (($def['permissions'] ?? []) !== ['*']) {
        $catalogue = array_merge($catalogue, $def['permissions']);
    }
}
foreach (array_diff(array_unique($catalogue), $filed) as $k) {
    printf("NOT ON FILE  %s (no row in permissions; the app seeds it on next boot)\n", $k);
    $issues++;
}

foreach ($defs as $slug => $def) {
    $want = ($def['permissions'] ?? []) === ['*'] ? $filed : $def['permissions'];
    $have = $byRole[$slug] ?? [];
    $missing = array_values(array_diff($want, $have));
    $extra   = array_values(array_diff($have, $want));

    printf("%-12s want %3d, has %3d%s\n", $slug, count($want), count($have),
        ($missing || $extra) ? '' : '  OK');
    foreach ($missing as $m) {
        printf("  MISSING    %s -> %s\n", $slug, $m);
        $issues++;
    }
    foreach ($extra as $m) {
        printf("  EXTRA      %s -> %s\n", $slug, $m);
        $issues++;
    }
}

printf("\n%d unexpected grant(s). %s\n", $issues, $issues === 0
    ? 'Shipped matrix and database agree.'
    : 'Reconcile in Settings -> Roles, or accept each one explicitly.');
exit($issues === 0 ? 0 : 1);
