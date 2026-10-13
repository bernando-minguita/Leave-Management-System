<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

/* ===========================================================================
 * Error handling
 *  - technical detail is written to storage/logs/php-error.log
 *  - the user only ever sees a friendly message (see View::error)
 * ======================================================================== */
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/php-error.log');
ini_set('default_charset', 'UTF-8');

date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/helpers/functions.php';
require_once __DIR__ . '/helpers/audit.php';
require_once __DIR__ . '/helpers/security.php';
require_once __DIR__ . '/helpers/simledger.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Status.php';
require_once __DIR__ . '/core/Validator.php';
require_once __DIR__ . '/core/View.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/middleware/permissions.php';

foreach (glob(__DIR__ . '/models/*.php') ?: [] as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/controllers/*.php') ?: [] as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/api/*.php') ?: [] as $file) {
    // routes.php is a config file that *returns* an array - skip it here.
    if (basename($file) === 'routes.php') {
        continue;
    }
    require_once $file;
}

// View templates are plain (non-namespaced) files, so they call
// View::partial(...) unqualified. Alias the namespaced class into the global
// namespace so those calls resolve without a `use` line in every template.
if (!class_exists('View', false)) {
    class_alias(\App\Core\View::class, 'View');
}

/* ===========================================================================
 * Session - hardened cookie, started once and only once
 * ======================================================================== */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('fleetsess');

    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ===========================================================================
 * Response security headers
 *
 * Sent from bootstrap, before any view renders, so every page, error page,
 * API payload and export gets them - there is no second place to remember.
 *
 * The Content-Security-Policy is deliberately the safe subset: frame-ancestors,
 * object-src and base-uri have no interaction with inline script at all. A
 * script-src policy is NOT emitted, because the views carry ~18 inline
 * <script> blocks (data payloads, modal wiring) and login depends on
 * reCAPTCHA's inline bootstrap - shipping one untested would risk locking
 * everyone out of the app. It is the right next step, but it needs browser
 * verification rather than a header swap.
 * ======================================================================== */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    // MIME sniffing lets an uploaded file be replayed as an executable page.
    header('X-Content-Type-Options: nosniff');

    // Nothing here is framed, and framing is how clickjacking works.
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'; object-src 'none'; base-uri 'self'");

    // Pages carry full driver names, leave dates and branch data; a bare
    // path is all a destination ever needs to see.
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // This tool has no use for any of them, so none are granted.
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()');

    // PHP only ever emits HTML, JSON and exports containing driver names,
    // leave dates and branch data - and .htaccess serves real files (the
    // CSS, JS and images) straight through, never touching this code. So
    // no PHP response benefits from being cached, and applying it here
    // means no route can be forgotten: an authenticated session is never
    // replayable from a shared machine's back button after sign-out.
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/* ===========================================================================
 * Fatal error / exception handling
 * ======================================================================== */
function log_fatal(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . ' | '
        . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' '
        . ($_SERVER['REQUEST_URI'] ?? '') . PHP_EOL;
    @file_put_contents(LOG_PATH . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND);
}

set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    log_fatal("[$no] $str in $file:$line");
    if (APP_DEBUG) {
        return false;
    }
    return true;
});

set_exception_handler(static function (Throwable $ex): void {
    log_fatal('EXCEPTION ' . get_class($ex) . ': ' . $ex->getMessage()
        . ' @ ' . $ex->getFile() . ':' . $ex->getLine());

    if (app_context() === 'api') {
        json_out(['ok' => false, 'message' => 'The request could not be completed. Please try again.'], 500);
    }

    http_response_code(500);
    \App\Core\View::error(500, 'Something went wrong while processing your request. Please try again.');
    exit;
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    log_fatal('FATAL [' . $error['type'] . '] ' . $error['message']
        . ' @ ' . $error['file'] . ':' . $error['line']);
});

if (!is_dir(LOG_PATH)) {
    @mkdir(LOG_PATH, 0775, true);
}
if (!is_dir(CACHE_PATH)) {
    @mkdir(CACHE_PATH, 0775, true);
}
if (!is_dir(EXPORT_PATH)) {
    @mkdir(EXPORT_PATH, 0775, true);
}
