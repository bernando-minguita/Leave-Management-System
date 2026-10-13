<?php

declare(strict_types=1);

/**
 * JSON API gateway: /lms/api/<area>/<action>
 *
 * Every mutating call verifies the CSRF token. Responses are always
 * {ok: bool, message: string, ...}.
 */

$GLOBALS['app_context'] = 'api';

require __DIR__ . '/app/bootstrap.php';

require_login();

$path   = trim((string)($_GET['p'] ?? request_path()), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Same map as the web routes, so an endpoint cannot drift out of sync.
require_route_permission('api', $method, $path);

$parts = $path === '' ? [] : explode('/', $path);
$area  = $parts[0] ?? '';
$action = $parts[1] ?? 'index';

$methods = require __DIR__ . '/app/api/routes.php';

$key = strtolower($action);
if (!isset($methods[$area][$key])) {
    json_error('Unknown API endpoint.', 404, ['endpoint' => $path]);
}

$mutating = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET';
if ($mutating) {
    csrf_check();
}

[$class, $method] = $methods[$area][$key];

if (!class_exists($class) || !method_exists($class, $method)) {
    json_error('This endpoint is not available.', 500);
}

(new $class())->{$method}();
