<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Driver Dashboard: operational availability over the address book.
 *
 * Every figure on the page comes out of ONE query and ONE classification rule
 * (spec sections 15 and 16): the rows are classified once, then projected into
 * the KPI cards, the branch summary, both charts and the JSON payload the
 * browser filters client-side (spec section 21). Nothing is counted twice, so
 * the cards, the charts and the table cannot disagree.
 */
final class DriverDashboardService
{
    /** The five buckets, in the order the cards, charts and table use. */
    public const CATEGORIES = ['active', 'on_leave', 'idle', 'gosi', 'other'];

    /** @var array<string, string> bucket key => display label */
    public const LABELS = [
        'active'   => 'Active',
        'on_leave' => 'On Leave',
        'idle'     => 'Idle',
        'gosi'     => 'GOSI',
        'other'    => 'Other / Unavailable',
    ];

    /**
     * Bootstrap contextual classes. Colour is never the only signal - every
     * badge, legend entry and chart label also carries its text (spec 12).
     *
     * @var array<string, string>
     */
    public const TONES = [
        'active'   => 'success',
        'on_leave' => 'info',
        'idle'     => 'warning',
        'gosi'     => 'danger',
        'other'    => 'secondary',
    ];

    /** @var array<string, string> bucket key => Bootstrap icon */
    public const ICONS = [
        'active'   => 'bi-person-check',
        'on_leave' => 'bi-airplane',
        'idle'     => 'bi-hourglass-split',
        'gosi'     => 'bi-heart-pulse',
        'other'    => 'bi-person-x',
    ];

    /*
     * drivers.operational_status values that name their own bucket. Anything
     * left over that is still a CONFIGURED value falls through to Active (an
     * assigned driver, or one with no flag set at all); anything not in the
     * Settings list at all lands in Other and raises the data-quality warning.
     */
    private const OP_ON_LEAVE = ['vacation'];
    private const OP_GOSI     = ['sick_gosi'];
    private const OP_IDLE     = ['idle'];
    private const OP_OTHER    = ['training', 'suspended', 'absconded', 'exit'];

    /**
     * THE operational-status calculation (spec section 16). Every card, chart,
     * summary row, table row, filter and export goes through this one rule.
     *
     * Priority (first match wins, so each driver lands in exactly one bucket):
     *
     *   1. outside the active employment population   -> other
     *   2. holds an open leave record (on_leave/overdue) -> on_leave
     *   3. operational_status vacation                -> on_leave
     *   4. operational_status sick_gosi               -> gosi
     *   5. operational_status idle                    -> idle
     *   6. operational_status training/suspended/absconded/exit -> other
     *   7. operational_status assigned or unset       -> active
     *   8. a value the Settings list does not know    -> other + unmapped
     *
     * Rule 7 is what keeps the page useful: the address book predates this
     * dashboard and almost nobody has filled in operational_status yet, so "no
     * flag set" has to read as available rather than as unknown.
     *
     * Employment and operational availability stay separate all the way
     * through (spec section 22): rule 1 is the HR status, rules 3-7 are whether
     * the driver can be dispatched today.
     *
     * @param array<string, mixed> $row a row from self::dataset()
     * @param string $today              today as Y-m-d, for the rule-1 end date
     * @return array{status: string, unmapped: bool}
     */
    public static function classify(array $row, string $today): array
    {
        // Rule 1 - the same predicate the address book and the Leave Dashboard
        // use for "still on the books today" (Driver::activeOnSql()).
        $employed = (string)$row['status'] === 'active'
            || ((string)($row['last_working_date'] ?? '') !== ''
                && (string)$row['last_working_date'] >= $today);

        if (!$employed) {
            return ['status' => 'other', 'unmapped' => false];
        }

        // Rule 2 - the Leave Dashboard's own predicate, so this page's On Leave
        // ties to its "Currently On Leave" card exactly.
        if ((int)($row['on_leave'] ?? 0) === 1) {
            return ['status' => 'on_leave', 'unmapped' => false];
        }

        $raw = (string)($row['operational_status'] ?? '');

        if ($raw === '') {
            // No flag on file: an employed driver with no absence is available.
            return ['status' => 'active', 'unmapped' => false];
        }

        if (in_array($raw, self::OP_ON_LEAVE, true)) {
            return ['status' => 'on_leave', 'unmapped' => false];
        }

        if (in_array($raw, self::OP_GOSI, true)) {
            return ['status' => 'gosi', 'unmapped' => false];
        }

        if (in_array($raw, self::OP_IDLE, true)) {
            return ['status' => 'idle', 'unmapped' => false];
        }

        if (in_array($raw, self::OP_OTHER, true)) {
            return ['status' => 'other', 'unmapped' => false];
        }

        // "assigned" reaches Active through the fall-through below; anything
        // else the Settings list cannot name is counted and reported.
        if ($raw !== 'assigned' && !array_key_exists($raw, Lookup::operationalStatuses())) {
            return ['status' => 'other', 'unmapped' => true];
        }

        return ['status' => 'active', 'unmapped' => false];
    }

    /**
     * Driver Status Override.
     *
     * The stored operational_status does not have the last word: leave does.
     * The five clauses are applied in exactly the order they were specified,
     * first match wins, and there is NO gate - a driver's stored flag is
     * overridden whether it reads Assigned, Idle or nothing at all.
     *
     *   1. approved Resignation whose Last Working Date has passed  -> Exit
     *   2. the driver is On Leave / vacation                        -> On Leave
     *   3. an ongoing Sick Leave application                        -> Sick / GOSI
     *   4. marked Not Returned                                      -> Exit
     *   5. marked Returned                                          -> Not Set
     *
     * WHAT EACH ONE READS:
     *   1  leave_applications, name LIKE '%resign%', approved, LWD < today
     *   2  the driver's open leave record (on_leave / overdue) - the same
     *      predicate the KPI card counts - OR the stored flag 'vacation'
     *   3  leave_applications, name LIKE '%sick%', approved, leave_status
     *      = 'on_leave' (genuinely ongoing, not closed)
     *   4  the driver's MOST RECENT leave_records.status = 'not_returned'
     *   5  the driver's MOST RECENT leave_records.status = 'returned'
     *
     * Rules 4 and 5 read the most recent record rather than "any record": a
     * driver whose old leave was Returned and whose new leave is open must
     * read On Leave (rule 2 catches them first anyway), and "any ever
     * returned" would reset drivers who are away right now.
     *
     * TWO DELIBERATE DECISIONS, both confirmed before implementation:
     *
     *   RULE 2 BEATS RULE 3. Ordered as specified, an ongoing sick
     *   application cannot reach rule 3, because such a driver is by
     *   definition on leave and rule 2 has already matched. Rule 3 is kept
     *   as written and remains a genuine fallback for an approved sick
     *   application whose leave record is missing or no longer open - but
     *   on today's data it does not fire, and the one sick driver reads
     *   On Leave. Say the word to swap rules 2 and 3.
     *
     *   RULE 5 IS LITERAL. A Returned driver reads the em dash even when a
     *   real status is stored - 4 of the 6 already blank, 2 give up a real
     *   flag. That is what "reset to Not Set" was taken to mean; it does
     *   mean the Driver List and the Address Book now disagree for those 2.
     *
     * ONLY THE DISPLAYED STATUS CHANGES. drivers.operational_status, the KPI
     * buckets and both charts are untouched.
     *
     * @param string $label      what the stored flag would have shown
     * @param string $raw        drivers.operational_status as stored
     * @param bool   $onLeave    an open leave record (on_leave / overdue)
     * @param string $leaveState most recent leave_records.status, '' if none
     * @param bool   $sick       ongoing approved Sick Leave application
     * @param bool   $resigned   approved Resignation with LWD in the past
     */
    public static function statusOverride(
        string $label,
        string $raw,
        bool $onLeave,
        string $leaveState,
        bool $sick,
        bool $resigned
    ): string {
        // 1 - terminal, and ahead of everything leave-related.
        if ($resigned) {
            return 'Exit';
        }

        // 2 - the driver is away right now, or is flagged vacation.
        if ($onLeave || $raw === 'vacation') {
            return 'On Leave';
        }

        // 3 - only reachable when rule 2 did not fire; see the note above.
        if ($sick) {
            return 'Sick / GOSI';
        }

        // 4 - closed as a non-return.
        if ($leaveState === 'not_returned') {
            return 'Exit';
        }

        // 5 - closed as returned: reset, whatever the stored flag says.
        if ($leaveState === 'returned') {
            return '—';
        }

        return $label;
    }

    /**
     * Every driver with its calculated operational status, plus the figures the
     * page shows. One read for the rows, one for the "last update" stamp.
     *
     * @return array{
     *     updated: string, today: string,
     *     rows: array<int, array<string, mixed>>,
     *     kpis: array<string, int|float>,
     *     branches: array<int, array<string, mixed>>,
     *     overall: array<string, int>,
     *     unmapped: int,
     *     branch_options: array<string, string>,
     *     status_options: array<string, string>
     * }
     */
    public static function dataset(): array
    {
        $today = today();

        // One row per driver IN THE ACTIVE POPULATION. Resigned, terminated
        // and inactive drivers are already outside the Leave Dashboard's
        // head-count, so counting them here would make Total Drivers a
        // different number from the rest of the product - they are excluded
        // at the source rather than subtracted later. Driver::activeOnSql()
        // is that rule, one placeholder, shared by every screen.
        //
        // The derived table is that driver's open leave (on_leave / overdue) -
        // exactly what the Leave Dashboard's "Currently On Leave" counts, so
        // the two pages cannot drift. One open leave per driver is enforced by
        // the overlap check; GROUP BY keeps that true even if a legacy row
        // slipped through.
        $rows = Database::all(
            'SELECT d.id, d.badge_number, d.driver_name, d.nationality, d.remarks,
                    d.status, d.operational_status, d.last_working_date,
                    d.branch_id, b.branch_name, a.agency_name,
                    lv.on_leave, lv.expected_return
               FROM drivers d
          LEFT JOIN branches b ON b.id = d.branch_id
          LEFT JOIN agencies a ON a.id = d.agency_id
          LEFT JOIN (
                    SELECT driver_id, 1 AS on_leave,
                           MIN(expected_arrival_date) AS expected_return
                      FROM leave_records
                     WHERE status IN ("on_leave", "overdue")
                     GROUP BY driver_id
                ) lv ON lv.driver_id = d.id
              WHERE ' . Driver::activeOnSql('d') . '
           ORDER BY d.driver_name ASC, d.badge_number ASC',
            [$today]
        );

        // Driver Status Override inputs, in two grouped queries rather than
        // one per driver. Both are bounded by the same activeOnSql() rule as
        // the query above, so neither can reach a driver the list does not
        // show. Each has two placeholders: its CASE comes first in the text,
        // then the WHERE.
        //
        //   (a) applications - rule 1 (resignation) and rule 3 (sick)
        $appRows = Database::all(
            'SELECT a.driver_id,
                    MAX(CASE WHEN LOWER(t.name) LIKE "%sick%"
                              AND a.status = "approved"
                              AND a.leave_status = "on_leave"
                             THEN 1 ELSE 0 END) AS sick_ongoing,
                    MAX(CASE WHEN LOWER(t.name) LIKE "%resign%"
                              AND a.status = "approved"
                              AND a.last_working_date IS NOT NULL
                              AND a.last_working_date < ?
                             THEN 1 ELSE 0 END) AS resign_passed
               FROM leave_applications a
               JOIN leave_types t ON t.id = a.leave_type_id
               JOIN drivers d ON d.id = a.driver_id
              WHERE ' . Driver::activeOnSql('d') . '
           GROUP BY a.driver_id',
            [$today, $today]
        );

        $sick    = [];
        $resigned = [];
        foreach ($appRows as $r) {
            $id = (int)$r['driver_id'];
            if ((int)$r['sick_ongoing'] === 1) { $sick[$id] = true; }
            if ((int)$r['resign_passed'] === 1) { $resigned[$id] = true; }
        }

        //   (b) records - rules 2, 4 and 5 all read the driver's CURRENT
        //   leave state, which is their most recent record. A driver can
        //   carry several: an old one Returned and a new one open must read
        //   On Leave, not Not Set, so "any record ever" would be wrong here.
        //   ORDER BY start date then id, and the first row per driver wins.
        $stateRows = Database::all(
            'SELECT r.driver_id, r.status
               FROM leave_records r
               JOIN drivers d ON d.id = r.driver_id
              WHERE ' . Driver::activeOnSql('d') . '
           ORDER BY r.driver_id, r.leave_start_date DESC, r.id DESC',
            [$today]
        );

        $leaveState = [];
        foreach ($stateRows as $r) {
            $id = (int)$r['driver_id'];
            if (!isset($leaveState[$id])) {
                $leaveState[$id] = (string)$r['status'];
            }
        }

        $mapped = [];

        foreach ($rows as $row) {
            $class  = self::classify($row, $today);
            $branch = (string)($row['branch_name'] ?? '');
            $rawOp  = (string)($row['operational_status'] ?? '');
            $id     = (int)$row['id'];

            $mapped[] = [
                'id'                 => $id,
                'badge_number'       => (string)$row['badge_number'],
                'driver_name'        => (string)$row['driver_name'],
                // "No branch" rather than a dash: it has to read as a group on
                // a chart axis and as a filter option, not as missing text.
                'branch_id'          => $row['branch_id'] === null ? null : (int)$row['branch_id'],
                'branch'             => $branch !== '' ? $branch : 'No branch',
                'agency'             => (string)($row['agency_name'] ?? ''),
                'nationality'        => (string)($row['nationality'] ?? ''),
                'remarks'            => (string)($row['remarks'] ?? ''),
                'employment_status'  => (string)$row['status'],
                'employment'         => Lookup::employmentLabel((string)$row['status']),
                'last_working_date'  => (string)($row['last_working_date'] ?? ''),
                // The raw column, kept beside the calculated one so the details
                // panel can show both (spec sections 11 and 22).
                'operational_status' => $rawOp,
                // What the Driver List shows under "Operational Status": the
                // stored flag, run through self::statusOverride().
                'op_flag'            => self::statusOverride(
                    Lookup::operationalLabel($rawOp),
                    $rawOp,
                    (int)($row['on_leave'] ?? 0) === 1,
                    $leaveState[$id] ?? '',
                    isset($sick[$id]),
                    isset($resigned[$id])
                ),
                'op_status'          => $class['status'],
                'op_label'           => self::LABELS[$class['status']],
                'expected_return'    => (string)($row['expected_return'] ?? ''),
                'unmapped'           => $class['unmapped'],
            ];
        }

        $updated = (string)Database::scalar('SELECT MAX(updated_at) FROM drivers');

        return [
            'updated'            => $updated === '' ? date('Y-m-d H:i:s') : $updated,
            'today'              => $today,
            'rows'               => $mapped,
            'kpis'               => self::kpis($mapped),
            'branches'           => self::byBranch($mapped),
            'overall'            => self::overall($mapped),
            'unmapped'           => count(array_filter(
                $mapped,
                static fn (array $r): bool => (bool)$r['unmapped']
            )),
            'branch_options'     => self::branchOptions($mapped),
            'status_options'     => self::statusOptions(),
        ];
    }

    /**
     * The five KPI cards. Every percentage uses Total Drivers as its
     * denominator (spec section 3) and is rounded to one decimal place.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int|float>
     */
    public static function kpis(array $rows): array
    {
        $total  = count($rows);
        $counts = array_fill_keys(self::CATEGORIES, 0);

        foreach ($rows as $row) {
            $key = (string)$row['op_status'];
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        $kpis = ['total' => $total, 'total_pct' => 100.0];

        foreach (self::CATEGORIES as $key) {
            $kpis[$key]          = $counts[$key];
            $kpis[$key . '_pct'] = $total > 0
                ? round(($counts[$key] / $total) * 100, 1)
                : 0.0;
        }

        return $kpis;
    }

    /**
     * Per-branch breakdown for the stacked chart and the summary table.
     * Busiest branch first, matching the examples in spec section 6.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function byBranch(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $id    = $row['branch_id'] === null ? 0 : (int)$row['branch_id'];
            $label = (string)$row['branch'];

            if (!isset($groups[$id])) {
                $groups[$id] = [
                    'branch_id' => $id,
                    'branch'    => $label,
                    'total'     => 0,
                ] + array_fill_keys(self::CATEGORIES, 0);
            }

            $groups[$id]['total']++;
            $key = (string)$row['op_status'];
            if (isset($groups[$id][$key])) {
                $groups[$id][$key]++;
            }
        }

        // Busiest first; the branch name breaks ties so the order is stable
        // from one load to the next.
        usort($groups, static function (array $a, array $b): int {
            return [$b['total'], $a['branch']] <=> [$a['total'], $b['branch']];
        });

        foreach ($groups as &$group) {
            $total = (int)$group['total'];
            $group['active_pct'] = $total > 0
                ? round(((int)$group['active'] / $total) * 100, 1)
                : 0.0;
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * The donut. The five counts always sum to Total Drivers (spec 14).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    public static function overall(array $rows): array
    {
        $counts = array_fill_keys(self::CATEGORIES, 0);

        foreach ($rows as $row) {
            $key = (string)$row['op_status'];
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /**
     * The row the Branch Summary foots to. Sums the same array the table and
     * the charts are drawn from, so the total cannot disagree with either.
     *
     * @param array<int, array<string, mixed>> $branches
     * @return array<string, int|float>
     */
    public static function totals(array $branches): array
    {
        $totals = ['branch_id' => -1, 'branch' => 'Total', 'total' => 0]
            + array_fill_keys(self::CATEGORIES, 0);

        foreach ($branches as $branch) {
            $totals['total'] += (int)$branch['total'];
            foreach (self::CATEGORIES as $key) {
                $totals[$key] += (int)($branch[$key] ?? 0);
            }
        }

        $total = (int)$totals['total'];
        $totals['active_pct'] = $total > 0
            ? round(((int)$totals['active'] / $total) * 100, 1)
            : 0.0;

        return $totals;
    }

    /**
     * The dropdown options actually present in the data (spec section 8).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, string> value => label
     */
    public static function branchOptions(array $rows): array
    {
        $options = ['' => 'All branches'];

        foreach ($rows as $row) {
            $id = $row['branch_id'] === null ? '0' : (string)$row['branch_id'];

            if (!isset($options[$id])) {
                $options[$id] = (string)$row['branch'];
            }
        }

        return $options;
    }

    /** Sentinel for "no operational_status on file" - '' already means "All". */
    public const UNSET_FLAG = '__unset__';

    /**
     * The Operational Status dropdown.
     *
     * Straight from Settings (operational_statuses), the same list the
     * Address Book filters on - NOT the five availability buckets.
     *
     * The buckets are what the KPI cards and the two charts count, and they
     * were what this dropdown used to offer. Five options, four of which
     * could not sensibly be chosen: vacation, training, sick_gosi and
     * undertermination are all configured statuses that drivers carry, and
     * none of them appeared - an under-terminated driver was only reachable
     * through "Active".
     *
     * __unset__ covers the drivers with nothing on file, which is most of
     * them; it needs its own value because '' is already "All statuses".
     *
     * @return array<string, string> value => label
     */
    public static function statusOptions(): array
    {
        return ['' => 'All statuses']
            + Lookup::operationalStatuses()
            + [self::UNSET_FLAG => 'Not set'];
    }

    /**
     * The same four filters the browser applies, in PHP, so an export honours
     * what is on screen (spec section 18).
     *
     * Keep in step with matches() in assets/js/driver-dashboard.js - a test
     * asserts both files name the same four keys.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    /**
     * Drivers per agency - the third chart on the page, beside "Operational
     * Status by Branch" and "Overall Operational Status".
     *
     * Built from the SAME rows the cards, the branch chart and the table are
     * drawn from, so the bars always add up to Total Drivers: a driver with
     * no agency on file is reported as "No agency" rather than dropped, and
     * dropping them is what would make the chart foot to less than the card
     * above it. Sorted by count then name, so the shape does not dance
     * between refreshes.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{agency: string, total: int}>
     */
    public static function byAgency(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $name = trim((string)($row['agency'] ?? ''));
            if ($name === '') {
                $name = 'No agency';
            }

            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        $out = [];
        foreach ($counts as $name => $total) {
            $out[] = ['agency' => (string)$name, 'total' => (int)$total];
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['total'] !== $b['total']) {
                return $b['total'] <=> $a['total'];
            }

            return strcmp($a['agency'], $b['agency']);
        });

        return $out;
    }

    public static function filterRows(array $rows, array $filters): array
    {
        $q      = strtolower(trim((string)($filters['q'] ?? '')));

        // TWO status keys, because the page asks two different questions:
        //
        //   op_status           the availability BUCKET - what the five KPI
        //                        cards and the two charts count (Active /
        //                        On Leave / Idle / GOSI / Other)
        //   operational_status  the driver's own flag, straight from Settings
        //                        - what the dropdown offers
        //
        // They used to share one key, which is why the dropdown could only
        // ever offer the five buckets: vacation, training, sick_gosi and
        // undertermination had nowhere to be selected. Both keys combine
        // with AND, so a card and the dropdown can narrow together.
        $status = (string)($filters['op_status'] ?? '');
        $flag   = (string)($filters['operational_status'] ?? '');
        $branch = (string)($filters['branch_id'] ?? '');

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($q, $status, $flag, $branch): bool {
                if ($status !== '' && (string)$row['op_status'] !== $status) {
                    return false;
                }

                if ($flag !== '') {
                    // Match what the column SHOWS, not what is stored. The
                    // status override can make a driver read "Sick / GOSI"
                    // while their stored flag is still blank, and a filter
                    // reading the raw value would hide the very row the
                    // column is now naming - or keep showing it under
                    // "Not set" after it stopped being unset.
                    $want = $flag === self::UNSET_FLAG
                        ? Lookup::operationalLabel('')
                        : (Lookup::operationalStatuses()[$flag] ?? $flag);

                    if ((string)($row['op_flag'] ?? '') !== $want) {
                        return false;
                    }
                }

                if ($branch !== '') {
                    $id = $row['branch_id'] === null ? '0' : (string)$row['branch_id'];
                    if ($id !== $branch) {
                        return false;
                    }
                }

                if ($q !== '') {
                    // Badge and name are the spec's search fields (section 8);
                    // nationality joins them because it is already on the row
                    // and is what a dispatcher scans for first.
                    $haystack = strtolower(
                        (string)$row['badge_number'] . ' '
                        . (string)$row['driver_name'] . ' '
                        . (string)$row['nationality']
                    );
                    if (strpos($haystack, $q) === false) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * CSV / Excel columns for the filtered list, in the table's own order.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public static function exportRows(array $rows): array
    {
        $headers = [
            'Badge Number', 'Driver Name', 'Branch / Terminal', 'Operational Status',
            'Agency', 'Nationality', 'Expected Return', 'Remarks',
        ];

        $data = [];

        foreach ($rows as $row) {
            $data[] = [
                $row['badge_number'],
                $row['driver_name'],
                $row['branch'],
                $row['op_flag'],
                $row['agency'],
                $row['nationality'],
                $row['expected_return'],
                $row['remarks'],
            ];
        }

        return [$headers, $data];
    }
}
