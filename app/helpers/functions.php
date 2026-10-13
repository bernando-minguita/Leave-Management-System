<?php
declare(strict_types=1);

/* ===========================================================================
 * Output / escaping / URLs
 * ======================================================================== */

/** Escape a value for safe HTML output. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Alias used inside views. */
function h($value): string
{
    return e($value);
}

/** Application base path, e.g. "/lms" ("" when installed at the domain root). */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir    = rtrim(dirname($script), '/');

    $base = ($dir === '' || $dir === '/' || $dir === '\\') ? '' : rtrim($dir, '/');

    return $base;
}

/** Build an application URL: url('drivers') -> /lms/drivers */
function url(string $path = ''): string
{
    if ($path === '') {
        return base_path() === '' ? '/' : base_path() . '/';
    }
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    return base_path() . '/' . ltrim($path, '/');
}

/** The requested route path without the base folder: "leave/new" */
function request_path(): string
{
    $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)parse_url($uri, PHP_URL_PATH);
    $base = base_path();

    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }

    $path = trim($path, '/');

    if ($path === '') {
        $path = trim((string)($_GET['r'] ?? ''), '/');
    }

    $path = preg_replace('#\.php$#', '', $path) ?? '';

    return trim($path, '/');
}

function redirect(string $path): void
{
    if (!headers_sent()) {
        header('Location: ' . url($path));
    } else {
        echo '<script>location.href=' . json_encode(url($path)) . ';</script>';
    }
    exit;
}

/**
 * The only shape a post-login redirect target may take.
 *
 * `?to=` and the hidden `redirect` field come straight off the request, and
 * this is the single gate between that value and a `Location:` header, so
 * anything that could name another origin is rejected:
 *
 *   - a scheme prefix     https://evil.com, javascript:alert(1), data:…
 *   - protocol-relative   //evil.com, and the `\\` and `/\` spellings a
 *                         browser also reads as an authority
 *   - a leading slash     never produced by the app, and url() would
 *                         re-prefix it with base_path() anyway
 *   - control characters  `java\tscript:` and `" https://"` both survive a
 *                         naive check yet still name another origin
 *
 * What survives is a bare in-app path - `dashboard`, `leave/new?x=1` - which
 * is exactly what request_path() and the login form ever produce. Everything
 * else degrades to $fallback, so a hostile value costs the attacker a plain
 * sign-in.
 */
function safe_redirect_target(string $target, string $fallback = 'dashboard'): string
{
    $target = trim($target);

    if ($target === '' || $target === 'login') {
        return $fallback;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
        return $fallback;
    }

    // `/…`, `//host`, `\host`, `/\host` - reject the lot rather than try to
    // tell a harmless absolute path from a disguised authority.
    if (preg_match('#^(?:/|\\\\)#', $target) === 1) {
        return $fallback;
    }

    // Anything with a scheme prefix, whether or not it carries `//`.
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target) === 1) {
        return $fallback;
    }

    return $target;
}

function is_post(): bool
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

/** 'web' for the front controller, 'api' for the JSON gateway. */
function app_context(): string
{
    return $GLOBALS['app_context'] ?? 'web';
}

/* ===========================================================================
 * JSON helpers (used by the /api/ gateway)
 * ======================================================================== */

/** Send a JSON payload and stop. */
function json_out($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_out(array_merge(['ok' => false, 'message' => $message], $extra), $status);
}

/** Decode a JSON request body, falling back to $_POST. */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}

/* ===========================================================================
 * Flash messages
 * ======================================================================== */

function flash_set(string $type, string $message, array $errors = []): void
{
    $_SESSION['flash'] = [
        'type'    => $type,   // success | danger | warning | info
        'message' => $message,
        'errors'  => $errors,
    ];
}

function flash_take(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

/* ===========================================================================
 * CSRF protection
 * ======================================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Verify the token on a mutating request; abort with a friendly message. */
function csrf_check(): void
{
    $token = (string)($_POST['_csrf'] ?? '');
    if ($token === '') {
        $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }

    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        log_fatal('csrf rejected (token mismatch or missing)');

        if (app_context() === 'api') {
            json_error('Your session has expired. Please refresh the page and try again.', 403);
        }

        // NOTE: 403, not the non-standard 419. Apache's strict protocol
        // checking discards unknown status codes, which surfaced as an empty
        // HTTP 500 in the browser instead of a helpful message.
        http_response_code(403);
        \App\Core\View::error(403, 'Your session has expired. Please refresh the page and try again.');
        exit;
    }
}

/* ===========================================================================
 * Dates  (every value is handled in the application time zone)
 * ======================================================================== */

/** Today in Asia/Riyadh as Y-m-d. */
function today(): string
{
    return date('Y-m-d');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * User-facing date pattern from Settings -> "Display date format".
 *
 * Returns a PHP date() format string (e.g. "d M Y", "d/M/Y", "Y-m-d").
 * Falls back to 'd M Y' when the setting is missing, empty, longer than is
 * sensible, or contains anything other than format characters and common
 * separators - so a bad value can never break rendering.
 *
 * Cached for the request: Lookup::settings() is also a per-request static.
 */
function date_format_pattern(): string
{
    static $pattern = null;

    if ($pattern !== null) {
        return $pattern;
    }

    $raw = '';

    try {
        $raw = trim(\App\Models\Lookup::setting('date_format', ''));
    } catch (\Throwable $ex) {
        $raw = '';
    }

    // Format characters plus the separators people actually type: / - : , . and space.
    $ok = $raw !== ''
        && strlen($raw) <= 40
        && preg_match('/^[a-zA-Z0-9 \/\-\:\,\._]+$/D', $raw) === 1;

    $pattern = $ok ? php_date_format($raw) : 'd M Y';

    return $pattern;
}

/**
 * Translate a user-facing date pattern into PHP's single-letter format codes.
 *
 * PHP reads each character on its own, so the setting "dd/MM/YY" parses as
 * d,d,/,M,M,/,Y,Y and prints "1313/JunJun/20262026". A run of two or more
 * identical letters is Excel/Java-style shorthand, not a PHP token, so those
 * runs are mapped here. Single letters are passed through untouched, which is
 * what keeps the PHP-native default ("d M Y") rendering as "13 Jun 2026".
 *
 * "MM"/"mm" mean MONTH, except directly after an hour run ("HH:mm") where
 * they mean minutes - the usual Excel rule.
 */
function php_date_format(string $pattern): string
{
    preg_match_all('/([a-zA-Z])\1*|([^a-zA-Z]+)/', $pattern, $tokens, PREG_SET_ORDER);

    $out = '';
    $afterHour = false;

    foreach ($tokens as $tok) {
        // A run of non-letters is a separator (or literal text) - carry it
        // over WITHOUT clearing $afterHour: "HH:mm" has a ":" between them and
        // the mm still has to mean minutes, not month.
        if (($tok[2] ?? '') !== '') {
            $out .= $tok[2];
            continue;
        }

        $ch     = $tok[1];
        $len    = strlen($tok[0]);
        $isHour = $ch === 'H' || $ch === 'h';

        if ($len < 2) {
            // Single letters are already valid PHP format characters.
            $out .= $tok[0];
            $afterHour = $isHour;
            continue;
        }

        if (($ch === 'm' || $ch === 'M') && $afterHour) {
            $out .= 'i';                                   // HH:mm -> minutes
        } elseif ($ch === 'm' || $ch === 'M') {
            $out .= $len >= 4 ? 'F' : ($len === 3 ? 'M' : 'm');   // month
        } elseif ($ch === 'y' || $ch === 'Y') {
            $out .= $len >= 4 ? 'Y' : 'y';                 // year
        } elseif ($ch === 'd' || $ch === 'D') {
            $out .= 'd';                                   // day
        } elseif ($isHour) {
            $out .= 'H';
        } elseif ($ch === 'i' || $ch === 'I') {
            $out .= 'i';
        } elseif ($ch === 's' || $ch === 'S') {
            $out .= 's';
        } else {
            $out .= $tok[0];
        }

        $afterHour = $isHour;
    }

    return $out;
}

/**
 * Format a date for display.
 *
 * Pass $format to force a specific pattern - the compact 'd M' chips on the
 * dashboard and Expected Returns deliberately do this. Omit it to honour the
 * configured "Display date format".
 */
function fmt_date($date, ?string $format = null): string
{
    if ($date === null || $date === '') {
        return '—';
    }

    $ts = strtotime((string)$date);

    if ($ts === false) {
        return '—';
    }

    return date($format ?: date_format_pattern(), $ts);
}

function fmt_date_time($datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }

    $ts = strtotime((string)$datetime);

    if ($ts === false) {
        return '—';
    }

    return date(date_format_pattern() . ', H:i', $ts);
}

/** Whole days from $from to $to (positive when $to is later). */
function days_between(string $from, string $to): int
{
    $a = strtotime($from);
    $b = strtotime($to);

    if ($a === false || $b === false) {
        return 0;
    }

    return (int)floor(($b - $a) / 86400);
}

/* ===========================================================================
 * Percentage display
 * ======================================================================== */

/**
 * "5.75%" / "5.7%" / "45%" - the one place a percentage becomes text.
 *
 * Percentages are COMPUTED at two decimals (round(..., 2)), so this only
 * decides how they READ: keep two decimals where they carry information and
 * trim the trailing zeros - 5.75% stays 5.75%, while 5.70% reads 5.7% (round 6
 * requirement). Whole numbers stay clean ("45%", not "45.00%").
 *
 * Used by the dashboard cards, the capacity cards, the day-by-day and monthly
 * tables and the summary report, so the same figure never reads two ways.
 *
 * @param int|float|string $value already rounded, but rounded again here so a
 *                                 caller cannot leak a stray third decimal
 */
function pct_label($value): string
{
    $text = rtrim(rtrim(number_format(round((float)$value, 2), 2, '.', ''), '0'), '.');

    // number_format(-0.00) is "-0.00" -> "-0"; a percentage of zero is "0".
    if ($text === '' || $text === '-0') {
        $text = '0';
    }

    return $text . '%';
}

/**
 * "14 days" / "—" for the Duration columns and the Duration (info only) field.
 *
 * Purely the span between the two dates a row carries - never a balance or an
 * entitlement (this system does not track leave balances, and a leave type is
 * a classification, not a quota). A missing date on either end leaves the span
 * unknown, which reads as an em dash rather than a misleading zero.
 *
 * @param string|null $from anchor date (leave start on a record, last working
 *                          date on an application)
 * @param string|null $to   expected return
 */
function app_duration(?string $from, ?string $to): string
{
    if ($from === null || $from === '' || $to === null || $to === '') {
        return '—';
    }

    $days = days_between($from, $to);

    return $days >= 0 ? $days . ' days' : '—';
}

function date_add_days(string $date, int $days): string
{
    $ts = strtotime($date);

    return $ts === false ? $date : date('Y-m-d', $ts + ($days * 86400));
}


/* ===========================================================================
 * HTML option / query-string helpers
 * ======================================================================== */

/**
 * Normalise a filter value that may be a comma-separated multi-select list
 * ("1,3") into a de-duplicated list of trimmed, non-empty strings.
 *
 * Accepts an array as well, so it keeps working if a form ever posts `name[]`.
 * A plain single value ("3") returns ["3"] - unchanged behaviour.
 *
 * @return array<int, string>
 */
// NOTE: not "filter_list" - that name is already taken by PHP itself, which
// makes the file fatal with "Cannot redeclare filter_list()".
function filter_values($value): array
{
    if (is_array($value)) {
        $value = implode(',', $value);
    }

    $out = [];

    foreach (explode(',', (string)$value) as $item) {
        $item = trim($item);

        if ($item !== '') {
            $out[$item] = $item;
        }
    }

    return array_values($out);
}

/**
 * Positive integer ids out of a multi-select filter value ("1,3" -> [1, 3]).
 *
 * Anything non-numeric is dropped, so a hand-edited query string can never
 * reach SQL.
 *
 * @return array<int, int>
 */
function id_filter($value): array
{
    $ids = [];

    foreach (filter_values($value) as $item) {
        if (ctype_digit($item) && (int)$item > 0) {
            $ids[] = (int)$item;
        }
    }

    return $ids;
}

/**
 * Build the "(?,?,?)" placeholder fragment for an IN clause.
 *
 * @param array<int, string> $values
 */
function sql_in(array $values): string
{
    return '(' . implode(',', array_fill(0, count($values), '?')) . ')';
}

/**
 * Date-range predicate for one or more whitelisted columns.
 *
 * The From/To filters are now checkbox multi-selects, so "Leave start" and
 * "Expected return" can both be ticked. The row matches when ANY of the
 * chosen columns falls inside the range - written as one BETWEEN per column
 * OR'd together, because `f1 >= from AND f2 <= to` would happily match a row
 * whose two columns sit on opposite sides of the range.
 *
 * With a single column this reduces to the original `col >= ?` / `col <= ?`.
 * Every $column must already come from a whitelist - none of it is user input.
 *
 * @param array<int, string> $columns
 * @return array{0: string, 1: array<int, mixed>} SQL fragment ('' when inert) + params
 */
function date_range_sql(array $columns, string $from, string $to): array
{
    $columns = array_values(array_unique(array_filter($columns, static fn ($c): bool => $c !== '')));

    if ($columns === [] || ($from === '' && $to === '')) {
        return ['', []];
    }

    $parts  = [];
    $params = [];

    foreach ($columns as $column) {
        if ($from !== '' && $to !== '') {
            $parts[] = "($column >= ? AND $column <= ?)";
            $params[] = $from;
            $params[] = $to;
        } elseif ($from !== '') {
            $parts[] = "($column >= ?)";
            $params[] = $from;
        } else {
            $parts[] = "($column <= ?)";
            $params[] = $to;
        }
    }

    return [' AND (' . implode(' OR ', $parts) . ')', $params];
}

/** Build an <option> list from [value => label]. */
function options(array $pairs, $selected = '', string $placeholder = ''): string
{
    // Multi-select filters send "1,3", so treat every part as selected.
    $selectedList = filter_values($selected);

    $html = '';

    if ($placeholder !== '') {
        $html .= '<option value="">' . e($placeholder) . '</option>';
    }

    foreach ($pairs as $value => $label) {
        $isSelected = in_array((string)$value, $selectedList, true);

        $html .= '<option value="' . e((string)$value) . '"'
            . ($isSelected ? ' selected' : '') . '>'
            . e((string)$label) . '</option>';
    }

    return $html;
}

/** Build a query string from the current GET parameters plus overrides. */
function query_string(array $overrides = [], array $drop = []): string
{
    $params = array_merge($_GET, $overrides);

    foreach ($drop as $key) {
        unset($params[$key]);
    }

    return '?' . http_build_query($params);
}

/* ===========================================================================
 * Pagination / sorting / search
 * ======================================================================== */

function per_page(): int
{
    $allowed = [10, 25, 50, 100, 250];
    $value   = (int)($_GET['per_page'] ?? DEFAULT_PER_PAGE);

    return in_array($value, $allowed, true) ? $value : DEFAULT_PER_PAGE;
}

function page_no(): int
{
    $page = (int)($_GET['page'] ?? 1);

    return $page > 0 ? $page : 1;
}

function offset(): int
{
    return (page_no() - 1) * per_page();
}

/**
 * Bootstrap 5 pager.
 *
 * @param array<string, mixed> $query current filters (page/per_page dropped)
 */
function pagination(int $total, int $perPage, int $page, array $query = []): string
{
    $perPage = max(1, $perPage);
    $pages   = max(1, (int)ceil($total / $perPage));
    $page    = min(max(1, $page), $pages);
    $first   = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $last    = min($total, $page * $perPage);

    foreach (['page', 'per_page', 'export', '_csrf'] as $drop) {
        unset($query[$drop]);
    }

    $link = static function (int $target) use ($query, $perPage): string {
        $q = $query;
        $q['page']     = $target;
        $q['per_page'] = $perPage;

        return '?' . http_build_query($q);
    };

    // The pager is marked up so assets/js/table.js can take it over: sorting,
    // paging and the rows-per-page select then happen in place instead of
    // reloading the page and throwing the reader back to the top. Every
    // affordance still works without JavaScript - the links are real URLs and
    // the select falls back to submitting its form.
    $html  = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 pager" data-dd-pager>';
    $html .= '<div class="text-body-secondary small" data-dd-showing>Showing <strong>' . number_format($first)
        . '</strong>&ndash;<strong>' . number_format($last) . '</strong> of <strong>'
        . number_format($total) . '</strong></div>';

    if ($pages > 1) {
        $window = [];
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
                $window[] = $i;
            }
        }

        $html .= '<nav aria-label="Table pagination" data-dd-nav><ul class="pagination pagination-sm mb-0">';

        $html .= $page > 1
            ? '<li class="page-item"><a class="page-link" href="' . e($link($page - 1)) . '">&lsaquo;</a></li>'
            : '<li class="page-item disabled"><span class="page-link">&lsaquo;</span></li>';

        $previous = null;
        foreach ($window as $number) {
            if ($previous !== null && $number - $previous > 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }

            $html .= $number === $page
                ? '<li class="page-item active"><span class="page-link">' . $number . '</span></li>'
                : '<li class="page-item"><a class="page-link" href="' . e($link($number)) . '">' . $number . '</a></li>';

            $previous = $number;
        }

        $html .= $page < $pages
            ? '<li class="page-item"><a class="page-link" href="' . e($link($page + 1)) . '">&rsaquo;</a></li>'
            : '<li class="page-item disabled"><span class="page-link">&rsaquo;</span></li>';

        $html .= '</ul></nav>';
    }

    $html .= '<div class="d-flex align-items-center gap-2">
        <label class="small text-body-secondary mb-0" for="perPage">Rows</label>
        <select class="form-select form-select-sm w-auto" id="perPage" name="per_page" data-dd-size '
        // table.js defines ddTableSize(); without it the select falls back to
        // submitting the form, so a browser with no script still changes size.
        . 'onchange="window.ddTableSize ? window.ddTableSize(this) : this.form.submit();">';

    foreach ([10, 25, 50, 100, 250] as $size) {
        $html .= '<option value="' . $size . '"' . ($size === $perPage ? ' selected' : '') . '>' . $size . '</option>';
    }

    $html .= '</select></div></div>';

    return $html;
}

/**
 * Current sort state restricted to a whitelist.
 *
 * @param array<string, string> $allowed column => SQL expression
 * @return array{0:string,1:string} [key, direction]
 */
function sort_state(array $allowed, string $defaultKey, string $defaultDir = 'asc'): array
{
    $key = (string)($_GET['sort'] ?? $defaultKey);
    $dir = strtolower((string)($_GET['dir'] ?? $defaultDir)) === 'desc' ? 'desc' : 'asc';

    if (!array_key_exists($key, $allowed)) {
        $key = $defaultKey;
        $dir = $defaultDir === 'desc' ? 'desc' : 'asc';
    }

    return [$key, $dir];
}

/**
 * Merge the current ?sort=&dir= into a query array.
 *
 * Sorting lives in the URL, but the links that are built by hand - status
 * chips, export buttons - are assembled from $filters alone, which would hand
 * the reader a page (or a file) ordered differently from the one on screen.
 * Anything that already carries the whole query string needs no help; this is
 * for the ones that rebuild it from scratch.
 *
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function sort_params(array $query): array
{
    foreach (['sort', 'dir'] as $key) {
        $value = trim((string)($_GET[$key] ?? ''));

        if ($value !== '') {
            $query[$key] = $value;
        }
    }

    return $query;
}

/**
 * Hidden inputs that keep the sort when a GET filter form is submitted.
 *
 * A GET form only sends the fields it contains, so Apply used to hand back a
 * query string with ?sort=&dir= stripped out: sort_state() fell back to the
 * default, data-dd-initial re-seeded assets/js/table.js with it, and the
 * column order the reader had chosen was gone the moment they filtered.
 *
 * Read straight from $_GET for the same reason per_page() and page_no() do -
 * the filter forms are shared partials, and there is nothing to emit on a
 * screen that was never sorted.
 */
function hold_sort(): string
{
    $key = trim((string)($_GET['sort'] ?? ''));

    if ($key === '') {
        return '';
    }

    $html = '<input type="hidden" name="sort" value="' . e($key) . '">';

    // dir is only sent when the reader actually had one: a controller whose
    // own default is desc must not have it flipped to asc by the next filter.
    $dir = strtolower(trim((string)($_GET['dir'] ?? '')));

    if ($dir === 'asc' || $dir === 'desc') {
        $html .= '<input type="hidden" name="dir" value="' . $dir . '">';
    }

    return $html;
}

/** Render a sortable <th>. */
function sort_header(
    string $key,
    string $label,
    array $sort,
    array $query = [],
    string $style = ''
): string {
    [$current, $dir] = $sort;
    $active  = $current === $key;
    $nextDir = $active && $dir === 'asc' ? 'desc' : 'asc';

    $params         = $query;
    $params['sort'] = $key;
    $params['dir']  = $nextDir;
    unset($params['page'], $params['export']);

    $icon = $active
        ? ($dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down')
        : 'bi-arrow-down-up opacity-50';

    // $style is how a column ships HIDDEN BY DEFAULT while still being
    // sortable. table-prefs.js seeds the reader's stored preference from the
    // header's INLINE display on a first visit, so the attribute has to sit on
    // this <th> - a plain <th> with a style could not have carried a sort link.
    return '<th class="sortable' . ($active ? ' is-sorted' : '') . '" data-dd-sort="' . e($key) . '"'
        . ' aria-sort="' . ($active ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none') . '"'
        . ($style !== '' ? ' style="' . e($style) . '"' : '') . '>'
        . '<a class="link-dark text-decoration-none" href="?' . e(http_build_query($params)) . '">'
        . e($label) . ' <i class="bi ' . $icon . ' small"></i></a></th>';
}

/**
 * A sortable <th> with no server-side fallback.
 *
 * sort_header() is for lists the controller also sorts from ?sort=, so its
 * anchor is a real link. A list that ships every row at once and lets
 * assets/js/table.js reorder them in the browser has no such URL to link to -
 * a dead anchor would be worse than none - so this renders the same markup
 * minus the <a>. The engine still wires tabindex, Enter / Space and the
 * aria-sort state, and updateHeaders() finds the icon inside.
 *
 * @param string $key   the column the engine sorts on (cell position is read
 *                      off this same <th>, so it must sit on the sorted cell)
 * @param string $label header text
 */
function sort_header_client(string $key, string $label): string
{
    return '<th class="sortable" data-dd-sort="' . e($key) . '" aria-sort="none">'
        . e($label) . ' <i class="bi bi-arrow-down-up small opacity-50"></i></th>';
}

/**
 * How many rows a client-paged table is sent in one go.
 *
 * Sorting, paging and the rows-per-page select are done by assets/js/table.js
 * in the browser, so the server ships the whole filtered set once instead of
 * one page at a time. 5000 is far beyond anything this application holds (the
 * largest list is ~500 rows) and keeps the payload bounded on a slow link; a
 * table that ever outgrew it would still render correctly, just not past the
 * cap, which the tests would catch.
 */
function table_row_cap(): int
{
    return 5000;
}

/**
 * Turn a user supplied term into a LIKE pattern.
 * "*" = any run, "?" = one character, plain terms match "contains".
 */
function wildcard_needle($value): ?string
{
    if ($value === null) {
        return null;
    }

    $term = trim((string)$value);

    if ($term === '') {
        return null;
    }

    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    $pattern = str_replace(['*', '?'], ['%', '_'], $escaped);

    if (strpos($pattern, '%') === false && strpos($pattern, '_') === false) {
        $pattern = '%' . $pattern . '%';
    }

    return $pattern;
}

/* ===========================================================================
 * Leave status presentation
 * ======================================================================== */

function status_label(string $status): string
{
    return match ($status) {
        'planned'   => 'Planned',
        'on_leave'  => 'On Leave',
        'returned'  => 'Returned',
        'overdue'   => 'Overdue',
        'cancelled' => 'Cancelled',
        // Closed out as a non-return - terminal, like cancelled.
        'not_returned' => 'Not Returned',
        // Leave applications: whether the leave itself has begun.
        'not_started'  => 'Not Started',
        // Derived: an approved departure past its last working day. Nothing
        // is outstanding, so it reads as terminal rather than pending.
        'closed'       => 'Closed',
        default     => ucfirst(str_replace('_', ' ', $status)),
    };
}

function status_icon(string $status): string
{
    return match ($status) {
        'planned'   => 'bi-calendar-event',
        'on_leave'  => 'bi-airplane',
        'returned'  => 'bi-check-circle-fill',
        'overdue'   => 'bi-exclamation-triangle-fill',
        'cancelled' => 'bi-slash-circle',
        'not_returned' => 'bi-person-x',
        // Leave applications (a separate register from leave_records).
        'pending'   => 'bi-clock-history',
        'approved'  => 'bi-check-circle-fill',
        'rejected'  => 'bi-x-circle',
        'not_started'  => 'bi-hourglass-split',
        'closed'       => 'bi-door-closed',
        // Synthetic: a projected absence, away but not yet a record.
        'expected'  => 'bi-calendar-check',
        default     => 'bi-circle',
    };
}

/**
 * Bootstrap tone for one status - the single place the palette lives.
 *
 * status_badge() renders server-side (Capacity Movement, Leave Records, every
 * report) while the Day-by-Day drill-down renders the same statuses in the
 * browser. Both read this map, so a status can never be one colour on the page
 * and another inside the modal.
 */
function status_badge_class(string $status): string
{
    return match ($status) {
        'planned'   => 'text-bg-secondary',
        'on_leave'  => 'text-bg-primary',
        'returned'  => 'text-bg-success',
        'overdue'   => 'text-bg-danger',
        'cancelled' => 'text-bg-dark',
        'not_returned' => 'text-bg-warning',
        // Leave applications: grey until decided, then green / red.
        'pending'   => 'text-bg-secondary',
        'approved'  => 'text-bg-success',
        'rejected'  => 'text-bg-danger',
        'not_started'  => 'text-bg-secondary',
        // Terminal, like cancelled: the window has shut, nothing is pending.
        'closed'       => 'text-bg-dark',
        // Synthetic, never a stored value: a projected absence the drill-down
        // draws before any leave record exists for it.
        'expected'  => 'text-bg-info',
        default     => 'text-bg-secondary',
    };
}

function status_badge(string $status): string
{
    // An empty status is not a status. Falling through to the default tone and
    // bi-circle used to render a grey pill with a glyph and NO TEXT, which
    // reads as a real (if mysterious) state - it is how a leave_status column
    // that is missing or NULL on the server shows up. Say nothing instead, in
    // the same em dash the rest of the app uses for "nothing here".
    if ($status === '') {
        return '<span class="text-body-secondary" title="No status set">&mdash;</span>';
    }

    $class = status_badge_class($status);

    return '<span class="badge rounded-pill ' . $class . ' status-badge">'
        . '<i class="bi ' . status_icon($status) . ' me-1"></i>' . e(status_label($status)) . '</span>';
}

/** Short "3d / 12d" day counts used in table columns. */
function day_count(int $days): string
{
    return $days . 'd';
}

/* ===========================================================================
 * CSV / Excel export helpers
 * ======================================================================== */

/**
 * Neutralise a spreadsheet formula-injection payload (OWASP "CSV injection").
 *
 * A cell beginning with =, +, -, @ or a tab / carriage return is parsed as a
 * formula by Excel, where it can compute, exfiltrate data or execute a command
 * through a DDE payload such as =cmd|'/c calc'!A1. Prefixing with an
 * apostrophe forces the value to be read as literal text; Excel hides the
 * apostrophe once it has decided the cell is text.
 *
 * Applied to every CSV cell AND every CSV header - headers are developer
 * constants today, but leaving them out is how this class of bug comes back.
 * JSON exports are deliberately NOT post-processed: that format is consumed by
 * programs, not by a spreadsheet parser.
 *
 * @param mixed $value
 * @return mixed
 */
function csv_safe($value)
{
    if ($value === null) {
        return '';
    }

    // Ints and floats cannot be a formula.
    if (!is_string($value) || $value === '') {
        return $value;
    }

    return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
        ? "'" . $value
        : $value;
}

/**
 * Stream an array of rows as CSV (UTF-8 with BOM so Excel renders Arabic
 * names correctly).
 *
 * @param array<int, array<int, mixed>> $rows
 * @param array<int, string>            $headers
 */
function export_csv(array $headers, array $rows, string $filename): void
{
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        // Re-stated rather than inherited: an export is the most sensitive
        // thing this app produces, and header() would otherwise replace the
        // no-store bootstrap already sent.
        header('Cache-Control: no-store, no-cache');
    }

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 byte order mark

    // $separator / $enclosure / $escape are all passed explicitly: relying on
    // the $escape default is deprecated from PHP 8.4, and saying it out loud
    // costs nothing. The values are the historical defaults.
    fputcsv($out, array_map('csv_safe', $headers), ',', '"', '\\');

    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe', array_values($row)), ',', '"', '\\');
    }

    fclose($out);
    exit;
}

/**
 * One SpreadsheetML cell.
 *
 * Every cell carries an explicit ss:Type, and an explicit type is what stops
 * Excel from ever re-interpreting the text as a formula - this is the half of
 * the injection defence the HTML implementation could not express.
 *
 * $style optionally attaches a named style (see xls_styles()). Left empty it
 * emits the bare <Cell> that the tests pin, so callers which do not care
 * about presentation are unaffected.
 *
 * @param mixed $value
 */
function xls_cell($value, string $style = ''): string
{
    if ($value === null) {
        $value = '';
    }

    $attr = $style === '' ? '' : ' ss:StyleID="' . $style . '"';

    if (is_int($value) || is_float($value)) {
        return '<Cell' . $attr . '><Data ss:Type="Number">' . $value . '</Data></Cell>';
    }

    // Keep everything else as text: badge numbers, iqama numbers and dates
    // must not be silently coerced and lose leading zeros or formatting.
    // XML forbids most control characters, so strip them before escaping.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);

    return '<Cell' . $attr . '><Data ss:Type="String">'
        . htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8')
        . '</Data></Cell>';
}

/**
 * The stylesheet every exported sheet points at.
 *
 * Borders are the point: an exported table that reads as a plain block of
 * text is unusable the moment it is printed or pasted. Header and data carry
 * a full four-sided rule; the header also gets a fill and a bold weight so it
 * still reads as a header when the file is printed in greyscale.
 *
 * Title and Meta deliberately carry NO border - they sit above the table, and
 * ruling them in would put a box around two lines of text.
 */
function xls_styles(): string
{
    $ruled = '<Borders>'
        . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>'
        . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>'
        . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>'
        . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>'
        . '</Borders>';

    return '  <Styles>' . "\n"
        . '    <Style ss:ID="Default" ss:Name="Normal">' . "\n"
        . '      <Alignment ss:Vertical="Center"/>' . "\n"
        . '      <Font ss:FontName="Calibri" ss:Size="11"/>' . "\n"
        . '    </Style>' . "\n"
        // Row 1 - the report name, big and bold so it survives a printout.
        . '    <Style ss:ID="Title">' . "\n"
        . '      <Alignment ss:Vertical="Center"/>' . "\n"
        . '      <Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1"/>' . "\n"
        . '    </Style>' . "\n"
        // Row 2 - when the file was produced: what makes an export auditable.
        . '    <Style ss:ID="Meta">' . "\n"
        . '      <Alignment ss:Vertical="Center"/>' . "\n"
        . '      <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#6B7280"/>' . "\n"
        . '    </Style>' . "\n"
        // Row 3 - the column headings.
        . '    <Style ss:ID="Header">' . "\n"
        . '      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n"
        . $ruled . "\n"
        . '      <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/>' . "\n"
        . '      <Interior ss:Color="#E8EEF7" ss:Pattern="Solid"/>' . "\n"
        . '    </Style>' . "\n"
        // Row 4 onward - the data.
        . '    <Style ss:ID="Data">' . "\n"
        . '      <Alignment ss:Vertical="Center"/>' . "\n"
        . $ruled . "\n"
        . '      <Font ss:FontName="Calibri" ss:Size="11"/>' . "\n"
        . '    </Style>' . "\n"
        . '  </Styles>';
}

/**
 * Advance widths, in pixels, of the printable ASCII glyphs in Calibri 11.
 *
 * Excel does not apply ss:AutoFitWidth when opening a SpreadsheetML file - it
 * is a documented dead end for this format - so the fit is computed here from
 * the headers and the data. The title row is deliberately left out: it is
 * merged across the table, and measuring it would blow the first column open
 * to the width of the report name.
 *
 * The fit must be done in PIXELS, not characters, because "widest" is not
 * "longest": Excel sums glyph advances, so 'MUHAMMAD MASOOD AHMAD GHULAM
 * MUHAMMAD' (37 characters) needs 349px while '2026-09-30 14:17:55' (19
 * characters) needs only 128px. Counting characters shortchanges capitals by
 * about a third - it shipped the driver column 53px narrow, clipping every
 * long name - and overcharges digits and dashes.
 *
 * Measured against Excel itself: fill a column with N and 2N copies of a
 * glyph, AutoFit it, and difference the two results, which separates the glyph
 * from the cell padding a single measurement cannot (ten digits at 77px is
 * consistent with both w=7.0/P=7 and w=7.2/P=5). Code points absent from this
 * map are priced at XLS_UNKNOWN_GLYPH_PX so an unfamiliar script overshoots
 * rather than clips.
 *
 * @var array<string, float>
 */
const XLS_GLYPH_PX = [
    ' ' => 3.0, '!' => 5.1, '"' => 6.2, '#' => 7.3, '$' => 7.3, '%' => 11.4,
    '&' => 10.4, "'" => 3.0, '(' => 5.1, ')' => 5.1, '*' => 7.3, '+' => 7.3,
    ',' => 4.0, '-' => 5.1, '.' => 4.0, '/' => 6.2,
    // 0-9 are tabular: every digit is the same 7.3px.
    '0' => 7.3, '1' => 7.3, '2' => 7.3, '3' => 7.3, '4' => 7.3,
    '5' => 7.3, '6' => 7.3, '7' => 7.3, '8' => 7.3, '9' => 7.3,
    ':' => 4.0, ';' => 4.0, '<' => 7.3, '=' => 7.3, '>' => 7.3, '?' => 7.3,
    '@' => 13.5,
    'A' => 9.3, 'B' => 8.3, 'C' => 8.3, 'D' => 9.3, 'E' => 7.3, 'F' => 7.3,
    'G' => 9.3, 'H' => 9.3, 'I' => 4.0, 'J' => 5.1, 'K' => 8.3, 'L' => 6.2,
    'M' => 12.4, 'N' => 10.4, 'O' => 10.4, 'P' => 8.3, 'Q' => 10.4, 'R' => 8.3,
    'S' => 7.3, 'T' => 7.3, 'U' => 9.3, 'V' => 9.3, 'W' => 13.5, 'X' => 8.3,
    'Y' => 7.3, 'Z' => 7.3,
    '[' => 5.1, '\\' => 6.2, ']' => 5.1, '^' => 7.3, '_' => 7.3, '`' => 4.0,
    'a' => 7.3, 'b' => 8.3, 'c' => 6.2, 'd' => 8.3, 'e' => 8.3, 'f' => 5.1,
    'g' => 7.3, 'h' => 8.3, 'i' => 4.0, 'j' => 4.0, 'k' => 7.3, 'l' => 4.0,
    'm' => 12.4, 'n' => 8.3, 'o' => 8.3, 'p' => 8.3, 'q' => 8.3, 'r' => 5.1,
    's' => 6.2, 't' => 5.1, 'u' => 8.3, 'v' => 7.3, 'w' => 11.4, 'x' => 7.3,
    'y' => 7.3, 'z' => 6.2,
    '{' => 5.1, '|' => 7.3, '}' => 5.1, '~' => 7.3,
];
/**
 * Glyphs Calibri draws wider when bold; everything else carries over.
 *
 * The heading row is styled Bold, so this is the half that sizes row 3.
 * Measured the same way as XLS_GLYPH_PX.
 *
 * @var array<string, float>
 */
const XLS_GLYPH_PX_BOLD = [
    '"' => 7.3, '&' => 11.4, "'" => 4.0, 'G' => 10.4, 'M' => 13.5,
    'U' => 10.4, 'W' => 14.5, 'Y' => 8.3, '`' => 5.1,
];

/** Anything outside XLS_GLYPH_PX: the widest glyph measured, so we overshoot. */
const XLS_UNKNOWN_GLYPH_PX = 13.5;

/**
 * Added to a cell's content width to get its column width, in pixels.
 *
 * Excel's own cell padding measures 4.73px; the rest is headroom so that a
 * rounding slip in the glyph table can never cost us a clipped column - a
 * shortfall hides data, a surplus costs a few points of white space.
 */
const XLS_COL_SLACK_PX = 7;

/** Narrowest column emitted: Excel's own default, 8.43 characters (61px). */
const XLS_COL_MIN_PX = 61;

/** Widest: the long-standing 60-character ceiling, in pixels (60 * 7 + 5). */
const XLS_COL_MAX_PX = 425;

/**
 * How wide a cell's content is, in pixels - the quantity Excel sums.
 *
 * Returns a float because glyph advances are fractional; xls_col_px() rounds
 * and clamps it into an actual column.
 *
 * @param mixed $value
 */
function xls_text_px($value, bool $bold = false): float
{
    if ($value === null) {
        return 0.0;
    }

    $text = (string) $value;

    if ($text === '') {
        return 0.0;
    }

    // ASCII is the whole of this application's data and takes the fast path.
    // Anything else is split by code point so a name in another script is
    // measured glyph by glyph instead of collapsed to one lump.
    if (preg_match('/[\x80-\xFF]/', $text)) {
        $chars = function_exists('mb_str_split')
            ? mb_str_split($text, 1, 'UTF-8')
            : preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($chars) || $chars === []) {
            $chars = str_split($text);
        }
    } else {
        $chars = str_split($text);
    }

    $total = 0.0;

    foreach ($chars as $ch) {
        if (isset(XLS_GLYPH_PX[$ch])) {
            $w = XLS_GLYPH_PX[$ch];

            if ($bold && isset(XLS_GLYPH_PX_BOLD[$ch])) {
                $w = XLS_GLYPH_PX_BOLD[$ch];
            }

            $total += $w;
        } else {
            $total += XLS_UNKNOWN_GLYPH_PX;
        }
    }

    return $total;
}

/**
 * Turn a cell's content width into the column width that holds it, in pixels.
 *
 * @param float $contentPx what xls_text_px() measured
 */
function xls_col_px(float $contentPx): int
{
    $px = (int) ceil($contentPx + XLS_COL_SLACK_PX);

    return max(XLS_COL_MIN_PX, min(XLS_COL_MAX_PX, $px));
}

/**
 * SpreadsheetML ss:Width is measured in POINTS, while everything above is
 * measured in pixels: at 96dpi one pixel is 0.75 points, so Excel's 64px
 * default column is 48 points.
 *
 * Emitting pixels instead inflates every column by a third, and emitting the
 * raw character count - the original bug - gave a 10-character column 10
 * points, which is 13px and under two characters wide. That is why every
 * export opened as a row of slivers with the dates clipped to "20" and Status
 * to "Pa".
 *
 * @param int $px column width in pixels
 * @see XLS_GLYPH_PX
 */
function xls_col_points(int $px): int
{
    return (int) round($px * 0.75);
}

/**
 * A readable heading for an export whose name is a machine slug.
 *
 *   capacity-monthly-20261002-143012  ->  Capacity Monthly
 */
function xls_title_from_filename(string $filename): string
{
    $base = preg_replace('/\.(xlsx?|csv|json)$/i', '', $filename);
    $base = preg_replace('/-\d{8}-\d{6}$/', '', (string) $base);
    $base = str_replace(['_', '-'], ' ', (string) $base);
    $base = trim((string) preg_replace('/\s+/', ' ', $base));

    return $base === '' ? 'Export' : ucwords($base);
}

/**
 * Excel export in SpreadsheetML (Excel 2003 XML).
 *
 * This is a real spreadsheet format, not a renamed HTML table. Excel and
 * LibreOffice open it natively with the .xls name and the
 * application/vnd.ms-excel type, without the "the file format and extension
 * don't match" warning the previous HTML implementation triggered - and
 * without the "we found a problem with some content" repair prompt.
 *
 * The sheet is laid out for reading rather than dumped raw:
 *
 *   row 1   report title        16pt bold, merged across every column
 *   row 2   exported at         when this file was produced
 *   row 3   column headings     bold, filled, bordered, wrapped
 *   row 4+  the data            bordered, columns fitted to their content
 *
 * so the data always begins on the fourth row, and gridlines are off so the
 * borders we draw are the only rules on the page.
 *
 * @param array<int, array<int, mixed>> $rows
 * @param array<int, string>            $headers
 * @param string                        $filename
 * @param string                        $title     row-1 heading; derived from
 *                                                 $filename when empty
 */
function export_xls(array $headers, array $rows, string $filename, string $title = ''): void
{
    if (!headers_sent()) {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Cache-Control: no-store, no-cache');
    }

    if ($title === '') {
        $title = xls_title_from_filename($filename);
    }

    $headers = array_values($headers);
    $count   = count($headers);

    // --- column widths, fitted to the headings and the data ---------------
    // Measured in pixels (xls_text_px), because counting characters gets the
    // answer wrong in both directions: capitals run ~40% wide, digits and
    // dashes run narrow. The heading is measured bold, as the Header style
    // renders it.
    $widths = [];

    foreach ($headers as $head) {
        $widths[] = xls_text_px($head, true);
    }

    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $w = xls_text_px($cell);

            if (!isset($widths[$i]) || $w > $widths[$i]) {
                $widths[$i] = $w;
            }
        }
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<?mso-application progid="Excel.Sheet"?>' . "\n"
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n"
        . xls_styles() . "\n"
        . '  <Worksheet ss:Name="Export">' . "\n"
        . '    <Table>';

    for ($i = 0; $i < $count; $i++) {
        // Content width in pixels, padded and clamped (xls_col_px), then
        // converted to the POINTS ss:Width asks for (xls_col_points). Emitting
        // anything else is how the sheet once opened as a row of slivers with
        // the dates clipped to "20"; the clamp keeps one runaway value from
        // opening a 400-character column and stops a short heading collapsing
        // the column to nothing.
        $px = xls_col_px($widths[$i] ?? 0.0);

        $xml .= '<Column ss:AutoFitWidth="0" ss:Width="' . xls_col_points($px) . '"/>';
    }

    $span = max(0, $count - 1);

    // Row 1 - the title, merged across the table so it reads as a heading
    // rather than as data sitting in column A.
    $xml .= "\n" . '      <Row ss:Height="24">'
        . '<Cell ss:MergeAcross="' . $span . '" ss:StyleID="Title">'
        . '<Data ss:Type="String">'
        . htmlspecialchars($title, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8')
        . '</Data></Cell></Row>' . "\n";

    // Row 2 - when it was exported. A file with no timestamp on it cannot be
    // reconciled against a later change to the data.
    $xml .= '      <Row>'
        . '<Cell ss:MergeAcross="' . $span . '" ss:StyleID="Meta">'
        . '<Data ss:Type="String">Exported ' . date('d M Y, H:i')
        . '</Data></Cell></Row>' . "\n";

    // Row 3 - the column headings.
    $xml .= '      <Row ss:Height="20">';

    foreach ($headers as $head) {
        $xml .= xls_cell((string) $head, 'Header');
    }

    $xml .= '</Row>' . "\n";

    // Row 4 onward - the data, which is what the caller actually wants.
    foreach ($rows as $row) {
        $xml .= '      <Row>';

        foreach (array_values($row) as $cell) {
            $xml .= xls_cell($cell, 'Data');
        }

        $xml .= '</Row>' . "\n";
    }

    $xml .= '    </Table>' . "\n"
        // Gridlines off: Excel draws its own rules on top of ours, and the
        // table reads as a double-ruled mess with both sets showing.
        . '    <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n"
        . '      <DoNotDisplayGridlines/>' . "\n"
        . '    </WorksheetOptions>' . "\n"
        . '  </Worksheet>' . "\n"
        . '</Workbook>';

    echo $xml;
    exit;
}

/**
 * Validate ?export= before a single byte is written.
 *
 * Every endpoint used to fall through to CSV for any value it did not
 * recognise, so ?export=pdf quietly handed back a .csv and ?export=xls on a
 * CSV-only screen quietly handed back the HTML page. A format we do not
 * support is a caller mistake - reject it instead of guessing.
 *
 * @param array<int, string> $allowed
 */
function export_format(string $requested, array $allowed): string
{
    $format = strtolower(trim($requested));

    if ($format === '') {
        $format = 'csv';
    }

    if (in_array($format, $allowed, true)) {
        return $format;
    }

    if (app_context() === 'api') {
        json_error('Unsupported export format: ' . $format, 400, ['allowed' => $allowed]);
    }

    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unsupported export format "' . $format . '". Allowed: ' . implode(', ', $allowed);
    exit;
}

/** @param array<int, array<int, mixed>> $rows */
function export_json(array $rows, string $filename): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }

    echo json_encode(
        ['exported_at' => now(), 'timezone' => APP_TIMEZONE, 'rows' => $rows],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    exit;
}

/* ===========================================================================
 * Empty / loading state partials
 * ======================================================================== */

/** A friendly empty state block so no screen is ever blank. */
function empty_state(string $icon, string $title, string $hint = '', string $actionHtml = ''): string
{
    $html = '<div class="text-center text-body-secondary py-5 empty-state">'
        . '<i class="bi ' . e($icon) . ' d-block mb-3" style="font-size:2.5rem;opacity:.45;"></i>'
        . '<h6 class="fw-semibold">' . e($title) . '</h6>';

    if ($hint !== '') {
        $html .= '<p class="small mb-3">' . e($hint) . '</p>';
    }

    if ($actionHtml !== '') {
        $html .= $actionHtml;
    }

    return $html . '</div>';
}


