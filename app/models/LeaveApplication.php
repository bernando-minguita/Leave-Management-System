<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Status;

/**
 * Leave / vacation applications.
 *
 * A standalone register of requests and the decision taken on them. It never
 * reads or writes leave_records, so nothing here reaches the dashboard, the
 * calendar or the expected-returns panels.
 *
 * branch_id is a snapshot recorded with the application - the same rule as on
 * leave_records - so moving a driver to another branch later cannot change
 * what the application said at the time.
 */
final class LeaveApplication
{
    /** @var string[] */
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    /**
     * Whether the LEAVE itself has begun - a different question from the
     * approval decision above, which is why it gets its own column.
     *
     * Stored rather than derived so it can be chosen when the application is
     * recorded; every value is whitelisted before it reaches the ENUM.
     *
     *   not_started   the leave has not begun yet
     *   on_leave      the driver is away right now
     *   returned      the driver came back
     *   not_returned  the leave happened and the driver has not come back
     *   closed        nothing is outstanding, on either axis. Derived, never
     *                 typed, by two sweeps Status::sync() runs on every page
     *                 load: closeExpiredNoReturn() closes an approved
     *                 departure whose last working day has passed (a no-return
     *                 type never produces a leave record - Requirement 9 keeps
     *                 deactivated drivers off that path - so nothing else would
     *                 ever move it off "Not Started"), and
     *                 closeRejectedOrCancelled() closes one whose decision was
     *                 Rejected or Cancelled, where no leave will happen at all.
     *                 Without that second sweep the row read "Not Started"
     *                 for ever, which says "still waiting" about a filing
     *                 nobody is waiting on any more.
     *
     * @var string[]
     */
    public const LEAVE_STATUSES = ['not_started', 'on_leave', 'returned', 'not_returned', 'closed'];

    /**
     * How a leave record's status translates into this register's leave_status.
     *
     * The record owns the truth (it is derived by the status engine from the
     * dates, never typed), so wherever the two meet the record wins and this
     * map is the ONLY place the translation lives:
     *
     *   planned       -> not_started   the trip has not begun
     *   on_leave      -> on_leave      away right now
     *   overdue       -> on_leave      away right now (just late)
     *   returned      -> returned      came back
     *   not_returned  -> not_returned  closed without an arrival
     *   cancelled     -> not_started   nothing happened; the application is
     *                                  usable again (or the record was deleted)
     *
     * An application with no record is never derived from dates - it keeps
     * whatever was chosen on it, default "not_started".
     *
     * @var array<string, string>
     */
    public const RECORD_STATUS_MAP = [
        'planned'      => 'not_started',
        'on_leave'     => 'on_leave',
        'overdue'      => 'on_leave',
        'returned'     => 'returned',
        'not_returned' => 'not_returned',
        'cancelled'    => 'not_started',
    ];

    /**
     * Leave types whose driver never comes back, so a record or an application
     * of one of them records no Expected Return Date - the form hides that
     * field (with Ticket Date and Duration beside it) and the controller drops
     * whatever was posted.
     *
     * Matched against the leave type NAME, the way
     * CapacityPlanningService::EXIT_PATTERNS identifies a resignation exit, so
     * an administrator who renames a type or adds "Resignation (final)" keeps
     * the rule. All three departure names are here: Resignation, Terminated
     * and Absconded. Absconded was originally excluded - round 6 item 2 named
     * only the two, treating it as a population exit rather than a date to
     * suppress - and was folded in when the New Leave / Vacation form was
     * asked for the same three fields on all three types. assets/js/leave.js
     * carries the mirror of this list and the two must stay in step.
     *
     * @var string[]
     */
    public const NO_RETURN_PATTERNS = ['%resign%', '%terminat%', '%abscond%'];

    /**
     * Ids of the leave types that never carry an expected return.
     *
     * @return int[]
     */
    public static function noReturnTypeIds(): array
    {
        $like = implode(' OR ', array_fill(0, count(self::NO_RETURN_PATTERNS), 'LOWER(lt.name) LIKE ?'));
        $rows = Database::all(
            'SELECT lt.id FROM leave_types lt WHERE ' . $like,
            self::NO_RETURN_PATTERNS
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * The expected return date a record or application of this type may keep.
     *
     * Resignation, Terminated and Absconded drivers never come back, so the
     * form hides the field and whatever was posted is dropped here instead of
     * being stored - a value picked before the leave type was switched cannot
     * survive the round trip (round 6, item 2).
     *
     * @param int      $leaveTypeId
     * @param string|null $posted normalised Y-m-d or null
     */
    public static function expectedArrivalFor(int $leaveTypeId, ?string $posted): ?string
    {
        if ($posted === null || $posted === '') {
            return null;
        }

        return in_array($leaveTypeId, self::noReturnTypeIds(), true) ? null : $posted;
    }

    /**
     * Columns the date range may be applied to. The label doubles as the
     * whitelist for any request-supplied value, so the column name reaching
     * the SQL is never taken from user input.
     */
    public const DATE_FIELDS = [
        'application_date'     => 'Application Date',
        'last_working_date'    => 'Last Working Date',
        'expected_arrival_date'=> 'Expected Return Date',
    ];

    public const SELECT = 'SELECT la.*,
                d.badge_number, d.driver_name, d.iqama_number,
                d.status AS driver_status,
                lt.name AS leave_type,
                lr.id AS linked_leave_id,
                lr.status AS linked_record_status,
                b.branch_name,
                cu.full_name AS created_by_name';

    public const FROM = ' FROM leave_applications la
                JOIN drivers d       ON d.id  = la.driver_id
                JOIN leave_types lt  ON lt.id = la.leave_type_id
           LEFT JOIN leave_records lr ON lr.leave_application_id = la.id
           LEFT JOIN branches b      ON b.id  = la.branch_id
           LEFT JOIN users cu        ON cu.id = la.created_by';

    /** Columns a user may sort the list by. */
    public const SORTABLE = [
        'application_date'      => 'la.application_date',
        'expected_arrival_date' => 'la.expected_arrival_date',
        'last_working_date'     => 'la.last_working_date',
        'driver_name'           => 'd.driver_name',
        'badge_number'          => 'd.badge_number',
        'leave_type'            => 'lt.name',
        'branch'                => 'b.branch_name',
        'status'                => 'la.status',
        'leave_status'          => 'la.leave_status',
        'created_at'            => 'la.created_at',
        'updated_at'            => 'la.updated_at',
        // Sorts on the JOINED creator name (LEFT JOIN users cu), so a record
        // whose creator was deleted still sorts - it lands with the NULLs.
        'created_by_name'       => 'cu.full_name',
    ];

    /**
     * WHERE fragment + bound parameters for the list.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function where(array $f): array
    {
        $sql    = ' WHERE 1 = 1';
        $params = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $needle = wildcard_needle($q);
            $sql   .= ' AND (d.driver_name LIKE ? OR d.badge_number LIKE ? OR d.iqama_number LIKE ?)';
            array_push($params, $needle, $needle, $needle);
        }

        // Approval status is a checkbox multi-select: "pending,approved" must
        // filter on both, and anything outside the whitelist is dropped.
        $statuses = array_values(array_intersect(
            filter_values($f['status'] ?? ''),
            self::STATUSES
        ));

        if ($statuses !== []) {
            $sql    .= ' AND la.status IN ' . sql_in($statuses);
            $params  = array_merge($params, $statuses);
        }

        // Leave status is a separate axis from the approval decision above.
        $leaveStatuses = array_values(array_intersect(
            filter_values($f['leave_status'] ?? ''),
            self::LEAVE_STATUSES
        ));

        if ($leaveStatuses !== []) {
            $sql    .= ' AND la.leave_status IN ' . sql_in($leaveStatuses);
            $params  = array_merge($params, $leaveStatuses);
        }

        $branchIds = id_filter((string)($f['branch_id'] ?? ''));
        if ($branchIds !== []) {
            $sql .= ' AND la.branch_id IN ' . sql_in($branchIds);
            array_push($params, ...$branchIds);
        }

        $typeIds = id_filter((string)($f['leave_type_id'] ?? ''));
        if ($typeIds !== []) {
            $sql .= ' AND la.leave_type_id IN ' . sql_in($typeIds);
            array_push($params, ...$typeIds);
        }

        // Date range on whichever bases the user ticked - application date,
        // last working date and/or expected return. Only whitelisted column
        // names are used and only validated ISO dates are bound, so a crafted
        // query string can reach neither.
        //
        // NULL columns simply evaluate to NULL and drop out of the OR, which
        // is what the old `IS NOT NULL` guard was there to guarantee.
        $fields = array_values(array_intersect(
            filter_values($f['date_field'] ?? ''),
            array_keys(self::DATE_FIELDS)
        ));

        // Nothing ticked means Last Working Date, matching the default the
        // filter shows ticked on the list page.
        if ($fields === []) {
            $fields = ['last_working_date'];
        }

        $columns = array_map(static fn (string $k): string => 'la.' . $k, $fields);

        [$range, $rangeParams] = date_range_sql(
            $columns,
            self::isoDate((string) ($f['date_from'] ?? '')),
            self::isoDate((string) ($f['date_to'] ?? ''))
        );

        $sql   .= $range;
        $params = array_merge($params, $rangeParams);

        return [$sql, $params];
    }

    /** Accept a real ISO date; anything else must filter nothing at all. */
    private static function isoDate(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false
            ? $value
            : '';
    }

    /** Whitelist the sort column and direction. */
    public static function orderBy(array $sort): string
    {
        $column = (string)($sort[0] ?? '');
        $dir    = strtolower((string)($sort[1] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $sql    = self::SORTABLE[$column] ?? self::SORTABLE['application_date'];

        return ' ORDER BY ' . $sql . ' ' . $dir . ', la.id DESC';
    }

    /** @return array{rows: array<int, array<string, mixed>>, total: int} */
    public static function paginate(array $filters, array $sort, int $limit, int $offset): array
    {
        [$where, $params] = self::where($filters);

        $total = (int) Database::scalar('SELECT COUNT(*)' . self::FROM . $where, $params);

        $rows = Database::all(
            self::SELECT . self::FROM . $where . self::orderBy($sort)
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(array $filters, array $sort): array
    {
        [$where, $params] = self::where($filters);

        return Database::all(self::SELECT . self::FROM . $where . self::orderBy($sort), $params);
    }

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = Database::one(self::SELECT . self::FROM . ' WHERE la.id = ?', [$id]);

        return ($row === null || $row === false) ? null : (array) $row;
    }

    /** @return array<string, int> status => count, for the filter chips. */
    public static function counts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);

        foreach (Database::all('SELECT status, COUNT(*) AS c FROM leave_applications GROUP BY status') as $row) {
            $key = (string) $row['status'];
            if (isset($out[$key])) {
                $out[$key] = (int) $row['c'];
            }
        }

        return $out;
    }

    /* ---------------------------------------------------- record link --- */

    /**
     * Applications this driver may still apply a leave against: approved,
     * the leave has not started, and no leave record points at them yet.
     *
     * "Approved + not started" is the rule from the brief: every approved
     * application still waiting for its trip gets an Apply New Leave action,
     * and once a record exists that action is gone (one application, one
     * record - enforced by the unique key as well).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function openForDriver(int $driverId, string $q = '', int $limit = 20): array
    {
        $where = ' WHERE la.driver_id = ?
                    AND la.status = "approved"
                    AND la.leave_status = "not_started"
                    AND NOT EXISTS (
                        SELECT 1 FROM leave_records lrx
                         WHERE lrx.leave_application_id = la.id
                    )';
        $params = [$driverId];

        $needle = trim($q);

        if ($needle !== '') {
            $where   .= ' AND (lt.name LIKE ? OR la.destination LIKE ?
                               OR la.reason LIKE ? OR la.application_date LIKE ?)';
            $pattern  = wildcard_needle($needle);
            array_push($params, $pattern, $pattern, $pattern, $pattern);
        }

        return Database::all(
            self::SELECT . self::FROM . $where
            . ' ORDER BY la.application_date DESC, la.id DESC'
            . ' LIMIT ' . max(1, $limit),
            $params
        );
    }

    /** The leave record this application already backs, if any. */
    public static function linkedRecordId(int $applicationId): ?int
    {
        $id = Database::scalar(
            'SELECT id FROM leave_records WHERE leave_application_id = ? LIMIT 1',
            [$applicationId]
        );

        return ($id === null || $id === false) ? null : (int) $id;
    }

    /**
     * Why this application cannot back a leave record - null when it can.
     *
     * Called from the leave form (and the JSON API) before the record is
     * written, so a stale tab cannot link a rejected application, another
     * driver's application, or one that already produced a record.
     *
     * @param int      $applicationId the application the form claims
     * @param int      $driverId      the driver the leave record is for
     * @param int|null $exceptRecordId the record being edited (its own link is fine)
     */
    public static function linkError(int $applicationId, int $driverId, ?int $exceptRecordId = null): ?string
    {
        $application = self::find($applicationId);

        if ($application === null) {
            return 'That leave application could not be found.';
        }

        if ((int) $application['driver_id'] !== $driverId) {
            return 'That leave application belongs to a different driver.';
        }

        if ((string) $application['status'] !== 'approved') {
            return 'Only an approved application can back a leave record.';
        }

        $recordId = self::linkedRecordId($applicationId);

        if ($recordId !== null && $recordId !== $exceptRecordId) {
            return 'Leave record #' . $recordId . ' already came from this application.';
        }

        if ($recordId === null && (string) $application['leave_status'] !== 'not_started') {
            return 'This application already records a leave that has begun or ended.'
                . ' Create a new application instead.';
        }

        return null;
    }

    /**
     * Write what a linked record says the application now is: its leave_status
     * AND its expected return date.
     *
     * Every LeaveRecord status change flows through here (create, update,
     * mark returned, cancel, not returned, status change), so the two
     * registers can never disagree about one trip - and the application shows
     * the return date the record actually carries, replacing whatever date it
     * was filed with. Callers pass a null date (unlinking, a record with no
     * date) to leave the stored date alone; an application without a record is
     * never derived from anything.
     *
     * @param int|null  $applicationId     the application to write
     * @param string    $recordStatus      the record's status (map key)
     * @param string|null $expectedArrivalDate the record's expected return (Y-m-d)
     */
    public static function syncFromRecord(
        ?int $applicationId,
        ?string $recordStatus,
        ?string $expectedArrivalDate = null
    ): void {
        if ($applicationId === null || $applicationId <= 0 || $recordStatus === null) {
            return;
        }

        $leaveStatus = self::RECORD_STATUS_MAP[$recordStatus] ?? null;

        if ($leaveStatus === null) {
            return;
        }

        $row = [
            'leave_status' => $leaveStatus,
            'updated_by'   => !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'updated_at'   => now(),
        ];

        // The record owns the trip's return date too: linking (or moving the
        // date on the record) replaces the application's own expected return
        // rather than leaving an outdated figure on the register.
        if ($expectedArrivalDate !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedArrivalDate) === 1
        ) {
            $row['expected_arrival_date'] = $expectedArrivalDate;
        }

        Database::update('leave_applications', $row, 'id = ?', [$applicationId]);
    }

    /**
     * Bring every application that HAS a linked record back into line with it.
     *
     * WHY THIS EXISTS. Every MANUAL transition on a record (create, update,
     * mark returned, cancel, not returned, status change) calls
     * syncFromRecord(). But the record's status is also rewritten in bulk by
     * Status::sync(), which the auth middleware runs on every page load so that
     * midnight rollover, overdue detection and the KPI cards stay true. That
     * statement updates leave_records and NOTHING ELSE - so a trip that rolled
     * from `planned` to `on_leave` overnight left its application reading
     * "Not Started" for as long as the leave lasted, which is exactly the
     * disagreement a reader notices first.
     *
     * Deliberately only WRITES where the stored value already disagrees: a
     * normal page load stays a read, updated_at is not bumped on rows that are
     * already right, and rows that drifted before this ever ran repair
     * themselves on the next page load - which is why there is no backfill
     * migration to forget to run.
     *
     * @return int number of applications corrected
     */
    public static function reconcileFromRecords(): int
    {
        $rows = Database::all(
            'SELECT la.id, la.leave_status, lr.status AS record_status,
                    lr.expected_arrival_date
               FROM leave_applications la
              INNER JOIN leave_records lr ON lr.leave_application_id = la.id'
        );

        $fixed = 0;

        foreach ($rows as $row) {
            $want = self::RECORD_STATUS_MAP[(string) $row['record_status']] ?? null;

            if ($want === null || $want === (string) $row['leave_status']) {
                continue;
            }

            $arrival = $row['expected_arrival_date'] ?? null;

            self::syncFromRecord(
                (int) $row['id'],
                (string) $row['record_status'],
                $arrival !== null && $arrival !== '' ? (string) $arrival : null
            );

            $fixed++;
        }

        return $fixed;
    }

    /**
     * Close an approved departure once its last working day has passed.
     *
     * WHY. A no-return type (Resignation / Terminated / Absconded) never
     * produces a leave record for a driver who has already left - Requirement
     * 9 keeps deactivated drivers off the record path - so nothing else ever
     * moved its leave_status off "not started". The row then read as pending
     * forever, for a window that had shut days ago. "Closed" says what the
     * dates say.
     *
     * DERIVED, NOT TYPED. It runs from Status::sync() on every page load, the
     * same place the record engine reconciles, and only writes where the stored
     * value disagrees - so an ordinary load stays a read, updated_at is not
     * bumped on rows already right, and rows that drifted repair themselves.
     * That is why there is no backfill to remember to run.
     *
     * TWO STATEMENTS, ON PURPOSE: moving the last working day back into the
     * future returns the row to "not started" instead of leaving it stuck on a
     * status the dates no longer support. Approval is required both ways, so
     * the DATES never decide a rejected or cancelled filing - that is
     * closeRejectedOrCancelled()'s rule. Keeping the two apart matters: a
     * resignation rejected today and re-approved tomorrow with a last working
     * day already behind it must stay Closed, and only the date rule is
     * entitled to say so.
     *
     * A LINKED RECORD IS NEVER TOUCHED. When leave_records owns the row,
     * RECORD_STATUS_MAP is the single source of truth and this must not fight
     * it; that is also why the close branch is limited to leave_status =
     * "not_started", which is exactly what a mapped record is not.
     *
     * A BLANK leave_status IS TREATED AS "not_started" IN BOTH DIRECTIONS. The
     * column can be the right shape - five members, NOT NULL - and still hold
     * the ENUM's EMPTY MEMBER on a row, which is what MySQL writes when a
     * narrower MODIFY dropped a value those rows were using. Such a row reads
     * as a blank badge, and a sweep that only matched "not_started" would walk
     * straight past it and never repair it: the one case where "no backfill to
     * remember" would have quietly stopped being true.
     *
     * @return int number of applications corrected
     */
    public static function closeExpiredNoReturn(): int
    {
        $like = implode(' OR ', array_fill(0, count(self::NO_RETURN_PATTERNS), 'LOWER(lt.name) LIKE ?'));
        $pair = ' AND NOT EXISTS (SELECT 1 FROM leave_records lr
                                 WHERE lr.leave_application_id = la.id)
                 AND (' . $like . ')';

        // Blank is not a status: it is a corrupted "not started".
        $open = ' (la.leave_status = "not_started" OR la.leave_status = "")';

        $fixed = 0;

        try {
            $fixed += Database::run(
                'UPDATE leave_applications la
                    JOIN leave_types lt ON lt.id = la.leave_type_id
                    SET la.leave_status = "closed"
                  WHERE la.status = "approved"
                    AND' . $open . '
                    AND la.last_working_date IS NOT NULL
                    AND la.last_working_date < CURDATE()' . $pair,
                self::NO_RETURN_PATTERNS
            )->rowCount();

            $fixed += Database::run(
                'UPDATE leave_applications la
                    JOIN leave_types lt ON lt.id = la.leave_type_id
                    SET la.leave_status = "not_started"
                  WHERE la.status = "approved"
                    AND (la.leave_status = "closed" OR la.leave_status = "")
                    AND (la.last_working_date IS NULL
                         OR la.last_working_date >= CURDATE())' . $pair,
                self::NO_RETURN_PATTERNS
            )->rowCount();
        } catch (\Throwable $ex) {
            // Housekeeping never breaks a render - same rule as Status::sync().
            log_fatal('leave status close failed: ' . $ex->getMessage());

            return 0;
        }

        return $fixed;
    }

    /**
     * Put a filing nobody will act on to bed: Rejected or Cancelled.
     *
     * WHY. leave_status answers "where has the LEAVE got to", and for a
     * rejected or cancelled application the answer is "nowhere - and never
     * will be". Reading "Not Started" says "still waiting", which is exactly
     * wrong: there is no window left to wait on. The approval decision is the
     * second axis that can close a row, after the dates.
     *
     * DERIVED, NOT TYPED - the same bargain closeExpiredNoReturn() strikes.
     * It runs from Status::sync() on every page load and writes only where the
     * stored value already disagrees, so the rejected and cancelled rows
     * already sitting on "Not Started" repair themselves with no backfill, and
     * decisions taken later are held to it automatically. Rejected and
     * Cancelled are precisely the two approval statuses
     * CapacityPlanningService already treats as "never leaves", so nothing
     * downstream begins counting a row it was already excluding.
     *
     * THE REOPEN BRANCH IS NOT OPTIONAL. LeaveApplicationController::status()
     * accepts all four decisions from any state - an administrator can reject
     * a filing and then put it back to pending or approved - so "Closed" has
     * to come off again the way it went on, or a re-approved application would
     * be refused by linkError() for a leave that is very much still open.
     *
     * Its guard, NOT (...), repeats the date rule's own close condition so the
     * two sweeps cannot fight: reopening must never undo a Closed the dates
     * are entitled to, or flipping a resignation back to approved would
     * reopen a departure whose last working day has already passed. Run the
     * two in either order and they agree - each statement is gated on a
     * different approval status, and the one overlap is excluded on purpose.
     *
     * A LINKED RECORD IS NEVER TOUCHED, for the reason that applies
     * everywhere: RECORD_STATUS_MAP owns the column then, and a linked
     * decision cannot be changed either (status() refuses it), so the two can
     * never meet.
     *
     * Only "not_started" and the blank are ever closed. Those are the only
     * values an unlinked row can hold - the form posts leave_status as a
     * hidden field so nothing is chosen by hand, and on_leave / returned /
     * not_returned all arrive through a record. Stopping there rather than
     * overwriting whatever is stored keeps this from erasing a value some
     * other rule is entitled to.
     *
     * @return int number of applications corrected
     */
    public static function closeRejectedOrCancelled(): int
    {
        $like = implode(' OR ', array_fill(0, count(self::NO_RETURN_PATTERNS), 'LOWER(lt.name) LIKE ?'));

        // As everywhere else: while leave_records owns the row, leave it alone.
        $noRecord = ' AND NOT EXISTS (SELECT 1 FROM leave_records lr
                                      WHERE lr.leave_application_id = la.id)';

        // The date rule's own close condition, minus its leading "AND".
        // Inverted below, it is what stops closeExpiredNoReturn() and this
        // sweep from undoing each other.
        $dated = 'la.last_working_date IS NOT NULL
                    AND la.last_working_date < CURDATE()
                    AND (' . $like . ')';

        $fixed = 0;

        try {
            $fixed += Database::run(
                'UPDATE leave_applications la
                    SET la.leave_status = "closed"
                  WHERE la.status IN ("cancelled", "rejected")
                    AND (la.leave_status = "not_started" OR la.leave_status = "")'
                    . $noRecord
            )->rowCount();

            $fixed += Database::run(
                'UPDATE leave_applications la
                    JOIN leave_types lt ON lt.id = la.leave_type_id
                    SET la.leave_status = "not_started"
                  WHERE la.status IN ("pending", "approved")
                    AND la.leave_status = "closed"' . $noRecord . '
                    AND NOT (' . $dated . ')',
                self::NO_RETURN_PATTERNS
            )->rowCount();
        } catch (\Throwable $ex) {
            // Housekeeping never breaks a render - same rule as Status::sync().
            log_fatal('leave status decision close failed: ' . $ex->getMessage());

            return 0;
        }

        return $fixed;
    }

    /**
     * Does this row have a linked record whose trip has not started yet?
     *
     * WHY THIS EXISTS. RECORD_STATUS_MAP sends a `planned` record to
     * `not_started`, which is correct - the record owns the truth and the
     * application has not begun - but it makes two very different rows read
     * identically: an application with a trip booked for the 16th, and an
     * application with nothing booked at all. Both say "Not Started".
     *
     * This reads the LINKED RECORD at render time instead of changing the
     * enum: the stored column keeps meaning exactly what it meant before, so
     * the mapping, the migration and the tests that pin them are untouched.
     * Nothing here writes anything.
     *
     * The list screen and the CSV export both ask this, so they can never
     * disagree about the same row.
     *
     * @param array<string, mixed> $row a row from SELECT (needs linked_record_status)
     */
    public static function hasPlannedTrip(array $row): bool
    {
        return (string)($row['linked_record_status'] ?? '') === 'planned';
    }

    /**
     * The Leave Status as PLAIN TEXT for this row - the export's cell, and
     * anywhere else a string rather than HTML is wanted.
     *
     * Nothing more than the status itself: a planned record simply reads
     * "Planned", no dates or extras appended.
     *
     * @param array<string, mixed> $row
     */
    public static function displayLeaveStatus(array $row): string
    {
        return self::hasPlannedTrip($row)
            ? status_label('planned')
            : status_label((string)($row['leave_status'] ?? ''));
    }

    /**
     * Put an application back to "not started" once the record that used it
     * is gone or unlinked: the trip no longer exists, so the application may
     * be applied for again.
     */
    public static function resetLeaveStatus(int $applicationId): void
    {
        self::syncFromRecord($applicationId, Status::CANCELLED);
    }

    public static function create(array $data): int
    {
        $userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        return (int) Database::insert('leave_applications', [
            'driver_id'             => (int) $data['driver_id'],
            'leave_type_id'         => (int) $data['leave_type_id'],
            'branch_id'             => self::intOrNull($data['branch_id'] ?? null),
            'application_date'      => (string) $data['application_date'],
            'last_working_date'     => self::strOrNull($data['last_working_date'] ?? null),
            'expected_arrival_date' => self::strOrNull($data['expected_arrival_date'] ?? null),
            'destination'           => self::strOrNull($data['destination'] ?? null),
            'reason'                => self::strOrNull($data['reason'] ?? null),
            'remarks'               => self::strOrNull($data['remarks'] ?? null),
            'status'                => self::status((string) ($data['status'] ?? 'pending')),
            'leave_status'          => self::leaveStatus((string) ($data['leave_status'] ?? '')),
            'created_by'            => $userId,
            'updated_by'            => $userId,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        $userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        $row = [
            'driver_id'             => (int) $data['driver_id'],
            'leave_type_id'         => (int) $data['leave_type_id'],
            'branch_id'             => self::intOrNull($data['branch_id'] ?? null),
            'application_date'      => (string) $data['application_date'],
            'last_working_date'     => self::strOrNull($data['last_working_date'] ?? null),
            'expected_arrival_date' => self::strOrNull($data['expected_arrival_date'] ?? null),
            'destination'           => self::strOrNull($data['destination'] ?? null),
            'reason'                => self::strOrNull($data['reason'] ?? null),
            'remarks'               => self::strOrNull($data['remarks'] ?? null),
            'status'                => self::status((string) ($data['status'] ?? 'pending')),
            'updated_by'            => $userId,
            'updated_at'            => now(),
        ];

        // leave_status is only written when the caller supplies a real value.
        // Blank - a disabled select, an API that omits it, a form posting
        // nothing - keeps whatever is stored instead of silently resetting
        // the column to "not started" on every save.
        $leaveStatus = trim((string) ($data['leave_status'] ?? ''));

        if ($leaveStatus !== '') {
            $row['leave_status'] = self::leaveStatus($leaveStatus);
        }

        Database::update('leave_applications', $row, 'id = ?', [$id]);
    }

    /** Move an application between pending / approved / rejected. */
    public static function setStatus(int $id, string $status): void
    {
        Database::update('leave_applications', [
            'status'     => self::status($status),
            'updated_by' => !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'updated_at' => now(),
        ], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM leave_applications WHERE id = ?', [$id]);
    }

    /** Never let an unknown value reach the ENUM column. */
    private static function status(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'pending';
    }

    /**
     * A brand new application is one whose leave has not begun, so a blank
     * field resolves to "not started" rather than an unknown ENUM value.
     */
    private static function leaveStatus(string $status): string
    {
        return in_array($status, self::LEAVE_STATUSES, true) ? $status : 'not_started';
    }

    private static function strOrNull($v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private static function intOrNull($v): ?int
    {
        return ($v === '' || $v === null) ? null : (int) $v;
    }
}
