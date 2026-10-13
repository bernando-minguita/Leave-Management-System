<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Driver address book + leave history queries.
 *
 * All listing is server-side: search, filters, sort and pagination happen in
 * SQL so the module stays fast with thousands of drivers.
 */
final class Driver
{
    /** Columns a user may sort by on the address book. */
    public const SORTABLE = [
        'badge_number'  => 'd.badge_number',
        'driver_name'   => 'd.driver_name',
        'iqama_number'   => 'd.iqama_number',
        'passport_number'=> 'd.passport_number',
        'mobile_number'  => 'd.mobile_number',
        'nationality'   => 'd.nationality',
        'agency'        => 'a.agency_name',
        'branch'        => 'b.branch_name',
        'position'      => 'd.position',
        'status'        => 'd.status',
        'operational_status' => 'd.operational_status',
        'created_at'    => 'd.created_at',
        'updated_at'    => 'd.updated_at',
    ];

    /**
     * Master fields that may only change through a governed path.
     *
     * Keyed by column => human label. This is the single whitelist that decides
     * (a) which fields can be requested from the Request Change screen,
     * (b) which fields a direct correction has to justify, and
     * (c) which fields a history row is written for.
     *
     * A column added to `drivers` is deliberately NOT governed until it is
     * listed here - opting in is what stops a new field silently becoming
     * requestable, and stops a history row being written for something nobody
     * has thought about. Mirrors how Driver::SORTABLE whitelists sort keys.
     *
     * Deliberately absent: `remarks` (free annotation, not master data) and
     * the emergency-contact pair (not worth a workflow in v1).
     *
     * @var array<string, string>
     */
    public const GOVERNABLE = [
        'badge_number'       => 'Badge Number',
        'driver_name'        => 'Driver Name',
        'iqama_number'       => 'Iqama Number',
        'passport_number'    => 'Passport Number',
        'mobile_number'      => 'Mobile Number',
        'alternate_mobile'   => 'Alternate Mobile',
        'nationality'        => 'Nationality',
        'agency_id'          => 'Agency',
        'branch_id'          => 'Branch',
        'department'         => 'Department',
        'position'           => 'Position',
        'joining_date'       => 'Joining Date',
        'status'             => 'Employment Status',
        'operational_status' => 'Operational Status',
        'last_working_date'  => 'Last Working Date',
        'address'            => 'Address',
        'city'               => 'City',
    ];

    /**
     * Is this column a governed master field?
     *
     * Checked against GOVERNABLE - never against whatever the caller posted -
     * so a crafted field_name can never write history for (or read) a column
     * outside the whitelist.
     */
    public static function isGovernable(string $field): bool
    {
        return isset(self::GOVERNABLE[$field]);
    }

    /**
     * Show a stored value the way the UI shows it.
     *
     * Foreign keys become their display name and NULL becomes an em dash, so
     * a History row reads like a sentence instead of a dump.
     */
    public static function displayGovernedValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($field === 'branch_id') {
            $name = Database::scalar(
                'SELECT branch_name FROM branches WHERE id = ?',
                [(int)$value]
            );

            return $name !== null ? (string)$name : '—';
        }

        if ($field === 'agency_id') {
            $name = Database::scalar(
                'SELECT agency_name FROM agencies WHERE id = ?',
                [(int)$value]
            );

            return $name !== null ? (string)$name : '—';
        }

        if ($field === 'status') {
            return Lookup::employmentLabel((string)$value);
        }

        if ($field === 'operational_status') {
            return Lookup::operationalLabel((string)$value);
        }

        if (in_array($field, ['joining_date', 'last_working_date'], true)) {
            return fmt_date((string)$value);
        }

        return (string)$value;
    }

    /**
     * SQL predicate for "this driver belongs to the active population".
     *
     * Employment status is the headline rule, but a driver deactivated WITH a
     * last working date still counts up to and INCLUDING that day: they were
     * still working on those dates and must not be excluded from the total
     * driver count for them (automated deactivation rules). The capacity walk
     * drops them the day after the date - the same exit rule it already
     * applies to an approved resignation application.
     *
     * A driver with no date on file behaves exactly as before this rule
     * existed, so applying the column moves no existing number.
     *
     * The caller binds the day as this predicate's only placeholder and puts
     * it where the predicate sits in the WHERE clause.
     *
     * @param string $alias table alias used by the caller ("d" by default)
     */
    public static function activeOnSql(string $alias = 'd'): string
    {
        return "({$alias}.status = 'active'"
            . " OR ({$alias}.last_working_date IS NOT NULL"
            . " AND {$alias}.last_working_date >= ?))";
    }

    /**
     * Build the shared WHERE clause for address book queries.
     *
     * @param array<string, mixed> $filters
     * @return array{0:string, 1:array<int, mixed>}
     */
    public static function where(array $filters): array
    {
        $sql    = ' FROM drivers d
                    LEFT JOIN agencies a ON a.id = d.agency_id
                    LEFT JOIN branches b ON b.id = d.branch_id
                    WHERE 1 = 1';
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $needle = wildcard_needle($q);
            $sql   .= ' AND (d.driver_name LIKE ? OR d.badge_number LIKE ?'
                . ' OR d.iqama_number LIKE ? OR d.passport_number LIKE ?'
                . ' OR d.mobile_number LIKE ?)';
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }

        // Integer filters accept a single value or a multi-select list ("1,3").
        foreach (['branch_id' => 'd.branch_id', 'agency_id' => 'd.agency_id'] as $key => $column) {
            $ids = id_filter($filters[$key] ?? '');

            if ($ids !== []) {
                $sql .= ' AND ' . $column . ' IN ' . sql_in($ids);
                array_push($params, ...$ids);
            }
        }

        // Status columns hold slugs from two Settings lists (employment and
        // operational) - both are multi-select filters like nationality.
        foreach (['status', 'operational_status', 'nationality'] as $key) {
            $values = filter_values($filters[$key] ?? '');

            if ($values !== []) {
                $sql .= ' AND d.' . $key . ' IN ' . sql_in($values);
                array_push($params, ...$values);
            }
        }

        // "Only drivers who are currently away".
        if (!empty($filters['on_leave'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM leave_records lr
                                   WHERE lr.driver_id = d.id
                                     AND lr.status IN ('on_leave','overdue'))";
        }

        // "Only drivers still employed" - the same predicate the Leave
        // Dashboard's Total Active Drivers count applies. Without it the
        // address-book total and that card disagree by every leaver, which is
        // exactly what a drill-down has to rule out.
        //
        // activeOnSql() carries one placeholder - the caller binds the day -
        // and this predicate is appended LAST, so its parameter goes last too,
        // keeping params in the same order as the placeholders in $sql.
        if (!empty($filters['active'])) {
            $sql   .= ' AND ' . self::activeOnSql('d');
            $params[] = today();
        }

        return [$sql, $params];
    }

    /**
     * Paged, sorted, filtered driver list.
     *
     * @param array<string, mixed> $filters
     * @param array{0:string,1:string} $sort
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function paginate(array $filters, array $sort = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::where($filters);

        $total = (int)Database::scalar('SELECT COUNT(*)' . $where, $params);

        $sortKey  = $sort[0] ?? 'badge_number';
        $sortDir  = ($sort[1] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $orderCol = self::SORTABLE[$sortKey] ?? 'd.badge_number';

        $sql = 'SELECT d.*, a.agency_name, b.branch_name' . $where
            . ' ORDER BY ' . $orderCol . ' ' . $sortDir . ', d.id ASC'
            . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        return ['rows' => Database::all($sql, $params), 'total' => $total];
    }

    /** Single driver with agency/branch labels joined on. */
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT d.*, a.agency_name, a.code AS agency_code,
                    b.branch_name, b.code AS branch_code
               FROM drivers d
          LEFT JOIN agencies a ON a.id = d.agency_id
          LEFT JOIN branches b ON b.id = d.branch_id
              WHERE d.id = ?
              LIMIT 1',
            [$id]
        );
    }

    /**
     * Lightweight rows for the AJAX driver picker on the New Leave form.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function searchLite(string $term, int $limit = 15): array
    {
        $needle = wildcard_needle($term);

        if ($needle === null) {
            return [];
        }

        return Database::all(
            'SELECT d.id, d.badge_number, d.driver_name, d.iqama_number,
                    d.passport_number, d.mobile_number, d.nationality, d.status,
                    a.agency_name, b.branch_name
               FROM drivers d
          LEFT JOIN agencies a ON a.id = d.agency_id
          LEFT JOIN branches b ON b.id = d.branch_id
              WHERE d.status = ?
                AND (d.driver_name LIKE ? OR d.badge_number LIKE ?
                     OR d.iqama_number LIKE ? OR d.mobile_number LIKE ?)
           ORDER BY d.driver_name ASC
              LIMIT ' . (int)$limit,
            ['active', $needle, $needle, $needle, $needle]
        );
    }

    /** @return array<int, string> distinct nationalities for the filter dropdown */
    public static function nationalities(): array
    {
        $rows = Database::all(
            'SELECT DISTINCT nationality FROM drivers
              WHERE nationality IS NOT NULL AND nationality <> ""
              ORDER BY nationality'
        );

        return array_map(static fn (array $r): string => (string)$r['nationality'], $rows);
    }

    /**
     * The driver's live leave status block (profile + New Leave screens).
     *
     * @return array<string, mixed>|null null when the driver is available
     */
    public static function currentLeave(int $driverId): ?array
    {
        return Database::one(
            "SELECT lr.*, lt.name AS leave_type
               FROM leave_records lr
          LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
              WHERE lr.driver_id = ?
                AND lr.actual_arrival_date IS NULL
                AND lr.status IN ('on_leave','overdue','planned')
           ORDER BY FIELD(lr.status,'on_leave','overdue','planned'),
                    lr.leave_start_date DESC
              LIMIT 1",
            [$driverId]
        );
    }

    /** Full leave history for one driver, newest first. */
    public static function leaveHistory(int $driverId, int $limit = 200): array
    {
        return Database::all(
            'SELECT lr.*, lt.name AS leave_type
               FROM leave_records lr
          LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
              WHERE lr.driver_id = ?
           ORDER BY lr.leave_start_date DESC, lr.id DESC
              LIMIT ' . (int)$limit,
            [$driverId]
        );
    }

    /** Is this badge/iqama already taken by a different driver? */
    public static function duplicateExists(string $field, string $value, ?int $exceptId = null): bool
    {
        if (!in_array($field, ['badge_number', 'iqama_number'], true)) {
            return false;
        }

        $sql    = 'SELECT COUNT(*) FROM drivers WHERE ' . $field . ' = ?';
        $params = [$value];

        if ($exceptId !== null) {
            $sql    .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int)Database::scalar($sql, $params) > 0;
    }

    /**
     * A driver with an open leave record cannot be deactivated - it would make
     * the Current Leave screen and the return workflow inconsistent.
     */
    public static function activeLeaveCount(int $driverId): int
    {
        return (int)Database::scalar(
            "SELECT COUNT(*) FROM leave_records
              WHERE driver_id = ? AND status IN ('on_leave','overdue','planned')",
            [$driverId]
        );
    }

    /** All matching drivers for CSV export (no pagination). */
    public static function allForExport(array $filters): array
    {
        [$where, $params] = self::where($filters);

        return Database::all(
            'SELECT d.badge_number, d.driver_name, d.iqama_number, d.passport_number,
                    d.mobile_number,
                    d.alternate_mobile, d.nationality, a.agency_name, b.branch_name,
                    d.department, d.position, d.joining_date, d.last_working_date,
                    d.emergency_contact_name,
                    d.emergency_contact_number, d.address, d.city, d.remarks, d.status,
                    d.operational_status'
            . $where . ' ORDER BY d.badge_number ASC',
            $params
        );
    }
}

