<?php
/**
 * End-to-end checks for the features nothing else exercises:
 *   A. not_returned survives Status::sync() and leaves the live counts
 *   B. leave_status saves, whitelists and filters
 *   C. 'Not Returned' is a first-class leave status
 *   D. an administrator can reverse a mistaken closure (change-status)
 *   E. an approved departure past its last working day reads 'Closed'
 *
 * Everything it touches is restored afterwards.
 */
declare(strict_types=1);

require 'C:/xampp/htdocs/lms/app/bootstrap.php';

use App\Core\Database;
use App\Core\Status;
use App\Models\LeaveApplication;
use App\Models\LeaveRecord;

$pass = 0;
$fail = 0;

function ok(bool $c, string $m): void
{
    global $pass, $fail;

    if ($c) {
        $pass++;
        echo "  ok    $m\n";
    } else {
        $fail++;
        echo "  FAIL  $m\n";
    }
}

/* ---------------------------------------------- A. not_returned ------ */

echo "== A. not_returned survives Status::sync() ==\n";

$rows = Database::all(
    'SELECT id, status, expected_arrival_date
       FROM leave_records
      WHERE status IN ("on_leave","overdue") AND actual_arrival_date IS NULL
      LIMIT 1'
);

if ($rows === []) {
    echo "  SKIP  no away record to test with\n";
} else {
    $row = $rows[0];
    $id  = (int) $row['id'];
    $was = (string) $row['status'];

    ok(LeaveRecord::canMarkNotReturned($row), 'an away record may be marked not returned');

    // ---------------------------------------------------------------
    // The four closure facts are mandatory. An empty submission, a reason
    // outside the list and a future decision date must all be refused.
    // ---------------------------------------------------------------
    $none = LeaveRecord::validateNotReturned([], $row);
    ok(isset($none['not_returned_reason']), 'reason is required');
    ok(isset($none['not_returned_date']), 'decision date is required');
    ok(isset($none['not_returned_remarks']), 'remarks are required');
    ok(count($none) === 3, 'exactly the three missing fields are reported');

    ok(isset(LeaveRecord::validateNotReturned([
        'not_returned_reason'  => 'Made up on the spot',
        'not_returned_date'    => date('Y-m-d'),
        'not_returned_remarks' => 'n',
    ], $row)['not_returned_reason']), 'a reason outside the list is refused');

    ok(isset(LeaveRecord::validateNotReturned([
        'not_returned_reason'  => 'Resigned',
        'not_returned_date'    => date('Y-m-d', strtotime('+1 day')),
        'not_returned_remarks' => 'n',
    ], $row)['not_returned_date']), 'a future decision date is refused');

    $good = [
        'not_returned_reason'  => 'Resigned',
        'not_returned_date'    => date('Y-m-d'),
        'not_returned_remarks' => 'Resignation letter received.',
    ];
    ok(LeaveRecord::validateNotReturned($good, $row) === [], 'a complete submission passes');

    // "Not Returned By" always comes from the session, never the request.
    $actorId = (int) Database::scalar('SELECT id FROM users ORDER BY id LIMIT 1');
    $_SESSION['user_id'] = $actorId;

    LeaveRecord::markNotReturned($id, $good);

    $after = (string) Database::scalar('SELECT status FROM leave_records WHERE id = ?', [$id]);
    ok($after === Status::NOT_RETURNED, "status written as not_returned (got $after)");

    // All four facts must actually land on the row.
    $closure = Database::one(
        'SELECT not_returned_reason, not_returned_date, not_returned_by, not_returned_remarks
           FROM leave_records WHERE id = ?',
        [$id]
    );
    ok(($closure['not_returned_reason'] ?? '') === 'Resigned', 'reason stored');
    ok(($closure['not_returned_date'] ?? '') === date('Y-m-d'), 'decision date stored');
    ok((int) ($closure['not_returned_by'] ?? 0) === $actorId, 'decided by the signed-in user');
    ok(($closure['not_returned_remarks'] ?? '') === 'Resignation letter received.', 'remarks stored');
    ok(!array_key_exists('not_returned_reason', $_POST), 'the request cannot set it either');

    // The real risk: the auth middleware runs sync() on EVERY page load, and
    // it used to rewrite any non-cancelled row back to its derived value.
    Status::sync();

    $synced = (string) Database::scalar('SELECT status FROM leave_records WHERE id = ?', [$id]);
    ok($synced === Status::NOT_RETURNED,
        "STILL not_returned after Status::sync() (got $synced)");

    $counted = (int) Database::scalar(
        'SELECT COUNT(*) FROM leave_records
          WHERE id = ? AND status NOT IN ("cancelled","not_returned")',
        [$id]
    );
    ok($counted === 0, 'it no longer counts as an open / returning leave');

    ok(!LeaveRecord::canMarkNotReturned(
        ['status' => Status::NOT_RETURNED, 'actual_arrival_date' => null]
    ), 'it cannot be marked not returned twice');

    ok(!in_array(Status::NOT_RETURNED, Status::AWAY, true), 'not in Status::AWAY');
    ok(!in_array(Status::NOT_RETURNED, Status::ACTIVE, true), 'not in Status::ACTIVE');
    ok(status_label(Status::NOT_RETURNED) === 'Not Returned', 'status_label is correct');
    ok(str_contains(status_badge(Status::NOT_RETURNED), 'text-bg-warning'), 'badge tone set');
    ok(in_array(Status::NOT_RETURNED, Status::CLOSED, true), 'it is a terminal / closed status');

    Database::run(
        'UPDATE leave_records
            SET status = ?, not_returned_reason = NULL, not_returned_date = NULL,
                not_returned_by = NULL, not_returned_remarks = NULL
          WHERE id = ?',
        [$was, $id]
    );
    $back = (string) Database::scalar('SELECT status FROM leave_records WHERE id = ?', [$id]);
    ok($back === $was, "original status restored ($back)");
    Status::sync();
}

/* ------------------------------------------------ B. leave_status ------ */

echo "\n== B. leave_status saves / whitelists / filters ==\n";

$seed = Database::one('SELECT * FROM leave_applications ORDER BY id DESC LIMIT 1');

if ($seed === null) {
    echo "  SKIP  no application to model a new one on\n";
} else {
    $id = LeaveApplication::create([
        'driver_id'             => (int) $seed['driver_id'],
        'leave_type_id'         => (int) $seed['leave_type_id'],
        'branch_id'             => $seed['branch_id'],
        'application_date'      => '2026-09-27',
        'last_working_date'     => '2026-10-05',
        'expected_arrival_date' => '2026-11-05',
        'leave_status'          => 'on_leave',
        'status'                => 'pending',
    ]);

    $saved = Database::one('SELECT * FROM leave_applications WHERE id = ?', [$id]);
    ok(($saved['leave_status'] ?? '') === 'on_leave', 'leave_status persisted as on_leave');

    LeaveApplication::update($id, [
        'driver_id'        => (int) $seed['driver_id'],
        'leave_type_id'    => (int) $seed['leave_type_id'],
        'branch_id'        => $seed['branch_id'],
        'application_date' => '2026-09-27',
        'leave_status'     => 'nonsense-value',
        'status'           => 'pending',
    ]);
    $bad = (string) Database::scalar('SELECT leave_status FROM leave_applications WHERE id = ?', [$id]);
    ok($bad === 'not_started', "unknown value falls back to not_started (got $bad)");

    // Unknown FILTER values must be dropped, never bound or interpolated.
    [$w3, $p3] = LeaveApplication::where(['leave_status' => "x' OR 1=1 --"]);
    ok(!str_contains($w3, 'leave_status'), 'unknown filter value is dropped, not bound');

    // orderBy() returns the ORDER BY clause as a string.
    $sorted = LeaveApplication::orderBy(['leave_status', 'asc']);
    ok(is_string($sorted) && str_contains($sorted, 'la.leave_status'),
        'leave_status is sortable');

    LeaveApplication::delete($id);
    ok(Database::one('SELECT id FROM leave_applications WHERE id = ?', [$id]) === null,
        'test application removed');
}

/* ------------------------------------------- C. not_returned value ------ */

echo "\n== C. 'Not Returned' is a first-class leave status ==\n";

ok(in_array('not_returned', LeaveApplication::LEAVE_STATUSES, true),
    'whitelisted (' . count(LeaveApplication::LEAVE_STATUSES) . ' values)');
ok(status_label('not_returned') === 'Not Returned', 'status_label reads "Not Returned"');
ok(str_contains(status_icon('not_returned'), 'bi-'), 'status_icon has a glyph');
ok(str_contains(status_badge('not_returned'), 'text-bg-'), 'status_badge has a tone');

// The DB ENUM has to have been widened, otherwise writing the value fails.
$enumType = (string) Database::scalar(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'leave_applications'
        AND COLUMN_NAME = 'leave_status'"
);
ok(str_contains($enumType, "'not_returned'"), "DB ENUM includes it (got $enumType)");

$seed2 = Database::one('SELECT * FROM leave_applications ORDER BY id DESC LIMIT 1');

if ($seed2 === null) {
    echo "  SKIP  no application to model one on\n";
} else {
    $id2 = LeaveApplication::create([
        'driver_id'         => (int) $seed2['driver_id'],
        'leave_type_id'     => (int) $seed2['leave_type_id'],
        'branch_id'         => $seed2['branch_id'],
        'application_date'  => '2026-09-27',
        'last_working_date' => '2026-09-01',
        'leave_status'      => 'not_returned',
        'status'            => 'pending',
    ]);

    $got = (string) Database::scalar(
        'SELECT leave_status FROM leave_applications WHERE id = ?',
        [$id2]
    );
    ok($got === 'not_returned', "persists as not_returned (got $got)");

    [$w, $p] = LeaveApplication::where(['leave_status' => 'not_returned']);
    $hits = Database::all('SELECT la.id FROM leave_applications la' . $w . ' LIMIT 500', $p);
    ok(in_array($id2, array_map('intval', array_column($hits, 'id')), true),
        'filter leave_status=not_returned finds it');

    // The filter is a checkbox multi-select, so several values arrive at once.
    [$w2, $p2] = LeaveApplication::where(['leave_status' => 'returned,not_returned']);
    $multi = Database::all('SELECT la.id FROM leave_applications la' . $w2 . ' LIMIT 500', $p2);
    ok(in_array($id2, array_map('intval', array_column($multi, 'id')), true),
        'multi-select "returned,not_returned" still finds it');

    LeaveApplication::delete($id2);
    ok(Database::one('SELECT id FROM leave_applications WHERE id = ?', [$id2]) === null,
        'test application removed');
}

/* --------------------------- D. admin status correction ----------------- */

echo "\n== D. an administrator can reverse a mistaken closure ==\n";

// The route is gated on settings.system - the permission only administrators
// hold by default - so the feature ships without a new permission row.
ok(permission_for_route('api', 'POST', '/leave/change-status') === ['settings.system'],
    'API route is gated on settings.system (administrator-only by default)');

// Modal / JS structural agreement: every cs_* id the view renders must be
// wired in app.js (this box has no JS runtime, so the source is the contract).
$modalSrc = (string) file_get_contents('C:/xampp/htdocs/lms/app/views/partials/modals.php');
$jsSrc    = (string) file_get_contents('C:/xampp/htdocs/lms/assets/js/app.js');
$viewSrc  = (string) file_get_contents('C:/xampp/htdocs/lms/app/views/leave/view.php');

ok(str_contains($modalSrc, 'id="changeStatusModal"'), 'modal shell present');
ok(str_contains($jsSrc, 'data-action="change-status"'), 'JS listens for the trigger');

preg_match_all('/id="(cs_[a-z_]+)"/', $modalSrc, $m);
$csIds = array_unique($m[1]);
$missingJs = [];
foreach ($csIds as $one) {
    if (!str_contains($jsSrc, "'" . $one . "'")) {
        $missingJs[] = $one;
    }
}
ok($csIds !== [] && $missingJs === [],
    'all ' . count($csIds) . ' cs_* ids are referenced by app.js'
    . ($missingJs === [] ? '' : ' (missing: ' . implode(', ', $missingJs) . ')'));

// The original complaint: the details page must show the closure facts.
ok(str_contains($viewSrc, 'not_returned_reason'), 'details banner shows the reason');
ok(str_contains($viewSrc, 'not_returned_remarks'), 'details banner shows the remarks');
ok(str_contains($viewSrc, 'confirmedBy'), 'details banner names who confirmed it');

// ...and the remark must reach the HISTORY, not just the row. Both mark-
// returned paths used to audit the status flip and the arrival date while
// dropping return_remarks, so a later edit left no trace of what was written
// the first time - unlike leave-cancelled and leave-not-returned, which
// already carried their note.
foreach ([
    'LeaveController' => (string) file_get_contents(APP_ROOT . '/app/controllers/LeaveController.php'),
    'LeaveApi'        => (string) file_get_contents(APP_ROOT . '/app/api/LeaveApi.php'),
] as $name => $src) {
    ok((bool) preg_match("/audit_log\('driver-returned'.*?'return_remarks'/s", $src),
        "{$name}: the driver-returned audit entry carries the return remarks");
}

// Behavioural: a fixture closed as not_returned, then corrected both ways.
$seed = Database::one('SELECT * FROM leave_records ORDER BY id DESC LIMIT 1');

if ($seed === null) {
    echo "  SKIP  no record to model a closure on\n";
} else {
    $start    = date('Y-m-d', strtotime('-10 days'));
    $expected = date('Y-m-d', strtotime('+20 days'));
    $actorId  = (int) Database::scalar('SELECT id FROM users ORDER BY id LIMIT 1');
    $_SESSION['user_id'] = $actorId;

    $fxId = Database::insert('leave_records', [
        'driver_id'             => (int) $seed['driver_id'],
        'leave_type_id'         => (int) $seed['leave_type_id'],
        'branch_id'             => $seed['branch_id'] ?? null,
        'leave_start_date'      => $start,
        'expected_arrival_date' => $expected,
        'status'                => Status::NOT_RETURNED,
        'not_returned_reason'   => 'Resigned',
        'not_returned_date'     => date('Y-m-d'),
        'not_returned_by'       => $actorId,
        'not_returned_remarks'  => 'Closed by mistake for this test.',
        'created_by'            => $actorId,
        'updated_by'            => $actorId,
        'created_at'            => now(),
        'updated_at'            => now(),
    ]);

    $fx = LeaveRecord::find($fxId);
    ok($fx !== null && LeaveRecord::canChangeStatus($fx), 'a not_returned record may be status-changed');

    // ------------------------------------------------------------ rules --
    ok(isset(LeaveRecord::validateStatusChange([], $fx)['new_status']),
        'a missing target is refused');
    ok(isset(LeaveRecord::validateStatusChange(['new_status' => 'cancelled'], $fx)['new_status']),
        'a target outside the three is refused');
    ok(isset(LeaveRecord::validateStatusChange(['new_status' => 'returned'], $fx)['actual_arrival_date']),
        'Returned demands an arrival date');
    ok(isset(LeaveRecord::validateStatusChange([
        'new_status'          => 'returned',
        'actual_arrival_date' => date('Y-m-d', strtotime($start . ' -1 day')),
    ], $fx)['actual_arrival_date']), 'an arrival before the leave start is refused');

    // Reopening as On leave once the expected date has passed must demand a
    // NEW expected date instead of silently flipping to Overdue next load.
    Database::run('UPDATE leave_records SET expected_arrival_date = ? WHERE id = ?',
        [date('Y-m-d', strtotime('-1 day')), $fxId]);
    $stale = LeaveRecord::find($fxId);

    ok(isset(LeaveRecord::validateStatusChange(['new_status' => 'on_leave'], $stale)['expected_arrival_date']),
        'a stale expected date is refused for On leave');
    ok(LeaveRecord::validateStatusChange([
        'new_status'            => 'on_leave',
        'expected_arrival_date' => $expected,
    ], $stale) === [], 'a fresh expected date passes for On leave');
    ok(isset(LeaveRecord::validateStatusChange(['new_status' => 'planned'], $stale)['new_status']),
        'Planned is refused once the trip has started');

    // ------------------------------------------- not_returned -> returned --
    Database::run('UPDATE leave_records SET expected_arrival_date = ? WHERE id = ?',
        [$expected, $fxId]);
    $fx    = LeaveRecord::find($fxId);
    $today = date('Y-m-d');

    LeaveRecord::changeStatus($fxId, $fx, [
        'new_status'          => 'returned',
        'actual_arrival_date' => $today,
        'remarks'             => 'Driver reported back.',
    ]);

    $after = LeaveRecord::find($fxId);
    ok((string) $after['status'] === Status::RETURNED, "record reads returned (got {$after['status']})");
    ok((string) $after['actual_arrival_date'] === $today, 'arrival date landed');
    ok($after['not_returned_reason'] === null && $after['not_returned_remarks'] === null,
        'the closure facts are cleared');
    ok((string) $after['return_remarks'] === 'Driver reported back.', 'remarks became return remarks');
    ok(!LeaveRecord::canChangeStatus($after), 'a returned record is no longer eligible');

    Status::sync();
    $synced = (string) Database::scalar('SELECT status FROM leave_records WHERE id = ?', [$fxId]);
    ok($synced === Status::RETURNED, "Status::sync() keeps it returned (got $synced)");

    // --------------------------------- re-close, then reopen as on_leave --
    Database::run(
        'UPDATE leave_records
            SET status = ?, actual_arrival_date = NULL,
                not_returned_reason = "Resigned", not_returned_date = ?, 
                not_returned_remarks = "re-closed for the test"
          WHERE id = ?',
        [Status::NOT_RETURNED, $today, $fxId]
    );
    Database::run('UPDATE leave_records SET not_returned_by = ? WHERE id = ?', [$actorId, $fxId]);
    $fx = LeaveRecord::find($fxId);

    LeaveRecord::changeStatus($fxId, $fx, [
        'new_status'            => 'on_leave',
        'expected_arrival_date' => $expected,
    ]);

    $after = LeaveRecord::find($fxId);
    ok((string) $after['status'] === Status::ON_LEAVE, "record reads on_leave (got {$after['status']})");
    ok((string) $after['expected_arrival_date'] === $expected, 'the extension landed');
    ok($after['not_returned_reason'] === null, 'closure facts cleared again');

    Status::sync();
    $synced = (string) Database::scalar('SELECT status FROM leave_records WHERE id = ?', [$fxId]);
    ok($synced === Status::ON_LEAVE, "Status::sync() keeps it on_leave (got $synced)");

    // Open records stay out of reach.
    ok(isset(LeaveRecord::validateStatusChange(['new_status' => 'on_leave'], $after)['new_status']),
        'an open record cannot be status-changed');

    Database::run('DELETE FROM leave_records WHERE id = ?', [$fxId]);
    ok(Database::one('SELECT id FROM leave_records WHERE id = ?', [$fxId]) === null, 'fixture removed');
}

/* ------------------------------ E. closed for a past departure ---------- */

echo "\n== E. an approved departure past its last working day reads Closed ==\n";

ok(in_array('closed', LeaveApplication::LEAVE_STATUSES, true),
    'whitelisted (' . count(LeaveApplication::LEAVE_STATUSES) . ' values)');
ok(status_label('closed') === 'Closed', 'status_label reads "Closed"');
ok(str_contains(status_icon('closed'), 'bi-'), 'status_icon has a glyph');
ok(str_contains(status_badge('closed'), 'text-bg-'), 'status_badge has a tone');

// A blank status must never look like a real one. status_badge('') used to
// fall through to the default tone + bi-circle and render a grey pill with a
// glyph and NO TEXT - which is exactly what a leave_status column that is
// missing or NULL on a deployed server looks like from the browser.
ok(!str_contains(status_badge(''), 'badge rounded-pill'),
    'an empty status renders no phantom pill');
ok(!str_contains(status_badge(''), 'bi-circle'),
    'and no default glyph');
ok(str_contains(status_badge(''), '&mdash;'),
    'it says nothing, the way the rest of the app says "nothing here"');

// ... and the sweep must be able to REPAIR such a row. The column can be the
// right shape and still hold the ENUM's empty member (a narrower MODIFY
// dropped a value those rows were using). closeExpiredNoReturn() matched only
// "not_started", so it walked straight past a blank and the badge stayed blank
// for ever - "no backfill to remember" quietly stopping being true.
$srcClose = (string) file_get_contents(APP_ROOT . '/app/models/LeaveApplication.php');
ok(str_contains($srcClose, 'la.leave_status = "not_started" OR la.leave_status = ""'),
    'the close branch treats a blank leave_status as not started');
ok(str_contains($srcClose, 'la.leave_status = "closed" OR la.leave_status = ""'),
    'and so does the reopen branch');

$enumType = (string) Database::scalar(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'leave_applications'
        AND COLUMN_NAME = 'leave_status'"
);
ok(str_contains($enumType, "'closed'"), "DB ENUM includes it (got $enumType)");

$seedApp = Database::one('SELECT * FROM leave_applications ORDER BY id DESC LIMIT 1');
$typeRes = (int) Database::scalar(
    'SELECT id FROM leave_types WHERE LOWER(name) LIKE "%resign%" LIMIT 1'
);
$typeVac = (int) Database::scalar(
    'SELECT id FROM leave_types WHERE LOWER(name) LIKE "%vacation%" LIMIT 1'
);

if ($seedApp === null || $typeRes <= 0 || $typeVac <= 0) {
    echo "  SKIP  needs an application plus Resignation and a vacation leave type\n";
} else {
    $mkApp = static function (int $type, string $lwd, string $status = 'approved') use ($seedApp): int {
        return LeaveApplication::create([
            'driver_id'         => (int) $seedApp['driver_id'],
            'leave_type_id'     => $type,
            'branch_id'         => $seedApp['branch_id'],
            'application_date'  => '2031-01-01',
            'last_working_date' => $lwd,
            'leave_status'      => 'not_started',
            'status'            => $status,
        ]);
    };
    $state = static fn (int $id): string => (string) Database::scalar(
        'SELECT leave_status FROM leave_applications WHERE id = ?', [$id]
    );

    $past  = date('Y-m-d', strtotime('-1 day'));
    $ahead = date('Y-m-d', strtotime('+30 days'));

    $cClosed  = $mkApp($typeRes, $past);      // the case asked for
    $cFuture  = $mkApp($typeRes, $ahead);     // window still open
    $cPending = $mkApp($typeRes, $past, 'pending');  // never approved
    $cVac     = $mkApp($typeVac, $past);      // not a departure
    $cLinked  = $mkApp($typeRes, $past);      // a record owns this one

    ok(LeaveApplication::closeExpiredNoReturn() > 0, 'the sweep ran and wrote');

    ok($state($cClosed) === 'closed',
        'approved departure with a passed last working day reads Closed (got '
        . $state($cClosed) . ')');
    ok($state($cFuture) === 'not_started',
        'one whose last working day is still ahead stays Not Started');
    ok($state($cPending) === 'not_started',
        'an unapproved filing is never dressed up as a closed leave');
    ok($state($cVac) === 'not_started',
        'an ordinary vacation is not a departure');

    // A linked record owns the column, so the sweep must walk past it -
    // otherwise this row would be closed despite RECORD_STATUS_MAP.
    $linkedRec = LeaveRecord::create([
        'driver_id'             => (int) $seedApp['driver_id'],
        'leave_type_id'         => $typeRes,
        'leave_application_id'   => $cLinked,
        'leave_start_date'      => $ahead,
        'expected_arrival_date' => null,
    ]);
    LeaveApplication::closeExpiredNoReturn();
    ok($state($cLinked) === 'not_started',
        'an application a leave record owns is left alone (got ' . $state($cLinked) . ')');

    // Derived, not sticky: move the date ahead and it comes back.
    Database::run(
        'UPDATE leave_applications SET last_working_date = ? WHERE id = ?',
        [$ahead, $cClosed]
    );
    LeaveApplication::closeExpiredNoReturn();
    ok($state($cClosed) === 'not_started',
        'moving the last working day ahead reopens it (got ' . $state($cClosed) . ')');

    // Which is what linkError() guards: a shut window cannot be booked.
    Database::run(
        'UPDATE leave_applications SET last_working_date = ? WHERE id = ?',
        [$past, $cClosed]
    );
    LeaveApplication::closeExpiredNoReturn();
    ok($state($cClosed) === 'closed', 'and back to Closed again');
    ok(LeaveApplication::linkError($cClosed, (int) $seedApp['driver_id']) !== null,
        'a closed application refuses to back a leave record');

    // Status::sync() owns the wiring: the rule has to fire from the same
    // page-load housekeeping that reconciles records, not from a call site
    // somebody has to remember.
    Database::run(
        'UPDATE leave_applications SET leave_status = "not_started" WHERE id = ?',
        [$cClosed]
    );
    ok($state($cClosed) === 'not_started', 'reset to Not Started first');
    Status::sync();
    ok($state($cClosed) === 'closed',
        'Status::sync() closes it on an ordinary page load (got ' . $state($cClosed) . ')');

    Database::run('DELETE FROM leave_records WHERE id = ?', [$linkedRec]);
    foreach ([$cClosed, $cFuture, $cPending, $cVac, $cLinked] as $one) {
        LeaveApplication::delete($one);
    }
    ok(Database::one('SELECT id FROM leave_applications WHERE id = ?', [$cClosed]) === null,
        'fixtures removed');
}

/* ------------------ F. a terminal decision closes the leave ------------- */

echo "\n== F. a rejected or cancelled filing reads Closed ==\n";

$decisionSrc = (string) file_get_contents(APP_ROOT . '/app/models/LeaveApplication.php');
ok(str_contains($decisionSrc, 'function closeRejectedOrCancelled(): int'),
    'the decision sweep exists on the model');
ok(str_contains((string) file_get_contents(APP_ROOT . '/app/core/Status.php'),
        'LeaveApplication::closeRejectedOrCancelled()'),
    'and Status::sync() runs it - not a call site somebody has to remember');
ok(str_contains($decisionSrc, 'la.status IN ("pending", "approved")')
    && str_contains($decisionSrc, 'AND NOT ('),
    'its reopen branch is guarded by the date rule, so the two sweeps cannot fight');

$seedF = Database::one('SELECT * FROM leave_applications ORDER BY id DESC LIMIT 1');
$typeFRes = (int) Database::scalar(
    'SELECT id FROM leave_types WHERE LOWER(name) LIKE "%resign%" LIMIT 1'
);
$typeFVac = (int) Database::scalar(
    'SELECT id FROM leave_types WHERE LOWER(name) LIKE "%vacation%" LIMIT 1'
);

if ($seedF === null || $typeFRes <= 0 || $typeFVac <= 0) {
    echo "  SKIP  needs an application plus Resignation and a vacation leave type\n";
} else {
    $fwd = static fn (int $id): string => (string) Database::scalar(
        'SELECT leave_status FROM leave_applications WHERE id = ?', [$id]
    );
    $decide = static function (int $id, string $status): void {
        Database::run('UPDATE leave_applications SET status = ? WHERE id = ?', [$status, $id]);
    };
    $mkF = static function (int $type, string $status, string $lwd) use ($seedF): int {
        return LeaveApplication::create([
            'driver_id'         => (int) $seedF['driver_id'],
            'leave_type_id'     => $type,
            'branch_id'         => $seedF['branch_id'],
            'application_date'  => '2031-01-01',
            'last_working_date' => $lwd,
            'leave_status'      => 'not_started',
            'status'            => $status,
        ]);
    };

    $ahead = date('Y-m-d', strtotime('+30 days'));
    $past  = date('Y-m-d', strtotime('-1 day'));

    $fCancel  = $mkF($typeFVac, 'cancelled', $ahead);
    $fReject  = $mkF($typeFVac, 'rejected',  $ahead);
    $fPend    = $mkF($typeFVac, 'pending',   $ahead);
    $fAppr    = $mkF($typeFVac, 'approved',  $ahead);
    $fRedo    = $mkF($typeFVac, 'cancelled', $ahead);
    $fResGone = $mkF($typeFRes, 'rejected',  $past);
    $fResAhead = $mkF($typeFRes, 'rejected', $ahead);
    $fLinked  = $mkF($typeFVac, 'cancelled', $ahead);

    // The BLANK repair cannot be staged live: the ENUM is strict here, so
    // `SET leave_status = ""` raises 1265 rather than storing the empty
    // member. Such a row only exists where a narrower MODIFY dropped a value
    // it held - which is exactly what the close branch has to tolerate.
    ok(str_contains($decisionSrc,
            'la.leave_status = "not_started" OR la.leave_status = ""'),
        'the close branch treats a blank leave_status as not started');

    ok(LeaveApplication::closeRejectedOrCancelled() > 0, 'the decision sweep ran and wrote');

    ok($fwd($fCancel) === 'closed',
        'Cancelled reads Closed (got ' . $fwd($fCancel) . ')');
    ok($fwd($fReject) === 'closed',
        'Rejected reads Closed (got ' . $fwd($fReject) . ')');
    ok($fwd($fPend) === 'not_started',
        'Pending is still being waited on, so it stays Not Started');
    ok($fwd($fAppr) === 'not_started',
        'an approved filing is not closed by a decision it has not had');

    // Reversibility: status() takes all four decisions from any state, so a
    // row closed by a rejection has to come back when it is unpicked - or a
    // re-approved application would be refused by linkError() for a leave
    // that is very much still open.
    $decide($fCancel, 'pending');
    LeaveApplication::closeRejectedOrCancelled();
    ok($fwd($fCancel) === 'not_started',
        'putting it back to Pending reopens it (got ' . $fwd($fCancel) . ')');

    $decide($fReject, 'approved');
    LeaveApplication::closeRejectedOrCancelled();
    ok($fwd($fReject) === 'not_started',
        're-approving an ordinary leave reopens it (got ' . $fwd($fReject) . ')');

    $decide($fResAhead, 'approved');
    LeaveApplication::closeRejectedOrCancelled();
    ok($fwd($fResAhead) === 'not_started',
        're-approving a departure still ahead of its last working day reopens it (got '
        . $fwd($fResAhead) . ')');

    // ... but not one whose last working day is behind it. The reopen branch
    // must never undo a Closed the DATE rule is entitled to, or flipping a
    // resignation back to approved would reopen a window that has shut.
    $decide($fResGone, 'approved');
    LeaveApplication::closeRejectedOrCancelled();
    ok($fwd($fResGone) === 'closed',
        're-approving a departure past its last working day keeps it Closed (got '
        . $fwd($fResGone) . ')');

    // A linked record owns the column, and status() will not let a linked
    // decision change either - so the sweep must never reach one.
    $recF = LeaveRecord::create([
        'driver_id'             => (int) $seedF['driver_id'],
        'leave_type_id'         => $typeFVac,
        'leave_application_id'  => $fLinked,
        'leave_start_date'      => $ahead,
        'expected_arrival_date' => date('Y-m-d', strtotime('+60 days')),
    ]);
    LeaveApplication::closeRejectedOrCancelled();
    ok($fwd($fLinked) === 'not_started',
        'an application a leave record owns is left alone (got ' . $fwd($fLinked) . ')');

    // The wiring, end to end - exactly as section E proves it for the dates.
    $decide($fRedo, 'cancelled');
    Database::run(
        'UPDATE leave_applications SET leave_status = "not_started" WHERE id = ?', [$fRedo]
    );
    ok($fwd($fRedo) === 'not_started', 'reset to Not Started first');
    Status::sync();
    ok($fwd($fRedo) === 'closed',
        'Status::sync() closes it on an ordinary page load (got ' . $fwd($fRedo) . ')');

    Database::run('DELETE FROM leave_records WHERE id = ?', [$recF]);
    foreach ([$fCancel, $fReject, $fPend, $fAppr, $fRedo,
              $fResGone, $fResAhead, $fLinked] as $one) {
        LeaveApplication::delete($one);
    }
    ok(Database::one('SELECT id FROM leave_applications WHERE id = ?', [$fCancel]) === null,
        'fixtures removed');
}

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

