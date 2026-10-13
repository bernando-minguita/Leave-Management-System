<?php

declare(strict_types=1);

/**
 * Route -> permission map.
 *
 * The single place that decides which permission a URL needs. Each key is
 * "METHOD /normalised/path" where every numeric segment has been replaced by
 * {id}. A route that is absent needs no permission (login/logout and the
 * kind-scoped Settings endpoints, which are checked in their controller).
 *
 * Changing which ROLE holds a permission never touches this file - that lives
 * in the database under Settings -> Roles.
 *
 * @return array<string, array<string, string|array<int, string>>>
 */
return [
    'web' => [
        /* Dashboard ------------------------------------------------------- */
        'GET /'         => 'dashboard.view',
        'GET /dashboard' => 'dashboard.view',
        // Driver Dashboard: its own right, so it can be granted or revoked per
        // role from Settings -> Roles without touching the Leave Dashboard.
        'GET /driver-dashboard' => 'dashboard.drivers',

        /* Address book ---------------------------------------------------- */
        'GET /drivers'                     => 'drivers.view',
        'GET /drivers/export'              => 'drivers.export',
        'GET /drivers/create'              => 'drivers.create',
        'POST /drivers/create'             => 'drivers.create',
        'GET /drivers/import'              => 'drivers.import',
        'POST /drivers/import'             => 'drivers.import',
        'GET /drivers/{id}'                => 'drivers.view',
        'GET /drivers/{id}/leave-history'  => 'drivers.view',
        // Direct correction of master data. Deliberately NOT `drivers.edit`:
        // the route map is OR (any listed key satisfies it), so pairing them
        // would let revoking one grant leave the other holding the door.
        'GET /drivers/{id}/edit'           => 'drivers.master_correct',
        'POST /drivers/{id}/edit'          => 'drivers.master_correct',
        // Master-data history is a separate read: viewing how a record came to
        // look like it does is not the same right as editing it.
        'GET /drivers/{id}/history'        => 'drivers.history.view',
        'POST /drivers/{id}/deactivate'    => 'drivers.deactivate',
        'POST /drivers/{id}/activate'      => 'drivers.deactivate',
        'POST /drivers/{id}/delete'        => 'drivers.delete',
        'POST /drivers/bulk-delete'        => 'drivers.delete',

        /* Driver master-data change requests -------------------------------- */
        // `drivers.edit` is the REQUEST right (it used to be direct edit).
        // The queue and the detail view are shared by both audiences: a
        // requester follows their own submission, an approver reviews it.
        'GET /driver-changes'             => ['drivers.edit', 'drivers.change_request_approve'],
        'GET /driver-changes/new'         => 'drivers.edit',
        'POST /driver-changes'            => 'drivers.edit',
        'GET /driver-changes/{id}'        => ['drivers.edit', 'drivers.change_request_approve'],
        'POST /driver-changes/{id}/cancel' => 'drivers.edit',
        'POST /driver-changes/{id}/approve' => 'drivers.change_request_approve',
        'POST /driver-changes/{id}/reject'  => 'drivers.change_request_approve',
        'GET /driver-changes/export'      => ['drivers.edit', 'drivers.change_request_approve'],

        /* Leave & vacation ----------------------------------------------- */
        'GET /leave'            => 'leave.view',
        'GET /leave/new'        => 'leave.create',
        'POST /leave/new'       => 'leave.create',
        'GET /leave/current'    => 'leave.view',
        'GET /leave/records'    => 'leave.view',
        'GET /leave/calendar'   => 'leave.view',
        'GET /leave/returns'    => 'leave.view',
        'GET /leave/{id}'       => 'leave.view',
        'GET /leave/{id}/edit'  => 'leave.edit',
        'POST /leave/{id}/edit' => 'leave.edit',
        'POST /leave/{id}/cancel' => 'leave.cancel',
    'POST /leave/{id}/not-returned' => 'leave.return',
        'POST /leave/{id}/return' => 'leave.return',
        'POST /leave/{id}/delete' => 'leave.delete',
        'POST /leave/bulk-delete' => 'leave.delete',

        /* Leave applications (separate register) --------------------------- */
        'GET /leave-applications'           => 'leave.view',
        'GET /leave-applications/export'    => 'reports.export',
        'GET /leave-applications/new'       => 'leave.create',
        'POST /leave-applications/new'      => 'leave.create',
        'GET /leave-applications/{id}'      => 'leave.view',
        'GET /leave-applications/{id}/edit' => 'leave.edit',
        'POST /leave-applications/{id}/edit' => 'leave.edit',
        'POST /leave-applications/{id}/status' => 'leave.edit',
        'POST /leave-applications/{id}/delete' => 'leave.delete',

        /* Planning & capacity ----------------------------------------------- */
        // reports.capacity: a dedicated permission so this page can be held by
        // the Administrator role alone (granted in role_definitions(); seeded by permissions.php).
        'GET /capacity-planning' => 'reports.capacity',

        /* Reports --------------------------------------------------------- */
        'GET /reports'         => 'reports.view',
        'GET /reports/leave'   => 'reports.view',
        'GET /reports/return'  => 'reports.view',
        'GET /reports/branch'  => 'reports.view',
        'GET /reports/agency'  => 'reports.view',


        /* Settings - anything under Settings needs at least one permission  */
        'GET /settings' => [
            'settings.users', 'settings.roles', 'settings.permissions',
            'settings.branches', 'settings.agencies', 'settings.leave_types',
            'settings.system',
        ],
        'GET /settings/leave-types'         => 'settings.leave_types',
        'GET /settings/branches'            => 'settings.branches',
        'GET /settings/agencies'            => 'settings.agencies',
        // The System screen no longer carries the accounts tab - user
        // administration lives at /settings/users behind its own right.
        'GET /settings/system'              => 'settings.system',
        'GET /settings/users'               => 'settings.users',
        'GET /settings/roles'               => ['settings.roles', 'settings.permissions'],
        'GET /settings/permissions'         => 'settings.permissions',
        'GET /settings/audit'               => 'audit.view',
        'POST /settings/reset-demo'         => 'settings.system',
        'POST /settings/remove-sample-data' => 'settings.system',
        'POST /settings/bulk-delete'        => 'settings.system',
        'GET /settings/backup'              => 'settings.system',
        'POST /settings/restore'            => 'settings.system',
        // settings/save and settings/destroy dispatch on `kind`, so their
        // controller asks for the permission that kind actually needs.

        /* Import / export -------------------------------------------------- */
        'GET /import'  => 'drivers.import',
        'POST /import' => 'drivers.import',
        /* Own account (My Profile) ---------------------------------------- */
        // The self-service routes (profile, name, email, password, 2FA setup)
        // deliberately appear nowhere: every signed-in user may manage their
        // own account. Only the administrator actions are gated.
        'POST /profile/2fa/force' => 'settings.users',
        'POST /profile/2fa/reset' => 'settings.users',

    ],

    'api' => [
        'GET /dashboard/kpis'       => 'dashboard.view',
        'GET /dashboard/panel'      => 'dashboard.view',
        // Row-click drill-down of the summary tables - same right as the page.
        'GET /dashboard/summary-drivers' => 'dashboard.view',
    // Rows behind one KPI card: same right as the dashboard that draws it.
    'GET /dashboard/kpi-drivers'     => 'dashboard.view',
        'GET /drivers/search'       => 'drivers.view',
        'GET /drivers/detail'       => 'drivers.view',
        'POST /drivers/deactivate'  => 'drivers.deactivate',
        // Sync the driver's mobile from SIMLedger - writes master data, so it
        // carries the same right as direct master-data correction.
        'POST /drivers/sync-simledger' => 'drivers.master_correct',
        // Bulk version (roster diff) from the Drivers list - same write right.
        'POST /drivers/sync-simledger-all' => 'drivers.master_correct',
        'GET /leave/calendar'       => 'leave.view',
        'GET /leave/overlap-check'  => 'leave.view',
        // The apply-leave form's application picker: same right as creating
        // the leave it feeds.
        'GET /leave/open-applications' => 'leave.create',
        'GET /leave/view'           => 'leave.view',
        'POST /leave/create'        => 'leave.create',
        'POST /leave/update'        => 'leave.edit',
        'POST /leave/mark-returned' => 'leave.return',
        'POST /leave/cancel'        => 'leave.cancel',
        'POST /leave/not-returned'  => 'leave.return',
        // Administrator correction of a closed record (Not Returned / Cancelled
        // -> Returned / On leave / Planned). Deliberately keyed on
        // settings.system - the permission only administrators hold by
        // default - so the feature ships without a new permission row.
        'POST /leave/change-status' => 'settings.system',
        'GET /reports/rows'         => 'reports.view',
        // Day-by-day drill-down for a single date on the capacity page.
        'GET /capacity/drivers'     => 'reports.capacity',
        'GET /settings/lookup'      => [
            'settings.users', 'settings.roles', 'settings.permissions',
            'settings.branches', 'settings.agencies', 'settings.leave_types',
            'settings.system',
        ],
        // POST /settings/save dispatches on `kind` in the controller.
    ],
];
