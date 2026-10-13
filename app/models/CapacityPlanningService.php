<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Driver Capacity Planning engine (spec: driver_capacity_planning_spec.md).
 *
 * The one rule the whole feature rests on (spec section 1):
 *
 *   Calculate driver capacity day-by-day first. Aggregate the daily results
 *   into monthly summaries second.
 *
 * So nothing here ever counts "applications submitted this month". It loads
 * the leaves overlapping the chosen window once, turns each into an interval
 * [leave start, end) per date, and walks the calendar day by day:
 *
 *   active(d)    = active drivers - not yet joined - resigned after LWD
 *   on_leave(d)  = drivers whose leave interval covers d   (driver counted once)
 *   available(d) = active(d) - drivers away on ANY leave type
 *   percentages  = part / active, 0 when active is 0
 *
 * A date's figures never read the stored `status` column: that value is
 * derived relative to TODAY (app/core/Status.php), so it would mislabel every
 * future date. Dates in, dates out - `today` is injectable for the tests.
 *
 * Two registers, deliberately kept apart (database/schema.sql:220):
 *   - leave_records        the leave itself      -> the daily on-leave intervals
 *   - leave_applications   approval workflow     -> ONLY the resignation
 *                          last-working dates that shrink the population
 * Resignation exits match the leave TYPE NAME (Resignation / Terminated /
 * Absconded), exactly like Dashboard::applicationsByBranch(), and NEVER
 * leave_records.last_working_date - that column is stamped on every record
 * (ordinary vacations included), so using it as an exit would remove the whole
 * workforce after their first holiday.
 */
final class CapacityPlanningService
{
    /** Plan of record: expected return drives the forecast (spec section 23). */
    public const MODE_FORECAST = 'forecast';

    /** Reality: actual arrival where it exists, expected where it does not. */
    public const MODE_ACTUAL = 'actual';

    public const MODES = [self::MODE_FORECAST, self::MODE_ACTUAL];

    /** Hard window cap: the page renders one row per calendar day (section 47). */
    public const MAX_DAYS = 400;

    /** End marker for a leave with no known return (still away / overdue). */
    private const OPEN = '9999-12-31';

    /** Leave type names that mean "this driver is leaving the company". */
    private const EXIT_PATTERNS = ['%resign%', '%terminat%', '%abscond%'];

    /**
     * Normalise the request into the filter set the engine runs on.
     *
     * @param array<string, mixed> $raw query string values
     * @return array<string, mixed>
     */
    public static function normalise(array $raw): array
    {
        $from = trim((string)($raw['from'] ?? ''));
        $to   = trim((string)($raw['to'] ?? ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || strtotime($from) === false) {
            $from = date('Y-m-01');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || strtotime($to) === false) {
            // Default window: this month plus the next two - a planning horizon,
            // not a historical report.
            $to = date('Y-m-t', strtotime('+2 months'));
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $clamped = false;
        $span    = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
        if ($span > self::MAX_DAYS) {
            $to      = date('Y-m-d', strtotime($from . ' +' . (self::MAX_DAYS - 1) . ' days'));
            $clamped = true;
        }

        $mode = ($raw['mode'] ?? '') === self::MODE_ACTUAL ? self::MODE_ACTUAL : self::MODE_FORECAST;

        // The day-by-day table shows ONE month (section 17). Default to the
        // month the user is looking at, otherwise the first month in range.
        $dailyMonth = (string)($raw['daily_month'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $dailyMonth) !== 1) {
            $today      = today();
            $dailyMonth = ($today >= $from && $today <= $to)
                ? substr($today, 0, 7)
                : substr($from, 0, 7);
        }
        if ($dailyMonth . '-01' > $to || date('Y-m-t', strtotime($dailyMonth . '-01')) < $from) {
            $dailyMonth = substr($from, 0, 7);
        }

        return [
            'from'          => $from,
            'to'            => $to,
            'mode'          => $mode,
            'daily_month'   => $dailyMonth,
            'branch_id'     => filter_values($raw['branch_id'] ?? ''),
            'agency_id'     => filter_values($raw['agency_id'] ?? ''),
            'leave_type_id' => filter_values($raw['leave_type_id'] ?? ''),
            'clamped'       => $clamped,
        ];
    }

    /**
     * Build the whole page payload in one pass.
     *
     * @param array<string, mixed> $f     filters from normalise()
     * @param string|null          $today injectable clock (tests)
     * @return array<string, mixed>
     */
    public static function calculate(array $f, ?string $today = null): array
    {
        $today = $today ?? today();
        $from  = $f['from'];
        $to    = $f['to'];

        // The live KPI cards answer "today" even when the window sits
        // elsewhere, so EVERYTHING the walk touches - record fetch, joins,
        // exits, day coverage - spans the earlier of (window, today) to the
        // later of (to, today). Clipping to the window alone reported
        // On Leave = 0 for a `today` outside it: a trip that started before
        // `from` was clipped away entirely (range 30 Sep -> 30 Nov with
        // today 29 Sep showed nobody away while the forecast under it said
        // a dozen drivers were out).
        $walkFrom = min($from, $today);
        $walkTo   = max($to, $today);

        [$base, $joinDates, $exits] = self::population($f, $walkFrom, $walkTo);
        $records = self::leaveRecords($f, $walkFrom, $walkTo, $today);

        // Expected Leave per day: applications that are expected to leave but
        // have not become a record yet. One query over the walked range feeds
        // the day-by-day column and (through monthly()) the monthly sum;
        // movement() asks for its own window slice.
        // Expected Leave per day: applications that are expected to leave but
        // have not become a record yet. One query over the walked range (type
        // filter NOT applied - the projection below needs every type, exactly
        // like records; the COLUMN below narrows to the filter, matching the
        // movement events) feeds the day-by-day column, the projected absence
        // and (through monthly()) the monthly sum; movement() asks for its own
        // window slice.
        $expectedAll = $f;
        $expectedAll['leave_type_id'] = [];
        $expectedRows  = self::expectedLeaveRows($expectedAll, $walkFrom, $walkTo);
        $expectedByDay = [];
        foreach ($expectedRows as $app) {
            if ($f['leave_type_id'] !== []
                && !in_array((string)$app['leave_type_id'], $f['leave_type_id'], true)) {
                continue;   // narrowed like the movement events (spec 61)
            }
            $eventDay = (string)$app['event_date'];
            $expectedByDay[$eventDay] = ($expectedByDay[$eventDay] ?? 0) + 1;
        }

        // ---- per-date accumulators -----------------------------------------
        $away    = [];  // date => drivers away on any leave type
        $onLeave = [];  // date => drivers away on a leave type inside the filter
        $overdue = [];  // date => drivers past expected return with no arrival
        $returns = [];  // date => records whose end falls on that date
        $starts  = [];  // date => records whose leave starts on that date
        $details = [];  // date => [driver_id => drill-down row]

        // One driver can only resign once: keep the earliest working date.
        $exitByDriver = [];
        foreach ($exits as $exit) {
            $id = (int)$exit['driver_id'];
            $exitByDriver[$id] = isset($exitByDriver[$id])
                ? min($exitByDriver[$id], (string)$exit['lwd'])
                : (string)$exit['lwd'];
        }

        $byDriver = [];
        foreach ($records as $record) {
            $byDriver[(int)$record['driver_id']][] = $record;
        }

        foreach ($byDriver as $driverId => $driverRecords) {
            $typeMatched = [];  // date => record, only leave types inside the filter
            $anyCovered  = [];  // date => record, every leave type
            $exitLwd     = $exitByDriver[$driverId] ?? null;

            foreach ($driverRecords as $record) {
                $start = (string)$record['leave_start_date'];
                $end   = self::endFor($record, $f['mode'], $today);  // null = still away
                $matched = $f['leave_type_id'] === []
                    || in_array((string)$record['leave_type_id'], $f['leave_type_id'], true);

                // The raw facts of the record. The clipped $start/$end below
                // decide which days a driver is AWAY (joining date, last
                // working date); the movement and the "Leave Starts" column
                // report what the record itself says, so they tie out with the
                // dashboard's leave trend, which counts records, not clips.
                $rawStart = $start;
                $rawEnd   = $end;

                // A driver only belongs to the population between joining and
                // their resignation last-working date, so each interval is
                // clipped to those bounds instead of running past them.
                $join = $record['joining_date'] !== null ? (string)$record['joining_date'] : null;
                if ($join !== null && $join > $start) {
                    $start = $join;
                }
                if ($exitLwd !== null) {
                    $end = min($end ?? self::OPEN, date_add_days($exitLwd, 1));
                }
                if ($end !== null && $end !== self::OPEN && $end <= $start) {
                    continue;  // zero-length: returned the day it started
                }

                $first = max($start, $walkFrom);
                $last  = $end === null || $end === self::OPEN
                    ? $walkTo
                    : min(date_add_days($end, -1), $walkTo);

                if ($first <= $last && $first <= $walkTo && $last >= $walkFrom) {
                    for ($d = $first; $d <= $last; $d = date_add_days($d, 1)) {
                        if (!isset($anyCovered[$d])) {
                            $anyCovered[$d] = $record;
                        }
                        if ($matched && !isset($typeMatched[$d])) {
                            $typeMatched[$d] = $record;
                        }
                    }
                }

                // Capacity movement: the leave start, and the day it ends.
                // Both belong to the filtered view - a leave type filter is
                // what the user asked to see the effect of (section 61).
                //
                // "Expected Returns" counts returns that are STILL TO HAPPEN:
                // a driver whose arrival is already on record has returned and
                // is not an expected return (the dashboard drops them from its
                // worklist for the same reason - otherwise the same driver is
                // counted twice, once as expected and once as done). Actual
                // mode is the reality check and keeps every recorded arrival,
                // so a driver who came back late still lands on their real
                // date there (spec sections 23-24).
                //
                // Round 6, item 3: a record whose status is "planned" has not
                // even started, so there is no arrival to expect - the driver
                // has not gone away yet. Such a trip counts as UNAVAILABLE
                // capacity (spec section 9) and its start is a "Leave start"
                // event, but its return is not an expected return in either
                // mode. Dashboard::arrivalMonths() drops the same rows, so the
                // dashboard card and this column agree.
                $hasArrived = $record['actual_arrival_date'] !== null
                    && $record['actual_arrival_date'] !== '';
                $notStarted = (string)$record['status'] === 'planned';

                $countReturn = !$notStarted
                    && ($f['mode'] === self::MODE_ACTUAL || !$hasArrived);

                if ($matched && $rawStart >= $from && $rawStart <= $to) {
                    $starts[$rawStart] = ($starts[$rawStart] ?? 0) + 1;
                }
                if ($matched && $countReturn && $rawEnd !== null && $rawEnd >= $from && $rawEnd <= $to) {
                    $returns[$rawEnd] = ($returns[$rawEnd] ?? 0) + 1;
                }
            }

            foreach ($anyCovered as $d => $record) {
                $away[$d] = ($away[$d] ?? 0) + 1;

                // Overdue: past the expected return and still not back as of
                // the date being simulated - never the stored status, which is
                // relative to today (Status::derive).
                $backBy = $record['actual_arrival_date'] !== null && $record['actual_arrival_date'] !== ''
                    ? (string)$record['actual_arrival_date']
                    : null;
                if ((string)$record['expected_arrival_date'] < $d
                    && ($backBy === null || $backBy > $d)) {
                    $overdue[$d] = ($overdue[$d] ?? 0) + 1;
                }
            }

            foreach ($typeMatched as $d => $record) {
                $onLeave[$d] = ($onLeave[$d] ?? 0) + 1;
            }

            // Drill-down explains the On leave column: the filtered types when
            // a leave type filter is on, every leave otherwise (section 61).
            $attributed = $f['leave_type_id'] !== [] ? $typeMatched : $anyCovered;
            foreach ($attributed as $d => $record) {
                $details[$d][$driverId] = [
                    'driver_id'            => $driverId,
                    'driver_name'          => (string)$record['driver_name'],
                    'badge_number'         => (string)$record['badge_number'],
                    'leave_type'           => (string)$record['leave_type'],
                    'leave_start_date'     => (string)$record['leave_start_date'],
                    'expected_arrival_date'=> (string)$record['expected_arrival_date'],
                    'actual_arrival_date'  => $record['actual_arrival_date'] !== null
                        ? (string)$record['actual_arrival_date']
                        : null,
                    // The stored status straight from the row: the drill-down
                    // says "Planned" for a trip that has not started rather
                    // than leaving a future absence unexplained (planned
                    // leaves count as unavailable capacity, same as started).
                    'status'               => (string)$record['status'],
                    'status_label'         => self::statusLabel((string)$record['status']),
                    // Same palette Capacity Movement renders server-side
                    // through status_badge(), so the modal and the table can
                    // never drift apart (helpers/functions.php holds it).
                    'badge_class'          => status_badge_class((string)$record['status']),
                    'badge_icon'           => status_icon((string)$record['status']),
                    'is_overdue'           => (string)$record['expected_arrival_date'] < $d
                        && ($record['actual_arrival_date'] === null
                            || $record['actual_arrival_date'] === ''
                            || (string)$record['actual_arrival_date'] > $d),
                ];
            }
        }

        // ---- projected absences ------------------------------------------
        // The SAME applications the Expected Leave column lists, counted as
        // away from the day after their last working date through the day
        // before their expected return: expectations are folded into On
        // Leave, Available, the trend chart, the monthly summary and the
        // forecast cards ("always project"). An application without an
        // expected return cannot be placed and stays out of these counts
        // (the quality panel flags it) - its column event still shows, but no
        // Capacity Movement row is raised for it either (see movement()). Same
        // clips as records: joining date, exit last working date, window
        // bounds. The overlap guards in expectedLeaveRows() make sure a trip
        // is either a record or an expectation - never both - so nothing is
        // counted twice.
        $projectedAway = [];   // date => drivers projected away that day
        $projectedDays = 0;    // trip-days projected, for the quality panel

        foreach ($expectedRows as $app) {
            $arrival = $app['expected_arrival_date'] ?? null;
            if ($arrival === null || $arrival === '') {
                continue;   // no end date: the absence cannot be placed
            }

            $driverId = (int)$app['driver_id'];
            $start    = (string)$app['event_date'];
            $end      = date_add_days((string)$arrival, -1);  // arrival = return day
            $matched  = $f['leave_type_id'] === []
                || in_array((string)$app['leave_type_id'], $f['leave_type_id'], true);

            $join = $app['joining_date'] ?? null;
            if ($join !== null && $join !== '' && (string)$join > $start) {
                $start = (string)$join;
            }
            if (isset($exitByDriver[$driverId])) {
                // Out of the population after the last working day, same as a
                // record would be clipped.
                $end = min($end, $exitByDriver[$driverId]);
            }
            if ($end < $start) {
                continue;   // inverted range (an arrival that predates the LWD)
            }

            $first = max($start, $walkFrom);
            $last  = min($end, $walkTo);

            for ($d = $first; $d <= $last; $d = date_add_days($d, 1)) {
                $away[$d]          = ($away[$d] ?? 0) + 1;
                $projectedAway[$d] = ($projectedAway[$d] ?? 0) + 1;
                $projectedDays++;

                if ($matched) {
                    $onLeave[$d] = ($onLeave[$d] ?? 0) + 1;
                }

                // Its own drill-down row - chip reads "Expected" - so the
                // eye icon always foots with the projected count above it.
                $details[$d][$driverId] = [
                    'driver_id'             => $driverId,
                    'driver_name'           => (string)$app['driver_name'],
                    'badge_number'          => (string)$app['badge_number'],
                    'leave_type'            => (string)($app['leave_type'] ?? ''),
                    'leave_start_date'      => (string)$app['event_date'],
                    'expected_arrival_date' => (string)$arrival,
                    'actual_arrival_date'   => null,
                    'status'                => 'expected',
                    // Read as "Expected Return": the date the driver is due
                    // back, which is what the column beside it shows.
                    'status_label'          => 'Expected Return',
                    'badge_class'           => status_badge_class('expected'),
                    'badge_icon'            => status_icon('expected'),
                    'is_overdue'            => false,
                ];
            }
        }

        // ---- walk the calendar ---------------------------------------------
        $joinOn    = array_count_values($joinDates);
        $exitOn    = array_count_values(array_column($exits, 'lwd'));
        $totalJoin = count($joinDates);

        // "Expected Resignations" counts FILINGS, not departures: every
        // resignation application that is not cancelled, dated on its last
        // working date - the SAME set the dashboard's "Resignation
        // Applications" card counts, so the two screens agree filing for
        // filing (pending and approved together, as asked). The population
        // still moves on $exitOn only: a filing that has not been approved
        // does not shrink active.
        $filingOn = self::resignationFilings($f, $walkFrom, $walkTo);

        $joined   = 0;   // drivers whose joining date has arrived
        $exited   = 0;   // drivers whose last working date has passed

        // Exits that passed before the first walked day are already out of
        // the population: the walk only adds an exit on the day AFTER its
        // last working date, so a resignation dated before the walk would
        // never land and active would stay too high for the whole window.
        foreach ($exits as $exit) {
            if ((string) $exit['lwd'] < $walkFrom) {
                $exited++;
            }
        }
        $dayRows  = [];
        $todayRow = null;
        $dayDates = [];

        // One contiguous walk: window OR today, whichever is wider. dayRows
        // keeps only the window itself; todayRow is captured on the way, so
        // the live cards answer no matter where the window sits (the old
        // "range + one appended today" walk left gaps - and clipped record
        // coverage - whenever today fell outside the window).
        for ($d = $walkFrom; $d <= $walkTo; $d = date_add_days($d, 1)) {
            $dayDates[] = $d;
        }

        foreach ($dayDates as $d) {
            $joined    += $joinOn[$d] ?? 0;
            $notYetIn   = $totalJoin - $joined;
            $active     = max(0, $base - $notYetIn - $exited);
            $a          = $away[$d] ?? 0;
            $o          = $onLeave[$d] ?? 0;
            $available  = max(0, $active - $a);

            $row = [
                'date'          => $d,
                'active'        => $active,
                'on_leave'      => $o,
                'away'          => $a,
                'available'     => $available,
                'on_leave_pct'  => self::pct($o, $active),
                'available_pct' => self::pct($available, $active),
                'returns'       => $returns[$d] ?? 0,
                'starts'        => $starts[$d] ?? 0,
                'expected_leave'=> $expectedByDay[$d] ?? 0,
                'resignations'  => $filingOn[$d] ?? 0,
                'overdue'       => $overdue[$d] ?? 0,
            ];

            if ($d === $today) {
                $todayRow = $row;
            }
            if ($d >= $from && $d <= $to) {
                $dayRows[] = $row;
            }

            // An exit takes effect the day AFTER the last working date, so the
            // driver is still inside the population on the date itself
            // (spec section 64 test 3: 15 Oct in, 16 Oct out).
            $exited += $exitOn[$d] ?? 0;
        }

        $targetPct = self::leaveTargetPct();

        return [
            'filters'    => $f,
            'today'      => $today,
            'today_row'  => $todayRow ?? self::emptyRow($today),
            'days'       => $dayRows,
            'monthly'    => self::monthly($dayRows, $targetPct),
            // Carried on the payload so the screen header and both exports can
            // name the ceiling they are measuring against - one read of the
            // setting, one number everywhere.
            'leave_target_pct' => $targetPct,
            'movement'   => self::movement($f, $records, $exits, $today),
            'kpis'       => self::kpis($todayRow ?? self::emptyRow($today)),
            'forecast'   => self::forecastAggregates($dayRows),
            'bridge'     => self::bridge($f, $today),
            'returnBridge' => self::returnBridge($f, $today),
            'resignBridge' => self::resignationBridge($f),
            'quality'    => self::quality($f, $records, $today),
            'months'     => self::months($dayRows),
            'details'    => $details,
            'base'       => $base,
            'clamped'    => (bool)($f['clamped'] ?? false),
            'mode_label' => $f['mode'] === self::MODE_ACTUAL ? 'Actual' : 'Forecast',
            'type_filter'=> $f['leave_type_id'] !== [],
            // How much of today / the range is a projected expectation rather
            // than a leave record - the dashboard comparison subtracts the
            // first to compare like with like, the quality panel states both.
            'projected_today' => (int)($projectedAway[$today] ?? 0),
            'projected_days'  => $projectedDays,
        ];
    }

    /**
     * Drill-down for one date (spec section 22): the drivers on leave that day.
     *
     * @param array<string, mixed> $f
     * @return array<int, array<string, mixed>>
     */
    public static function driversAwayOn(string $date, array $f, ?string $today = null): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return [];
        }

        $oneDay = $f;
        $oneDay['from']        = $date;
        $oneDay['to']          = $date;
        $oneDay['daily_month'] = substr($date, 0, 7);

        $payload = self::calculate($oneDay, $today);
        $rows    = $payload['details'][$date] ?? [];

        ksort($rows);

        return array_values($rows);
    }

    /* ==================================================================== *
     * Data access - three indexed queries, never one query per day
     * ==================================================================== */

    /**
     * Active population plus the two events that move it over time.
     *
     * @return array{0: int, 1: array<int, string>, 2: array<int, array<string, mixed>>}
     *               [active head-count, joining dates inside window, exits]
     */
    private static function population(array $f, string $from, string $to): array
    {
        // Active population: employed drivers plus anyone still inside their
        // last working date (a deactivation recorded with an end date). The
        // walk drops them the day AFTER that date - see the driver-sourced
        // exits below - so a deactivated driver is never excluded from the
        // total for dates on which they were still working. Branch and agency
        // scope sits behind the placeholder, so it keeps its parameter order.
        $scope  = [];
        $scopeP = [];

        if ($f['branch_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['branch_id']), '?'));
            $scope[] = "d.branch_id IN ({$in})";
            $scopeP  = array_merge($scopeP, array_values($f['branch_id']));
        }
        if ($f['agency_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['agency_id']), '?'));
            $scope[] = "d.agency_id IN ({$in})";
            $scopeP  = array_merge($scopeP, array_values($f['agency_id']));
        }

        $where  = array_merge([Driver::activeOnSql('d')], $scope);
        $params = array_merge([$from], $scopeP);

        $base = (int)Database::scalar(
            'SELECT COUNT(*) FROM drivers d WHERE ' . implode(' AND ', $where),
            $params
        );

        // Joining dates inside the window: each adds a driver from that date
        // onwards (spec section 51).
        $joins = Database::all(
            'SELECT d.joining_date FROM drivers d
              WHERE ' . implode(' AND ', $where) . '
                AND d.joining_date IS NOT NULL
                AND d.joining_date > ?
              ORDER BY d.joining_date',
            array_merge($params, [$from])
        );
        $joinDates = array_map(
            static fn (array $row): string => (string)$row['joining_date'],
            $joins
        );

        // Resignation exits. The applications register carries the last working
        // date; the leave TYPE NAME identifies a resignation, matching the
        // convention Dashboard::applicationsByBranch() already uses.
        //
        // Round 6, item 5: PENDING filings count alongside approved ones. This
        // is a forecast, so a resignation that has been filed with a last
        // working date on file is treated as one that will happen - the
        // decision only confirms what the forecast already assumed. Cancelled
        // and rejected still never reach this query (spec tests 5-7).
        $like = '(' . implode(' OR ', array_fill(0, count(self::EXIT_PATTERNS), 'LOWER(lt.name) LIKE ?')) . ')';

        $exits = Database::all(
            'SELECT la.last_working_date AS lwd,
                    la.status, lt.name AS leave_type, lt.id AS leave_type_id,
                    b.branch_name,
                    d.id AS driver_id, d.driver_name, d.badge_number
               FROM leave_applications la
               JOIN leave_types lt ON lt.id = la.leave_type_id
               JOIN drivers d ON d.id = la.driver_id
          LEFT JOIN branches b ON b.id = la.branch_id
              WHERE la.status IN ("approved","pending")
                AND la.last_working_date IS NOT NULL
                AND la.last_working_date <= ?
                AND ' . implode(' AND ', $where) . '
                AND ' . $like . '
              ORDER BY la.last_working_date',
            array_merge([$to], $params, array_values(self::EXIT_PATTERNS))
        );

        // Deactivations recorded on the driver row itself (Address Book ->
        // Deactivate, or Edit Driver while the status is a leaving one). Same
        // shape as the application-sourced exits above, so the walk drops the
        // driver the day AFTER their last working date whether the end date
        // arrived with an approved resignation filing or with the
        // deactivation itself. Only drivers already outside employment status
        // need this - activeOnSql's OR keeps an active driver in the
        // population until their date either way.
        $driverExits = Database::all(
            'SELECT d.last_working_date AS lwd,
                    d.status, d.id AS driver_id, d.driver_name, d.badge_number,
                    b.branch_name, NULL AS leave_type, NULL AS leave_type_id
               FROM drivers d
          LEFT JOIN branches b ON b.id = d.branch_id
              WHERE d.status <> "active"
                AND d.last_working_date IS NOT NULL
                AND d.last_working_date <= ?
                AND d.last_working_date >= ?'
                . ($scope === [] ? '' : ' AND ' . implode(' AND ', $scope)),
            array_merge([$to, $from], $scopeP)
        );

        // One driver resigns once: keep the earliest working date so the
        // population never subtracts the same person twice.
        $unique = [];
        foreach (array_merge($exits, $driverExits) as $row) {
            $id  = (int)$row['driver_id'];
            $lwd = (string)$row['lwd'];
            if (!isset($unique[$id]) || $lwd < (string)$unique[$id]['lwd']) {
                $unique[$id] = $row;
            }
        }

        return [$base, $joinDates, array_values($unique)];
    }

    /**
     * Leave records that can touch the window, with everything the day walk
     * and the drill-down need on the row.
     *
     * The third branch of the date predicate keeps a leave OPEN past its
     * expected return: no actual arrival and the expected date has gone by,
     * so the driver is still away (Status::AWAY = on_leave + overdue).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function leaveRecords(array $f, string $from, string $to, string $today): array
    {
        $where = [
            'lr.status NOT IN ("cancelled","not_returned")',
            // Placeholder 1: a deactivated driver still counts while their
            // last working date is on or after the first walked day.
            Driver::activeOnSql('d'),
            'lr.leave_start_date <= ?',
            '(lr.expected_arrival_date >= ?
              OR lr.actual_arrival_date >= ?
              OR (lr.actual_arrival_date IS NULL AND lr.expected_arrival_date < ?))',
        ];
        // Order follows $where: population day, window end, window start,
        // window start, today.
        $params = [$from, $to, $from, $from, $today];

        if ($f['branch_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['branch_id']), '?'));
            $where[] = "d.branch_id IN ({$in})";
            $params  = array_merge($params, array_values($f['branch_id']));
        }
        if ($f['agency_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['agency_id']), '?'));
            $where[] = "d.agency_id IN ({$in})";
            $params  = array_merge($params, array_values($f['agency_id']));
        }
        // No leave-type predicate here on purpose (spec section 61): every
        // leave is loaded so `available` keeps counting drivers away on OTHER
        // leave types, and the filter is applied in the day walk instead -
        // it decides what "On leave" shows, never how many drivers exist.

        return Database::all(
            'SELECT lr.id, lr.driver_id, lr.leave_type_id, lr.leave_start_date,
                    lr.expected_arrival_date, lr.actual_arrival_date, lr.status,
                    d.driver_name, d.badge_number, d.joining_date,
                    lt.name AS leave_type, b.branch_name
               FROM leave_records lr
               JOIN drivers d ON d.id = lr.driver_id
               JOIN leave_types lt ON lt.id = lr.leave_type_id
          LEFT JOIN branches b ON b.id = lr.branch_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY lr.driver_id, lr.leave_start_date',
            $params
        );
    }

    /**
     * Human label for a stored record status, for the drill-down chip.
     * Kept here so the forecast never invents a label the rest of the app
     * does not use.
     */
    private static function statusLabel(string $status): string
    {
        $labels = [
            'planned'      => 'Planned',
            'on_leave'     => 'On leave',
            'overdue'      => 'Overdue',
            'returned'     => 'Returned',
            'cancelled'    => 'Cancelled',
            'not_returned' => 'Not returned',
        ];

        return $labels[$status] ?? ucfirst($status);
    }

    /**
     * The last day a record counts for, per mode (spec sections 23-24).
     *
     *   forecast  expected return is the plan; where an actual arrival already
     *             exists the earlier of the two wins, so a driver who came back
     *             early is never drawn as away in the days ahead
     *   actual    the real arrival date, expected used while none exists
     *
     * Both return null - "still away" - once the expected date has passed with
     * no arrival on record: nobody invents a return date (spec section 49).
     *
     * @param array<string, mixed> $record
     */
    private static function endFor(array $record, string $mode, string $today): ?string
    {
        $expected = (string)$record['expected_arrival_date'];
        $actual   = $record['actual_arrival_date'] !== null && $record['actual_arrival_date'] !== ''
            ? (string)$record['actual_arrival_date']
            : null;

        if ($actual !== null) {
            return $mode === self::MODE_ACTUAL ? $actual : min($actual, $expected);
        }

        return $expected >= $today ? $expected : null;
    }

    /** Percentage of the active population; 0 when there is nobody to divide. */
    private static function pct(int $part, int $whole): float
    {
        // Two decimals (round 6): the trend chart plots these values, so
        // 5.75% has to survive to the browser instead of being flattened to
        // 5.8% on the way out.
        return $whole > 0 ? round(100 * $part / $whole, 2) : 0.0;
    }

    /** @return array<string, mixed> */
    private static function emptyRow(string $date): array
    {
        return [
            'date'          => $date,
            'active'        => 0,
            'on_leave'      => 0,
            'away'          => 0,
            'available'     => 0,
            'on_leave_pct'  => 0.0,
            'available_pct' => 0.0,
            'returns'       => 0,
            'starts'        => 0,
            'expected_leave'=> 0,
            'resignations'  => 0,
            'overdue'       => 0,
        ];
    }

    /* ==================================================================== *
     * Aggregation - monthly figures are DERIVED from the daily rows
     * ==================================================================== */

    /**
     * Monthly summary (spec sections 24 and 26): every figure is the mean or
     * the maximum of that month's daily rows. Nothing is recounted from
     * applications - counting submissions per month is exactly what section 1
     * forbids.
     *
     * @param array<int, array<string, mixed>> $days
     * @param float $targetPct configured leave ceiling in percent (0 = unset)
     * @return array<int, array<string, mixed>>
     */
    private static function monthly(array $days, float $targetPct): array
    {
        $byMonth = [];

        foreach ($days as $row) {
            $key = substr((string)$row['date'], 0, 7);
            if (!isset($byMonth[$key])) {
                $byMonth[$key] = [
                    'active' => [], 'on_leave' => [], 'pct' => [],
                    'peak' => 0, 'peak_pct' => 0.0,
                    'returns' => 0, 'starts' => 0, 'expected_leave' => 0,
                    'resignations' => 0, 'days' => 0,
                ];
            }

            $byMonth[$key]['active'][]      = (int)$row['active'];
            $byMonth[$key]['on_leave'][]    = (int)$row['on_leave'];
            $byMonth[$key]['pct'][]         = (float)$row['on_leave_pct'];
            $byMonth[$key]['peak']          = max($byMonth[$key]['peak'], (int)$row['on_leave']);
            $byMonth[$key]['peak_pct']      = max($byMonth[$key]['peak_pct'], (float)$row['on_leave_pct']);
            $byMonth[$key]['returns']      += (int)$row['returns'];
            $byMonth[$key]['starts']       += (int)$row['starts'];
            $byMonth[$key]['expected_leave'] += (int)($row['expected_leave'] ?? 0);
            $byMonth[$key]['resignations'] += (int)$row['resignations'];
            $byMonth[$key]['days']         += 1;
        }

        $out = [];
        foreach ($byMonth as $key => $month) {
            $n = max(1, $month['days']);

            $avgActive = (int) round(array_sum($month['active']) / $n);
            $peak      = $month['peak'];

            // How many more drivers may start leave before this month hits the
            // configured ceiling. The ceiling is that share of the month's
            // average active fleet, rounded DOWN - a fraction of a driver
            // cannot be approved (the same rule as Dashboard::leavePolicy()).
            // It is measured against the month's PEAK rather than its average,
            // because the answer has to hold on the month's worst day: a
            // figure derived from the mean could still be approved straight
            // into a breach.
            //
            // Null while no ceiling is configured, so a missing setting can
            // never be read as "0 slots left" - nothing is claimed as limited
            // until somebody has actually set a limit.
            $ceiling = ($targetPct > 0 && $avgActive > 0)
                ? (int) floor(($targetPct / 100) * $avgActive)
                : null;

            $out[] = [
                'month'             => (string)$key,
                'label'             => date('M Y', strtotime($key . '-01')),
                'days'              => $month['days'],
                'avg_active'        => $avgActive,
                'avg_on_leave'      => (int) round(array_sum($month['on_leave']) / $n),
                'avg_on_leave_pct'  => round(array_sum($month['pct']) / $n, 2),
                'peak_on_leave'     => $peak,
                'peak_on_leave_pct' => $month['peak_pct'],
                'leave_ceiling'     => $ceiling,
                'leave_slots'       => $ceiling === null ? null : max(0, $ceiling - $peak),
                'returns'           => $month['returns'],
                'starts'            => $month['starts'],
                'expected_leave'    => $month['expected_leave'],
                'resignations'      => $month['resignations'],
            ];
        }

        return $out;
    }

    /**
     * The configured leave ceiling as a percentage, or 0 when unset.
     *
     * Reads the same `leave_percentage` setting as Dashboard::leavePolicy(), so
     * the dashboard report and this forecast can never quote two different
     * ceilings for the same day.
     */
    private static function leaveTargetPct(): float
    {
        return (float) trim(Lookup::setting('leave_percentage', '0'));
    }

    /** Months present in the window - the day-by-day table picks one (section 17). */
    private static function months(array $days): array
    {
        $out = [];
        foreach ($days as $row) {
            $key = substr((string)$row['date'], 0, 7);
            if (!isset($out[$key])) {
                $out[$key] = [
                    'value' => $key,
                    'label' => date('F Y', strtotime($key . '-01')),
                ];
            }
        }

        return array_values($out);
    }

    /**
     * The CURRENT STATUS figures (spec section 17). Everything here answers
     * "right now": today's row is used even when the selected window sits
     * somewhere else, so the date range never moves these numbers.
     *
     * Period figures live in forecastAggregates() - keeping the two apart is
     * what lets the page label one group "Current Status" and the other
     * "Forecast <range>" instead of mixing both meanings in one row.
     *
     * @param array<string, mixed> $today today's row
     * @return array<string, int|float>
     */
    private static function kpis(array $today): array
    {
        return [
            'active_today'        => (int)$today['active'],
            'on_leave_today'      => (int)$today['on_leave'],
            'available_today'     => (int)$today['available'],
            'on_leave_pct_today'  => (float)$today['on_leave_pct'],
            'available_pct_today' => (float)$today['available_pct'],
        ];
    }

    /**
     * The SELECTED PERIOD forecast figures: every number aggregated across the
     * whole range from the daily rows (spec sections 17 and 24) - averages are
     * the mean of the daily values, peaks the maximum, returns and
     * resignations the sums. Nothing is recounted from applications, so the
     * cards and the monthly table below always foot with each other.
     *
     * @param array<int, array<string, mixed>> $days
     * @return array<string, int|float>
     */
    private static function forecastAggregates(array $days): array
    {
        $count        = count($days);
        $active       = [];
        $onLeave      = [];
        $pcts         = [];
        $returns      = 0;
        $resignations = 0;

        foreach ($days as $row) {
            $active[]  = (int)$row['active'];
            $onLeave[] = (int)$row['on_leave'];
            $pcts[]    = (float)$row['on_leave_pct'];
            $returns  += (int)$row['returns'];
            $resignations += (int)$row['resignations'];
        }

        if ($count === 0) {
            return [
                'days'                  => 0,
                'avg_active'            => 0.0,
                'avg_on_leave'          => 0.0,
                'avg_on_leave_pct'      => 0.0,
                'peak_on_leave'         => 0,
                'peak_on_leave_pct'     => 0.0,
                'expected_returns'      => 0,
                'expected_resignations' => 0,
            ];
        }

        $n = $count;

        return [
            'days'                  => $count,
            'avg_active'            => (int) round(array_sum($active) / $n),
            'avg_on_leave'          => (int) round(array_sum($onLeave) / $n),
            'avg_on_leave_pct'      => round(array_sum($pcts) / $n, 2),
            'peak_on_leave'         => max($onLeave),
            'peak_on_leave_pct'     => max($pcts),
            'expected_returns'      => $returns,
            'expected_resignations' => $resignations,
        ];
    }

    /**
     * Applications that are EXPECTED TO LEAVE - the "Expected Leave" event.
     *
     * The status rules, exactly as specified:
     *   #1 approved + leave status "Not Started"  -> counts
     *   #2 resignations count too (pending or approved, like any leave type)
     *   #3 the ONLY approval statuses excluded are cancelled and rejected
     *   #4 approved with leave status <> "Not Started" -> excluded (the leave
     *      has begun or ended, so the record tells that story)
     *
     * On top of the status rules, one trip can never appear twice: a linked
     * record carries the trip already, and - the legacy gap - a driver who
     * has a live record covering these dates counts once, link or no link
     * (otherwise "Leave start" and "Expected Leave" would land together).
     *
     * Dated the DAY AFTER the last working date - 44 of the 62 records in the
     * data start exactly then - so when the application becomes a record the
     * event keeps its day and simply becomes "Leave start". Movement carries
     * three families side by side: expected return/arrival, expected leave
     * and resignations.
     *
     * These are expectations, not absences in the population: they never
     * change Active. From the day after the last working date through the day
     * before the expected return, however, calculate() PROJECTS them as away
     * (On Leave, Available, the trend chart, monthly summary and forecast
     * cards follow; see the projected-absence loop there).
     *
     * @return array<int, array{event_date:string, driver_id:int, driver_name:string, badge_number:string, last_working_date:string, expected_arrival_date:string|null, joining_date:string|null, leave_type:string, leave_type_id:int|string, status:string}>
     */
    private static function expectedLeaveRows(array $f, string $from, string $to): array
    {
        $where = [
            // #3 - cancelled and rejected are the only approval statuses that
            // never leave; pending and approved both count.
            'la.status NOT IN ("cancelled","rejected")',
            // #4 - approved only while the leave itself has not begun: once it
            // started or ended, the leave record carries the trip (and the two
            // guards below keep even that to a single event).
            'NOT (la.status = "approved" AND la.leave_status <> "not_started")',
            'la.last_working_date IS NOT NULL',
            'NOT EXISTS (SELECT 1 FROM leave_records lrx
                          WHERE lrx.leave_application_id = la.id)',
            // The trip must not already exist as a record: any live record of
            // the same driver overlapping [expected leave -> expected return]
            // is that trip, linked or not (legacy rows have no link). One trip,
            // one event.
            'NOT EXISTS (
                SELECT 1 FROM leave_records lrx
                 WHERE lrx.driver_id = la.driver_id
                   AND lrx.status NOT IN ("cancelled","not_returned")
                   AND lrx.leave_start_date <= COALESCE(
                           la.expected_arrival_date,
                           DATE_ADD(la.last_working_date, INTERVAL 1 DAY))
                   AND lrx.expected_arrival_date >= DATE_ADD(
                           la.last_working_date, INTERVAL 1 DAY))',
            // A trip that STARTED before the range but continues into it still
            // counts: leaveRecords() loads on OVERLAP (leave_start <= walkTo
            // AND expected >= walkFrom), so an expectation has to as well.
            // Gating on the START day alone meant moving `from` later silently
            // dropped absences covering a day still on screen - Oct 1-31 and
            // Oct 5-31 quoted different On Leave figures for the same 5 Oct.
            // No arrival is excluded here: the Expected Leave column still
            // lists those (calculate()'s projection loop places what it can).
            'DATE_ADD(la.last_working_date, INTERVAL 1 DAY) <= ?
              AND (la.expected_arrival_date IS NULL
                   OR la.expected_arrival_date >= ?)',
            Driver::activeOnSql('d'),
        ];
        // Order follows $where: window end, window start, population day.
        $params = [$to, $from, $from];

        if ($f['branch_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['branch_id']), '?'));
            $where[] = "d.branch_id IN ({$in})";
            $params  = array_merge($params, array_values($f['branch_id']));
        }
        if ($f['agency_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['agency_id']), '?'));
            $where[] = "d.agency_id IN ({$in})";
            $params  = array_merge($params, array_values($f['agency_id']));
        }
        // Same rule as the records themselves: a leave-type filter decides
        // what the view shows, never what exists.
        if ($f['leave_type_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['leave_type_id']), '?'));
            $where[] = "la.leave_type_id IN ({$in})";
            $params  = array_merge($params, array_values($f['leave_type_id']));
        }

        return Database::all(
            'SELECT la.id, la.driver_id, la.status, la.leave_type_id,
                    la.last_working_date, la.expected_arrival_date,
                    DATE_ADD(la.last_working_date, INTERVAL 1 DAY) AS event_date,
                    d.driver_name, d.badge_number, d.joining_date,
                    lt.name AS leave_type, b.branch_name
               FROM leave_applications la
               JOIN drivers d ON d.id = la.driver_id
               JOIN leave_types lt ON lt.id = la.leave_type_id
          LEFT JOIN branches b ON b.id = la.branch_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY event_date, d.driver_name',
            $params
        );
    }

    /**
     * Capacity Movement (spec section 22): every event that changes a driver
     * count, in date order, with its impact on the population.
     *
     * @param array<string, mixed>              $f
     * @param array<int, array<string, mixed>>  $records
     * @param array<int, array<string, mixed>>  $exits
     * @return array<int, array<string, string|int>>
     */
    private static function movement(array $f, array $records, array $exits, ?string $today = null): array
    {
        $today  = $today ?? today();
        $from   = $f['from'];
        $to     = $f['to'];
        $actual = $f['mode'] === self::MODE_ACTUAL;
        $events = [];

        foreach ($records as $record) {
            $matched = $f['leave_type_id'] === []
                || in_array((string)$record['leave_type_id'], $f['leave_type_id'], true);
            if (!$matched) {
                continue;
            }

            $name  = (string)$record['driver_name'];
            $badge = (string)$record['badge_number'];
            $start = (string)$record['leave_start_date'];

            if ($start >= $from && $start <= $to) {
                $events[] = [
                    'date'   => $start,
                    'event'  => 'Leave start',
                    'driver' => $name,
                    'badge'  => $badge,
                    'branch'     => (string)($record['branch_name'] ?? ''),
                    'leave_type' => (string)$record['leave_type'],
                    'status'     => (string)$record['status'],
                    'impact' => '+1 On Leave',
                    'kind'   => 0,
                ];
            }

            $end = self::endFor($record, $f['mode'], $today);

            // Round 6, item 3: a trip that has not begun has no arrival to
            // expect, so it emits no return event - otherwise this table
            // listed rows the "Expected Returns" column above it ignores.
            // The "Leave start" event still stands: the trip is on the books,
            // it simply has not started.
            $notStarted = (string)$record['status'] === 'planned';

            if (!$notStarted && $end !== null && $end >= $from && $end <= $to) {
                $hasActual = $record['actual_arrival_date'] !== null && $record['actual_arrival_date'] !== '';

                // Same rule as the Expected Returns column: in forecast mode
                // a driver who is already back is history, not an expected
                // return - otherwise the table lists rows the totals ignore.
                // Actual mode still shows their real return; that is the
                // point of the mode.
                if (!$actual && $hasActual) {
                    continue;
                }

                $events[]  = [
                    'date'   => $end,
                    'event'  => $actual && $hasActual ? 'Actual return' : 'Expected return',
                    'driver' => $name,
                    'badge'  => $badge,
                    'branch'     => (string)($record['branch_name'] ?? ''),
                    'leave_type' => (string)$record['leave_type'],
                    'status'     => (string)$record['status'],
                    'impact' => '-1 On Leave',
                    'kind'   => 1,
                ];
            }
        }

        foreach ($exits as $exit) {
            // The same leave-type rule the record events above follow. The
            // table renders a Leave Type column, so filtering to Annual
            // Vacation must not still list Resignation exits in it - they were
            // the one family that ignored the filter (section 61).
            //
            // The population walk is deliberately untouched: a leave-type
            // filter decides what the view shows, never how many drivers
            // exist, so Active still drops for these exits on the day after
            // their last working date. Only the row is hidden.
            $matched = $f['leave_type_id'] === []
                || in_array((string)($exit['leave_type_id'] ?? ''), $f['leave_type_id'], true);

            if (!$matched) {
                continue;
            }

            $lwd = (string)$exit['lwd'];
            if ($lwd >= $from && $lwd <= $to) {
                $events[] = [
                    'date'   => $lwd,
                    'event'  => 'Resignation last working date',
                    'driver' => (string)$exit['driver_name'],
                    'badge'  => (string)$exit['badge_number'],
                    // Branch is the snapshot on the filing when there is one
                    // (a deactivation exit comes off the driver row instead);
                    // leave_type is the resignation type itself, status the
                    // approval decision - pending counts too (round 6, item 5).
                    'branch'     => (string)($exit['branch_name'] ?? ''),
                    'leave_type' => (string)($exit['leave_type'] ?? ''),
                    'status'     => (string)($exit['status'] ?? ''),
                    'impact' => '-1 Active',
                    'kind'   => 2,
                ];
            }
        }

        // Expected Leave: applications that are expected to leave - pending or
        // approved-with-its-leave-not-started, resignations included, only
        // cancelled / rejected excluded (plus approved whose leave already
        // began).
        //
        // One application may satisfy two families: a resignation emits its
        // "-1 Active" exit above on the last working date AND would emit
        // "+1 Expected" here the day after. The table lists events that change
        // a driver count, so an event whose impact no view ever shows does not
        // belong in it. An application without an expected return - the field
        // LeaveApplication drops for the no-return types - is skipped by
        // calculate()'s projection loop on exactly that condition, so "+1
        // Expected" would name a number that stays 0 in On Leave, Available,
        // the trend chart, the monthly summary and the forecast cards, while
        // the departure itself is already fully carried by the exit row. One
        // application, one movement row.
        //
        // The day-by-day Expected Leave column and the monthly sum still count
        // them: they come straight from expectedLeaveRows(), which is
        // unchanged (requirement 2). Only this table drops the second row.
        foreach (self::expectedLeaveRows($f, $from, $to) as $app) {
            $arrival = $app['expected_arrival_date'] ?? null;
            if ($arrival === null || $arrival === '') {
                continue;   // no end date: the impact would never land
            }

            // expectedLeaveRows() is an OVERLAP test so that calculate()'s day
            // walk can project a trip that began before the range through it.
            // This table shows START events, so it clips to its own window -
            // the same guard the record branch applies at "starts inside".
            // Without it a September trip would raise an October movement row
            // in a range that starts in October.
            $event = (string)$app['event_date'];
            if ($event < $from || $event > $to) {
                continue;
            }

            $events[] = [
                'date'   => (string)$app['event_date'],
                'event'  => 'Expected Leave',
                'driver' => (string)$app['driver_name'],
                'badge'  => (string)$app['badge_number'],
                'branch'     => (string)($app['branch_name'] ?? ''),
                'leave_type' => (string)$app['leave_type'],
                'status'     => (string)$app['status'],
                'impact' => '+1 Expected',
                'kind'   => 3,
            ];
        }

        usort($events, static function (array $a, array $b): int {
            return [$a['date'], $a['kind'], $a['driver']] <=> [$b['date'], $b['kind'], $b['driver']];
        });

        return $events;
    }

    /* ==================================================================== *
     * Forecast Data Quality (spec sections 49-50 and 58)
     * ==================================================================== */

    /**
     * Every input condition the forecast would otherwise hide. Each line is a
     * real count: when it is zero the line reads "No issues", never an
     * invented warning.
     *
     * @param array<string, mixed>              $f
     * @param array<int, array<string, mixed>>  $records
     * @return array<int, array{label:string,count:int,detail:string,state:string}>
     */
    private static function quality(array $f, array $records, string $today): array
    {
        $from = $f['from'];
        $to   = $f['to'];
        $rows = [];

        $add = static function (string $label, int $count, string $detail) use (&$rows): void {
            $rows[] = [
                'label'  => $label,
                'count'  => $count,
                'detail' => $count === 0 ? 'No issues detected.' : $detail,
                'state'  => $count === 0 ? 'ok' : 'warn',
            ];
        };

        // 1. Expected return earlier than the leave start: a range the engine
        //    cannot walk (section 49).
        $invalid = (int)Database::scalar(
            'SELECT COUNT(*) FROM leave_records
              WHERE leave_start_date <= ?
                AND expected_arrival_date < leave_start_date',
            [$to]
        );
        $add('Invalid leave date ranges', $invalid,
            'Expected return earlier than the leave start: those records count for zero days. Correct them in Leave Records.');

        // 2. Applications with no expected return (section 49): they carry no
        //    return date, so they cannot become an on-leave interval. Say so
        //    rather than inventing a date.
        $noArrival = (int)Database::scalar(
            'SELECT COUNT(*) FROM leave_applications WHERE expected_arrival_date IS NULL'
        );
        $add('Applications without expected return', $noArrival,
            'These leave applications have no expected return date, so they cannot be placed in the forecast. Add the date on the application to include it.');

        // 3. Closed records (cancelled / not returned) in the window - excluded
        //    by design (sections 31, 32 and 35).
        $closed = (int)Database::scalar(
            'SELECT COUNT(*) FROM leave_records
              WHERE status IN ("cancelled","not_returned")
                AND leave_start_date <= ?
                AND expected_arrival_date >= ?',
            [$to, $from]
        );
        $add('Closed records excluded', $closed,
            'Cancelled and Not Returned records inside this range stay out of the forecast: a closed case is not a future return. Their drivers stay in the active population.');

        // 4. Leave belonging to drivers outside the population - deactivated
        //    with no last working date inside this range. (A driver deactivated
        //    WITH a date on or after the first walked day still counts, so
        //    their leave is counted rather than flagged here.)
        $inactiveLeave = (int)Database::scalar(
            'SELECT COUNT(DISTINCT lr.driver_id)
               FROM leave_records lr
               JOIN drivers d ON d.id = lr.driver_id
              WHERE NOT ' . Driver::activeOnSql('d') . '
                AND lr.status NOT IN ("cancelled","not_returned")
                AND lr.leave_start_date <= ?
                AND lr.expected_arrival_date >= ?',
            [$from, $to, $from]
        );
        $add('Leave on drivers outside the head-count', $inactiveLeave,
            'These drivers are not in the active head-count, so their leave is left out of the numerator and the denominator alike.');

        // 5. Two overlapping leaves for one driver would otherwise count that
        //    driver twice on the same day (section 58).
        $overlaps = (int)Database::scalar(
            'SELECT COUNT(DISTINCT a.driver_id)
               FROM leave_records a
               JOIN leave_records b ON b.driver_id = a.driver_id AND b.id > a.id
              WHERE a.status NOT IN ("cancelled","not_returned")
                AND b.status NOT IN ("cancelled","not_returned")
                AND a.leave_start_date < b.expected_arrival_date
                AND b.leave_start_date < a.expected_arrival_date'
        );
        $add('Drivers with overlapping leaves', $overlaps,
            'Each driver is still counted once per day here, but the overlapping records should be reviewed in Leave Records.');

        // 6. Branch / agency filters read drivers.branch_id and agency_id - a
        //    driver without one cannot be selected.
        $unassigned = (int)Database::scalar(
            'SELECT COUNT(*) FROM drivers d
              WHERE ' . Driver::activeOnSql('d') . '
                AND (branch_id IS NULL OR agency_id IS NULL)',
            [$today]
        );
        $add('Active drivers without branch or agency', $unassigned,
            'Assign a branch or agency in the Address Book so these drivers appear under every branch and agency filter.');

        // 7. Joining dates beyond the window enter the population only when
        //    they arrive (section 51).
        $futureJoins = (int)Database::scalar(
            'SELECT COUNT(*) FROM drivers d
              WHERE ' . Driver::activeOnSql('d') . ' AND joining_date > ?',
            [$today, $to]
        );
        $add('Joining dates after this range', $futureJoins,
            'These drivers join later, so they only enter the active population from their joining date - they are not counted today.');

        // 8. A leave type filter changes what "On leave" shows but never how
        //    many drivers exist (section 61). Say it out loud on the page.
        if ($f['leave_type_id'] !== []) {
            $rows[] = [
                'label'  => 'Leave type filter active',
                'count'  => count($f['leave_type_id']),
                'detail' => 'On leave counts only the selected leave type(s); Available still subtracts every driver away on any leave, so no other leave type is pretended away.',
                'state'  => 'info',
            ];
        }

        // 9. Planned leaves ARE counted as unavailable capacity - from the
        //    leave start date through the day before the expected return -
        //    exactly like a leave that has already started. Saying it out loud
        //    (with the Planned chip in the drill-down) is what stops a future
        //    dip in Available from reading as a bug.
        $plannedDays = 0;
        foreach ($records as $record) {
            if ((string)$record['status'] !== 'planned') {
                continue;
            }

            $start = (string)$record['leave_start_date'];
            $end   = self::endFor($record, $f['mode'], $today);
            $last  = ($end === null || $end === self::OPEN) ? $to : date_add_days($end, -1);
            $first = max($start, $from);

            if ($first <= $last) {
                $plannedDays += ((int)floor((strtotime($last) - strtotime($first)) / 86400)) + 1;
            }
        }

        $rows[] = [
            'label'  => 'Planned leaves counted as unavailable',
            'count'  => $plannedDays,
            'detail' => $plannedDays === 0
                ? 'No planned trip falls inside this range. Planned rows count exactly like started ones: unavailable from the leave start date through the day before the expected return.'
                : 'Planned trip-day(s) in this range are subtracted from Available and added to On leave, the same as a leave that has already started - the trip is planned, the absence is real.',
            'state'  => 'info',
        ];

        return $rows;
    }

    /**
     * The two figures that explain why this page and the dashboard can show a
     * different "away" number for the very same day.
     *
     * The dashboard counts a leave as away until its return is RECORDED
     * (stored status in on_leave/overdue), while this forecast counts the
     * expected return day as the return day - spec section 64, test 1
     * ("20 Oct = Expected Returned"). The gap is therefore exactly the drivers
     * due back today whose return has not been confirmed yet.
     *
     * @param array<string, mixed> $f
     * @return array{status_away:int, due_back_unconfirmed:int}
     */
    private static function bridge(array $f, string $today): array
    {
        $where  = ['lr.status IN ("on_leave","overdue")', Driver::activeOnSql('d')];
        // Placeholder order: the SUM's date first (SELECT clause), then the
        // population day, then branch / agency scope.
        $params = [$today];

        if ($f['branch_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['branch_id']), '?'));
            $where[] = "d.branch_id IN ({$in})";
            $params  = array_merge($params, array_values($f['branch_id']));
        }
        if ($f['agency_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['agency_id']), '?'));
            $where[] = "d.agency_id IN ({$in})";
            $params  = array_merge($params, array_values($f['agency_id']));
        }

        $row = Database::one(
            'SELECT COUNT(*) AS status_away,
                    COALESCE(SUM(lr.expected_arrival_date = ?
                                 AND lr.actual_arrival_date IS NULL), 0) AS due_back_unconfirmed
               FROM leave_records lr
               JOIN drivers d ON d.id = lr.driver_id
              WHERE ' . implode(' AND ', $where),
            array_merge([$today], $params)
        );

        return [
            'status_away'          => (int)($row['status_away'] ?? 0),
            'due_back_unconfirmed' => (int)($row['due_back_unconfirmed'] ?? 0),
        ];
    }

    /**
     * Why this page's "Expected Returns" and the dashboard's "Expected
     * Arrivals" can differ for the same date range - both sides were verified
     * to foot; they simply answer different questions:
     *
     *   dashboard = a worklist: expected date inside the range AND the return
     *               has not been recorded yet. Confirmed returns fall off it;
     *               past-due rows stay on it until someone confirms them.
     *   this page = the plan: every return the range contains, confirmed ones
     *               included - while a return that never happened is Overdue
     *               here instead of being counted as a return (spec 49).
     *
     * Branch and agency scope applies to all four counts, so with no filters
     * dashboard_arrivals is exactly the number the dashboard card shows.
     * The leave-type filter (a page-only concept) applies to the two capacity
     * counts, never to the dashboard mirror.
     *
     * @param array<string, mixed> $f
     * @return array{dashboard_arrivals:int, shared_ahead:int, confirmed:int, overdue_unreturned:int}
     */
    private static function returnBridge(array $f, string $today): array
    {
        $scope  = [];
        $scopeP = [];

        if ($f['branch_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['branch_id']), '?'));
            $scope[] = "d.branch_id IN ({$in})";
            $scopeP  = array_merge($scopeP, array_values($f['branch_id']));
        }
        if ($f['agency_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['agency_id']), '?'));
            $scope[] = "d.agency_id IN ({$in})";
            $scopeP  = array_merge($scopeP, array_values($f['agency_id']));
        }
        $scopeSql = $scope === [] ? '' : ' AND ' . implode(' AND ', $scope);

        $typeSql  = '';
        $typeP    = [];
        if ($f['leave_type_id'] !== []) {
            $in       = implode(',', array_fill(0, count($f['leave_type_id']), '?'));
            $typeSql  = " AND lr.leave_type_id IN ({$in})";
            $typeP    = array_values($f['leave_type_id']);
        }

        $from  = $f['from'];
        $to    = $f['to'];
        $count = static fn (string $where, array $params): int => (int)Database::scalar(
            'SELECT COUNT(*) FROM leave_records lr
               JOIN drivers d ON d.id = lr.driver_id
              WHERE ' . $where,
            $params
        );

        // `planned` is excluded from the two ARRIVAL counts below only: a trip
        // that has not started has no arrival to expect (round 6, item 3).
        // The confirmed and overdue counts keep their own predicates - a
        // planned record has no actual arrival and a future expected date, so
        // it was never in either of them.
        $closed = 'lr.status NOT IN ("cancelled","not_returned")';
        $due    = $closed . ' AND lr.status <> "planned"';

        // The dashboard's own predicate - a mirror of Dashboard::arrivalMonths().
        $dashboardArrivals = $count(
            $due . ' AND lr.actual_arrival_date IS NULL
                    AND lr.expected_arrival_date BETWEEN ? AND ?' . $scopeSql,
            array_merge([$from, $to], $scopeP)
        );

        // Still ahead: counted by BOTH screens (this page ends them on the
        // expected date, the dashboard lists them as not yet recorded). A
        // planned trip is on neither list: it has not begun, so neither
        // screen has an arrival to expect.
        $sharedAhead = $count(
            $due . ' AND lr.actual_arrival_date IS NULL
                    AND lr.expected_arrival_date >= ?
                    AND lr.expected_arrival_date BETWEEN ? AND ?
                    AND ' . Driver::activeOnSql('d') . $scopeSql . $typeSql,
            array_merge([$today, $from, $to, $today], $scopeP, $typeP)
        );

        // Already recorded: this page counts them at the planned date (the
        // earlier of expected and actual), the dashboard drops them.
        $confirmed = $count(
            $closed . ' AND lr.actual_arrival_date IS NOT NULL
                       AND LEAST(lr.actual_arrival_date, lr.expected_arrival_date) BETWEEN ? AND ?
                       AND ' . Driver::activeOnSql('d') . $scopeSql . $typeSql,
            array_merge([$from, $to, $today], $scopeP, $typeP)
        );

        // Past due and never recorded: the dashboard keeps waiting for them,
        // this page shows them as Overdue and counts no return.
        $overdueUnreturned = $count(
            $closed . ' AND lr.actual_arrival_date IS NULL
                       AND lr.expected_arrival_date < ?
                       AND lr.expected_arrival_date BETWEEN ? AND ?' . $scopeSql,
            array_merge([$today, $from, $to], $scopeP)
        );

        return [
            'dashboard_arrivals'    => $dashboardArrivals,
            'shared_ahead'          => $sharedAhead,
            'confirmed'             => $confirmed,
            'overdue_unreturned'    => $overdueUnreturned,
        ];
    }

    /**
     * Why this page's "Expected Resignations" and the dashboard's
     * "Resignation Applications" differ for the same date range:
     *
     *   dashboard = a filing list: every resignation-type application that is
     *               NOT cancelled, bucketed on its last working date - pending
     *               and rejected rows included, because the card answers
     *               "how many resignation papers are in the pipeline".
     *   this page = population exits: resignation filings whose last working
     *               date falls in the range can shrink the active head-count
     *               (spec sections 15/30). Round 6, item 5 added PENDING to
     *               that set - this is a forecast, so a filing with a date on
     *               file is taken as one that will happen. Only cancelled and
     *               rejected change nothing.
     *
     * Branch and agency scope applies to all counts, so unfiltered
     * dashboard_applications is exactly what the dashboard card shows.
     *
     * @param array<string, mixed> $f
     * @return array{dashboard_applications:int, pending:int, rejected:int, approved:int}
     */
    /**
     * Non-cancelled resignation applications by LAST WORKING DATE - the
     * dashboard's own filing predicate (family LIKE %resign%, status
     * <> cancelled), so Expected Resignations equals the dashboard's
     * "Resignation Applications" filing for filing. The population moves on
     * the exit list: pending AND approved filings both count (round 6, item
     * 5) - a date on file is what the forecast acts on, not the decision.
     *
     * @return array<string, int> last working date => filings that day
     */
    private static function resignationFilings(array $f, string $from, string $to): array
    {
        $scope   = [];
        $scopeP  = [];

        if ($f['branch_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['branch_id']), '?'));
            $scope[] = "d.branch_id IN ({$in})";
            $scopeP  = array_merge($scopeP, array_values($f['branch_id']));
        }
        if ($f['agency_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['agency_id']), '?'));
            $scope[] = "d.agency_id IN ({$in})";
            $scopeP  = array_merge($scopeP, array_values($f['agency_id']));
        }
        $scopeSql = $scope === [] ? '' : ' AND ' . implode(' AND ', $scope);

        $rows = Database::all(
            'SELECT la.last_working_date AS lwd, COUNT(*) AS total
               FROM leave_applications la
               JOIN leave_types lt ON lt.id = la.leave_type_id
               JOIN drivers d ON d.id = la.driver_id
              WHERE LOWER(lt.name) LIKE "%resign%"
                AND la.status <> "cancelled"
                AND la.last_working_date IS NOT NULL
                AND la.last_working_date BETWEEN ? AND ?' . $scopeSql . '
           GROUP BY la.last_working_date',
            array_merge([$from, $to], $scopeP)
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['lwd']] = (int) $row['total'];
        }

        return $byDay;
    }

    private static function resignationBridge(array $f): array
    {
        $scope  = [];
        $scopeP = [];

        if ($f['branch_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['branch_id']), '?'));
            $scope[] = "d.branch_id IN ({$in})";
            $scopeP  = array_merge($scopeP, array_values($f['branch_id']));
        }
        if ($f['agency_id'] !== []) {
            $in      = implode(',', array_fill(0, count($f['agency_id']), '?'));
            $scope[] = "d.agency_id IN ({$in})";
            $scopeP  = array_merge($scopeP, array_values($f['agency_id']));
        }
        $scopeSql = $scope === [] ? '' : ' AND ' . implode(' AND ', $scope);

        $from = $f['from'];
        $to   = $f['to'];

        $count = static fn (string $where, array $params): int => (int)Database::scalar(
            'SELECT COUNT(*) FROM leave_applications la
               JOIN leave_types lt ON lt.id = la.leave_type_id
               JOIN drivers d ON d.id = la.driver_id
              WHERE ' . $where,
            $params
        );

        // The dashboard's own predicate - a mirror of
        // Dashboard::applicationsByBranch(..., 'resign').
        $filed = 'LOWER(lt.name) LIKE "%resign%"
                  AND la.status <> "cancelled"
                  AND la.last_working_date IS NOT NULL
                  AND la.last_working_date BETWEEN ? AND ?' . $scopeSql;

        $dashboardApplications = $count($filed, array_merge([$from, $to], $scopeP));
        $pending = $count($filed . ' AND la.status = "pending"', array_merge([$from, $to], $scopeP));
        $rejected = $count($filed . ' AND la.status = "rejected"', array_merge([$from, $to], $scopeP));
        // Approved WITHIN the dashboard's filing family - the card footer's
        // "approved" segment, so pending + approved (+ rejected) foot with
        // the total shown right next to them.
        $approvedFiled = $count($filed . ' AND la.status = "approved"', array_merge([$from, $to], $scopeP));

        // This page's exits: resignation family (the superset this engine
        // already uses), pending AND approved (round 6, item 5 - the same
        // predicate population() runs), driver still in the population.
        $approved = $count(
            '(LOWER(lt.name) LIKE "%resign%"
              OR LOWER(lt.name) LIKE "%terminat%"
              OR LOWER(lt.name) LIKE "%abscond%")
             AND la.status IN ("approved","pending")
             AND la.last_working_date IS NOT NULL
             AND la.last_working_date BETWEEN ? AND ?
             AND ' . Driver::activeOnSql('d') . $scopeSql,
            array_merge([$from, $to, $from], $scopeP)
        );

        return [
            'dashboard_applications' => $dashboardApplications,
            'pending'               => $pending,
            'rejected'              => $rejected,
            // Renamed from "approved": the count is exits, not decisions, and
            // the two stopped being the same thing in round 6 (item 5).
            'exits'                 => $approved,
            'approved_filed'        => $approvedFiled,
        ];
    }

    /* ==================================================================== *
     * Export (spec sections 44-45) - same filters, same numbers
     * ==================================================================== */

    /**
     * Daily table as [headers, rows] for export_csv() / export_xls().
     *
     * @param array<string, mixed> $payload result of calculate()
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public static function exportDaily(array $payload): array
    {
        $f = $payload['filters'];

        $headers = [
            'Date', 'Active Drivers', 'On Leave', 'Available',
            'On-Leave %', 'Available %',
            'Leave Starts', 'Expected Leave',
            $f['mode'] === self::MODE_ACTUAL ? 'Returns' : 'Expected Returns',
            'Expected Resignations', 'Overdue',
        ];

        $rows = [];
        foreach ($payload['days'] as $day) {
            $rows[] = [
                $day['date'],
                (int)$day['active'],
                (int)$day['on_leave'],
                (int)$day['available'],
                (float)$day['on_leave_pct'],
                (float)$day['available_pct'],
                (int)$day['starts'],
                (int)($day['expected_leave'] ?? 0),
                (int)$day['returns'],
                (int)$day['resignations'],
                (int)$day['overdue'],
            ];
        }

        return [$headers, $rows];
    }

    /**
     * Monthly table as [headers, rows] for export_csv() / export_xls().
     *
     * @param array<string, mixed> $payload result of calculate()
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public static function exportMonthly(array $payload): array
    {
        $targetPct = (float) ($payload['leave_target_pct'] ?? 0);

        $headers = [
            'Month', 'Avg Active Drivers', 'Avg On Leave', 'Avg On-Leave %',
            'Peak On Leave', 'Peak On-Leave %',
            // Named with the configured figure so a spreadsheet says the same
            // thing as the screen - same helper, same rendering (pct_label
            // already carries the percent sign). Falls back to a bare name
            // when nothing is configured.
            $targetPct > 0 ? 'Leave Slots to ' . pct_label($targetPct) : 'Leave Slots',
            'Leave Starts', 'Expected Leave', 'Expected Returns', 'Expected Resignations',
        ];

        $rows = [];
        foreach ($payload['monthly'] as $month) {
            $rows[] = [
                $month['label'],
                (float)$month['avg_active'],
                (float)$month['avg_on_leave'],
                (float)$month['avg_on_leave_pct'],
                (int)$month['peak_on_leave'],
                (float)$month['peak_on_leave_pct'],
                // null stays null: an unconfigured ceiling must read as an
                // empty cell, never as a 0 somebody could act on.
                $month['leave_slots'] ?? null,
                (int)$month['starts'],
                (int)($month['expected_leave'] ?? 0),
                (int)$month['returns'],
                (int)$month['resignations'],
            ];
        }

        return [$headers, $rows];
    }

    /**
     * Capacity Movement as [headers, rows] for export_csv() / export_xls().
     *
     * Every event in the range, not just the page on screen - the same rule as
     * the daily and monthly exports (spec sections 44-45). Status is written
     * as the plain word the badge would read, never the badge markup, so the
     * column stays filterable in a spreadsheet.
     *
     * @param array<string, mixed> $payload result of calculate()
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public static function exportMovement(array $payload): array
    {
        $headers = ['Date', 'Event', 'Driver', 'Badge', 'Branch', 'Leave Type', 'Status', 'Impact'];

        $rows = [];
        foreach (($payload['movement'] ?? []) as $event) {
            $rows[] = [
                (string)$event['date'],
                (string)$event['event'],
                (string)$event['driver'],
                (string)$event['badge'],
                (string)($event['branch'] ?? ''),
                (string)($event['leave_type'] ?? ''),
                (string)($event['status'] ?? '') === ''
                    ? ''
                    : status_label((string)$event['status']),
                (string)$event['impact'],
            ];
        }

        return [$headers, $rows];
    }
}









