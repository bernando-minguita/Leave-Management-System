<?php

declare(strict_types=1);

/**
 * API endpoint map for the JSON gateway (api.php).
 *
 * Returns [area => [action => [controller class, method]]].
 * The gateway verifies the CSRF token for every non-GET request before
 * dispatching, so these handlers never have to.
 *
 * @return array<string, array<string, array{0: class-string, 1: string}>>
 */
return [
    'drivers' => [
        // Type-ahead used by the New Leave / report driver pickers.
        'search'   => ['App\\Api\\DriversApi', 'search'],
        'detail'   => ['App\\Api\\DriversApi', 'detail'],
        // Quick KPI refresh (called after mark-returned).
        'deactivate' => ['App\\Api\\DriversApi', 'deactivate'],
        'sync-simledger' => ['App\\Api\\DriversApi', 'syncSimLedger'],
        'sync-simledger-all' => ['App\\Api\\DriversApi', 'syncSimLedgerAll'],
    ],

    'leave' => [
        'create'        => ['App\\Api\\LeaveApi', 'create'],
        'update'        => ['App\\Api\\LeaveApi', 'update'],
        'mark-returned' => ['App\\Api\\LeaveApi', 'markReturned'],
        'cancel'        => ['App\\Api\\LeaveApi', 'cancel'],
        'not-returned'  => ['App\\Api\\LeaveApi', 'notReturned'],
        // Administrator correction of a closed record (see LeaveApi::changeStatus).
        'change-status'  => ['App\\Api\\LeaveApi', 'changeStatus'],
        'view'          => ['App\\Api\\LeaveApi', 'view'],
        // FullCalendar feed: /api/leave/calendar?start=..&end=..
        'calendar'      => ['App\\Api\\LeaveApi', 'calendar'],
        'overlap-check' => ['App\\Api\\LeaveApi', 'overlapCheck'],
    // Approved applications a leave may be applied for (type-ahead source).
    'open-applications' => ['App\\Api\\LeaveApi', 'openApplications'],
    ],

    'dashboard' => [
        // Recompute the nine KPI cards without a full page reload.
        'kpis'  => ['App\\Api\\DashboardApi', 'kpis'],
        'panel' => ['App\\Api\\DashboardApi', 'panel'],
        // Row-click drill-down of the three summary tables.
        'summary-drivers' => ['App\\Api\\DashboardApi', 'summaryDrivers'],
        // The rows behind one KPI card, opened from the card's own "View".
        'kpi-drivers'     => ['App\\Api\\DashboardApi', 'kpiDrivers'],
    ],

    'settings' => [
        'save'      => ['App\\Api\\SettingsApi', 'save'],
        'lookup'    => ['App\\Api\\SettingsApi', 'lookup'],
    ],

    'reports' => [
        'rows' => ['App\\Api\\ReportsApi', 'rows'],
    ],

    'capacity' => [
        // Drivers on leave for one date - the capacity page's drill-down.
        'drivers' => ['App\\Api\\CapacityApi', 'drivers'],
    ],
];
