<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Status;

/**
 * Leave / vacation records.
 *
 * Every query works against the DERIVED status rules in
 * app/core/Status.php; nothing is hand-classified by the user.
 */
final class LeaveRecord
{
    /** Shared SELECT used by lists, reports and the calendar feed. */
    public const SELECT = 'SELECT lr.*,
                d.badge_number, d.driver_name, d.iqama_number, d.mobile_number,
                d.nationality, d.position,
                lt.name AS leave_type,
                la.id AS application_id, la.status AS application_status,
                la.leave_status AS application_leave_status,
                a.agency_name, b.branch_name,
                cu.full_name AS created_by_name';

    // Branch comes from lr.branch_id - the value snapshotted when the leave
    // was created - and never from d.branch_id, which is the driver's current
    // branch and can change at any time.
    //
    // The application join is LEFT: a record recorded directly (or imported)
    // has no application and shows no chip rather than losing its row.
    public const FROM = ' FROM leave_records lr
                JOIN drivers d       ON d.id  = lr.driver_id
                JOIN leave_types lt  ON lt.id = lr.leave_type_id
           LEFT JOIN leave_applications la ON la.id = lr.leave_application_id
           LEFT JOIN agencies a      ON a.id  = d.agency_id
           LEFT JOIN branches b      ON b.id  = lr.branch_id
           LEFT JOIN users cu        ON cu.id = lr.created_by';

    /** Columns a user may sort by on any leave list. */
    public const SORTABLE = [
        'leave_start_date'      => 'lr.leave_start_date',
        'last_working_date'     => 'lr.last_working_date',
        'ticket_date'           => 'lr.ticket_date',
        'expected_arrival_date' => 'lr.expected_arrival_date',
        'actual_arrival_date'   => 'lr.actual_arrival_date',
        'driver_name'           => 'd.driver_name',
        'badge_number'          => 'd.badge_number',
        'iqama_number'          => 'd.iqama_number',
        'leave_type'            => 'lt.name',
        'branch'                => 'b.branch_name',
        'agency'                => 'a.agency_name',
        'destination'           => 'lr.destination',
        'status'                => 'lr.status',
        'created_at'            => 'lr.created_at',
        'updated_at'            => 'lr.updated_at',
        // Sorts on the JOINED name, not the alias: MySQL resolves an alias in
        // ORDER BY too, but the expression is kept explicit so the map stays
        // readable next to the lr./d./lt. entries it sits among.
        'created_by_name'       => 'cu.full_name',
    ];

    /**
     * Filters shared by Current Leave / Leave Records / Reports.
     *
     * @param array<string, mixed> $f
     * @return array{0:string, 1:array<int, mixed>}
     */
    public static function where(array $f): array
    {
        $sql    = ' WHERE 1 = 1';
        $params = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $needle = wildcard_needle($q);
            $sql   .= ' AND (d.driver_name LIKE ? OR d.badge_number LIKE ?'
                . ' OR d.iqama_number LIKE ? OR d.mobile_number LIKE ?)';
            array_push($params, $needle, $needle, $needle, $needle);
        }

        // Integer filters accept a single value or a multi-select list ("1,3").
        foreach ([
            'branch_id'     => 'lr.branch_id',
            'agency_id'     => 'd.agency_id',
            'leave_type_id' => 'lr.leave_type_id',
            'driver_id'     => 'lr.driver_id',
        ] as $key => $column) {
            $ids = id_filter($f[$key] ?? '');

            if ($ids !== []) {
                $sql .= ' AND ' . $column . ' IN ' . sql_in($ids);
                array_push($params, ...$ids);
            }
        }

        $statuses = filter_values($f['status'] ?? '');

        if ($statuses !== []) {
            $sql .= ' AND lr.status IN ' . sql_in($statuses);
            array_push($params, ...$statuses);
        }

        if (($f['destination'] ?? '') !== '') {
            $sql     .= ' AND lr.destination LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$f['destination']) . '%';
        }

        // date_field is a checkbox multi-select now, so every ticked key goes
        // through the whitelist and the row matches ANY of them.
        $dateKeys = filter_values($f['date_field'] ?? '');

        if ($dateKeys === []) {
            $dateKeys = ['']; // dateColumn('') falls back to leave_start_date
        }

        $columns = array_map(
            static fn (string $key): string => self::dateColumn($key),
            $dateKeys
        );

        [$range, $rangeParams] = date_range_sql(
            $columns,
            (string)($f['date_from'] ?? ''),
            (string)($f['date_to'] ?? '')
        );

        $sql   .= $range;
        $params = array_merge($params, $rangeParams);

        return [$sql, $params];
    }

    /** Whitelist the user-selectable date range column. */
    public static function dateColumn(string $key): string
    {
        return match ($key) {
            'expected_arrival_date' => 'lr.expected_arrival_date',
            'actual_arrival_date'   => 'lr.actual_arrival_date',
            'created_at'            => 'lr.created_at',
            default                 => 'lr.leave_start_date',
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{0:string,1:string} $sort
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function paginate(array $filters, array $sort, int $limit, int $offset): array
    {
        [$where, $params] = self::where($filters);

        $total = (int)Database::scalar('SELECT COUNT(*)' . self::FROM . $where, $params);

        $sql = self::SELECT . self::FROM . $where
            . ' ORDER BY ' . self::orderBy($sort)
            . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        return ['rows' => Database::all($sql, $params), 'total' => $total];
    }

    /** All matching rows for exports/reports (no pagination). */
    public static function all(array $filters, array $sort = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = self::where($filters);

        $sql = self::SELECT . self::FROM . $where . ' ORDER BY ' . self::orderBy($sort);

        // Optional windowing so one caller can ask for a page while another
        // (the CSV/Excel export) asks for every row - both from the identical
        // WHERE, which is what stops the screen and the file disagreeing.
        // Cast to int before they reach the SQL: a filter value must never be
        // able to write its own LIMIT clause.
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, (int) $limit) . ' OFFSET ' . max(0, (int) $offset);
        }

        return Database::all($sql, $params);
    }

    /** @param array{0?:string,1?:string} $sort */
    private static function orderBy(array $sort): string
    {
        $sortKey  = $sort[0] ?? 'leave_start_date';
        $sortDir  = ($sort[1] ?? 'desc') === 'desc' ? 'DESC' : 'ASC';
        $orderCol = self::SORTABLE[$sortKey] ?? 'lr.leave_start_date';

        return $orderCol . ' ' . $sortDir . ', lr.id DESC';
    }

    public static function find(int $id): ?array
    {
        return Database::one(self::SELECT . self::FROM . ' WHERE lr.id = ? LIMIT 1', [$id]);
    }

    /* ------------------------------------------------------ KPI queries - */

    /**
     * The eight dashboard KPIs. One indexed query per card.
     *
     * @return array<string, int>
     */
    public static function kpis(): array
    {
        $today    = today();
        $tomorrow = date_add_days($today, 1);
        $weekEnd  = date_add_days($today, 6);

        $totalDrivers = (int)Database::scalar(
            // Active population as of today: employment status plus any
            // driver still inside their last working date (deactivated with
            // an end date on file) - they were working today, so today's
            // head-count and the On-Leave % denominator include them. See
            // Driver::activeOnSql().
            'SELECT COUNT(*) FROM drivers d WHERE ' . Driver::activeOnSql('d'),
            [$today]
        );
        $onLeave = (int)Database::scalar(
            'SELECT COUNT(*) FROM leave_records WHERE status IN ("on_leave","overdue")'
        );

        // Headline ratio: the share of the active fleet that is away right now.
        // One open leave per driver (overlap check) plus the deactivation guard
        // keep this at or below 100%; the zero check avoids NaN when the address
        // book is empty.
        $onLeavePct = $totalDrivers > 0
            ? round(($onLeave / $totalDrivers) * 100, 2)
            : 0.0;

        return [
            'total_drivers'      => $totalDrivers,
            'on_leave'           => $onLeave,
            'on_leave_pct'       => $onLeavePct,
            'returning_today'    => self::countExpected($today, $today),
            'returning_tomorrow' => self::countExpected($tomorrow, $tomorrow),
            'returning_week'     => self::countExpected($today, $weekEnd),
            'overdue'            => (int)Database::scalar(
                'SELECT COUNT(*) FROM leave_records
                  WHERE actual_arrival_date IS NULL
                    AND status NOT IN ("cancelled","not_returned")
                    AND expected_arrival_date < ?',
                [$today]
            ),
            // "Starting this week" counts trips that have NOT begun yet, so
            // planned rows only. That is exactly the set the dashboard's View
            // link pre-filters on Leave Records (status=planned) - keeping the
            // two queries aligned is what stops the card and the list from
            // disagreeing by one row.
            'starting_week'      => (int)Database::scalar(
                'SELECT COUNT(*) FROM leave_records
                  WHERE status = "planned"
                    AND leave_start_date BETWEEN ? AND ?',
                [$today, $weekEnd]
            ),
        ];
    }

    /**
     * Returns still owed in a window - the count behind "returning today /
     * tomorrow / this week".
     *
     * Public so the Leave Dashboard's KPI drill-down modal counts exactly what
     * expectedArrivals() lists: both now build ONE predicate. Planned rows are
     * excluded - a trip that has not begun has nobody due back. They used to
     * disagree (this one counted planned, the list did not), so a card could
     * outnumber its own list by every unstarted trip inside the window, which
     * is the mismatch the drill-down exists to prevent.
     */
    public static function countExpected(string $from, string $to): int
    {
        [$where, $p] = self::where([]);

        $where .= ' AND lr.actual_arrival_date IS NULL'
            . " AND lr.status NOT IN ('cancelled','not_returned','planned')"
            . ' AND lr.expected_arrival_date BETWEEN ? AND ?';
        array_push($p, $from, $to);

        return (int)Database::scalar('SELECT COUNT(*)' . self::FROM . $where, $p);
    }

    /** Rows currently away (dashboard panel + Current Leave screen). */
    /**
     * @param array{0?:string,1?:string}|null $sort null keeps the dashboard
     *        panels' "soonest expected return first" order; the Current Leave
     *        screen passes the user's chosen column instead.
     */
    public static function currentlyAway(
        array $filters = [],
        int $limit = 0,
        int $offset = 0,
        ?array $sort = null
    ): array {
        $filters['status'] = '';
        [$where, $p] = self::where($filters);

        $where .= " AND lr.status IN ('on_leave','overdue')";

        // $sort used to be handed to the VIEW only, so the header arrow toggled
        // on every click while the query kept its fixed ORDER BY - the rows
        // never moved. orderBy() whitelists against SORTABLE.
        $order = $sort === null
            ? 'lr.expected_arrival_date ASC, d.driver_name ASC'
            : self::orderBy($sort);

        $sql = self::SELECT . self::FROM . $where . ' ORDER BY ' . $order;

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        }

        return Database::all($sql, $p);
    }

    /** Count of rows currently away (for the pager on Current Leave). */
    public static function countCurrentlyAway(array $filters = []): int
    {
        $filters['status'] = '';
        [$where, $p] = self::where($filters);

        $where .= " AND lr.status IN ('on_leave','overdue')";

        return (int)Database::scalar('SELECT COUNT(*)' . self::FROM . $where, $p);
    }

    /**
     * Expected returns in a window (today / tomorrow / this week / month).
     *
     * Planned trips are excluded: the driver has not left yet, so nobody is
     * actually due back. It used to be the other way round for the month card
     * ("every scheduled arrival belongs in it"), but that meant a return could
     * be listed for a driver who had not gone anywhere - and future months
     * offered nothing but trips that had not started. Round 6 settled it:
     * there is no version of an expected return without a departure first, so
     * the parameter that allowed it is gone rather than defaulted off.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function expectedArrivals(
        string $from,
        string $to,
        array $filters = [],
        int $limit = 0
    ): array {
        [$where, $p] = self::where($filters);

        $where .= ' AND lr.actual_arrival_date IS NULL'
            . " AND lr.status NOT IN ('cancelled','not_returned','planned')";

        $where .= ' AND lr.expected_arrival_date BETWEEN ? AND ?';
        array_push($p, $from, $to);

        $sql = self::SELECT . self::FROM . $where
            . ' ORDER BY lr.expected_arrival_date ASC, d.driver_name ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        return Database::all($sql, $p);
    }

    /** Overdue: expected return passed, actual arrival still missing. */
    public static function overdue(array $filters = [], int $limit = 0): array
    {
        [$where, $p] = self::where($filters);

        $where .= ' AND lr.actual_arrival_date IS NULL'
            . " AND lr.status NOT IN ('cancelled','not_returned')"
            . ' AND lr.expected_arrival_date < ?';
        $p[] = today();

        $sql = self::SELECT . self::FROM . $where
            . ' ORDER BY lr.expected_arrival_date ASC, d.driver_name ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        return Database::all($sql, $p);
    }

    /**
     * Count for overdue() - same predicate, written once as a pair so the
     * "Overdue Returns" card and its drill-down cannot drift apart.
     */
    public static function countOverdue(array $filters = []): int
    {
        [$where, $p] = self::where($filters);

        $where .= ' AND lr.actual_arrival_date IS NULL'
            . " AND lr.status NOT IN ('cancelled','not_returned')"
            . ' AND lr.expected_arrival_date < ?';
        $p[] = today();

        return (int)Database::scalar('SELECT COUNT(*)' . self::FROM . $where, $p);
    }

    /**
     * Trips that have not begun yet but start inside the window - the rows
     * behind "Leave Starting This Week".
     *
     * Deliberately the same two conditions the card counts and the same ones
     * the card's View link once carried: status must be "planned" (a trip that
     * has already started is not one this week's figure was about) and the
     * leave start date must fall inside the window.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function startingThisWeek(string $from, string $to): array
    {
        [$where, $p] = self::where([]);

        $where .= " AND lr.status = 'planned'"
            . ' AND lr.leave_start_date BETWEEN ? AND ?';
        array_push($p, $from, $to);

        $sql = self::SELECT . self::FROM . $where
            . ' ORDER BY lr.leave_start_date ASC, d.driver_name ASC';

        return Database::all($sql, $p);
    }

    /** Count for startingThisWeek(), built from the identical WHERE clause. */
    public static function countStartingThisWeek(string $from, string $to): int
    {
        [$where, $p] = self::where([]);

        $where .= " AND lr.status = 'planned'"
            . ' AND lr.leave_start_date BETWEEN ? AND ?';
        array_push($p, $from, $to);

        return (int)Database::scalar('SELECT COUNT(*)' . self::FROM . $where, $p);
    }

    /* ------------------------------------------------------ mutations --- */

    /**
     * A date bound for a DATE column: blank stays null.
     *
     * The payload used to cast with (string), which turned "no expected
     * return" into '' - an empty string a strict-mode DATE column rejects.
     * Resignation, Terminated and Absconded records legitimately carry no
     * expected return (the form hides the field), so null has to survive all
     * the way to the database.
     *
     * @param mixed $value
     */
    private static function dateOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * The leave start a blank field falls back to: the day AFTER the last
     * working day - the driver works that day, so the leave begins the next.
     *
     * Returns null when there is nothing to derive from, so an empty last
     * working date still leaves the field empty and required() reports it:
     * the default never invents a date out of nothing. '+1 day' is parsed as a
     * calendar day rather than added as 86400 seconds, so it cannot slip by an
     * hour across a DST change.
     *
     * @param mixed $lastWorkingDate Y-m-d, or blank/null when unknown
     */
    public static function defaultStart($lastWorkingDate): ?string
    {
        if ($lastWorkingDate === null || trim((string) $lastWorkingDate) === '') {
            return null;
        }

        $start = strtotime(trim((string) $lastWorkingDate) . ' +1 day');

        return $start === false ? null : date('Y-m-d', $start);
    }

    /**
     * Create a leave record. Status is derived, never taken from the request.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $status = Status::derive(
            (string)$data['leave_start_date'],
            self::dateOrNull($data['expected_arrival_date'] ?? null),
            $data['actual_arrival_date'] ?? null
        );

        $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        // The application the leave was applied for, if the form picked one.
        // Validation (same driver, approved, not already used) happens in the
        // controller/API through LeaveApplication::linkError() before we get
        // here; the unique key and the foreign key are the backstop.
        $applicationId = !empty($data['leave_application_id'])
            ? (int)$data['leave_application_id']
            : null;

        $id = Database::insert('leave_records', [
            'driver_id'             => (int)$data['driver_id'],
            'leave_type_id'         => (int)$data['leave_type_id'],
            'leave_application_id'  => $applicationId !== null && $applicationId > 0 ? $applicationId : null,
            'last_working_date'     => $data['last_working_date'] ?? null,
            'leave_start_date'      => (string)$data['leave_start_date'],
            'ticket_date'           => $data['ticket_date'] ?? null,
            // Nullable: a no-return type (Resignation / Terminated /
            // Absconded) stores no expected return, and (string)null would
            // hand '' to a DATE column, which strict mode rejects.
            'expected_arrival_date' => self::dateOrNull($data['expected_arrival_date'] ?? null),
            'actual_arrival_date'   => $data['actual_arrival_date'] ?? null,
            'actual_arrival_time'   => $data['actual_arrival_time'] ?? null,
            'passport_number'       => $data['passport_number'] ?? null,
            'branch_id'             => (isset($data['branch_id']) && $data['branch_id'] !== ''
                && $data['branch_id'] !== null) ? (int)$data['branch_id'] : null,
            'destination'           => $data['destination'] ?? null,
            'reason'                => $data['reason'] ?? null,
            'remarks'               => $data['remarks'] ?? null,
            'return_remarks'        => $data['return_remarks'] ?? null,
            'status'                => $status,
            'created_by'            => $userId,
            'updated_by'            => $userId,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // Requirement 3: the application now follows the record it produced -
        // its leave status AND its expected return date (the trip's real
        // return date, replacing the one the application was filed with).
        LeaveApplication::syncFromRecord($applicationId, $status, self::dateOrNull($data['expected_arrival_date'] ?? null));

        return $id;
    }

    /** Update an existing record (dates/notes only; status is re-derived). */
    public static function update(int $id, array $data): void
    {
        $current = self::find($id);

        if ($current === null) {
            throw new \RuntimeException('Leave record not found.');
        }

        $userId   = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $actual   = $data['actual_arrival_date'] ?? $current['actual_arrival_date'];
        $cancelled = $current['status'] === Status::CANCELLED;

        // The edit screen only offers Actual Arrival Date on a record that is
        // already returned, so a posted date here is a CORRECTION of an
        // existing one. Anything else keeps the stored date: without this
        // guard a crafted POST could flip an on-leave record to returned,
        // which is what the Mark Returned action is for (and it audits as
        // 'driver-returned', this does not).
        $postedActual = $data['actual_arrival_date'] ?? null;
        if ($current['status'] === Status::RETURNED && $postedActual !== null && $postedActual !== '') {
            $actual = $postedActual;
        } elseif ($current['status'] !== Status::RETURNED) {
            $actual = $current['actual_arrival_date'];
        }

        $status = Status::derive(
            (string)$data['leave_start_date'],
            self::dateOrNull($data['expected_arrival_date'] ?? null),
            $actual !== null ? (string)$actual : null,
            $cancelled
        );

        // The application link is only changed when the form sends one; an
        // edit that omits it keeps the application the record came from.
        $previousLink = ($current['leave_application_id'] ?? null) !== null
            && $current['leave_application_id'] !== ''
            ? (int)$current['leave_application_id']
            : null;

        $link = $previousLink;

        if (array_key_exists('leave_application_id', $data)) {
            $raw  = $data['leave_application_id'];
            $link = ($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw;
        }

        Database::update('leave_records', [
            'leave_start_date'      => (string)$data['leave_start_date'],
            'expected_arrival_date' => self::dateOrNull($data['expected_arrival_date'] ?? null),
            'last_working_date'     => $data['last_working_date'] ?? null,
            'ticket_date'           => $data['ticket_date'] ?? null,
            'passport_number'       => $data['passport_number'] ?? null,
            'branch_id'             => (isset($data['branch_id']) && $data['branch_id'] !== ''
                && $data['branch_id'] !== null) ? (int)$data['branch_id'] : null,
            'destination'           => $data['destination'] ?? null,
            'reason'                => $data['reason'] ?? null,
            'remarks'               => $data['remarks'] ?? null,
            'leave_type_id'         => (int)$data['leave_type_id'],
            'leave_application_id'  => $link,
            // Corrected arrival date on an already-returned record (the edit
            // screen is the only place this is posted; see the guard above).
            'actual_arrival_date'   => $actual !== null && $actual !== '' ? (string)$actual : null,
            'status'                => $status,
            'updated_by'            => $userId,
            'updated_at'            => now(),
        ], 'id = ?', [$id]);

        // Unlinking hands the old application back to "not started" so it can
        // be applied for again, and the new one (if any) follows the record.
        if ($previousLink !== null && $previousLink !== $link) {
            LeaveApplication::resetLeaveStatus($previousLink);
        }

        LeaveApplication::syncFromRecord($link, $status, (string)$data['expected_arrival_date']);
    }

    /**
     * Overlap guard: an active record for the same driver must not overlap the
     * proposed window.
     *
     * $end is nullable because a no-return leave type (Resignation,
     * Terminated, Absconded) stores no expected return - the form hides the
     * field, so the window is open-ended. '9999-12-31' keeps that comparison
     * on a real DATE; an empty string would be coerced and strict mode rejects
     * it.
     *
     * @return array<string, mixed>|null the clashing record, or null
     */
    public static function findOverlap(int $driverId, string $start, ?string $end = null, ?int $exceptId = null): ?array
    {
        $sql    = 'SELECT lr.*, lt.name AS leave_type FROM leave_records lr
                     JOIN leave_types lt ON lt.id = lr.leave_type_id
                    WHERE lr.driver_id = ?
                      AND lr.status NOT IN ("cancelled","not_returned")
                      AND lr.leave_start_date <= ?
                      AND lr.expected_arrival_date >= ?';
        $params = [$driverId, $end ?? '9999-12-31', $start];

        if ($exceptId !== null) {
            $sql    .= ' AND lr.id <> ?';
            $params[] = $exceptId;
        }

        return Database::one($sql . ' LIMIT 1', $params);
    }

    /**
     * Push this record's status to the application it came from (one query,
     * and nothing at all when the record has no application).
     *
     * Every status transition - returned, cancelled, not returned, hand
     * change - ends here so leave_applications.leave_status can never be left
     * behind the record it describes.
     */
    private static function syncApplication(int $recordId): void
    {
        $row = Database::one(
            'SELECT leave_application_id, status, expected_arrival_date
               FROM leave_records WHERE id = ?',
            [$recordId]
        );

        if ($row === null || $row === false || ($row['leave_application_id'] ?? null) === null) {
            return;
        }

        LeaveApplication::syncFromRecord(
            (int)$row['leave_application_id'],
            (string)$row['status'],
            $row['expected_arrival_date'] !== null && $row['expected_arrival_date'] !== ''
                ? (string)$row['expected_arrival_date']
                : null
        );
    }

    /** Record the actual return of a driver. */
    public static function markReturned(int $id, string $actualDate, ?string $actualTime, ?string $remarks): void
    {
        $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        Database::update('leave_records', [
            'actual_arrival_date' => $actualDate,
            'actual_arrival_time' => $actualTime,
            'return_remarks'      => $remarks,
            'status'              => Status::RETURNED,
            'updated_by'          => $userId,
            'updated_at'          => now(),
        ], 'id = ?', [$id]);

        self::syncApplication($id);
    }

    /** Cancel a leave record (the only manual status). */
    public static function cancel(int $id, string $reason): void
    {
        $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        Database::update('leave_records', [
            'status'           => Status::CANCELLED,
            'cancelled_reason' => $reason,
            'updated_by'       => $userId,
            'updated_at'       => now(),
        ], 'id = ?', [$id]);

        // A cancelled trip hands its application back to "not started".
        self::syncApplication($id);
    }

    /**
     * Reasons a leave may be closed out as a non-return.
     *
     * A closed list rather than free text so the column stays reportable -
     * "why did drivers not come back this quarter?" has to be answerable with
     * a count, not fifty spellings of "resigned".
     */
    public const NOT_RETURNED_REASONS = [
        'Resigned',
        'Terminated',
        'Transferred',
        'Did not return from vacation',
        'Other',
    ];

    /**
     * Check the closure details the form must supply before it is written.
     *
     * All four are mandatory: reason, the date the decision was taken, who
     * took it (always the signed-in user) and remarks. Without them the status
     * is just an unexplained gap in an otherwise complete leave history.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $row  the record being closed
     * @return array<string, string> field => message; empty when valid
     */
    public static function validateNotReturned(array $input, array $row): array
    {
        $errors  = [];
        $reason  = trim((string) ($input['not_returned_reason'] ?? ''));
        $date    = trim((string) ($input['not_returned_date'] ?? ''));
        $remarks = trim((string) ($input['not_returned_remarks'] ?? ''));

        if ($reason === '') {
            $errors['not_returned_reason'] = 'Choose why the driver is not returning.';
        } elseif (!in_array($reason, self::NOT_RETURNED_REASONS, true)) {
            $errors['not_returned_reason'] = 'Choose one of the listed reasons.';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            $errors['not_returned_date'] = 'Enter the date the decision was made.';
        } elseif ($date > today()) {
            $errors['not_returned_date'] = 'The decision date cannot be in the future.';
        } else {
            $start = (string) ($row['leave_start_date'] ?? '');

            if ($start !== '' && $date < $start) {
                $errors['not_returned_date'] = 'The decision cannot predate the leave start date.';
            }
        }

        if ($remarks === '') {
            $errors['not_returned_remarks'] = 'Remarks are required - record what was decided.';
        }

        return $errors;
    }

    /**
     * Close a leave out WITHOUT an arrival - management has confirmed the
     * driver is not coming back.
     *
     * The opposite of cancel(): the leave really happened, the driver really
     * left, they simply did not return. `actual_arrival_date` is deliberately
     * left NULL - inventing an arrival date would make the record
     * indistinguishable from a normal return.
     *
     * The reason, decision date, remarks and the user who confirmed them are
     * stored alongside the status, so "who closed this, when and why" is still
     * answerable years later - which is the whole point of calling it
     * Not Returned rather than Never Returned.
     *
     * Like cancelled, this status is manual: Status::sync() leaves it alone,
     * so it cannot be reverted to "overdue" on the next page load.
     *
     * @param array<string, mixed> $input already validated by validateNotReturned()
     */
    public static function markNotReturned(int $id, array $input): void
    {
        $userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        Database::update('leave_records', [
            'status'               => Status::NOT_RETURNED,
            'not_returned_reason'  => trim((string) ($input['not_returned_reason'] ?? '')),
            'not_returned_date'    => trim((string) ($input['not_returned_date'] ?? '')),
            'not_returned_by'      => $userId,
            'not_returned_remarks' => trim((string) ($input['not_returned_remarks'] ?? '')),
            'updated_by'           => $userId,
            'updated_at'           => now(),
        ], 'id = ?', [$id]);

        self::syncApplication($id);
    }

    /**
     * May this record be closed out as not returned?
     *
     * Only a driver who is physically away right now (Status::AWAY) qualifies:
     * a planned trip has not started, and somebody already returned or
     * cancelled has a terminal status.
     */
    public static function canMarkNotReturned(array $row): bool
    {
        if (($row['actual_arrival_date'] ?? null) !== null && ($row['actual_arrival_date'] ?? '') !== '') {
            return false;
        }

        return in_array((string)($row['status'] ?? ''), Status::AWAY, true);
    }

    /**
     * May an administrator hand-correct this record's status?
     *
     * Only the two MANUAL, closed statuses qualify. They are dead ends by
     * design - Status::sync() never touches them - so nothing else in the app
     * can move them again (Not Returned in particular: the driver later turns
     * up, and there is no undo). Derived statuses need no override: they are
     * corrected by editing the dates or by Mark Returned, and the engine
     * recomputes the status on the next page load.
     */
    public static function canChangeStatus(array $row): bool
    {
        return in_array((string)($row['status'] ?? ''), Status::CLOSED, true);
    }

    /**
     * Validate a hand-applied status change. The API owns the rules; the
     * modal does no checking of its own, so every message shown to the user
     * is one the server would enforce anyway.
     *
     * The chosen target must be DERIVABLE from the record's dates once the
     * modal's fields are applied: Status::sync() recomputes every non-manual
     * status on each page load, so a status the dates disagree with would
     * silently flip back. The errors name the date that stands in the way.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $leave the record as it is now
     * @return array<string, string>
     */
    public static function validateStatusChange(array $input, array $leave): array
    {
        $v = new \App\Core\Validator($input);

        $v->required('new_status', 'New status')
            ->in('new_status', 'New status', [Status::RETURNED, Status::ON_LEAVE, Status::PLANNED])
            ->maxLength('remarks', 'Remarks', 500)
            ->date('actual_arrival_date', 'Actual arrival date')
            ->date('expected_arrival_date', 'New expected return date');

        if (!self::canChangeStatus($leave)) {
            $v->addError('new_status', 'Only a closed record (Not Returned or Cancelled) can be re-opened.');
        }

        $target   = $v->text('new_status');
        $start    = (string) ($leave['leave_start_date'] ?? '');
        $expected = (string) ($leave['expected_arrival_date'] ?? '');

        if ($target === Status::RETURNED) {
            // Same rule as Mark Returned: the arrival cannot predate the trip.
            $v->required('actual_arrival_date', 'Actual arrival date');
            $actual = $v->dateValue('actual_arrival_date');

            if ($actual !== null && $actual < $start) {
                $v->addError('actual_arrival_date', 'Actual arrival cannot be before the leave start date.');
            }
        }

        if ($target === Status::ON_LEAVE) {
            $new = $v->dateValue('expected_arrival_date');

            if ($new !== null && $new < date('Y-m-d')) {
                $v->addError('expected_arrival_date', 'A new expected return date must be today or later.');
            }

            $final   = $new ?? $expected;
            $derived = Status::derive($start, $final, null);

            if ($derived !== Status::ON_LEAVE) {
                $v->addError('expected_arrival_date', $derived === Status::PLANNED
                    ? 'That leave has not started yet - reopen it as Planned instead.'
                    : 'The expected return date has already passed - enter a new expected return date (today or later) to reopen as On leave.');
            }
        }

        if ($target === Status::PLANNED) {
            if (Status::derive($start, $expected, null) !== Status::PLANNED) {
                $v->addError('new_status', 'That leave cannot show as Planned - it has already started. Reopen it as On leave or Returned instead.');
            }
        }

        return $v->errors();
    }

    /**
     * Apply an administrator's status correction (validated first).
     *
     * The closure facts belong to the closure: leaving Not Returned clears
     * the four not_returned_* fields, leaving Cancelled clears the reason -
     * a reopened record whose details still said "Resigned" would be a lie.
     * The audit log keeps the old values, which is where a reversal belongs.
     * Everything written agrees with the dates, so Status::sync() leaves the
     * record alone afterwards.
     *
     * @param array<string, mixed> $input  already validated
     * @param array<string, mixed> $leave  the record as it was before the change
     */
    public static function changeStatus(int $id, array $leave, array $input): void
    {
        $userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $v      = new \App\Core\Validator($input);
        $target = $v->text('new_status');

        $data = [
            'status'     => $target,
            'updated_by' => $userId,
            'updated_at' => now(),
        ];

        if ($target === Status::RETURNED) {
            $data['actual_arrival_date'] = $v->dateValue('actual_arrival_date');
            $data['actual_arrival_time'] = null;

            $remarks = $v->nullable('remarks');

            if ($remarks !== null) {
                $data['return_remarks'] = $remarks;
            }
        } else {
            // Re-opening: no arrival is on file any more (a cancelled record
            // can carry one), and "extended" moves the expected date.
            $data['actual_arrival_date'] = null;
            $data['actual_arrival_time'] = null;

            $new = $v->dateValue('expected_arrival_date');

            if ($target === Status::ON_LEAVE && $new !== null) {
                $data['expected_arrival_date'] = $new;
            }
        }

        if ((string) ($leave['status'] ?? '') === Status::NOT_RETURNED) {
            $data['not_returned_reason']  = null;
            $data['not_returned_date']    = null;
            $data['not_returned_by']      = null;
            $data['not_returned_remarks'] = null;
        }

        if ((string) ($leave['status'] ?? '') === Status::CANCELLED) {
            $data['cancelled_reason'] = null;
        }

        Database::update('leave_records', $data, 'id = ?', [$id]);

        self::syncApplication($id);
    }

    /** Can this record still be marked returned? */
    /**
     * May this record be marked as returned?
     *
     * "Returned" only means something once the driver has actually left. A
     * planned trip has not started, so there is nobody to bring back - and
     * markReturned() insists on an actual arrival on or after the leave
     * start, which for a planned record is a date in the future.
     *
     * Status::AWAY is the status engine's own definition of "physically away
     * right now", so ask it instead of re-inventing the rule here. The
     * actual-arrival check stays as belt and braces against a stale row.
     */
    public static function canMarkReturned(array $row): bool
    {
        return $row['actual_arrival_date'] === null
            && in_array((string)($row['status'] ?? ''), Status::AWAY, true);
    }

    /** Calendar feed rows for a date window. */
    public static function forCalendar(string $start, string $end, array $filters = []): array
    {
        [$where, $p] = self::where($filters);

        // A cancelled trip is not a plan. Drawing it on the calendar reads as
        // time the driver is away when they are not - exactly the misleading
        // view this rule exists to prevent. Cancelled rows stay in Leave
        // Records, where they can be reviewed and deleted.
        $where .= ' AND lr.status <> ?';
        $p[]    = Status::CANCELLED;

        $where .= ' AND lr.leave_start_date <= ? AND lr.expected_arrival_date >= ?';
        array_push($p, $end, $start);

        return Database::all(
            self::SELECT . self::FROM . $where
            . ' ORDER BY lr.leave_start_date ASC, d.driver_name ASC',
            $p
        );
    }
}



