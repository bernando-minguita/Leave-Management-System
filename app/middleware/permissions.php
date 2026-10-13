<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\View;

/**
 * Centralised permission gate.
 *
 * Application code asks WHAT may be done ("leave.create"), never WHO is doing
 * it. Roles, and which permissions each role holds, live in the database, so
 * an access rule can be changed without touching a controller or a view.
 *
 * The catalogue below is only the *seed definition* used by
 * tools/migrate-rbac.php; at runtime permissions come from the tables, so an
 * administrator can grant or revoke them from Settings.
 *
 * users -> user_roles -> roles -> role_permissions -> permissions.
 * The UI assigns exactly one role per user, but the join table already allows
 * several, so multiple roles per user can be added later without a migration.
 */

/**
 * Canonical permission catalogue, grouped for the Settings screens.
 *
 * @return array<string, array<string, string>> group => [permission key => label]
 */
function permission_catalogue(): array
{
    return [
        'Dashboard' => [
            'dashboard.view'   => 'View dashboard, KPI cards and charts',
            // Separate from dashboard.view so the Driver Dashboard can be
            // shown to one role and withheld from another without taking the
            // Leave Dashboard with it (spec section 20).
            'dashboard.drivers' => 'View the Driver Dashboard',
        ],
        'Address Book' => [
            'drivers.view'       => 'View, search and filter drivers, profiles and leave history',
            'drivers.create'     => 'Add drivers',
            // Repurposed: this now opens and submits a CHANGE REQUEST. Writing
            // master data directly moved to drivers.master_correct, so granting
            // this key can never again hand somebody the whole record.
            'drivers.edit'       => 'Request changes to driver master information',
            'drivers.master_correct'
                => 'Correct driver master data directly (reason required, recorded in Driver History)',
            'drivers.change_request_approve'
                => 'Approve or reject driver change requests',
            'drivers.history.view'
                => 'View driver history and its filters',
            'drivers.deactivate' => 'Deactivate and reactivate drivers',
            'drivers.delete'     => 'Permanently delete drivers',
            'drivers.import'     => 'Import drivers from a CSV file',
            'drivers.export'     => 'Export the address book',
        ],
        'Leave & Vacation' => [
            'leave.view'   => 'View current leave, records, calendar, expected and overdue returns',
            'leave.create' => 'Create leave records',
            'leave.edit'   => 'Edit leave records',
            'leave.cancel' => 'Cancel leave records',
            'leave.return' => 'Mark drivers as returned',
            'leave.delete' => 'Permanently delete leave records',
        ],
        'Reports' => [
            'reports.view'   => 'View reports',
            'reports.export' => 'Export reports',
            'reports.print'  => 'Print reports',
            // Separate from reports.view so the Driver Capacity Planning page
            // can be restricted to one role without touching the reports.
            'reports.capacity' => 'View driver capacity planning',
        ],
        'Settings' => [
            'settings.users'       => 'Manage user accounts',
            'settings.roles'       => 'Manage roles',
            'settings.permissions' => 'Grant or revoke role permissions',
            'settings.branches'    => 'Manage branches',
            'settings.agencies'    => 'Manage agencies',
            'settings.leave_types' => 'Manage leave types',
            'settings.system'      => 'System settings, backup, restore and demo data',
        ],
        'Audit' => [
            'audit.view' => 'View audit logs',
        ],
    ];
}

/**
 * The four roles the product ships with, and the permissions each holds.
 * `['*']` means every permission in the catalogue.
 *
 * @return array<string, array{name:string, description:string, permissions:array<int,string>}>
 */
function role_definitions(): array
{
    return [
        'admin' => [
            'name'        => 'Administrator',
            'description' => 'Unrestricted access to every function, including users, roles, permissions and system settings.',
            'permissions' => ['*'],
        ],
        'hr' => [
            'name'        => 'HR / Personnel',
            'description' => 'Maintains driver and leave records. Requests and approves '
                . 'driver master changes; may correct them directly. No access to '
                . 'settings, roles or system configuration.',
            'permissions' => [
                'dashboard.view', 'dashboard.drivers',
                'drivers.view', 'drivers.create', 'drivers.edit',
                // GRADUAL rollout: HR ships WITH the direct-correction right so
                // nothing tightens on upgrade day. Revoke master_correct from
                // Settings -> Roles when the business wants HR to go through
                // request/approve only - no code change needed.
                'drivers.master_correct', 'drivers.change_request_approve',
                'drivers.history.view',
                'drivers.deactivate',
                'drivers.import', 'drivers.export',
                'leave.view', 'leave.create', 'leave.edit', 'leave.cancel', 'leave.return',
                'reports.view', 'reports.export', 'reports.print',
                'audit.view',
            ],
        ],
        'operations' => [
            'name'        => 'Operations',
            'description' => 'Operational visibility and leave/return updates; read-only '
                . 'address book, but may ask for a driver master change. '
                . 'Cancelling leave needs leave.cancel granted separately.',
            'permissions' => [
                'dashboard.view', 'dashboard.drivers',
                'drivers.view',
                // May file a request; may not approve one and may not write
                // master data. The queue is visible so a requester can follow
                // their own submission.
                'drivers.edit', 'drivers.history.view',
                'leave.view', 'leave.create', 'leave.edit', 'leave.return',
                'reports.view', 'reports.export', 'reports.print',
                // leave.cancel is deliberately withheld - grant it from
                // Settings -> Roles when an operations user needs it.
            ],
        ],
        'viewer' => [
            'name'        => 'Viewer / Management',
            'description' => 'Read-only visibility for managers: can view and export '
                . 'reports, including how a driver record came to look like it does, '
                . 'but changes nothing.',
            'permissions' => [
                'dashboard.view', 'dashboard.drivers',
                'drivers.view',
                'drivers.history.view',
                'leave.view',
                'reports.view', 'reports.export', 'reports.print',
            ],
        ],
    ];
}

/** Every permission key in the catalogue. @return array<int, string> */
function permission_keys(): array
{
    $keys = [];

    foreach (permission_catalogue() as $group) {
        foreach (array_keys($group) as $key) {
            $keys[] = $key;
        }
    }

    return $keys;
}

/**
 * Make sure every key in the catalogue has a row in `permissions`, and that a
 * brand-new key is granted to the roles that ship asking for it.
 *
 * Why this exists: without the row, can() is false for EVERYONE - including
 * the Administrator, who has no `*` in the tables - so applying a patch that
 * adds a permission would 403 its pages until somebody remembered to run a
 * migration. It only ever acts on a key that is missing entirely; the moment
 * the row exists, an administrator's grant or revocation in Settings -> Roles
 * is never overwritten. Mirrors tools/migrate-rbac.php, scoped to new keys.
 *
 * Called once per request from user_permissions().
 */
function ensure_permission_catalogue(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    $known = [];
    foreach (Database::all('SELECT permission_key FROM permissions') as $row) {
        $known[(string)$row['permission_key']] = true;
    }

    $roleId = [];
    foreach (Database::all('SELECT id, slug FROM roles') as $row) {
        $roleId[(string)$row['slug']] = (int)$row['id'];
    }

    $definitions = role_definitions();

    foreach (permission_catalogue() as $group => $entries) {
        foreach ($entries as $key => $label) {
            if (isset($known[$key])) {
                continue;   // already on file: nothing here may override it
            }

            Database::run(
                'INSERT IGNORE INTO permissions (permission_key, label, group_name)
                 VALUES (?, ?, ?)',
                [(string)$key, (string)$label, (string)$group]
            );

            // Brand-new permission: seed the shipped grants so the feature is
            // reachable straight after an upgrade.
            foreach ($definitions as $slug => $definition) {
                $wanted = ($definition['permissions'] === ['*'])
                    || in_array((string)$key, $definition['permissions'], true);

                if ($wanted && isset($roleId[$slug])) {
                    Database::run(
                        'INSERT IGNORE INTO role_permissions (role_id, permission_id)
                         SELECT ?, id FROM permissions WHERE permission_key = ?',
                        [$roleId[$slug], (string)$key]
                    );
                }
            }
        }
    }
}

/**
 * Permissions held by the signed-in user: the union across every role they
 * have. Memoised per request and invalidated when an administrator edits a
 * role. Never reads users.role, so a second role can be added later with no
 * code change.
 *
 * @return array<int, string>
 */
function user_permissions(): array
{
    static $cache = null;
    static $cachedGeneration = -1;

    $generation = (int) ($GLOBALS['__perm_generation'] ?? 0);

    if ($cache !== null && $cachedGeneration === $generation) {
        return $cache;
    }

    $user = $_SESSION['user'] ?? null;

    if ($user === null || empty($user['id'])) {
        $cachedGeneration = $generation;

        return $cache = [];
    }

    try {
        ensure_permission_catalogue();

        $rows = Database::all(
            'SELECT DISTINCT p.permission_key
               FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p       ON p.id      = rp.permission_id
              WHERE ur.user_id = ?',
            [(int) $user['id']]
        );
    } catch (Throwable $ex) {
        // Not migrated yet: fail closed, never open.
        log_fatal('permission lookup failed: ' . $ex->getMessage());
        $cachedGeneration = $generation;

        return $cache = [];
    }

    $cachedGeneration = $generation;

    return $cache = array_values(array_map(
        static fn (array $row): string => (string) $row['permission_key'],
        $rows
    ));
}

/** Invalidate the memo so the next can() reads the database again. */
function forget_permissions(): void
{
    $GLOBALS['__perm_generation'] = (int) ($GLOBALS['__perm_generation'] ?? 0) + 1;
}

/**
 * True when the signed-in user holds the Administrator role (any of their
 * roles, not just the primary one). For management-only panels that should
 * stay hidden even from roles granted the page-level permission - e.g.
 * Forecast Data Quality on the capacity page. Fails closed: without a
 * session or on any lookup error the answer is false.
 */
function is_administrator(): bool
{
    static $admin = null;
    static $cachedGeneration = -1;

    $generation = (int) ($GLOBALS['__perm_generation'] ?? 0);

    if ($admin !== null && $cachedGeneration === $generation) {
        return $admin;
    }

    $user = $_SESSION['user'] ?? null;

    if ($user === null || empty($user['id'])) {
        $cachedGeneration = $generation;

        return $admin = false;
    }

    try {
        $holds = (int) Database::scalar(
            'SELECT COUNT(*)
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = ? AND r.slug = "admin"',
            [(int) $user['id']]
        ) > 0;
    } catch (Throwable $ex) {
        // Not migrated yet: fail closed, never open.
        log_fatal('administrator lookup failed: ' . $ex->getMessage());
        $holds = false;
    }

    $cachedGeneration = $generation;

    return $admin = $holds;
}

function can(string $permission): bool
{
    return in_array($permission, user_permissions(), true);
}

/** True when the user holds at least one of the listed permissions. */
function can_any(array $permissions): bool
{
    $held = user_permissions();

    foreach ($permissions as $permission) {
        if (in_array((string) $permission, $held, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Abort with 403 unless the permission is held. Accepts a single key or a
 * list, in which case any one of them is enough.
 *
 * @param string|array<int, string> $permission
 */
function require_permission($permission): void
{
    $granted = is_array($permission) ? can_any($permission) : can($permission);

    if ($granted) {
        return;
    }

    if (app_context() === 'api') {
        json_error('You do not have permission to do that.', 403);
    }

    View::error(403, 'You do not have permission to open this screen.');
}

/** Human-readable label for the signed-in user's primary role (display only). */
function current_role_label(): string
{
    static $label = null;

    if ($label !== null) {
        return $label;
    }

    $user = $_SESSION['user'] ?? null;

    if ($user === null || empty($user['id'])) {
        return $label = '';
    }

    try {
        $name = Database::scalar(
            'SELECT r.name FROM roles r JOIN users u ON u.role = r.slug WHERE u.id = ?',
            [(int) $user['id']]
        );
    } catch (Throwable $ex) {
        $name = null;
    }

    if ($name !== null && $name !== false && $name !== '') {
        return $label = (string) $name;
    }

    // Fall back to the slug so the menu is never blank.
    return $label = ucwords(str_replace(['_', '-'], ' ', (string) ($user['role'] ?? '')));
}

/**
 * Collapse a request path onto its route pattern: "/drivers/12/edit" and
 * "/drivers/99/edit" both become "/drivers/{id}/edit".
 */
function normalize_route_path(string $path): string
{
    $out = [];

    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment === '') {
            continue;
        }

        $out[] = ctype_digit($segment) ? '{id}' : $segment;
    }

    return '/' . implode('/', $out);
}

/**
 * The permission a route needs, or null when it needs none.
 *
 * @return array<int, string>|null any one of the listed keys satisfies it
 */
function permission_for_route(string $context, string $method, string $path): ?array
{
    static $map = null;

    if ($map === null) {
        $map = require __DIR__ . '/route-permissions.php';
    }

    $key   = strtoupper($method) . ' ' . normalize_route_path($path);
    $entry = $map[$context][$key] ?? null;

    if ($entry === null) {
        return null;
    }

    return is_array($entry) ? array_values($entry) : [$entry];
}

/** Enforce the map for a request. Absent entries are open by design. */
function require_route_permission(string $context, string $method, string $path): void
{
    $permission = permission_for_route($context, $method, $path);

    if ($permission !== null) {
        require_permission($permission);
    }
}

