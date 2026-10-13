<?php

declare(strict_types=1);

/**
 * Confirm-target test (CLI only).
 *
 *   php tools/confirm-test.php
 *
 * Guards against the bug where clicking anywhere inside the Address Book or
 * Leave Records grid opened the delete confirmation.
 *
 * Cause: data-confirm sat on the <form>, and the bulk form wraps the whole
 * table, so app.js's closest('[data-confirm]') matched it for every click in
 * the grid. The confirmation belongs on the submit button.
 *
 * Invariant checked here: no <form data-confirm="..."> may enclose a <table>.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/confirm-test.php\n");
}

require __DIR__ . '/../app/bootstrap.php';

$base = 'http://127.0.0.1/lms';
$GLOBALS['JAR'] = tempnam(sys_get_temp_dir(), 'cf');

$pass = 0;
$fail = 0;
function ck(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%-58s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $ok || $detail === '' ? '' : '  [' . $detail . ']');
}

function req(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE     => $GLOBALS['JAR'],
        CURLOPT_COOKIEJAR      => $GLOBALS['JAR'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => true,
    ]);
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return substr($raw, $size);
}

/* ---- sign in (one helper for both calls, so the jar stays consistent) ---- */
$call = static function (string $url, array $post = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE     => $GLOBALS['JAR'],
        CURLOPT_COOKIEJAR      => $GLOBALS['JAR'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => true,
    ]);
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['code' => $code, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
};

$get = $call($base . '/login');
preg_match('/name="_csrf"[^>]*value="([^"]+)"/', $get['body'], $m);
$login = $call($base . '/login', [
    '_csrf' => $m[1] ?? '', 'username' => 'admin', 'password' => getenv('LMS_TEST_PASS') ?: 'admin123', 'redirect' => '',
]);
echo 'login: ' . $login['code'] . ' token=' . strlen($m[1] ?? '') . "\n";

$probe = $call($base . '/dashboard');
$signedIn = $probe['code'] === 200 && (str_contains($probe['body'], 'Sign out')
    || str_contains($probe['body'], 'Log out') || str_contains($probe['body'], 'data-bulk-select'));
echo 'signed in: ' . ($signedIn ? 'yes' : 'NO') . ' (dashboard ' . $probe['code'] . ")\n";
if (!$signedIn) {
    echo 'head: ' . substr(preg_replace('/\s+/', ' ', strip_tags($probe['body'])), 0, 120) . "\n";
}

/* ---- the two grids under test ---- */
$pages = [
    'Leave Records' => req($base . '/leave/records'),
    'Address Book'  => req($base . '/drivers'),
];

foreach ($pages as $name => $html) {
    $tag = preg_match('/<form[^>]*data-bulk-select[^>]*>/s', $html, $fm) === 1 ? $fm[0] : '';
    ck("{$name}: bulk form found", $tag !== '');
    ck("{$name}: form tag has NO data-confirm", $tag !== '' && stripos($tag, 'data-confirm') === false);

    $block = preg_match('/<form[^>]*data-bulk-select.*?<\/form>/s', $html, $bm) === 1 ? $bm[0] : '';
    ck("{$name}: form block captured", $block !== '');

    // The form must NOT enclose the table: a <form> inside a <form> is dropped
    // by HTML parsers, and an enclosing form made every click in the grid look
    // like a confirm trigger.
    ck("{$name}: form does not enclose the table", $block !== '' && !str_contains($block, '<table'));
    // Only the form's own opening tag: a second <form> inside it would be a
    // nested form, which parsers drop.
    $formTags = substr_count((string) $block, '<form');
    ck("{$name}: form contains no nested <form>", $block !== '' && $formTags === 1,
        "found {$formTags} opening tags");

    $confirms = preg_match_all('/data-confirm=/', $block, $cm);
    ck("{$name}: exactly one data-confirm inside the form", $confirms === 1, "found {$confirms}");

    // ...and it belongs to the submit button, not to the form or a cell.
    $onButton = preg_match('/<button[^>]*data-confirm="[^"]*"[^>]*>/s', $block, $bm2) === 1
        && str_contains($bm2[0], 'data-bulk-submit');
    ck("{$name}: data-confirm is on the bulk submit button", $onButton);

    ck("{$name}: button is type=submit so the dialog fires on click",
        preg_match('/<button[^>]*type="submit"[^>]*data-confirm=/s', $block) === 1
        || preg_match('/<button[^>]*data-confirm=[^>]*type="submit"/s', $block) === 1);

    // Tick boxes link back to the form without living inside it.
    preg_match('/<form[^>]*id="([^"]+)"[^>]*data-bulk-select/s', $html, $idm);
    $formId = $idm[1] ?? '';
    ck("{$name}: bulk form has an id for the form= link", $formId !== '');
    ck("{$name}: tick boxes carry form=\"{$formId}\"",
        $formId !== '' && str_contains($html, 'name="ids[]"') && str_contains($html, 'form="' . $formId . '"'));

    // Row-level actions must be real, non-nested forms again.
    $rowForms = preg_match_all('/<form method="post"[^>]*data-confirm=/s', $html, $rf);
    ck("{$name}: row-level confirm forms still present", $rowForms >= 1, "found {$rowForms}");
}

/* ---- the structural invariant, over every view ---- */
$offenders = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(APP_ROOT . '/app/views', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());
    $count  = preg_match_all('/<form[^>]*data-confirm=[^>]*>/s', $source, $forms);

    for ($i = 0; $i < $count; $i++) {
        $start = strpos($source, $forms[0][$i]);
        $end   = strpos($source, '</form>', $start);

        if ($start === false || $end === false) {
            continue;
        }

        if (str_contains(substr($source, $start, $end - $start), '<table')) {
            $offenders[] = $file->getFilename();
        }
    }
}
ck('no <form data-confirm> anywhere encloses a table', $offenders === [], implode(', ', $offenders));

/* ---- the JS must target the button ---- */
$js = (string) file_get_contents(APP_ROOT . '/assets/js/app.js');
ck('app.js writes the count onto the button', str_contains($js, "btn.setAttribute('data-confirm'"));
ck('app.js no longer writes it onto the form', !str_contains($js, "form.setAttribute('data-confirm'"));
ck('app.js keeps the submit-button confirm branch',
    str_contains($js, "trigger.tagName === 'BUTTON' && trigger.type === 'submit'"));

@unlink($GLOBALS['JAR']);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
