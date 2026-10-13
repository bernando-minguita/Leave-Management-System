<?php
/**
 * Leave Applications: is there a View action in the Actions column, for every
 * role, and does the detail page open without widening anything else?
 *
 *   php C:/xampp/htdocs/lms/tools/application-view-test.php
 *
 * The bug this pins: applications/index.php rendered only Edit/Delete-gated
 * actions, so any role holding leave.view but neither leave.edit nor
 * leave.delete - Viewer today, Operations the moment an administrator narrows
 * its grants - saw a completely blank Actions cell and had no way to open an
 * application at all. Every other list in the app already had a View.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$pass = 0;
$fail = 0;

function ok(bool $cond, string $msg): void
{
    global $pass, $fail;

    if ($cond) {
        $pass++;
        echo "  ok    $msg\n";
    } else {
        $fail++;
        echo "  FAIL  $msg\n";
    }
}

const BASE = 'http://localhost/lms';
const SLUGS = ['admin', 'hr', 'operations', 'viewer'];

function fetch(string $url, array &$jar, ?string $post = null): array
{
    $headers = ['User-Agent: Mozilla/5.0 application-view-test'];
    if ($jar !== []) {
        $p = [];
        foreach ($jar as $k => $v) {
            $p[] = "$k=$v";
        }
        $headers[] = 'Cookie: ' . implode('; ', $p);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    $body = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    foreach (preg_split('/\r?\n/', substr($body, 0, $size)) as $line) {
        if (stripos($line, 'set-cookie:') === 0) {
            $pair = explode('=', explode(';', substr($line, 11), 2)[0], 2);
            $jar[trim($pair[0])] = trim($pair[1] ?? '');
        }
    }

    curl_close($ch);

    return [$code, substr($body, $size)];
}

/** Actions cell of every data row, plus the Actions column index. */
function actionCells(string $page): array
{
    preg_match_all('/<thead[^>]*>(.*?)<\/thead>/s', $page, $heads);
    $col = null;

    foreach ($heads[1] as $h) {
        if (stripos($h, 'Actions') === false) {
            continue;
        }
        preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $h, $th, PREG_SET_ORDER);
        foreach ($th as $i => $one) {
            if (trim(strip_tags($one[1])) === 'Actions') {
                $col = $i;
            }
        }
    }

    preg_match('/<tbody[^>]*>(.*?)<\/tbody>/s', $page, $body);
    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $body[1] ?? '', $rows);

    $cells = [];

    foreach ($rows[1] ?? [] as $row) {
        preg_match_all('/<td\b([^>]*)>(.*?)<\/td>/s', $row, $td, PREG_SET_ORDER);
        if ($col !== null && isset($td[$col])) {
            $cells[] = $td[$col][2];
        }
    }

    return [$col, $cells];
}

/* ------------------------------------------------------- set up users --- */

echo "== set up one user per role ==\n";

foreach (SLUGS as $slug) {
    $username = 'zav_' . $slug;
    Database::run('DELETE FROM users WHERE username = ?', [$username]);
    Database::run(
        'INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
        [$username, password_hash('test123', PASSWORD_DEFAULT), 'AV ' . $slug, $slug, 'active']
    );
    $userId = (int) Database::scalar('SELECT id FROM users WHERE username = ?', [$username]);
    $roleId = (int) Database::scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);
    ok($userId > 0 && $roleId > 0, "$slug user ready");
}

/* ------------------------------------------------- per-role behaviour --- */

$firstId = (int) Database::scalar('SELECT id FROM leave_applications ORDER BY id LIMIT 1');
ok($firstId > 0, "a leave application exists to open (#$firstId)");

foreach (SLUGS as $slug) {
    echo "\n== $slug ==\n";

    $jar = [];
    [, $login] = fetch(BASE . '/login', $jar);
    preg_match('/name="_csrf"\s+value="([^"]+)"/', $login, $m);

    fetch(BASE . '/login', $jar, http_build_query([
        'username' => 'zav_' . $slug,
        'password' => 'test123',
        '_csrf'    => $m[1] ?? '',
        'redirect' => '',
    ]));

    [$code, $page] = fetch(BASE . '/leave-applications', $jar);
    ok($code === 200, "list opens (HTTP $code)");

    [$col, $cells] = actionCells($page);
    ok($col !== null, 'Actions column present');
    ok($cells !== [], 'list has ' . count($cells) . ' data rows');
    // The .table-grid floor: a one-result search still has room for the
    // row-action dropdown to open downwards instead of being clipped.
    ok(str_contains($page, 'table-responsive table-grid'),
        'list keeps the 300px table floor so a single row shows its actions');

    // The regression itself: never a blank cell, whatever else the role holds.
    $blank = 0;
    $noView = 0;

    foreach ($cells as $cell) {
        if (trim(strip_tags($cell)) === '' && stripos($cell, '<a') === false) {
            $blank++;
        }
        if (stripos($cell, 'leave-applications/') === false
            || stripos($cell, 'title="View"') === false) {
            $noView++;
        }
    }

    ok($blank === 0, $blank === 0
        ? 'no blank Actions cell (' . count($cells) . ' rows)'
        : "$blank blank Actions cell(s)");

    ok($noView === 0, $noView === 0
        ? 'every row offers View'
        : "$noView row(s) without a View link");

    // The detail page itself - leave.view, so every one of these roles gets in.
    [$code, $detail] = fetch(BASE . '/leave-applications/' . $firstId, $jar);
    ok($code === 200, "detail opens for $slug (HTTP $code)");
    ok(str_contains($detail, 'Leave Application #' . $firstId), 'detail shows the application header');
    ok(!str_contains($detail, 'Something went wrong'), 'detail rendered without a 500');

    // Reading must not have widened anything: edit still needs leave.edit.
    $canEdit = in_array($slug, ['admin', 'hr', 'operations'], true);
    [$code] = fetch(BASE . '/leave-applications/' . $firstId . '/edit', $jar);
    ok($code === ($canEdit ? 200 : 403),
        "edit still gated by leave.edit (HTTP $code, expected " . ($canEdit ? 200 : 403) . ')');

    // Unknown id must not leak a stack trace.
    [$code] = fetch(BASE . '/leave-applications/99999999', $jar);
    ok($code === 404, "unknown id is 404 (HTTP $code)");
}

/* ------------------------------------------------------------- clean up -- */

foreach (SLUGS as $slug) {
    Database::run('DELETE FROM users WHERE username = ?', ['zav_' . $slug]);
}

ok((int) Database::scalar("SELECT COUNT(*) FROM users WHERE username LIKE 'zav\\_%'") === 0,
    'throwaway users removed');

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
