<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Report read models. Every report is a plain aggregate query so it can also
 * be streamed straight to CSV/Excel without loading the page.
 */
final class Report
{
    /**
     * Leave report rows (filters mirror LeaveRecord::where()).
     *
     * @param array<string, mixed> $filters
     * @param int|null             $limit   null = every row (what the export wants)
     * @return array<int, array<string, mixed>>
     */
    public static function leave(array $filters, ?int $limit = null, int $offset = 0): array
    {
        return \App\Models\LeaveRecord::all($filters, ['leave_start_date', 'desc'], $limit, $offset);
    }

    /** Rows the leave report holds in total - the same WHERE, no window. */
    public static function leaveCount(array $filters): int
    {
        [$where, $params] = \App\Models\LeaveRecord::where($filters);

        return (int) Database::scalar(
            'SELECT COUNT(*)' . \App\Models\LeaveRecord::FROM . $where,
            $params
        );
    }

    /**
     * Return report: expected vs actual with the day difference.
     *
     * @param array<string, mixed> $filters
     * @param int|null             $limit   null = every row (what the export wants)
     * @return array<int, array<string, mixed>>
     */
    public static function returns(array $filters, ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = \App\Models\LeaveRecord::where($filters);

        // Only records where a return has actually been recorded, plus any
        // still outstanding (so the report shows lateness properly).
        $sql = \App\Models\LeaveRecord::SELECT . ',
                    lr.actual_arrival_date,
                    DATEDIFF(lr.actual_arrival_date, lr.expected_arrival_date) AS variance_days'
            . \App\Models\LeaveRecord::FROM
            . $where
            . ' ORDER BY lr.expected_arrival_date DESC, lr.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, (int) $limit) . ' OFFSET ' . max(0, (int) $offset);
        }

        return Database::all($sql, $params);
    }

    /** Rows the return report holds in total - the same WHERE, no window. */
    public static function returnsCount(array $filters): int
    {
        [$where, $params] = \App\Models\LeaveRecord::where($filters);

        return (int) Database::scalar(
            'SELECT COUNT(*)' . \App\Models\LeaveRecord::FROM . $where,
            $params
        );
    }

    /**
     * On-time vs late across the WHOLE return report.
     *
     * These two figures sit in the page heading as badges, so they describe
     * the report - not the page a reader happens to be looking at. Counting
     * the current $rows there would make the badges shrink as somebody paged
     * through, which is the same "the heading disagrees with the list" fault
     * pagination is supposed to prevent.
     *
     * IFNULL matters: a record with no actual arrival has a NULL DATEDIFF,
     * and the view's PHP counts that as on time ((int) null === 0), so the
     * SQL has to make the same call or the two would drift by the number of
     * outstanding returns.
     *
     * @return array{on_time: int, late: int}
     */
    public static function returnsSummary(array $filters): array
    {
        [$where, $params] = \App\Models\LeaveRecord::where($filters);

        $variance = 'IFNULL(DATEDIFF(lr.actual_arrival_date, lr.expected_arrival_date), 0)';

        $row = Database::one(
            'SELECT SUM(CASE WHEN ' . $variance . ' <= 0 THEN 1 ELSE 0 END) AS on_time,'
                . ' SUM(CASE WHEN ' . $variance . ' > 0 THEN 1 ELSE 0 END) AS late'
            . \App\Models\LeaveRecord::FROM . $where,
            $params
        );

        return [
            'on_time' => (int) ($row['on_time'] ?? 0),
            'late'    => (int) ($row['late'] ?? 0),
        ];
    }

    /**
     * Summary by branch: totals, currently away, overdue, returned, planned.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function byBranch(array $filters = []): array
    {
        // Branch is snapshotted onto the leave record (leave_records.branch_id);
        // it is NOT reliably held on the driver - only 24 of 459 drivers have
        // drivers.branch_id set. Routing the join through drivers therefore
        // dropped 18 of 28 records and the report totalled 10 instead of 28.
        [$range, $rangeParams] = self::rangeClause($filters);

        $rows = Database::all(
            'SELECT b.id, b.branch_name AS label, b.code,
                    COUNT(lr.id) AS total_leaves,
                    SUM(CASE WHEN lr.status IN ("on_leave","overdue") THEN 1 ELSE 0 END) AS away_now,
                    SUM(CASE WHEN lr.status = "overdue" THEN 1 ELSE 0 END) AS overdue_count,
                    SUM(CASE WHEN lr.status = "returned" THEN 1 ELSE 0 END) AS returned_count,
                    SUM(CASE WHEN lr.status = "planned" THEN 1 ELSE 0 END) AS planned_count,
                    SUM(CASE WHEN lr.status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_count,
                    SUM(CASE WHEN lr.status = "not_returned" THEN 1 ELSE 0 END) AS not_returned_count
               FROM branches b
          LEFT JOIN leave_records lr ON lr.branch_id = b.id' . $range . '
           GROUP BY b.id, b.branch_name, b.code
           ORDER BY b.branch_name',
            $rangeParams
        );

        // Leave records with no branch snapshot would otherwise vanish
        // entirely, leaving the Total Leaves column short of the real count.
        $orphans = Database::all(
            'SELECT COUNT(*) AS total_leaves,
                    SUM(CASE WHEN status IN ("on_leave","overdue") THEN 1 ELSE 0 END) AS away_now,
                    SUM(CASE WHEN status = "overdue" THEN 1 ELSE 0 END) AS overdue_count,
                    SUM(CASE WHEN status = "returned" THEN 1 ELSE 0 END) AS returned_count,
                    SUM(CASE WHEN status = "planned" THEN 1 ELSE 0 END) AS planned_count,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_count,
                    SUM(CASE WHEN lr.status = "not_returned" THEN 1 ELSE 0 END) AS not_returned_count
               FROM leave_records lr
              WHERE lr.branch_id IS NULL' . $range,
            $rangeParams
        );

        $orphan = $orphans[0] ?? [];

        if ((int) ($orphan['total_leaves'] ?? 0) > 0) {
            $rows[] = [
                'id'             => null,
                'label'          => 'Unassigned',
                'code'           => null,
                'total_leaves'   => (int) $orphan['total_leaves'],
                'away_now'       => (int) ($orphan['away_now'] ?? 0),
                'overdue_count'  => (int) ($orphan['overdue_count'] ?? 0),
                'returned_count' => (int) ($orphan['returned_count'] ?? 0),
                'planned_count'  => (int) ($orphan['planned_count'] ?? 0),
                'cancelled_count'=> (int) ($orphan['cancelled_count'] ?? 0),
            'not_returned_count' => (int) ($orphan['not_returned_count'] ?? 0),
            ];
        }

        return $rows;
    }

    /** Same shape as byBranch() but grouped by agency. */
    public static function byAgency(array $filters = []): array
    {
        [$range, $rangeParams] = self::rangeClause($filters);

        $rows = Database::all(
            'SELECT a.id, a.agency_name AS label, a.code,
                    COUNT(lr.id) AS total_leaves,
                    SUM(CASE WHEN lr.status IN ("on_leave","overdue") THEN 1 ELSE 0 END) AS away_now,
                    SUM(CASE WHEN lr.status = "overdue" THEN 1 ELSE 0 END) AS overdue_count,
                    SUM(CASE WHEN lr.status = "returned" THEN 1 ELSE 0 END) AS returned_count,
                    SUM(CASE WHEN lr.status = "planned" THEN 1 ELSE 0 END) AS planned_count,
                    SUM(CASE WHEN lr.status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_count,
                    SUM(CASE WHEN lr.status = "not_returned" THEN 1 ELSE 0 END) AS not_returned_count
               FROM agencies a
          LEFT JOIN drivers d ON d.agency_id = a.id
          LEFT JOIN leave_records lr ON lr.driver_id = d.id' . $range . '
           GROUP BY a.id, a.agency_name, a.code
           ORDER BY a.agency_name',
            $rangeParams
        );

        // A driver with no agency is unreachable when starting FROM agencies,
        // so those leave records have to be counted separately or the column
        // total falls short of the table.
        $orphans = Database::all(
            'SELECT COUNT(*) AS total_leaves,
                    SUM(CASE WHEN lr.status IN ("on_leave","overdue") THEN 1 ELSE 0 END) AS away_now,
                    SUM(CASE WHEN lr.status = "overdue" THEN 1 ELSE 0 END) AS overdue_count,
                    SUM(CASE WHEN lr.status = "returned" THEN 1 ELSE 0 END) AS returned_count,
                    SUM(CASE WHEN lr.status = "planned" THEN 1 ELSE 0 END) AS planned_count,
                    SUM(CASE WHEN lr.status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_count,
                    SUM(CASE WHEN lr.status = "not_returned" THEN 1 ELSE 0 END) AS not_returned_count
               FROM leave_records lr
          LEFT JOIN drivers d ON d.id = lr.driver_id
              WHERE d.agency_id IS NULL' . $range,
            $rangeParams
        );

        $orphan = $orphans[0] ?? [];

        if ((int) ($orphan['total_leaves'] ?? 0) > 0) {
            $rows[] = [
                'id'              => null,
                'label'           => 'Unassigned',
                'code'            => null,
                'total_leaves'    => (int) $orphan['total_leaves'],
                'away_now'        => (int) ($orphan['away_now'] ?? 0),
                'overdue_count'   => (int) ($orphan['overdue_count'] ?? 0),
                'returned_count'  => (int) ($orphan['returned_count'] ?? 0),
                'planned_count'   => (int) ($orphan['planned_count'] ?? 0),
                'cancelled_count' => (int) ($orphan['cancelled_count'] ?? 0),
            'not_returned_count' => (int) ($orphan['not_returned_count'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Date-range predicate shared by byBranch() and byAgency().
     *
     * The fragment is meant to be concatenated onto the leave_records JOIN,
     * never used as WHERE: with a WHERE, the LEFT JOIN degenerates into an
     * inner join and branches/agencies with no leave in the range disappear
     * instead of showing a 0.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, string>}
     */
    private static function rangeClause(array $filters): array
    {
        $allowed = ['leave_start_date', 'expected_arrival_date', 'actual_arrival_date', 'created_at'];

        $field = (string) ($filters['date_field'] ?? 'leave_start_date');
        $field = in_array($field, $allowed, true) ? $field : 'leave_start_date';

        $from = (string) ($filters['date_from'] ?? '');
        $to   = (string) ($filters['date_to'] ?? '');

        $sql    = '';
        $params = [];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql    .= ' AND lr.' . $field . ' >= ?';
            $params[] = $from;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            if ($field === 'created_at') {
                // created_at is a DATETIME: "<= the bare date" would exclude
                // everything entered after midnight on the final day.
                $sql    .= ' AND lr.' . $field . ' < ?';
                $params[] = date('Y-m-d', (int) strtotime($to) + 86400);
            } else {
                $sql    .= ' AND lr.' . $field . ' <= ?';
                $params[] = $to;
            }
        }

        return [$sql, $params];
    }

    /** Distinct drivers/branches used to populate report filters. */
    public static function filterOptions(): array
    {
        return [
            'branches'     => \App\Models\Lookup::branchPairs(),
            'agencies'     => \App\Models\Lookup::agencyPairs(),
            'leave_types'  => \App\Models\Lookup::leaveTypePairs(),
        ];
    }
}
