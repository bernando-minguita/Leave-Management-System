<?php

declare(strict_types=1);

/**
 * Role UI test (CLI only).
 *
 *   php tools/viewer-ui-test.php
 *
 * Checks what each role is actually SHOWN, not just what it is allowed to
 * reach. A read-only role must never be offered a button that answers 403,
 * and the checks are mirrored against HR so the fix cannot overshoot and hide
 * things a permitted role still needs.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line:  php tools/viewer-ui-test.php\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$base = 'http://127.0.0.1/lms';
$GLOBALS['BASE'] = $base;
$GLOBALS['JARS'] = [];

$pass = 0;
$fail = 0;
function ck(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%-60s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $ok || $detail === '' ? '' : '  [' . $detail . ']');
}

function call(string $url, array $post = [], string $jarKey = 'tmp'): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $GLOBALS['JARS'][$jarKey], CURLOPT_COOKIEJAR => $GLOBALS['JARS'][$jarKey],
        CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true,
    ]);
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['code' => $code, 'body' => substr($raw, $size)];
}

/** Create a throwaway account on $slug and return its cookie jar key. */
function makeUser(string $slug): string
{
    $username = 'zui_' . $slug;
    Database::run("DELETE FROM users WHERE username = ?", [$username]);

    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, status, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$username, password_hash('test123', PASSWORD_DEFAULT), 'UI ' . $slug, $slug, 'active']);

    $userId = (int) $pdo->lastInsertId();
    $roleId = (int) Database::scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);

    $jar = tempnam(sys_get_temp_dir(), 'ui');
    $GLOBALS['JARS'][$slug] = $jar;

    $g = call($GLOBALS['BASE'] . '/login', [], $slug);
    preg_match('/name="_csrf"[^>]*value="([^"]+)"/', $g['body'], $m);
    call($GLOBALS['BASE'] . '/login', [
        '_csrf' => $m[1] ?? '', 'username' => $username, 'password' => 'test123', 'redirect' => '',
    ], $slug);

    return $slug;
}

function view(string $path, string $role): string
{
    return call($GLOBALS['BASE'] . $path, [], $role)['body'];
}

$driverId = (int) Database::scalar('SELECT MIN(id) FROM drivers');

// Fixture: Edit and Mark Returned only exist for a record that is still open,
// and the live table may hold a cancelled one - which would make the positive
// assertions meaningless.
$fixtureLeaveId = Database::insert('leave_records', [
    'driver_id'             => $driverId,
    'leave_type_id'         => (int) Database::scalar('SELECT MIN(id) FROM leave_types'),
    'leave_start_date'      => '2027-06-01',
    'expected_arrival_date' => '2027-07-01',
    'status'                => 'planned',
    'created_at'            => now(),
    'updated_at'            => now(),
]);
$leaveId = $fixtureLeaveId;

// Second fixture: the driver is actually away. Whether Return is offered is
// a STATUS rule, not a permission, so both shapes need covering - planned
// must never offer it, away always must.
$awayLeaveId = Database::insert('leave_records', [
    'driver_id'             => $driverId,
    'leave_type_id'         => (int) Database::scalar('SELECT MIN(id) FROM leave_types'),
    'leave_start_date'      => date('Y-m-d', strtotime('-5 days')),
    'expected_arrival_date' => date('Y-m-d'),
    'status'                => 'on_leave',
    'created_at'            => now(),
    'updated_at'            => now(),
]);

// The rule itself, independent of any rendering.
ck('planned record cannot be marked returned',
    !\App\Models\LeaveRecord::canMarkReturned(\App\Models\LeaveRecord::find($fixtureLeaveId)));
ck('away record can be marked returned',
    \App\Models\LeaveRecord::canMarkReturned(\App\Models\LeaveRecord::find($awayLeaveId)));

makeUser('viewer');
makeUser('hr');

/* ============================ VIEWER: nothing that 403s =============== */
// Every entry is a control the read-only role must not be offered.
$viewerAbsences = [
    // The dashboard "Returning Today" / "Overdue" cards share the same partial.
    '/dashboard'                    => ['leave/new', 'data-action="mark-returned"'],
    '/drivers'                      => ['drivers/create', 'leave/new', '/edit"', '/deactivate'],
    '/drivers/' . $driverId         => ['leave/new', '/edit"', '/deactivate'],
    '/drivers/' . $driverId . '/leave-history' => ['leave/new'],
    '/leave/calendar'               => ['leave/new'],
    // Current Leave: no Edit link and no Return button for a read-only role.
    '/leave/current'                => ['leave/new', '/edit"', 'data-action="mark-returned"'],
    '/leave/records'                => ['leave/new'],
    // Expected Returns: the Return button.
    '/leave/returns'                => ['data-action="mark-returned"'],
    // The record detail page carries the same three controls.
    '/leave/' . $leaveId            => [
        '/edit"', 'data-action="mark-returned"', 'data-action="cancel-leave"',
    ],
    '/leave/' . $awayLeaveId        => [
        '/edit"', 'data-action="mark-returned"', 'data-action="cancel-leave"',
    ],
];

foreach ($viewerAbsences as $path => $needles) {
    $html = view($path, 'viewer');

    foreach ($needles as $needle) {
        ck('viewer  ' . str_pad($path, 34) . ' hides ' . $needle,
            !str_contains($html, $needle), 'found');
    }
}

// The Address Book row menu would be empty for a viewer, so the toggle itself
// goes: 1 is the account menu in the top bar, which is always there.
$drv = view('/drivers', 'viewer');
ck('viewer  Address Book hides the row dropdown toggle',
    substr_count($drv, 'dropdown-toggle') === 1,
    'found ' . substr_count($drv, 'dropdown-toggle'));

// Leave Records keeps a menu, but only for looking.
$rec = view('/leave/records', 'viewer');
ck('viewer  Leave Records row menu still offers View',
    preg_match('#/leave/' . $leaveId . '"#', $rec) === 1);
ck('viewer  Leave Records hides row Edit',
    !str_contains($rec, '/leave/' . $leaveId . '/edit'));
ck('viewer  Leave Records hides Mark Returned',
    !str_contains($rec, 'data-action="mark-returned"'));
ck('viewer  Leave Records hides Cancel Leave',
    !str_contains($rec, 'data-action="cancel-leave"'));
ck('viewer  Leave Records hides row Delete',
    !str_contains($rec, '/leave/' . $leaveId . '/delete'));
ck('viewer  Leave Records has no bulk-select grid',
    !str_contains($rec, 'data-bulk-select'));

// Non-vacuous guard: those pages must actually be listing rows, otherwise
// "the button is missing" would pass simply because there is nothing to click.
$cur = view('/leave/current', 'viewer');
ck('viewer  Current Leave still lists rows', preg_match('#/leave/\d+"#', $cur) === 1);
$expRet = view('/leave/returns', 'viewer');
ck('viewer  Expected Returns still lists rows', preg_match('#/leave/\d+"#', $expRet) === 1);

/* ============================== HR: still sees its own tools =========== */
// Guards against over-correcting: hiding everything is easy, hiding only what
// the role lacks is the point.
$hrDrv = view('/drivers', 'hr');
ck('hr      Address Book still offers Add Driver', str_contains($hrDrv, 'drivers/create'));
ck('hr      Address Book still offers row Add leave', str_contains($hrDrv, 'leave/new'));
ck('hr      Address Book still offers row Edit', preg_match('/\/edit"/', $hrDrv) === 1);
ck('hr      Address Book still shows the row dropdown (it can deactivate)',
    substr_count($hrDrv, 'dropdown-toggle') > 1,
    'found ' . substr_count($hrDrv, 'dropdown-toggle'));

$hrProfile = view('/drivers/' . $driverId, 'hr');
ck('hr      profile still offers Edit', str_contains($hrProfile, '/edit"'));
ck('hr      profile still offers Add Leave', str_contains($hrProfile, 'leave/new'));
ck('hr      profile still offers Deactivate', str_contains($hrProfile, '/deactivate'));

$hrRec = view('/leave/records', 'hr');
ck('hr      Leave Records still offers New Leave', str_contains($hrRec, 'leave/new'));
ck('hr      Leave Records still offers row Edit',
    str_contains($hrRec, '/leave/' . $leaveId . '/edit'));
ck('hr      Leave Records still offers Mark Returned',
    str_contains($hrRec, 'data-action="mark-returned"'));
ck('hr      Leave Records does NOT offer Delete (not granted)',
    !str_contains($hrRec, '/leave/' . $leaveId . '/delete'));
ck('hr      still reaches Settings', view('/settings/audit', 'hr') !== '');

// The mirror of every absence above: HR must keep the controls, so the fix
// cannot be satisfied by simply hiding the buttons from everyone.
$hrCur = view('/leave/current', 'hr');
ck('hr      Current Leave still offers Edit', str_contains($hrCur, '/edit"'));
ck('hr      Current Leave still offers Return',
    str_contains($hrCur, 'data-action="mark-returned"'));

$hrExpRet = view('/leave/returns', 'hr');
ck('hr      Expected Returns still offers Return',
    str_contains($hrExpRet, 'data-action="mark-returned"'));

$hrLeave = view('/leave/' . $leaveId, 'hr');
ck('hr      detail page still offers Edit',
    str_contains($hrLeave, '/leave/' . $leaveId . '/edit'));
ck('hr      detail page still offers Cancel Leave',
    str_contains($hrLeave, 'data-action="cancel-leave"'));
// The status rule, isolated from permissions: HR does hold leave.return, so
// if this control is missing it can only be the planned status hiding it.
ck('hr      planned record does NOT offer Mark Returned',
    !str_contains($hrLeave, 'data-action="mark-returned"'));
ck('hr      away record still offers Mark Returned',
    str_contains(view('/leave/' . $awayLeaveId, 'hr'), 'data-action="mark-returned"'));

ck('hr      Dashboard still offers Return',
    str_contains(view('/dashboard', 'hr'), 'data-action="mark-returned"'));

/* ================================ cleanup ============================= */
// Fixture account(s) and the open record they were judged against.
foreach (['viewer', 'hr'] as $slug) {
    Database::run('DELETE FROM users WHERE username = ?', ['zui_' . $slug]);
    @unlink($GLOBALS['JARS'][$slug]);
}
Database::run('DELETE FROM leave_records WHERE id IN (?, ?)', [$fixtureLeaveId, $awayLeaveId]);
ck('fixture accounts removed',
    (int) Database::scalar("SELECT COUNT(*) FROM users WHERE username LIKE 'zui\\_%'") === 0);
ck('fixture leave records removed',
    (int) Database::scalar('SELECT COUNT(*) FROM leave_records WHERE id IN (?, ?)',
        [$fixtureLeaveId, $awayLeaveId]) === 0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

