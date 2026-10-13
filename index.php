<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\View;

/**
 * Front controller: every clean URL lands here.
 *   /lms/            -> Dashboard
 *   /lms/drivers      -> DriverController::index
 *   /lms/leave/new    -> LeaveController::create
 *   ... and so on
 */

$GLOBALS['app_context'] = 'web';

require __DIR__ . '/app/bootstrap.php';

$router = new Router();

/* --- Dashboard ---------------------------------------------------------- */
$router->add('GET', 'driver-dashboard', 'App\\Controllers\\DriverDashboardController@index');
$router->add('GET', '', 'App\\Controllers\DashboardController@index');
$router->add('GET', 'dashboard', 'App\\Controllers\DashboardController@index');

/* --- Authentication ----------------------------------------------------- */
$router->add('GET', 'login', 'App\\Controllers\AuthController@showLogin');
$router->add('POST', 'login', 'App\\Controllers\AuthController@login');
$router->add('ANY', 'logout', 'App\\Controllers\AuthController@logout');
$router->add('GET', 'login/2fa', 'App\\Controllers\\AuthController@twoFactorForm');
$router->add('POST', 'login/2fa', 'App\\Controllers\\AuthController@twoFactorVerify');

/* --- Own account (My Profile) ------------------------------------------- */
$router->add('GET', 'profile', 'App\\Controllers\\ProfileController@index');
$router->add('POST', 'profile/name', 'App\\Controllers\\ProfileController@updateName');
$router->add('POST', 'profile/password', 'App\\Controllers\\ProfileController@changePassword');
$router->add('POST', 'profile/email', 'App\\Controllers\\ProfileController@updateEmail');
$router->add('POST', 'profile/2fa/start', 'App\\Controllers\\ProfileController@twoFactorStart');
$router->add('POST', 'profile/2fa/enable', 'App\\Controllers\\ProfileController@twoFactorEnable');
$router->add('POST', 'profile/2fa/cancel', 'App\\Controllers\\ProfileController@twoFactorCancel');
$router->add('POST', 'profile/2fa/disable', 'App\\Controllers\\ProfileController@twoFactorDisable');
$router->add('POST', 'profile/2fa/recovery', 'App\\Controllers\\ProfileController@regenerateRecovery');
$router->add('POST', 'profile/2fa/force', 'App\\Controllers\\ProfileController@forceTwoFactor');
$router->add('POST', 'profile/2fa/reset', 'App\\Controllers\\ProfileController@resetTwoFactor');
$router->add('POST', 'profile/2fa/ack', 'App\\Controllers\\ProfileController@acknowledgeRecovery');


/* --- Address book (drivers) -------------------------------------------- */
$router->add('GET', 'drivers', 'App\\Controllers\DriverController@index');
$router->add('GET', 'drivers/export', 'App\\Controllers\DriverController@export');
$router->add('GET', 'drivers/create', 'App\\Controllers\DriverController@create');
$router->add('POST', 'drivers/create', 'App\\Controllers\DriverController@save');
$router->add('GET', 'drivers/import', 'App\\Controllers\DriverController@importForm');
$router->add('POST', 'drivers/import', 'App\\Controllers\DriverController@importRun');
$router->add('GET', 'drivers/{id}', 'App\\Controllers\DriverController@profile');
$router->add('GET', 'drivers/{id}/edit', 'App\\Controllers\DriverController@edit');
$router->add('POST', 'drivers/{id}/edit', 'App\\Controllers\DriverController@update');
$router->add('POST', 'drivers/{id}/deactivate', 'App\\Controllers\DriverController@deactivate');
$router->add('POST', 'drivers/{id}/activate', 'App\\Controllers\DriverController@activate');
$router->add('POST', 'drivers/{id}/delete', 'App\Controllers\DriverController@destroy');

$router->add('GET', 'drivers/{id}/leave-history', 'App\\Controllers\DriverController@leaveHistory');

/* --- Driver master-data change requests --------------------------------- */

// Master-data history for one driver. A different page from the leave history
// above, so it gets its own route and its own permission (drivers.history.view).
$router->add('GET', 'drivers/{id}/history', 'App\\Controllers\\DriverController@history');

// Literal segments (export, new) come BEFORE driver-changes/{id}: the router
// matches in registration order, so {id} would otherwise swallow "new" and
// treat it as a request id.
$router->add('GET', 'driver-changes', 'App\\Controllers\\DriverChangeController@index');
$router->add('GET', 'driver-changes/export', 'App\\Controllers\\DriverChangeController@export');
$router->add('GET', 'driver-changes/new', 'App\\Controllers\\DriverChangeController@create');
$router->add('POST', 'driver-changes', 'App\\Controllers\\DriverChangeController@store');
$router->add('GET', 'driver-changes/{id}', 'App\\Controllers\\DriverChangeController@view');
$router->add('POST', 'driver-changes/{id}/approve', 'App\\Controllers\\DriverChangeController@approve');
$router->add('POST', 'driver-changes/{id}/reject', 'App\\Controllers\\DriverChangeController@reject');
$router->add('POST', 'driver-changes/{id}/cancel', 'App\\Controllers\\DriverChangeController@cancel');

/* --- Leave & vacation --------------------------------------------------- */
$router->add('GET', 'leave', 'App\\Controllers\LeaveController@records');
$router->add('GET', 'leave/new', 'App\\Controllers\LeaveController@create');
$router->add('POST', 'leave/new', 'App\\Controllers\LeaveController@save');
$router->add('GET', 'leave/current', 'App\\Controllers\LeaveController@current');
$router->add('GET', 'leave/records', 'App\\Controllers\LeaveController@records');
$router->add('GET', 'leave/calendar', 'App\\Controllers\LeaveController@calendar');
$router->add('GET', 'leave/returns', 'App\\Controllers\LeaveController@returns');
// NOTE: the old "GET leave/export" route is gone. It pointed at
// LeaveController::export(), which is a private helper taking a required
// argument ($which), so the URL could only ever produce a 500. Nothing linked
// to it; the export is reached through ?export= on leave/current and
// leave/records instead.
$router->add('GET', 'leave/{id}', 'App\\Controllers\LeaveController@view');
$router->add('GET', 'leave/{id}/edit', 'App\\Controllers\LeaveController@edit');
$router->add('POST', 'leave/{id}/edit', 'App\\Controllers\LeaveController@update');
$router->add('POST', 'leave/{id}/cancel', 'App\\Controllers\LeaveController@cancel');
$router->add('POST', 'leave/{id}/return', 'App\\Controllers\LeaveController@markReturned');
$router->add('POST', 'leave/{id}/not-returned', 'App\Controllers\LeaveController@notReturned');

$router->add('POST', 'leave/bulk-delete', 'App\\Controllers\\LeaveController@bulkDelete');
$router->add('POST', 'drivers/bulk-delete', 'App\\Controllers\\DriverController@bulkDelete');

$router->add('POST', 'leave/{id}/delete', 'App\\Controllers\\LeaveController@destroy');

/* --- Leave applications (separate register) ------------------------------ *
 * Deliberately its own namespace: nothing here feeds the dashboard, the
 * calendar or the expected-returns panels.
 * -------------------------------------------------------------------------- */
$router->add('GET', 'leave-applications', 'App\\Controllers\\LeaveApplicationController@index');
$router->add('GET', 'leave-applications/export', 'App\\Controllers\\LeaveApplicationController@export');
$router->add('GET', 'leave-applications/new', 'App\\Controllers\\LeaveApplicationController@create');
$router->add('POST', 'leave-applications/new', 'App\\Controllers\\LeaveApplicationController@save');
$router->add('GET', 'leave-applications/{id}/edit', 'App\\Controllers\\LeaveApplicationController@edit');
$router->add('POST', 'leave-applications/{id}/edit', 'App\\Controllers\\LeaveApplicationController@update');
$router->add('POST', 'leave-applications/{id}/status', 'App\\Controllers\\LeaveApplicationController@status');
$router->add('POST', 'leave-applications/{id}/delete', 'App\\Controllers\\LeaveApplicationController@destroy');
// Read-only detail: the target of the View action in the list. Declared after
// every literal segment (export, new) so those keep winning the match.
$router->add('GET', 'leave-applications/{id}', 'App\\Controllers\\LeaveApplicationController@view');

/* --- Reports ------------------------------------------------------------ */
$router->add('GET', 'reports', 'App\\Controllers\ReportController@index');
$router->add('GET', 'reports/leave', 'App\\Controllers\ReportController@leave');
$router->add('GET', 'reports/return', 'App\\Controllers\ReportController@returnReport');
$router->add('GET', 'reports/branch', 'App\\Controllers\ReportController@branch');
$router->add('GET', 'reports/agency', 'App\\Controllers\ReportController@agency');


/* --- Planning & capacity -------------------------------------------------- */
// Day-by-day driver capacity forecast + monthly summary. Gated on
// reports.view so it ships without a new permission row (RBAC unchanged).
$router->add('GET', 'capacity-planning', 'App\\Controllers\\CapacityController@index');

/* --- Settings ----------------------------------------------------------- */
$router->add('GET', 'settings', 'App\\Controllers\SettingsController@index');
$router->add('GET', 'settings/leave-types', 'App\\Controllers\SettingsController@leaveTypes');
$router->add('GET', 'settings/branches', 'App\\Controllers\SettingsController@branches');
$router->add('GET', 'settings/agencies', 'App\\Controllers\SettingsController@agencies');
$router->add('GET', 'settings/system', 'App\\Controllers\SettingsController@system');
// User accounts have their own screen so the sidebar can carry a "Users"
// entry gated on settings.users alone (formerly the "User Accounts" tab
// on System Settings).
$router->add('GET', 'settings/users', 'App\\Controllers\SettingsController@users');
$router->add('GET', 'settings/audit', 'App\\Controllers\SettingsController@audit');
$router->add('POST', 'settings/save', 'App\\Controllers\SettingsController@save');
$router->add('POST', 'settings/reset-demo', 'App\Controllers\SettingsController@resetDemo');
$router->add('POST', 'settings/remove-sample-data', 'App\Controllers\SettingsController@removeSampleData');
$router->add('POST', 'settings/destroy', 'App\Controllers\SettingsController@destroy');




$router->add('POST', 'settings/bulk-delete', 'App\\Controllers\\SettingsController@bulkDelete');
$router->add('GET', 'settings/backup', 'App\\Controllers\\SettingsController@backup');
$router->add('POST', 'settings/restore', 'App\\Controllers\\SettingsController@restore');

$router->add('GET', 'settings/roles', 'App\\Controllers\\SettingsController@roles');
$router->add('GET', 'settings/permissions', 'App\\Controllers\\SettingsController@permissions');

/* --- Import / export ---------------------------------------------------- */
$router->add('GET', 'import', 'App\Controllers\DriverController@importForm');
$router->add('POST', 'import', 'App\Controllers\DriverController@importRun');


$path   = request_path();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Login, the two-factor challenge and logout are the only entry points that
// work without a session. The challenge has to be reachable before sign-in
// completes - that is the entire point of it - so it joins the exceptions.
// Everything else needs a signed-in user first, then the permission its route
// asks for (app/middleware/route-permissions.php).
if (!in_array($path, ['login', 'login/2fa', 'logout'], true)) {
    require_login();
    require_route_permission('web', $method, $path);
}

if (!$router->dispatch($method, $path)) {
    View::error(404);
}
