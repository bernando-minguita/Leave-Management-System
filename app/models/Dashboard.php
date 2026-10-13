<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Status;

/**
 * Read model for the operational dashboard.
 *
 * All figures are produced with indexed aggregate queries; no data is loaded
 * into PHP just to count it.
 */
final class Dashboard
{
    /**
     * Current leave grouped by branch (chart 1).
     *
     * Joins lr.branch_id - the branch recorded on the leave itself - and NOT
     * d.branch_id. drivers.branch_id is the driver's CURRENT branch: it is
     * null for many drivers and can change at any time, which would disagree
     * with the Leave Records grid, its export and its branch filter - all of
     * which read the snapshot.
     */
    public static function awayByBranch(): array
    {
        return Database::all(
            'SELECT COALESCE(b.branch_name, "Unassigned") AS label,
                    COUNT(*) AS total
               FROM leave_records lr
          LEFT JOIN drivers d ON d.id = lr.driver_id
          LEFT JOIN branches b ON b.id = lr.branch_id
              WHERE lr.status IN ("on_leave","overdue")
           GROUP BY b.id, b.branch_name
           ORDER BY total DESC'
        );
    }

    /** Current leave grouped by agency (chart 2). */
    public static function awayByAgency(): array
    {
        return Database::all(
            'SELECT COALESCE(a.agency_name, "Unassigned") AS label,
                    COUNT(*) AS total
               FROM leave_records lr
          LEFT JOIN drivers d ON d.id = lr.driver_id
          LEFT JOIN agencies a ON a.id = d.agency_id
              WHERE lr.status IN ("on_leave","overdue")
           GROUP BY a.id, a.agency_name
           ORDER BY total DESC'
        );
    }

    /**
     * Monthly leave trend for a period (chart 3).
     *
     * @param string $from Y-m-d
     * @param string $to   Y-m-d
     * @return array<int, array{label:string, total:int}>
     */
    public static function leaveTrend(string $from, string $to): array
    {
        $rows = Database::all(
            'SELECT DATE_FORMAT(lr.leave_start_date, "%Y-%m") AS month_key,
                    COUNT(*) AS total
               FROM leave_records lr
              WHERE lr.status NOT IN ("cancelled","not_returned")
                AND lr.leave_start_date BETWEEN ? AND ?
           GROUP BY month_key
           ORDER BY month_key ASC',
            [$from, $to]
        );

        // Fill the gaps so the line chart has one point per month.
        return self::fillMonths($from, $to, $rows);
    }

    /* ----------------------------------------- arrivals & applications -- */

    /**
     * Three-series trend behind the "Applications & Arrivals" chart.
     *
     * Two registers, two date bases - each one chosen because it is the date
     * the business actually feels:
     *
     *   pending / approved   leave_applications, bucketed on last working date
     *   arrivals             leave_records,      bucketed on expected return
     *
     * Cancelled rows are left out of every series: a cancelled trip never
     * produced an arrival, and a cancelled application is no longer asking
     * anyone for a decision.
     *
     * @param string $from Y-m-d
     * @param string $to   Y-m-d
     * @return array{labels: string[], pending: int[], approved: int[], arrivals: int[]}
     */
    public static function arrivalsTrend(string $from, string $to): array
    {
        $pending  = self::fillMonths($from, $to, self::applicationMonths($from, $to, 'pending'));
        $approved = self::fillMonths($from, $to, self::applicationMonths($from, $to, 'approved'));
        $arrivals = self::fillMonths($from, $to, self::arrivalMonths($from, $to));

        // Every series is built from the same month axis, so they stay
        // index-aligned for Chart.js: one shared label list, three counts.
        $labels = [];

        foreach ($pending as $row) {
            $labels[] = $row['label'];
        }

        return [
            'labels'   => $labels,
            'pending'  => array_column($pending, 'total'),
            'approved' => array_column($approved, 'total'),
            'arrivals' => array_column($arrivals, 'total'),
        ];
    }

    /**
     * Per-branch, per-month figures behind the three summary tables.
     *
     * Each table gets one column per branch CODE plus a row total, a column
     * total and a grand total - so every number on the card can be checked
     * across or down.
     *
     * Applications are matched on the leave type NAME rather than a hard-coded
     * id: the seeded types are "Resignation" and "Annual Vacation", and an admin
     * who renames one or adds a variant keeps the card working as long as the
     * word survives. A NULL last working date cannot be placed in a month, so
     * those rows are left out rather than filed under an invented one.
     *
     * Branch is the SNAPSHOT recorded on the row (lr.branch_id / la.branch_id),
     * never the driver's current branch - the rule every other breakdown on
     * this system follows.
     *
     * @param string $from Y-m-d
     * @param string $to   Y-m-d
     * @return array{
     *   branches: array<string, string>,
     *   totals: array{arrivals: int, resignations: int, vacations: int},
     *   series: array<string, array{
     *     months: array<int, array{label: string, cells: array<string, int>, total: int}>,
     *     byBranch: array<string, int>,
     *     total: int
     *   }>
     * }
     */
    public static function arrivalSummary(string $from, string $to): array
    {
        $codes  = self::branchCodes();
        $series = [
            'arrivals'     => self::fillMonthBranch($from, $to, self::arrivalsByBranch($from, $to), $codes),
            'resignations' => self::fillMonthBranch($from, $to, self::applicationsByBranch($from, $to, 'resign'), $codes),
            'vacations'    => self::fillMonthBranch($from, $to, self::applicationsByBranch($from, $to, 'vacation'), $codes),
        ];

        // A row can carry a branch code the branches table does not list:
        // branch_id is NULL (COALESCE renders it as "-") or the branch row has
        // been deleted while its history stayed. Those counts are real, so they
        // must be given a column - otherwise the printed cells add up to less
        // than the row total and the whole grid stops footing.
        foreach ($series as $s) {
            foreach (array_keys($s['byBranch']) as $code) {
                if (!isset($codes[$code])) {
                    $codes[$code] = $code === '-' ? 'No branch' : $code;
                }
            }
        }

        // The three KPI cards read totals.*; the tables read series.*. Both
        // come from the same numbers, so a card can never disagree with the
        // grid underneath it.
        return [
            'branches' => $codes,
            'totals'   => [
                'arrivals'     => $series['arrivals']['total'],
                'resignations' => $series['resignations']['total'],
                'vacations'    => $series['vacations']['total'],
            ],
            'series'   => $series,
        ];
    }

    /**
     * Named operational buckets used by the dashboard panels and the
     * "expected return" strip.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function returnBuckets(): array
    {
        $today    = today();
        $tomorrow = date_add_days($today, 1);
        $weekEnd  = date_add_days($today, 6);

        return [
            'today'    => \App\Models\LeaveRecord::expectedArrivals($today, $today, [], 50),
            'tomorrow' => \App\Models\LeaveRecord::expectedArrivals($tomorrow, $tomorrow, [], 50),
            'week'     => \App\Models\LeaveRecord::expectedArrivals($today, $weekEnd, [], 200),
            'overdue'  => \App\Models\LeaveRecord::overdue([], 50),
        ];
    }

    /* ---------------------------------------- summary report aggregates - */

    /**
     * How many days ahead a pending application's last working date may sit
     * and still be counted by the summary report.
     *
     * The cutoff always rolls forward with today's date, so the figure keeps
     * meaning the same thing whenever the report is generated - no deadline
     * is written into the code. A blank or nonsense value falls back to 30
     * days rather than blanking the KPI.
     */
    public static function pendingWindowDays(): int
    {
        $days = (int) trim(Lookup::setting('pending_window_days', '30'));

        return $days >= 0 && $days <= 3650 ? $days : 30;
    }

    /**
     * The window's far edge: pending applications ON or BEFORE this date are
     * counted, working backwards from it.
     *
     * @param string $today Y-m-d
     */
    public static function pendingCutoff(string $today): string
    {
        $stamp = strtotime($today);

        if ($stamp === false) {
            $stamp = time();
        }

        return date('Y-m-d', strtotime('+' . self::pendingWindowDays() . ' days', $stamp));
    }

    /**
     * Pending applications inside the window - the report's "Pending
     * applications" KPI.
     *
     * Strictly status = pending: rejected and cancelled applications are not
     * waiting on anyone, and an approved one already has its decision. A NULL
     * last working date cannot be placed in the window at all, so it is left
     * out rather than silently counted.
     *
     * @param string $today Y-m-d
     */
    public static function pendingApplications(string $today): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM leave_applications
              WHERE status = "pending"
                AND last_working_date IS NOT NULL
                AND last_working_date <= ?',
            [self::pendingCutoff($today)]
        );
    }

    /**
     * Per-branch figures behind the report's breakdown table.
     *
     * leave_records and leave_applications are deliberately separate
     * registers, so the leave side and the application side are counted with
     * two queries and joined in PHP on the branch. Both keep the definitions
     * LeaveRecord::kpis() already uses - "on leave" reads the stored status
     * and "overdue" the date rule - so the table agrees with the dashboard
     * instead of drifting away from it.
     *
     * Branch is the SNAPSHOT recorded on the row (lr.branch_id and
     * la.branch_id), never the driver's current branch - the same rule
     * awayByBranch() has always used, so moving a driver later cannot rewrite
     * what these figures said today.
     *
     * @return array<int, array{label:string, on_leave:int, overdue:int, pending:int}>
     */
    public static function branchBreakdown(string $today): array
    {
        $byBranch = [];

        $label = static function ($raw): string {
            return ($raw === null || trim((string) $raw) === '')
                ? 'Unassigned'
                : (string) $raw;
        };

        foreach (Database::all(
            'SELECT b.branch_name,
                    SUM(lr.status IN ("on_leave","overdue")) AS on_leave,
                    SUM(lr.actual_arrival_date IS NULL
                        AND lr.status NOT IN ("cancelled","not_returned")
                        AND lr.expected_arrival_date < ?)      AS overdue
               FROM leave_records lr
          LEFT JOIN branches b ON b.id = lr.branch_id
           GROUP BY b.id, b.branch_name',
            [$today]
        ) as $row) {
            $key = $label($row['branch_name']);

            $byBranch[$key] = [
                'label'    => $key,
                'on_leave' => (int) $row['on_leave'],
                'overdue'  => (int) $row['overdue'],
                'pending'  => 0,
            ];
        }

        foreach (Database::all(
            'SELECT b.branch_name, COUNT(*) AS pending
               FROM leave_applications la
          LEFT JOIN branches b ON b.id = la.branch_id
              WHERE la.status = "pending"
                AND la.last_working_date IS NOT NULL
                AND la.last_working_date <= ?
           GROUP BY b.id, b.branch_name',
            [self::pendingCutoff($today)]
        ) as $row) {
            $key = $label($row['branch_name']);

            if (!isset($byBranch[$key])) {
                $byBranch[$key] = ['label' => $key, 'on_leave' => 0, 'overdue' => 0, 'pending' => 0];
            }

            $byBranch[$key]['pending'] = (int) $row['pending'];
        }

        // Loudest branch first, so the table reads as a ranked list without
        // the reader having to sort it themselves.
        usort(
            $byBranch,
            static fn (array $a, array $b): int
                => [$b['on_leave'], $b['overdue'], $a['label']] <=> [$a['on_leave'], $a['overdue'], $b['label']]
        );

        return $byBranch;
    }

    /**
     * The fixed leave percentage and how the live figure compares against it.
     *
     * "Allowed" is that share of the fleet rounded DOWN - a fraction of a
     * driver cannot be approved. Nothing is reported as over or under target
     * until the setting exists, so a missing value can never make the report
     * claim a breach that was never configured.
     *
     * @return array<string, int|float|bool>
     */
    public static function leavePolicy(): array
    {
        $kpis       = LeaveRecord::kpis();
        $total      = (int) $kpis['total_drivers'];
        $onLeave    = (int) $kpis['on_leave'];
        $targetPct  = (float) trim(Lookup::setting('leave_percentage', '0'));
        $configured = $targetPct > 0;
        $livePct    = $total > 0 ? round(($onLeave / $total) * 100, 2) : 0.0;
        $allowed    = $configured && $total > 0
            ? (int) floor(($targetPct / 100) * $total)
            : 0;

        return [
            'configured' => $configured,
            'target_pct' => $targetPct,
            'live_pct'   => $livePct,
            'allowed'    => $allowed,
            'on_leave'   => $onLeave,
            'total'      => $total,
            'headroom'   => max(0, $allowed - $onLeave),
            'over'       => $configured && $onLeave > $allowed,
        ];
    }

    /**
     * One bucket per calendar month of $from..$to, gaps zero-filled.
     *
     * @param array<int, array<string, mixed>> $rows rows carrying month_key + total
     * @return array<int, array{label: string, total: int}>
     */
    private static function fillMonths(string $from, string $to, array $rows): array
    {
        $byKey = [];

        foreach ($rows as $row) {
            $key = $row['month_key'] ?? null;

            if ($key === null || $key === '') {
                continue;
            }

            $byKey[(string)$key] = (int)($row['total'] ?? 0);
        }

        $series = [];

        foreach (self::monthAxis($from, $to) as $month) {
            $series[] = ['label' => $month['label'], 'total' => $byKey[$month['key']] ?? 0];
        }

        return $series;
    }

    /**
     * Calendar months covered by $from..$to as key/label pairs.
     *
     * NOTE: date() returns a *formatted string* here. Casting that to int
     * yields 2025 (the year), i.e. 2025 seconds after the epoch - so the
     * series started at "Jan 1970" and crawled forward ~680 months, which
     * is what made the chart look broken. Round-trip through strtotime.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private static function monthAxis(string $from, string $to): array
    {
        $cursor = strtotime($from . ' 00:00:00');
        $end    = strtotime($to . ' 00:00:00');

        if ($cursor === false || $end === false || $cursor > $end) {
            return [];
        }

        // Normalise to the first of the starting month.
        $cursor = (int)strtotime(date('Y-m-01', $cursor));

        $axis = [];

        while ($cursor <= $end) {
            $axis[] = ['key' => date('Y-m', $cursor), 'label' => date('M Y', $cursor)];

            $next = strtotime('+1 month', $cursor);

            if ($next === false) {
                break;
            }

            $cursor = $next;
        }

        return $axis;
    }

    /** Applications bucketed by last working date for one decision status. */
    private static function applicationMonths(string $from, string $to, string $status): array
    {
        return Database::all(
            'SELECT DATE_FORMAT(la.last_working_date, "%Y-%m") AS month_key,
                    COUNT(*) AS total
               FROM leave_applications la
              WHERE la.status = ?
                AND la.last_working_date IS NOT NULL
                AND la.last_working_date BETWEEN ? AND ?
           GROUP BY month_key',
            [$status, $from, $to]
        );
    }

    /** Leave records bucketed by expected return date. */
    /**
     * Expected returns bucketed by month - "still due back", not "was due
     * back".
     *
     * `actual_arrival_date IS NULL` is the whole point: without it a driver
     * who landed on the 22nd still counts for that month, so the dashboard
     * total ran one (or more) ahead of Current Leave, which only ever lists
     * people who are still away. Same reason a landed driver must not appear
     * in the arrival cards or the trend line.
     *
     * `status <> "planned"` for the same reason turned inside out: a trip
     * that has not started has no arrival to expect - the driver has not
     * gone away yet. Planned rows belong to "Leave Starting", not to this
     * count (round 6, item 3).
     */
    private static function arrivalMonths(string $from, string $to): array
    {
        return Database::all(
            'SELECT DATE_FORMAT(lr.expected_arrival_date, "%Y-%m") AS month_key,
                    COUNT(*) AS total
               FROM leave_records lr
              WHERE lr.status NOT IN ("cancelled","not_returned","planned")
                AND lr.actual_arrival_date IS NULL
                AND lr.expected_arrival_date IS NOT NULL
                AND lr.expected_arrival_date BETWEEN ? AND ?
           GROUP BY month_key',
            [$from, $to]
        );
    }

    /**
     * Branch code => branch name, in name order.
     *
     * The code is the column heading (short enough to keep the grid readable);
     * the name rides along as the tooltip.
     *
     * @return array<string, string>
     */
    private static function branchCodes(): array
    {
        $codes = [];

        foreach (Database::all('SELECT code, branch_name FROM branches ORDER BY branch_name, code') as $row) {
            $code = trim((string)($row['code'] ?? ''));
            $name = trim((string)($row['branch_name'] ?? ''));

            if ($code !== '') {
                $codes[$code] = $name === '' ? $code : $name;
            }
        }

        return $codes;
    }

    /**
     * Expected returns bucketed by month AND the branch on the record.
     *
     * Same "still due back" rule as arrivalMonths(): only rows with no actual
     * arrival date count, so a driver who has already landed drops out of the
     * card, the table and the trend together - and a trip that has not started
     * drops out with them, because there is no arrival to expect yet.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function arrivalsByBranch(string $from, string $to): array
    {
        return Database::all(
            'SELECT DATE_FORMAT(lr.expected_arrival_date, "%Y-%m") AS month_key,
                    COALESCE(b.code, "-") AS branch_code,
                    COUNT(*) AS total
               FROM leave_records lr
          LEFT JOIN branches b ON b.id = lr.branch_id
              WHERE lr.status NOT IN ("cancelled","not_returned","planned")
                AND lr.actual_arrival_date IS NULL
                AND lr.expected_arrival_date IS NOT NULL
                AND lr.expected_arrival_date BETWEEN ? AND ?
           GROUP BY month_key, b.id, b.code',
            [$from, $to]
        );
    }

    /**
     * Leave-type predicate for one of the two application families.
     *
     *   'resign'   the resignation family: name LIKE %resign%, exactly as the
     *              "Resignation Applications" card has always counted it.
     *   'vacation' EVERY leave type except the three that mean the driver is
     *              leaving the company (Resignation, Terminated, Absconded).
     *              The card asks "how many vacation applications are in the
     *              pipeline", not "how many Annual Vacation applications" - so
     *              sick, emergency, Umrah and unpaid requests now count, and a
     *              departure does not.
     *
     * Matched on the leave type NAME, the convention this model and
     * CapacityPlanningService::EXIT_PATTERNS already share, so a renamed or
     * extra type keeps working as long as the word survives.
     *
     * @return array{0: string, 1: array<int, string>} [sql predicate, params]
     */
    private static function applicationFamilySql(string $family): array
    {
        if ($family === 'resign') {
            return ['LOWER(lt.name) LIKE ?', ['%resign%']];
        }

        $excluded = ['%resign%', '%terminat%', '%abscond%'];
        $notLike  = implode(' AND ', array_fill(0, count($excluded), 'LOWER(lt.name) NOT LIKE ?'));

        return [$notLike, $excluded];
    }

    /**
     * Applications bucketed by month and branch for a leave-type family.
     *
     * @param string $family 'resign' | 'vacation'
     * @return array<int, array<string, mixed>>
     */
    private static function applicationsByBranch(string $from, string $to, string $family): array
    {
        [$types, $typeP] = self::applicationFamilySql($family);

        return Database::all(
            'SELECT DATE_FORMAT(la.last_working_date, "%Y-%m") AS month_key,
                    COALESCE(b.code, "-") AS branch_code,
                    COUNT(*) AS total
               FROM leave_applications la
               JOIN leave_types lt ON lt.id = la.leave_type_id
          LEFT JOIN branches b ON b.id = la.branch_id
              WHERE ' . $types . '
                AND la.status <> "cancelled"
                AND la.last_working_date IS NOT NULL
                AND la.last_working_date BETWEEN ? AND ?
           GROUP BY month_key, b.id, b.code',
            array_merge($typeP, [$from, $to])
        );
    }

    /**
     * Dense month x branch grid for one series: every month gets a row, every
     * branch a column, and the gaps come back as 0 rather than missing cells.
     *
     * A code the branch list does not know about (a branch whose row has gone
     * but whose history has not) is still given its column instead of being
     * silently folded into another one.
     *
     * @param array<int, array<string, mixed>> $rows rows carrying month_key, branch_code, total
     * @param array<string, string> $codes branch code => name, in column order
     * @return array{months: array<int, array{label: string, cells: array<string, int>, total: int}>,
     *               byBranch: array<string, int>, total: int}
     */
    private static function fillMonthBranch(string $from, string $to, array $rows, array $codes): array
    {
        $known = [];

        // $codes is code => name; only the KEYS become columns.
        foreach ($codes as $code => $name) {
            $known[$code] = 0;
        }

        $byKey = [];

        foreach ($rows as $row) {
            $key  = (string)($row['month_key'] ?? '');
            $code = (string)($row['branch_code'] ?? '');

            if ($key === '' || $code === '') {
                continue;
            }

            $byKey[$key][$code] = (int)($row['total'] ?? 0);

            if (!isset($known[$code])) {
                $known[$code] = 0;
            }
        }

        $months = [];

        foreach (self::monthAxis($from, $to) as $month) {
            $cells = $known;

            foreach ($byKey[$month['key']] ?? [] as $code => $count) {
                $cells[$code] = $count;
            }

            $months[] = [
                // key (YYYY-MM) lets the row carry its bucket to the
                // drill-down endpoint; label is only for display.
                'key'   => $month['key'],
                'label' => $month['label'],
                'cells' => $cells,
                'total' => array_sum($cells),
            ];
        }

        $byBranch = $known;

        foreach ($months as $month) {
            foreach ($month['cells'] as $code => $count) {
                $byBranch[$code] = ($byBranch[$code] ?? 0) + $count;
            }
        }

        return [
            'months'   => $months,
            'byBranch' => $byBranch,
            'total'    => array_sum($byBranch),
        ];
    }

    /* ----------------------------------- summary table drill-down ------- */

    /** The three summary tables' series keys, in card order. */
    public const SUMMARY_SERIES = ['arrivals', 'resignations', 'vacations'];

    /**
     * The drivers behind one month of one summary table (row-click modal).
     *
     * This MIRRORS arrivalSummary() exactly - same three series rules, same
     * month bucketing (expected return date / last working date), same
     * "branch is the snapshot on the record" rule - so total always
     * equals the number printed in that cell. If the aggregate and this list
     * ever disagree, one of them is a bug.
     *
     * Rows carry BOTH status axes, named the way the modal shows them:
     * `application_status` (the approval decision - pending / approved /
     * rejected, or NULL when an arrivals record has no application behind it)
     * and `leave_status` (the leave-progress axis). For the two application
     * series the planned-trip nuance is resolved through the SAME predicate
     * the Leave Applications register uses (LeaveApplication::hasPlannedTrip),
     * so one row can never read two ways on two screens.
     *
     * @param string $series    arrivals | resignations | vacations
     * @param string $month     any Y-m-d date inside the wanted month, or a Y-m key
     * @param string $branch    optional branch CODE as the table shows it
     *                           ("-" = the "No branch" column); null = whole row
     * @param int    $page      1-based page; ignored when $size is 0
     * @param int    $size      rows per page, 0 = NO paging (the historic
     *                           behaviour: every row of the month). The model
     *                           callers and the footing tests rely on that.
     * @return array{
     *   series: string, month: string, from: string, to: string,
     *   branch: string|null, total: int, page: int, size: int, pages: int,
     *   rows: array<int, array<string, mixed>>
     * }
     */
    public static function summaryDrivers(
        string $series,
        string $month,
        ?string $branch = null,
        int $page = 1,
        int $size = 0
    ): array {
        // Accept either a full date ("2026-10-17") or the month key ("2026-10").
        if (!preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', trim($month), $m)) {
            $month = date('Y-m');
            $year  = (int)substr($month, 0, 4);
            $mon   = (int)substr($month, 5, 2);
        } else {
            $year = (int)$m[1];
            $mon  = (int)$m[2];
        }

        if ($mon < 1 || $mon > 12) {
            $mon  = (int)date('n');
            $year = (int)date('Y');
        }

        $monthKey = sprintf('%04d-%02d', $year, $mon);
        $from     = $monthKey . '-01';
        $to       = date('Y-m-t', (int)strtotime($from));

        $rows = match ($series) {
            'arrivals'     => self::summaryArrivalRows($from, $to, $branch),
            'resignations' => self::summaryApplicationRows($from, $to, 'resign', $branch),
            'vacations'    => self::summaryApplicationRows($from, $to, 'vacation', $branch),
            default        => [],
        };

        // total is ALWAYS the whole month - the number the clicked cell
        // printed - while `rows` is the page on screen. Slicing here (rather
        // than in SQL) keeps one query and one footing rule; size 0 keeps the
        // historic every-row behaviour for the direct callers above.
        $total = count($rows);
        $pages = $size > 0 ? max(1, (int)ceil($total / $size)) : 1;
        $page  = min(max(1, $page), $pages);

        if ($size > 0) {
            $rows = array_slice($rows, ($page - 1) * $size, $size);
        }

        return [
            'series' => in_array($series, self::SUMMARY_SERIES, true) ? $series : '',
            'month'  => $monthKey,
            'from'   => $from,
            'to'     => $to,
            'branch' => $branch,
            'total'  => $total,
            'page'   => $page,
            'size'   => $size,
            'pages'  => $pages,
            'rows'   => $rows,
        ];
    }

    /**
     * Row-level form of arrivalsByBranch(): still-due-back records in range,
     * chronologically by EXPECTED ARRIVAL DATE - the date this series buckets
     * by - with the driver name as a tie-break.
     *
     * Carries BOTH axes the modal shows: leave_status (the record's own
     * derived status) and application_status - the approval decision of the
     * application behind the record, or NULL when the record was entered
     * directly and no filing exists (the modal reads that as an em dash).
     * The join is on la.id = PK, so it can never multiply rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function summaryArrivalRows(string $from, string $to, ?string $branch): array
    {
        $sql = 'SELECT lr.id AS record_id, d.id AS driver_id, d.driver_name,
                       d.badge_number, COALESCE(b.code, "-") AS branch_code,
                       b.branch_name, lr.expected_arrival_date AS event_date,
                       lr.status AS leave_status,
                       la.status AS application_status
                  FROM leave_records lr
                  JOIN drivers d  ON d.id = lr.driver_id
             LEFT JOIN branches b ON b.id = lr.branch_id
             LEFT JOIN leave_applications la ON la.id = lr.leave_application_id
                 WHERE lr.status NOT IN ("cancelled","not_returned","planned")
                   AND lr.actual_arrival_date IS NULL
                   AND lr.expected_arrival_date IS NOT NULL
                   AND lr.expected_arrival_date BETWEEN ? AND ?';
        $params = [$from, $to];

        // Same expression the GROUP BY buckets on, so the "No branch" column
        // ("-") drills down to exactly its own rows.
        if ($branch !== null && $branch !== '') {
            $sql      .= ' AND COALESCE(b.code, "-") = ?';
            $params[]  = $branch;
        }

        $sql .= ' ORDER BY lr.expected_arrival_date ASC, d.driver_name ASC, d.badge_number ASC';

        return Database::all($sql, $params);
    }

    /**
     * Row-level form of applicationsByBranch(): not-cancelled applications of
     * a leave-type family, placed by last working date. It has to mirror the
     * aggregate exactly - same predicate, same params - or a cell would drill
     * down to a different number of drivers than it prints.
     *
     * Carries BOTH axes the modal shows: application_status (the approval
     * decision) and leave_status (the leave-progress axis). leave_status is
     * resolved through LeaveApplication::hasPlannedTrip - the SAME predicate
     * the Leave Applications register and its export use - so a trip booked
     * for the future reads "planned" here exactly as it reads "Planned"
     * there. The join is on the UNIQUE leave_application_id, so it can never
     * multiply rows and break the footing above.
     *
     * @param string $family 'resign' | 'vacation'
     * @return array<int, array<string, mixed>>
     */
    private static function summaryApplicationRows(string $from, string $to, string $family, ?string $branch): array
    {
        [$types, $typeP] = self::applicationFamilySql($family);

        $sql = 'SELECT la.id AS application_id, d.id AS driver_id, d.driver_name,
                       d.badge_number, COALESCE(b.code, "-") AS branch_code,
                       b.branch_name, la.last_working_date AS event_date,
                       la.status AS application_status,
                       la.leave_status,
                       lr.status AS linked_record_status,
                       lt.name AS leave_type
                  FROM leave_applications la
                  JOIN drivers d      ON d.id = la.driver_id
                  JOIN leave_types lt ON lt.id = la.leave_type_id
             LEFT JOIN branches b     ON b.id = la.branch_id
             LEFT JOIN leave_records lr ON lr.leave_application_id = la.id
                 WHERE ' . $types . '
                   AND la.status <> "cancelled"
                   AND la.last_working_date IS NOT NULL
                   AND la.last_working_date BETWEEN ? AND ?';
        $params = array_merge($typeP, [$from, $to]);

        if ($branch !== null && $branch !== '') {
            $sql      .= ' AND COALESCE(b.code, "-") = ?';
            $params[]  = $branch;
        }

        $sql .= ' ORDER BY la.last_working_date ASC, d.driver_name ASC';

        return array_map(static function (array $row): array {
            // The register's rule, reused rather than re-invented: a linked
            // planned record means the trip is BOOKED, not merely undecided.
            if (LeaveApplication::hasPlannedTrip($row)) {
                $row['leave_status'] = Status::PLANNED;
            }

            return $row;
        }, Database::all($sql, $params));
    }

    /**
     * The rows behind ONE Leave Dashboard KPI card, paged.
     *
     * Every one of these cards used to carry a plain link to a page that had
     * to be re-filtered by hand to reach the same number, so the list and the
     * figure printed on the card never had to agree. This returns the card's
     * own set instead: each branch below reuses the SAME method that produces
     * that card's count, so the two cannot drift apart.
     *
     * Leave rows are fetched whole and sliced here rather than in SQL on
     * purpose - these lists are the size of a trip roster - and total is taken
     * from the card's own count, never from count($rows), so what the modal
     * claims is exactly what the card claimed.
     *
     * @param string|null $from optional Y-m-d window; required by the three
     *                           summary headline cards, which are totals over
     *                           the range in the page header rather than over
     *                           "today"
     * @return array<string, mixed>
     */
    public static function kpiDrivers(
        string $kpi,
        int $page,
        int $size,
        ?string $from = null,
        ?string $to = null
    ): array {
        $today   = today();
        // The SAME rolling seven-day window the cards, the "THIS WEEK" strip
        // and the Leave Dashboard all use - DashboardController, LeaveController
        // and LeaveRecord::countKpis() each write `date_add_days($today, 6)`.
        //
        // This used to read `max(0, $iso) - 1`, the ISO formula mirrored: on a
        // Monday that produced TODAY, so the card said "4 returning" while the
        // drill-down modal listed none. The card is the figure a reader trusts,
        // so the list has to be drawn from the card's own window.
        $weekEnd = date_add_days($today, 6);

        $titles = [
            'total_drivers'          => 'Total Active Drivers',
            'on_leave_pct'           => 'On Leave vs Total Drivers',
            'on_leave'               => 'Currently On Leave',
            'returning_week'         => 'Returning This Week',
            'starting_week'          => 'Leave Starting This Week',
            'overdue'                => 'Overdue Returns',
            // The three headline cards above the summary tables: their number
            // spans the range chosen in the page header, so they carry it.
            'expected_arrivals'      => 'Expected Returns',
            'resignation_applications' => 'Resignation Applications',
            'vacation_applications'  => 'Vacation Applications',
        ];

        if (!isset($titles[$kpi])) {
            return ['ok' => false];
        }

        $rangeCards = ['expected_arrivals', 'resignation_applications',
                       'vacation_applications'];

        if (in_array($kpi, $rangeCards, true)) {
            $ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from) === 1
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to) === 1
                && (string)$from <= (string)$to;

            if (!$ok) {
                return ['ok' => false];
            }
        }

        $eventColumn = 'expected_arrival_date';
        $rows        = [];
        $total       = 0;
        $offset      = ($page - 1) * $size;

        if ($kpi === 'total_drivers') {
            // Already paged: a fleet is the one list here that is not a
            // handful of trips, so SQL does the windowing.
            $res   = \App\Models\Driver::paginate(['active' => true], [], $size, $offset);
            $total = (int)$res['total'];
            $rows  = array_map(static function (array $r): array {
                return [
                    'driver_id'      => (int)($r['id'] ?? 0),
                    'badge_number'   => (string)($r['badge_number'] ?? ''),
                    'driver_name'    => (string)($r['driver_name'] ?? ''),
                    'branch_name'    => (string)($r['branch_name'] ?? ''),
                    'status'         => 'active',
                    'event_date'     => '',
                    'event_date_fmt' => '',
                ];
            }, (array)$res['rows']);
        } else {
            switch ($kpi) {
                case 'on_leave':
                case 'on_leave_pct':
                    $total = \App\Models\LeaveRecord::countCurrentlyAway();
                    $rows  = \App\Models\LeaveRecord::currentlyAway();
                    break;

                case 'returning_week':
                    $total = \App\Models\LeaveRecord::countExpected($today, $weekEnd);
                    $rows  = \App\Models\LeaveRecord::expectedArrivals($today, $weekEnd);
                    break;

                case 'starting_week':
                    $eventColumn = 'leave_start_date';
                    $total       = \App\Models\LeaveRecord::countStartingThisWeek($today, $weekEnd);
                    $rows        = \App\Models\LeaveRecord::startingThisWeek($today, $weekEnd);
                    break;

                // The three headline cards are the SAME totals the summary
                // tables print, over the range in the page header - so they go
                // to the same row builders, just with a range instead of a
                // month. total is count($rows) because that is what
                // summaryDrivers() reports for the matching cell.
                case 'expected_arrivals':
                    $eventColumn = 'event_date';
                    $rows        = self::summaryArrivalRows((string)$from, (string)$to, null);
                    $total       = count($rows);
                    break;

                case 'resignation_applications':
                case 'vacation_applications':
                    $eventColumn = 'event_date';
                    $rows        = self::summaryApplicationRows(
                        (string)$from,
                        (string)$to,
                        $kpi === 'resignation_applications' ? 'resign' : 'vacation',
                        null
                    );
                    $total       = count($rows);
                    break;

                default: // overdue
                    $total = \App\Models\LeaveRecord::countOverdue();
                    $rows  = \App\Models\LeaveRecord::overdue();
            }

            $pages = max(1, (int)ceil($total / max(1, $size)));
            $page  = max(1, min($page, $pages));
            $rows  = array_map(static function (array $r) use ($eventColumn): array {
                $date = (string)($r[$eventColumn] ?? '');

                return [
                    'driver_id'      => (int)($r['driver_id'] ?? 0),
                    'badge_number'   => (string)($r['badge_number'] ?? ''),
                    'driver_name'    => (string)($r['driver_name'] ?? ''),
                    'branch_name'    => (string)($r['branch_name'] ?? ''),
                    'status'         => (string)($r['status'] ?? ''),
                    'event_date'     => $date,
                    'event_date_fmt' => $date === '' ? '' : fmt_date($date),
                ];
            }, array_slice($rows, ($page - 1) * $size, $size));
        }

        $pages = max(1, (int)ceil($total / max(1, $size)));
        $page  = max(1, min($page, $pages));

        return [
            'ok'    => true,
            'kpi'   => $kpi,
            'title' => $titles[$kpi],
            // Windowed cards name THEIR window. The week cards always could;
            // the three range cards were showing a single date, which is what
            // made the same card read one way on this screen and another on
            // Capacity Planning (which prints "from - to").
            'label' => in_array($kpi, ['returning_week', 'starting_week'], true)
                ? fmt_date($today) . ' – ' . fmt_date($weekEnd)
                : (in_array($kpi, $rangeCards, true) && $from !== null && $to !== null
                    ? fmt_date($from) . ' – ' . fmt_date($to)
                    : fmt_date($today)),
            'rows'  => $rows,
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'pages' => $pages,
        ];
    }
}
