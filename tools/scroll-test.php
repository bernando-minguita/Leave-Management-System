<?php

declare(strict_types=1);

/**
 * Scroll-memory test (CLI only).
 *
 *   php tools/scroll-test.php
 *
 * Guards the fix for "clicking next page throws the scrollbar to the top".
 *
 * Paging and filtering are plain navigations, so the new document starts at
 * offset 0. The position has to be stashed just before leaving and only put
 * back on the same screen (same path, different query).
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/scroll-test.php\n");
}

require __DIR__ . '/../app/bootstrap.php';

$base = 'http://127.0.0.1/lms';
$GLOBALS['JAR'] = tempnam(sys_get_temp_dir(), 'sc');

$pass = 0;
$fail = 0;
function ck(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%-58s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $ok || $detail === '' ? '' : '  [' . $detail . ']');
}

function get(string $path): string
{
    $ch = curl_init($GLOBALS['BASE'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE     => $GLOBALS['JAR'],
        CURLOPT_COOKIEJAR      => $GLOBALS['JAR'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => true,
    ]);
    $raw  = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return substr($raw, $size);
}

$GLOBALS['BASE'] = $base;

/* ---- sign in ---- */
$call = static function (string $url, array $post = []) use ($base): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $GLOBALS['JAR'], CURLOPT_COOKIEJAR => $GLOBALS['JAR'],
        CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true,
    ]);
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw  = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return substr($raw, $size);
};

$login = $call($base . '/login');
preg_match('/name="_csrf"[^>]*value="([^"]+)"/', $login, $m);
$call($base . '/login', [
    '_csrf' => $m[1] ?? '', 'username' => 'admin', 'password' => getenv('LMS_TEST_PASS') ?: 'admin123', 'redirect' => '',
]);

/* ---- the script ---- */
$js = (string) file_get_contents(APP_ROOT . '/assets/js/app.js');

ck('app.js exposes window.rememberScroll', str_contains($js, 'window.rememberScroll = function'));
ck('rememberScroll stores the source screen (p)',
    str_contains($js, 'p: window.location.pathname'));
ck('restore compares that against the current path',
    str_contains($js, 'saved.p === window.location.pathname'));
ck('restore still refuses stale entries', str_contains($js, '> 15000'));
ck('a click on a same-screen link remembers the offset',
    preg_match("/addEventListener\('click'[\s\S]{0,600}rememberScroll\(\)/", $js) === 1);
ck('a GET form submit remembers the offset',
    preg_match("/addEventListener\('submit'[\s\S]{0,600}rememberScroll\(\)/", $js) === 1);
ck('modifier-key / non-left clicks are ignored',
    str_contains($js, 'event.metaKey || event.ctrlKey') && str_contains($js, 'event.button !== 0'));
ck('cross-screen links are excluded via sameScreen()',
    str_contains($js, 'function sameScreen(href)')
    && str_contains($js, '.pathname === window.location.pathname'));

/* ---- the markup the listeners rely on ---- */
// Request a small page size: this block checks the SHAPE of pager links, not
// how many rows happen to be in the table today. At the default 50/page an
// exactly-50-row grid is a single page, and pagination() renders no page
// links at all when $pages === 1.
$drivers = get('/drivers?per_page=10');
$records = get('/leave/records');

ck('/drivers => 200 (has >1 page, so a pager renders)', $drivers !== '');
ck('pager links are same-path query links',
    preg_match('/<a class="page-link" href="\?[^"]*page=\d+/', $drivers) === 1);
ck('pager sits inside a GET form on the same path',
    preg_match('/<form method="get" action="[^"]*\/drivers">/', $drivers) === 1);
ck('sort headers are same-path links',
    preg_match('/<th class="sortable[^>]*><a [^>]*href="\?/', $drivers) === 1);

ck('/leave/records => 200', $records !== '');
ck('leave grid filter form is a GET form', preg_match('/<form method="get" action="[^"]*\/leave\/records">/', $records) === 1);

/* ---- structure ---- */
foreach ([['{', '}'], ['(', ')']] as $pair) {
    $o = substr_count($js, $pair[0]);
    $c = substr_count($js, $pair[1]);
    ck('app.js ' . $pair[0] . $pair[1] . ' balanced', $o === $c, "{$o}/{$c}");
}

@unlink($GLOBALS['JAR']);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
