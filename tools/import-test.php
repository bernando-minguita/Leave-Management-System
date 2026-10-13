<?php

declare(strict_types=1);

/**
 * Focused CSV import test (CLI only).
 *
 *   php tools/import-test.php [baseUrl]
 *
 * Logs in, downloads the template, uploads it back with one new driver,
 * confirms the import and verifies the driver exists. Cleans up afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/import-test.php\n");
}

$base = rtrim($argv[1] ?? 'http://localhost/lms', '/');

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The curl PHP extension is required.\n");
    exit(1);
}

$jar = rtrim((string)sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
    . 'lms-imp-' . bin2hex(random_bytes(4)) . '.txt';

function req(string $method, string $url, array|string $post = []): array
{
    global $jar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 20,      // short so a hang shows up fast
        CURLOPT_HEADER         => false,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);

        if (is_string($post)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        } else {
            $hasFile = false;
            foreach ($post as $v) {
                if ($v instanceof CURLFile) {
                    $hasFile = true;
                    break;
                }
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $post : http_build_query($post));
        }
    }

    echo "  -> $method " . basename(parse_url($url, PHP_URL_PATH) ?: $url) . " ...\n";

    $body   = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0) {
        echo "  !! curl error $errno: $error\n";
    } else {
        echo "  <- HTTP $status, " . strlen($body) . " bytes\n";
    }

    return [$status, $body];
}

function token(string $html): string
{
    if (preg_match('#name="csrf-token" content="([^"]+)"#', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('#name="_csrf" value="([^"]+)"#', $html, $m) === 1) {
        return $m[1];
    }

    return '';
}

echo "1. login page\n";
[, $html] = req('GET', $base . '/login');
$csrf = token($html);
echo "   token=" . substr($csrf, 0, 8) . "...\n";

echo "2. login\n";
[, $body] = req('POST', $base . '/login', [
    '_csrf' => $csrf, 'username' => 'admin', 'password' => getenv('LMS_TEST_PASS') ?: 'admin123', 'redirect' => '',
]);
echo '   dashboard=' . (strpos($body, 'Leave Dashboard') !== false ? 'yes' : 'NO') . "\n";

echo "3. import page\n";
[, $html] = req('GET', $base . '/import');
$csrf = token($html);
echo '   token=' . substr($csrf, 0, 8) . "...\n";

echo "4. template download\n";
[, $template] = req('GET', $base . '/import?template=1');
$lines   = preg_split('/\r\n|\r|\n/', trim($template)) ?: [];
$header  = $lines[0] ?? '';
echo '   header=' . substr($header, 0, 40) . "...\n";

echo "5. upload for preview\n";
$badge = 'W-' . random_int(100, 999);
$iqama = '9' . random_int(100000000, 999999999);
$cells = str_getcsv($lines[1] ?? '');
$cells[0] = $badge;
$cells[1] = 'Import Probe Driver';
$cells[2] = $iqama;

$tmp = rtrim((string)sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'lms-imp-probe.csv';
file_put_contents($tmp, $header . "\n" . implode(',', $cells) . "\n");
echo '   csv=' . $tmp . ' (' . filesize($tmp) . " bytes)\n";

[, $preview] = req('POST', $base . '/import', [
    '_csrf'    => $csrf,
    'csv_file' => new CURLFile($tmp, 'text/csv', 'drivers.csv'),
]);
echo '   badge_in_preview=' . (strpos($preview, $badge) !== false ? 'YES' : 'NO') . "\n";
echo '   confirm_btn=' . (strpos($preview, 'Confirm import') !== false ? 'YES' : 'NO') . "\n";
$alert = '';
if (preg_match('#<div class="alert[^>]*>(.*?)</div>#s', $preview, $m) === 1) {
    $alert = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
}
echo '   alert=' . ($alert === '' ? '(none)' : substr($alert, 0, 90)) . "\n";

if (strpos($preview, $badge) === false) {
    echo "STOPPED: no preview to confirm.\n";
    @unlink($tmp);
    @unlink($jar);
    exit(1);
}

echo "6. confirm import\n";
$csrf2 = token($preview);
[, $summary] = req('POST', $base . '/import', [
    '_csrf' => $csrf2, 'step' => 'confirm', 'mode' => 'skip',
]);
$flat = trim(preg_replace('/\s+/', ' ', strip_tags($summary)));
echo '   summary=' . substr($flat, 0, 120) . "\n";

echo "7. verify driver\n";
[, $search] = req('GET', $base . '/drivers?q=' . urlencode($badge));
echo '   found=' . (strpos($search, $badge) !== false ? 'YES' : 'NO') . "\n";

echo "8. cleanup\n";
if (preg_match('#href="[^"]*/drivers/(\d+)"#', $search, $m) === 1) {
    [, $edit] = req('GET', $base . '/drivers/' . $m[1] . '/edit');
    req('POST', $base . '/drivers/' . $m[1] . '/edit', [
        '_csrf'        => token($edit),
        'badge_number' => $badge,
        'driver_name'  => 'Import Probe Driver',
        'iqama_number' => $iqama,
        'status'       => 'inactive',
        'remarks'      => 'Temporary import probe - safe to delete',
    ]);
    echo "   deactivated probe driver\n";
}

@unlink($tmp);
@unlink($jar);
echo "done\n";
exit(0);
