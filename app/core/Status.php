<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * The single source of truth for leave status.
 *
 * A record's status is DERIVED from its dates plus the actual arrival date -
 * it is never invented by the user. Two statuses are manual:
 *
 *   cancelled     - the record was cancelled by a user
 *   not_returned   - management closed the case as a non-return: the driver
 *                    did not come back and the leave is closed out without an
 *                    arrival date. Always recorded with a reason, a date and
 *                    the user who confirmed it.
 *
 *   returned   - actual arrival date is recorded
 *   overdue    - expected return < today  AND actual arrival IS NULL
 *   planned    - leave start > today  AND no arrival recorded
 *   on_leave   - leave start <= today AND (expected return >= today)
 *                AND actual arrival IS NULL
 *
 * All comparisons use the application time zone (Asia/Riyadh).
 */
final class Status
{
    public const PLANNED        = 'planned';
    public const ON_LEAVE       = 'on_leave';
    public const RETURNED       = 'returned';
    public const OVERDUE        = 'overdue';
    public const CANCELLED     = 'cancelled';
    public const NOT_RETURNED  = 'not_returned';

    public const ALL = [
        self::PLANNED, self::ON_LEAVE, self::RETURNED, self::OVERDUE,
        self::CANCELLED, self::NOT_RETURNED,
    ];

    /** Statuses that mean "the driver is physically away right now". */
    public const AWAY = [self::ON_LEAVE, self::OVERDUE];

    /** Statuses that count as active (not cancelled / not finished). */
    public const ACTIVE = [self::PLANNED, self::ON_LEAVE, self::OVERDUE, self::RETURNED];

    /**
     * Terminal statuses that must be left alone by sync() and excluded from
     * every "still out there" count - nobody is coming back, so they are not
     * returning, overdue or expected any more.
     */
    public const CLOSED = [self::CANCELLED, self::NOT_RETURNED];

    /**
     * Compute the status for one record.
     *
     * @param string|null $leaveStart     Y-m-d
     * @param string|null $expected       Y-m-d expected return
     * @param string|null $actual         Y-m-d actual arrival (null when not back)
     * @param bool        $cancelled      manual cancellation flag
     * @param string|null $today          override for tests; defaults to today
     */
    public static function derive(
        ?string $leaveStart,
        ?string $expected,
        ?string $actual,
        bool $cancelled = false,
        ?string $today = null
    ): string {
        if ($cancelled) {
            return self::CANCELLED;
        }

        if ($actual !== null && $actual !== '') {
            return self::RETURNED;
        }

        $today = $today ?? date('Y-m-d');

        if ($expected !== null && $expected !== '' && $expected < $today) {
            return self::OVERDUE;
        }

        if ($leaveStart !== null && $leaveStart !== '' && $leaveStart > $today) {
            return self::PLANNED;
        }

        return self::ON_LEAVE;
    }

    /**
     * Recompute the stored `status` column for every row whose stored value
     * disagrees with the derived value.
     *
     * Runs a single indexed UPDATE - cheap enough to call on every page load,
     * and it is what makes midnight rollover, overdue detection and KPI cards
     * correct without any cron job.
     */
    public static function sync(?string $today = null): void
    {
        $today = $today ?? date('Y-m-d');

        // NOTE: positional placeholders are used deliberately. With native
        // prepares (EMULATE_PREPARES = false) a named placeholder cannot be
        // referenced more than once, so the case expression is written twice
        // with four separate `?` markers.
        $case = 'CASE
            WHEN actual_arrival_date IS NOT NULL THEN "returned"
            WHEN expected_arrival_date < ?       THEN "overdue"
            WHEN leave_start_date > ?            THEN "planned"
            ELSE "on_leave"
        END';

        $sql = "UPDATE leave_records
                   SET status = {$case}
                 WHERE status NOT IN ('cancelled', 'not_returned')
                   AND status <> ({$case})";

        try {
            Database::run($sql, [$today, $today, $today, $today]);
        } catch (\Throwable $ex) {
            // Never let a housekeeping query break a page render.
            log_fatal('status sync failed: ' . $ex->getMessage());

            return;
        }

        // The record is only half of it. Every MANUAL transition above ends by
        // pushing its new status into leave_applications; this bulk statement
        // is the one path that moves a record WITHOUT going through them, so it
        // has to close the loop itself or a trip that rolls over at midnight
        // leaves its application reading "Not Started" all day.
        //
        // Only writes where the two already disagree, so the usual page load
        // stays a read - and rows that drifted before this line existed repair
        // themselves on the next load, which is why there is no backfill.
        try {
            \App\Models\LeaveApplication::reconcileFromRecords();
        } catch (\Throwable $ex) {
            // Same rule as above: housekeeping never breaks a render.
            log_fatal('leave application sync failed: ' . $ex->getMessage());
        }

        // And the half no record ever touches: an approved departure whose
        // last working day has passed has nothing left to wait for. It runs
        // here for the same reason as the line above - it depends on a DATE
        // rollover, so it has to happen without anybody clicking anything.
        try {
            \App\Models\LeaveApplication::closeExpiredNoReturn();
        } catch (\Throwable $ex) {
            log_fatal('leave application close failed: ' . $ex->getMessage());
        }

        // The other way a filing ends without a leave: somebody refused it or
        // pulled it. That does not depend on a date, but it does have to hold
        // for the rows already on file as well as the decisions taken later,
        // so it belongs in the same sweep rather than in status() - otherwise
        // every rejected and cancelled application filed before this existed
        // would still read "Not Started", which claims a wait that nothing is
        // waiting for.
        try {
            \App\Models\LeaveApplication::closeRejectedOrCancelled();
        } catch (\Throwable $ex) {
            log_fatal('leave application decision close failed: ' . $ex->getMessage());
        }
    }

    /** A SQL fragment that evaluates to the derived status of an aliased row. */
    public static function sqlExpression(string $alias = 'lr'): string
    {
        // TWO `?` placeholders, in this order: [expected, leave_start].
        //
        // The previous comment claimed four and named the order wrongly. It
        // mattered: passing four (the count sync() needs, because sync writes
        // the CASE twice) raises SQLSTATE[HY093] Invalid parameter number.
        // sync() keeps its own four inline - it does not call this.
        //
        // No production caller uses this yet; it exists so a caller can
        // COMPARE status against the derived value in SQL instead of loading
        // rows and calling derive() in PHP.
        return "CASE
            WHEN {$alias}.actual_arrival_date IS NOT NULL THEN 'returned'
            WHEN {$alias}.expected_arrival_date < ?       THEN 'overdue'
            WHEN {$alias}.leave_start_date > ?            THEN 'planned'
            ELSE 'on_leave'
        END";
    }

    /** True when the driver is away right now (on leave or overdue). */
    public static function isAway(?string $actualArrival, ?string $expected, string $today): bool
    {
        if ($actualArrival !== null && $actualArrival !== '') {
            return false;
        }

        if ($expected !== null && $expected !== '' && $expected < $today) {
            return true; // overdue = still away
        }

        return true;
    }

    /** Human readable summary of what a record is doing today. */
    public static function describe(string $status): string
    {
        return match ($status) {
            self::PLANNED        => 'Scheduled to start in the future',
            self::ON_LEAVE       => 'Currently away, expected back on schedule',
            self::RETURNED       => 'Driver has been marked as returned',
            self::OVERDUE        => 'Past the expected return date and not yet marked returned',
            self::CANCELLED      => 'Record cancelled',
            self::NOT_RETURNED => 'Closed as a non-return by management; no arrival date recorded',
            default              => '',
        };
    }
}
