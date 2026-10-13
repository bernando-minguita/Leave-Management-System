<?php
/**
 * Leave <-> Leave Application linking (requirements 1-5).
 *
 *   php C:/xampp/htdocs/lms/tools/leave-link-test.php
 *
 * What must stay true:
 *   1. The schema carries the link: leave_records.leave_application_id with a
 *      unique key (one application -> one record) and a foreign key.
 *   2. Only an approved, not-started, unlinked application for the SAME driver
 *      may back a record - everything else is refused with a readable reason.
 *   3. The record owns the truth: its status flows into the application's
 *      leave_status through ONE mapping (LeaveApplication::RECORD_STATUS_MAP)
 *      on create, on every transition, and unlinking hands the application
 *      back to "not started".
 *   4. Creating a record DEMANDS the link: validateLink() refuses a blank
 *      leave_application_id on create (the API does the same), while editing
 *      stays permissive so a legacy record with no application can still be
 *      corrected. The form carries all seven fields across from the
 *      application: leave type, branch, last working date, expected return,
 *      destination country, reason, remarks.
 *
 * Fixtures wear badge LNKD-* / type LNKD FIX * and are removed whatever
 * happens, so the live data is left exactly as it was found.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\LeaveApplication;
use App\Models\LeaveRecord;

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

/* ---------------------------------------------------------- fixtures --- */

$cleanup = static function (): void {
    foreach (['leave_records', 'leave_applications'] as $table) {
        Database::run(
            "DELETE {$table} FROM {$table}
               JOIN drivers d ON d.id = {$table}.driver_id
              WHERE d.badge_number LIKE 'LNKD-%'"
        );
    }
    Database::run("DELETE FROM drivers WHERE badge_number LIKE 'LNKD-%'");
    Database::run("DELETE FROM leave_types WHERE name LIKE 'LNKD FIX %'");
};

$cleanup();
register_shutdown_function($cleanup);

$typeId = Database::insert('leave_types', ['name' => 'LNKD FIX ANNUAL', 'status' => 'active']);

$mkDriver = static function (string $badge): int {
    return Database::insert('drivers', [
        'badge_number' => $badge,
        'driver_name'  => 'LNKD FIX DRIVER ' . $badge,
        'iqama_number' => 'IQ' . $badge,
        'status'       => 'active',
        'joining_date' => '2020-01-01',
    ]);
};

$driverId      = $mkDriver('LNKD-01');
$otherDriverId = $mkDriver('LNKD-02');

$mkApp = static function (int $driver, string $status, string $leaveStatus) use ($typeId): int {
    return Database::insert('leave_applications', [
        'driver_id'             => $driver,
        'leave_type_id'         => $typeId,
        'application_date'      => date('Y-m-d'),
        'last_working_date'     => date('Y-m-d', strtotime('+1 day')),
        'expected_arrival_date' => date('Y-m-d', strtotime('+12 days')),
        'status'                => $status,
        'leave_status'          => $leaveStatus,
    ]);
};

$leaveStatusOf = static function (int $appId): string {
    return (string)LeaveApplication::find($appId)['leave_status'];
};

/* ----------------------------------------------------------- 1. schema -- */

echo "== 1. schema ==\n";

$col = Database::one("SHOW COLUMNS FROM leave_records LIKE 'leave_application_id'");
ok($col !== null, 'leave_records.leave_application_id exists');
ok($col !== null && str_contains(strtolower((string)$col['Type']), 'int'),
    'it is an integer column (got ' . (string)($col['Type'] ?? 'missing') . ')');
ok($col !== null && stripos((string)$col['Null'], 'yes') === 0,
    'and it is nullable - a record recorded directly simply has no application');

$ddl = (string)(Database::one('SHOW CREATE TABLE leave_records')['Create Table'] ?? '');
ok(str_contains($ddl, 'uq_leave_application'),
    'the one-application-one-record unique key is on the table');
ok(str_contains($ddl, 'fk_leave_application'),
    'the foreign key to leave_applications is on the table');

/* --------------------------------------------------------- 2. the map -- */

echo "\n== 2. the record -> application status map ==\n";

$map = LeaveApplication::RECORD_STATUS_MAP;

ok(($map['planned'] ?? '') === 'not_started', 'planned -> not started');
ok(($map['on_leave'] ?? '') === 'on_leave' && ($map['overdue'] ?? '') === 'on_leave',
    'on_leave and overdue -> on leave');
ok(($map['returned'] ?? '') === 'returned', 'returned -> returned');
ok(($map['not_returned'] ?? '') === 'not_returned', 'not returned -> not returned');
ok(($map['cancelled'] ?? '') === 'not_started',
    'cancelled (or deleted) -> not started, so the application is usable again');

/* -------------------------------------------------- 3. what may be linked - */

echo "\n== 3. only a fresh approved application may back a record ==\n";

$openApp    = $mkApp($driverId, 'approved', 'not_started');
$pendingApp = $mkApp($driverId, 'pending', 'not_started');
$otherApp   = $mkApp($otherDriverId, 'approved', 'not_started');

// The application is filed with its OWN expected return (+12 days); the
// record below is created with a different one (+14) to prove the link
// REPLACES the filed date rather than leaving it on the register.
$openAppFiled = (string)LeaveApplication::find($openApp)['expected_arrival_date'];
ok($openAppFiled === date('Y-m-d', strtotime('+12 days')),
    'the application carries the expected return it was filed with');

$offered = array_column(LeaveApplication::openForDriver($driverId), 'id');

ok(in_array($openApp, $offered, true), 'an approved, not-started, unlinked application is offered');
ok(!in_array($pendingApp, $offered, true), 'a pending application is not offered');
ok(!in_array($otherApp, $offered, true), "another driver's application is not offered");

ok(LeaveApplication::linkError($openApp, $driverId) === null,
    'the open application may back a record');
ok(LeaveApplication::linkError(99999999, $driverId) !== null,
    'a missing application is refused');
ok(LeaveApplication::linkError($openApp, $otherDriverId) !== null,
    "another driver's application is refused");
ok(LeaveApplication::linkError($pendingApp, $driverId) !== null,
    'a pending application is refused');

/* ------------------------------------------------- 4. create with a link -- */

echo "\n== 4. creating the record links it and starts the follow-through ==\n";

$recId = LeaveRecord::create([
    'driver_id'             => $driverId,
    'leave_type_id'         => $typeId,
    'leave_application_id'  => $openApp,
    'leave_start_date'      => date('Y-m-d', strtotime('+2 days')),
    'expected_arrival_date' => date('Y-m-d', strtotime('+14 days')),
]);

$rec = LeaveRecord::find($recId);

ok($rec !== null && (int)$rec['leave_application_id'] === $openApp,
    'the record stores the application it came from');
ok($rec !== null && (int)($rec['application_id'] ?? 0) === $openApp,
    'the list/view query joins the application id through');
ok($rec !== null && (string)$rec['status'] === 'planned', 'a future trip derives as planned');
ok($leaveStatusOf($openApp) === 'not_started',
    'and the application stays Not started (requirement 3, planned branch)');
ok((string)LeaveApplication::find($openApp)['expected_arrival_date'] === date('Y-m-d', strtotime('+14 days')),
    'and the application expected return is REPLACED by the record\'s (+14, not the filed +12)');

$offeredAfter = array_column(LeaveApplication::openForDriver($driverId), 'id');
ok(!in_array($openApp, $offeredAfter, true), 'a used application is no longer offered for a new leave');
ok(LeaveApplication::linkError($openApp, $driverId, $recId) === null,
    'the record being edited may keep its own link');
ok(LeaveApplication::linkError($openApp, $driverId) !== null,
    'a second record may not claim the same application');

/* -------------------------------------------- 5. every transition follows - */

echo "\n== 5. every status change flows into the application ==\n";

// Underway: started in the past, still expected back.
$app2   = $mkApp($driverId, 'approved', 'not_started');
$start2 = date('Y-m-d', strtotime('-2 days'));
$rec2   = LeaveRecord::create([
    'driver_id'             => $driverId,
    'leave_type_id'         => $typeId,
    'leave_application_id'  => $app2,
    'leave_start_date'      => $start2,
    'expected_arrival_date' => date('Y-m-d', strtotime('+8 days')),
]);

ok((string)LeaveRecord::find($rec2)['status'] === 'on_leave', 'the trip is underway');
ok($leaveStatusOf($app2) === 'on_leave', 'an underway trip flips the application to On leave');

LeaveRecord::markReturned($rec2, date('Y-m-d'), null, 'back on time');
ok((string)LeaveRecord::find($rec2)['status'] === 'returned', 'the record is returned');
ok($leaveStatusOf($app2) === 'returned', 'marking returned follows through to the application');

// Not returned: the closure needs reason, date and remarks.
$app3 = $mkApp($driverId, 'approved', 'not_started');
$rec3 = LeaveRecord::create([
    'driver_id'             => $driverId,
    'leave_type_id'         => $typeId,
    'leave_application_id'  => $app3,
    'leave_start_date'      => date('Y-m-d', strtotime('-3 days')),
    'expected_arrival_date' => date('Y-m-d', strtotime('+5 days')),
]);
$closure = [
    'not_returned_reason'  => 'Resigned',
    'not_returned_date'    => date('Y-m-d'),
    'not_returned_remarks' => 'Confirmed by management.',
];
ok(LeaveRecord::validateNotReturned($closure, LeaveRecord::find($rec3)) === [],
    'the closure details validate');

LeaveRecord::markNotReturned($rec3, $closure);
ok((string)LeaveRecord::find($rec3)['status'] === 'not_returned', 'the record is closed as not returned');
ok($leaveStatusOf($app3) === 'not_returned', 'and the application says Not returned');

// Cancelled: nothing happened, so the application is usable again.
$app4 = $mkApp($driverId, 'approved', 'not_started');
$rec4 = LeaveRecord::create([
    'driver_id'             => $driverId,
    'leave_type_id'         => $typeId,
    'leave_application_id'  => $app4,
    'leave_start_date'      => date('Y-m-d', strtotime('+3 days')),
    'expected_arrival_date' => date('Y-m-d', strtotime('+13 days')),
]);
LeaveRecord::cancel($rec4, 'Plans changed.');
ok((string)LeaveRecord::find($rec4)['status'] === 'cancelled', 'the record is cancelled');
ok($leaveStatusOf($app4) === 'not_started',
    'a cancelled trip hands the application back to Not started');

/* --------------------------------------------------- 6. unlinking ------- */

echo "\n== 6. unlinking hands the application back ==\n";

LeaveRecord::update($rec2, [
    'driver_id'             => $driverId,
    'leave_type_id'         => $typeId,
    'leave_application_id'  => null,
    'leave_start_date'      => $start2,
    'expected_arrival_date' => date('Y-m-d', strtotime('+8 days')),
]);

ok(LeaveRecord::find($rec2)['leave_application_id'] === null,
    'the link can be cleared on an edit');
ok($leaveStatusOf($app2) === 'not_started',
    'clearing the link resets the application so it may be applied for again');
ok((string)LeaveApplication::find($app2)['expected_arrival_date'] === date('Y-m-d', strtotime('+8 days')),
    'unlinking keeps the copied expected return - the trip\'s dates are history');

// The controller calls this after deleting a linked record; the helper is the
// single place that owns the reset.
LeaveApplication::resetLeaveStatus($app3);
ok($leaveStatusOf($app3) === 'not_started', 'resetLeaveStatus hands an application back');
ok(LeaveApplication::linkError($app2, $driverId) === null,
    'a handed-back application may back the next record');

/* ------------------------------ 7. edits never blank the column -------- */

echo "\n== 7. editing an application cannot blank its leave status ==\n";

// Put a value on the column, then edit the application WITHOUT mentioning
// it - the old code silently reset leave_status to "not started" on every
// save, which contradicted requirement 3 the moment a record was linked.
Database::run('UPDATE leave_applications SET leave_status = "returned" WHERE id = ?', [$app2]);

$edit = [
    'driver_id'             => $driverId,
    'leave_type_id'         => $typeId,
    'application_date'      => date('Y-m-d'),
    'last_working_date'     => null,
    'expected_arrival_date' => null,
    'status'                => 'approved',
];

LeaveApplication::update($app2, $edit);
ok($leaveStatusOf($app2) === 'returned',
    'an edit that does not mention the column keeps the stored value');

LeaveApplication::update($app2, $edit + ['leave_status' => 'on_leave']);
ok($leaveStatusOf($app2) === 'on_leave', 'a supplied value is still written');

LeaveApplication::update($app2, $edit + ['leave_status' => '']);
ok($leaveStatusOf($app2) === 'on_leave', 'a blank value never resets it');

/* -------------------------- 8. the record's dates follow through ------- */

echo "\n== 8. moving the record's expected return moves the application's ==\n";

// The record owns the trip: editing its expected return moves the linked
// application's date too, and every later transition re-states the record's
// date instead of falling back to the one the application was filed with.
// The start moves to today so the trip has begun - a mark-returned with an
// arrival before the leave start would (correctly) be refused by the table's
// chk_leave_dates check.
LeaveRecord::update($recId, [
    'driver_id'             => $driverId,
    'leave_type_id'         => $typeId,
    'leave_start_date'      => date('Y-m-d'),
    'expected_arrival_date' => date('Y-m-d', strtotime('+20 days')),
]);

ok((string)LeaveApplication::find($openApp)['expected_arrival_date'] === date('Y-m-d', strtotime('+20 days')),
    'an edit to the record moves the application expected return');
ok($leaveStatusOf($openApp) === 'on_leave',
    'the edit\'s derived status follows the record as well (planned -> on leave)');

LeaveRecord::markReturned($recId, date('Y-m-d'), null, null);

ok((string)LeaveApplication::find($openApp)['expected_arrival_date'] === date('Y-m-d', strtotime('+20 days')),
    'marking returned re-states the record\'s expected return');
ok($leaveStatusOf($openApp) === 'returned', 'and flips the application to Returned');

/* ------------------------------------- 9. the screens are wired up ----- */

echo "\n== 9. the screens that do the linking are wired up ==\n";

$leaveForm = (string)file_get_contents(APP_ROOT . '/app/views/leave/form.php');
ok(str_contains($leaveForm, 'name="driver_id" id="driver_id"'),
    'the EDIT screen exposes driver_id to the picker (legacy record -> application)');
ok(str_contains($leaveForm, 'applicationPicker'), 'the form carries the application search');
ok(str_contains($leaveForm, 'name="leave_application_id"'), 'and the hidden link field');

// That fallback copy could never be trusted: the block carried `hidden`, but
// `.d-block` (needed to beat .invalid-feedback's display:none) is equal
// specificity and !important, and Bootstrap loads the utilities after the
// reboot - so .d-block won the tie and the label showed on every open of the
// form. The block is now rendered only when the server rejected the link.
ok(!str_contains($leaveForm, 'That application is no longer available'),
    'the unreachable fallback copy is gone');
ok(!preg_match('/id="applicationError"[^>]*\bhidden\b/s', $leaveForm),
    'the error block no longer relies on [hidden], which .d-block outranks');

$js = (string)file_get_contents(APP_ROOT . '/assets/js/leave.js');
ok(str_contains($js, 'driverValue()'),
    'the picker resolves the driver with or without an id on the field');

$applicationForm = (string)file_get_contents(APP_ROOT . '/app/views/applications/form.php');
ok(str_contains($applicationForm, 'linked_leave_id'),
    'a linked application shows a Leave Status that follows its record (read-only)');

/* ------------------------------ 8. the list reads the link, not the enum - */

echo "\n== 8. a linked planned trip reads Planned on the list ==\n";

// The stored rule is untouched by the display change: planned still maps to
// not_started. The enum has since gained 'closed' (an approved departure past
// its last working day - leave-status-test section E), but "planned" is still
// not one of them: the list only READS the link, so every assertion above
// about the mapping still stands.
ok(LeaveApplication::RECORD_STATUS_MAP['planned'] === 'not_started',
    'RECORD_STATUS_MAP still sends planned -> not_started (unchanged)');
ok(count(LeaveApplication::LEAVE_STATUSES) === 5
    && !in_array('planned', LeaveApplication::LEAVE_STATUSES, true),
    'the leave_status enum holds five values and none of them is "planned"');
ok(str_contains(LeaveApplication::SELECT, 'lr.status AS linked_record_status'),
    'the list query carries the linked record status it needs');

// A fixture of its own: the application from section 4 has long since moved
// on, so borrowing it would assert against whatever it became.
$viewDriver = $mkDriver('LNKD-VIEW');
$viewApp    = $mkApp($viewDriver, 'approved', 'not_started');
$viewRec    = LeaveRecord::create([
    'driver_id'             => $viewDriver,
    'leave_type_id'         => $typeId,
    'leave_application_id'  => $viewApp,
    'leave_start_date'      => date('Y-m-d', strtotime('+5 days')),
    'expected_arrival_date' => date('Y-m-d', strtotime('+15 days')),
]);

$plannedRow = LeaveApplication::find($viewApp);
ok($plannedRow !== null && (string)$plannedRow['linked_record_status'] === 'planned',
    'the joined row carries the linked record status');
ok(LeaveApplication::hasPlannedTrip($plannedRow) === true,
    'a linked planned record is detected');
ok(LeaveApplication::displayLeaveStatus($plannedRow) === 'Planned',
    'and the cell reads "Planned" (got "'
    . LeaveApplication::displayLeaveStatus($plannedRow) . '")');
ok((string)$plannedRow['leave_status'] === 'not_started',
    'while the stored column is still not_started - nothing was rewritten');
ok(LeaveRecord::find((int)$viewRec)['status'] === 'planned'
    && (string)$plannedRow['leave_status'] === 'not_started',
    'the two registers still disagree exactly as RECORD_STATUS_MAP intends');

// An application with nothing booked must be untouched.
$bareRow = ['leave_status' => 'not_started', 'linked_record_status' => null];
ok(LeaveApplication::hasPlannedTrip($bareRow) === false,
    'no linked record is not a planned trip');
ok(LeaveApplication::displayLeaveStatus($bareRow) === 'Not Started',
    'and it still reads "Not Started"');

// Same helper on both surfaces: the screen and the CSV can never disagree.
$indexView = (string)file_get_contents(APP_ROOT . '/app/views/applications/index.php');
$exportSrc = (string)file_get_contents(APP_ROOT . '/app/controllers/LeaveApplicationController.php');
ok(str_contains($indexView, 'hasPlannedTrip'),
    'the list screen asks the shared predicate');
ok(str_contains($exportSrc, 'displayLeaveStatus'),
    'the CSV export uses the same helper');
ok(!str_contains($indexView, 'linked_record_start'),
    'no date is appended anywhere - it is the status alone');

/* --------------------- 10. a NEW leave must carry the link ---------------- */

echo "\n== 10. a new leave cannot be created without its application ==\n";

// The rule lives in LeaveController::validateLink(): blank is a failure on
// CREATE, and stays legal on EDIT - a legacy record that was filed without an
// application has to remain correctable, and its link may be cleared.
$controller = new \App\Controllers\LeaveController();
$validate   = new ReflectionMethod($controller, 'validateLink');
$validate->setAccessible(true);

$checkLink = static function (array $input, ?int $recordId) use ($controller, $validate): \App\Core\Validator {
    $v = new \App\Core\Validator($input);
    $validate->invoke($controller, $v, $recordId);

    return $v;
};

$openFresh = $mkApp($driverId, 'approved', 'not_started');

$v = $checkLink(['driver_id' => $driverId, 'leave_application_id' => ''], null);
ok($v->fails() && isset($v->errors()['leave_application_id']),
    'a create with no application is refused');
ok(str_contains($v->errors()['leave_application_id'] ?? '', 'cannot be created without one'),
    'with a message that says why (got "' . ($v->errors()['leave_application_id'] ?? '') . '")');

$v = $checkLink(['driver_id' => $driverId, 'leave_application_id' => '0'], null);
ok($v->fails(), 'a zero id counts as no application');

$v = $checkLink(['driver_id' => $driverId, 'leave_application_id' => (string)$openFresh], null);
ok(!$v->fails(), 'a fresh approved application of the same driver is accepted');

$v = $checkLink(['driver_id' => $driverId, 'leave_application_id' => ''], 4242);
ok(!$v->fails(), 'an EDIT may carry no application (legacy records stay correctable)');

$v = $checkLink(['driver_id' => $driverId, 'leave_application_id' => (string)$openApp], $recId);
ok(!$v->fails(), 'and a record being edited keeps its own link');

// The screen: the picker is a requirement now, not an offer.
ok(!str_contains($leaveForm, '(optional)'),
    'the picker no longer offers itself as optional');
ok(str_contains($leaveForm, 'Approved application <span class="text-danger">*</span>'),
    'and is marked as a required field');
ok(str_contains($leaveForm, 'No approved application, no leave record.'),
    'the form states the rule in words');
ok(str_contains($leaveForm, 'id="applicationRequired"'),
    'and has somewhere for the browser to put the refusal');

// All seven fields come across from the application, not six.
$leaveCtrl = (string)file_get_contents(APP_ROOT . '/app/controllers/LeaveController.php');
$prefill = '';
if (preg_match('/if \(\$application !== null && \$old === \[\]\) \{(.*?)\n        \}/s', $leaveCtrl, $m) === 1) {
    $prefill = $m[1];
}
ok($prefill !== '', 'the create prefill reads from the application');

foreach (['leave_type_id', 'branch_id', 'last_working_date', 'expected_arrival_date',
         'destination', 'reason', 'remarks'] as $field) {
    ok(str_contains($prefill, "'" . $field . "'"),
        "the prefill carries the application's $field");
}

// The same rule on the API path, and the branch the picker needs to offer it.
$api = (string)file_get_contents(APP_ROOT . '/app/api/LeaveApi.php');
ok(str_contains($api, '$applicationId <= 0'),
    'POST /api/leave/create refuses a payload with no application');
ok(str_contains($api, '$row[\'branch_id\']'),
    'the application picker carries the branch as well');

// And the browser refuses the click instead of letting the server do it.
ok(str_contains($js, 'applicationRequired'),
    'leave.js names the missing link on submit');
ok(str_contains($js, 'editId() === 0 && linkInput'),
    'but only on create - an edit of a legacy record is not blocked');
ok(str_contains($js, 'fillBranch(app.branch_id)'),
    'picking an application puts its branch on the form');

printf("\nRESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);


