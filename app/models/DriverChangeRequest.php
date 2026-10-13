<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A pending request to change one governed field on one driver.
 *
 * One row = one field. That granularity is the point: a reviewer can approve a
 * mobile number while rejecting a branch transfer, and every decision gets its
 * own record. Rejected and cancelled requests leave no history row at all -
 * history only ever describes a change that actually happened.
 *
 * Lifecycle:
 *
 *   pending ──approve──> approved   (writes driver + a driver_history row)
 *      │
 *      ├──reject───────> rejected   (writes nothing but itself)
 *      │
 *      └──cancel───────> cancelled  (requester withdraws their own request)
 *
 * The rows are append-only from the application's point of view: status and
 * review fields are written once, and there is no path back to `pending`.
 */
final class DriverChangeRequest
{
    /** Same four words leave_applications.status already uses. */
    public const STATUSES = [
        'pending'   => 'Pending',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    /** Default ordering of the queue: undecided first, then newest. */
    public const SORTABLE = [
        'requested_at' => 'dcr.requested_at',
        'field_name'   => 'dcr.field_name',
        'status'       => "FIELD(dcr.status,'pending','approved','rejected','cancelled')",
        'driver'       => 'd.driver_name',
        'badge_number' => 'd.badge_number',
        'reviewed_at'  => 'dcr.reviewed_at',
    ];

    /**
     * Turn the stored TEXT representation back into something `drivers` will
     * accept. Without this a branch request would store the string "5" into an
     * INT UNSIGNED column and an empty value would store '' rather than NULL.
     */
    public static function coerce(string $field, mixed $value): mixed
    {
        if (!Driver::isGovernable($field)) {
            return null;
        }

        $text = $value === null ? '' : trim((string)$value);

        if (in_array($field, ['branch_id', 'agency_id'], true)) {
            return $text === '' ? null : (int)$text;
        }

        if (in_array($field, ['joining_date', 'last_working_date'], true)) {
            return $text === '' ? null : $text;
        }

        return $text === '' ? null : $text;
    }

    /**
     * Open a request. Returns the new id, or throws when a request for the
     * same driver + field is already waiting (uq_dcr_pending, and a friendlier
     * message here than the database would give).
     */
    public static function submit(
        int $driverId,
        string $field,
        mixed $newValue,
        string $reason,
        int $requestedBy,
        ?string $effectiveDate = null
    ): int {
        if (!Driver::isGovernable($field)) {
            throw new \RuntimeException('That field cannot be requested.');
        }

        if (self::pendingFor($driverId, $field) !== null) {
            throw new \RuntimeException(
                'There is already a pending request for this field.'
            );
        }

        return Database::insert('driver_change_requests', [
            'driver_id'      => $driverId,
            'field_name'     => $field,
            'old_value'      => self::toText(Driver::find($driverId)[$field] ?? null),
            'new_value'      => self::toText($newValue),
            'reason'         => mb_substr(trim($reason), 0, 500),
            'status'         => 'pending',
            'requested_by'   => $requestedBy,
            'requested_at'   => now(),
            'effective_date' => $effectiveDate !== null && $effectiveDate !== ''
                ? $effectiveDate : null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /** The open request for this field, if there is one. */
    public static function pendingFor(int $driverId, string $field): ?array
    {
        return Database::one(
            "SELECT * FROM driver_change_requests
              WHERE driver_id = ? AND field_name = ? AND status = 'pending'
              LIMIT 1",
            [$driverId, $field]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT dcr.* FROM driver_change_requests dcr WHERE dcr.id = ?',
            [$id]
        );
    }

    /** Requests still waiting for a decision (nav badge). */
    public static function pendingCount(): int
    {
        return (int)Database::scalar(
            "SELECT COUNT(*) FROM driver_change_requests WHERE status = 'pending'"
        );
    }

    /**
     * The queue, newest undecided first.
     *
     * Filters follow the same shape as the other grids (status, free text,
     * people, branch, date range) so the list can reuse sort_header(), table
     * prefs and the export writer unchanged.
     *
     * @param array<string, mixed> $filters
     * @param array{0:string, 1:string} $sort
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function paginate(array $filters, array $sort, int $limit, int $offset): array
    {
        $sql    = ' FROM driver_change_requests dcr
              JOIN drivers d ON d.id = dcr.driver_id
         LEFT JOIN branches b ON b.id = d.branch_id
              LEFT JOIN users ru ON ru.id = dcr.requested_by
              LEFT JOIN users rv ON rv.id = dcr.reviewed_by
                  WHERE 1 = 1';
        $params = [];

        if (($filters['status'] ?? '') !== '') {
            $sql     .= ' AND dcr.status = ?';
            $params[]  = (string)$filters['status'];
        }

        if (($filters['field'] ?? '') !== '') {
            $sql     .= ' AND dcr.field_name = ?';
            $params[]  = (string)$filters['field'];
        }

        if (($filters['requested_by'] ?? '') !== '') {
            $sql     .= ' AND dcr.requested_by = ?';
            $params[]  = (int)$filters['requested_by'];
        }

        // Branch is the driver's CURRENT posting: the queue answers "who is
        // moving today", not "who filed the request from where".
        if (($filters['branch_id'] ?? '') !== '') {
            $sql     .= ' AND d.branch_id = ?';
            $params[]  = (int)$filters['branch_id'];
        }

        if (($filters['driver_id'] ?? '') !== '') {
            $sql     .= ' AND dcr.driver_id = ?';
            $params[]  = (int)$filters['driver_id'];
        }

        if (($filters['q'] ?? '') !== '') {
            $needle   = wildcard_needle((string)$filters['q']);
            $sql     .= ' AND (d.driver_name LIKE ? OR d.badge_number LIKE ?'
                . ' OR dcr.reason LIKE ?)';
            array_push($params, $needle, $needle, $needle);
        }

        if (($filters['date_from'] ?? '') !== '') {
            $sql     .= ' AND dcr.requested_at >= ?';
            $params[]  = (string)$filters['date_from'] . ' 00:00:00';
        }

        if (($filters['date_to'] ?? '') !== '') {
            $sql     .= ' AND dcr.requested_at <= ?';
            $params[]  = (string)$filters['date_to'] . ' 23:59:59';
        }

        $total = (int)Database::scalar('SELECT COUNT(*)' . $sql, $params);

        [$orderKey, $orderDir] = $sort;
        $order = self::SORTABLE[$orderKey] ?? 'dcr.requested_at';
        $dir   = strtolower((string)$orderDir) === 'asc' ? 'ASC' : 'DESC';

        // Undecided rows stay on top of every sort except an explicit status
        // sort - the queue has to open on what still needs doing.
        if (($orderKey ?? '') !== 'status') {
            $order = "FIELD(dcr.status,'pending','approved','rejected','cancelled'), "
                . $order . ' ' . $dir . ', dcr.id DESC';
        } else {
            $order .= ' ' . $dir . ', dcr.id DESC';
        }

        $rows = Database::all(
            'SELECT dcr.*, d.driver_name, d.badge_number, d.branch_id AS driver_branch_id,
                    b.branch_name,
                    ru.username AS requested_by_name, rv.username AS reviewed_by_name'
            . $sql . ' ORDER BY ' . $order
            . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** Every request filed against one driver, newest first. */
    public static function forDriver(int $driverId): array
    {
        return Database::all(
            'SELECT dcr.*, u.username AS requested_by_name, v.username AS reviewed_by_name
               FROM driver_change_requests dcr
          LEFT JOIN users u ON u.id = dcr.requested_by
          LEFT JOIN users v ON v.id = dcr.reviewed_by
              WHERE dcr.driver_id = ?
           ORDER BY dcr.id DESC',
            [$driverId]
        );
    }

    /** Requested-by people, for the filter dropdown. */
    public static function requesters(): array
    {
        $rows = Database::all(
            'SELECT DISTINCT u.id, u.username
               FROM driver_change_requests dcr
               JOIN users u ON u.id = dcr.requested_by
           ORDER BY u.username'
        );

        $pairs = [];
        foreach ($rows as $r) {
            $pairs[(int)$r['id']] = (string)$r['username'];
        }

        return $pairs;
    }


    /**
     * Same rules the edit form applies, run against a single field.
     *
     * Called both at submit and again at approval: a branch deleted while the
     * request sat in the queue must not be written just because it was valid
     * when it was filed.
     *
     * @return string|null an error message, or null when the value is acceptable
     */
    public static function validate(string $field, mixed $value): ?string
    {
        if (!Driver::isGovernable($field)) {
            return 'That field cannot be changed.';
        }

        $text = $value === null ? '' : trim((string)$value);

        if ($field === 'branch_id' && $text !== ''
            && !Database::exists('SELECT 1 FROM branches WHERE id = ?', [(int)$text])
        ) {
            return 'That branch no longer exists.';
        }

        if ($field === 'agency_id' && $text !== ''
            && !Database::exists('SELECT 1 FROM agencies WHERE id = ?', [(int)$text])
        ) {
            return 'That agency no longer exists.';
        }

        if ($field === 'status'
            && !array_key_exists($text, Lookup::employmentStatuses())
        ) {
            return 'That employment status is not on file.';
        }

        if ($field === 'operational_status'
            && $text !== ''
            && !array_key_exists($text, Lookup::operationalStatuses())
        ) {
            return 'That operational status is not on file.';
        }

        if (in_array($field, ['joining_date', 'last_working_date'], true)
            && $text !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)
        ) {
            return 'Dates must be recorded as YYYY-MM-DD.';
        }

        if ($field === 'badge_number' || $field === 'iqama_number') {
            if ($text === '') {
                return 'That value is required.';
            }
        }

        return null;
    }

    /**
     * Approve: apply the change, write history, close the request.
     *
     * Everything happens inside one transaction, so a failure can never leave
     * the master row updated with no history - the exact outcome this feature
     * exists to prevent.
     *
     * @return array<string, mixed> the driver row as it is after the change
     * @throws \RuntimeException when the request is not pending, is stale, or
     *         no longer validates
     */
    public static function approve(
        int $id,
        int $reviewer,
        ?string $note = null,
        ?string $effectiveDate = null
    ): array {
        return Database::tx(static function () use ($id, $reviewer, $note, $effectiveDate) {
            $req = Database::one(
                'SELECT * FROM driver_change_requests WHERE id = ? FOR UPDATE',
                [$id]
            );

            if ($req === null) {
                throw new \RuntimeException('That change request could not be found.');
            }

            if ((string)$req['status'] !== 'pending') {
                throw new \RuntimeException(
                    'That change request has already been decided.'
                );
            }

            $driver = Driver::find((int)$req['driver_id']);

            if ($driver === null) {
                throw new \RuntimeException('The driver is no longer on file.');
            }

            // Stale check: the master must still hold what was recorded when
            // the request was filed. If it does not, the request is describing
            // a change that has already been superseded, and applying it would
            // silently overwrite somebody else's newer edit.
            $current = self::toText($driver[$req['field_name']] ?? null);

            if ($current !== $req['old_value']) {
                throw new \RuntimeException(
                    'The master record has changed since this request was filed. '
                    . 'Reject it and ask for a fresh request.'
                );
            }

            $value = self::coerce((string)$req['field_name'], $req['new_value']);
            $error = self::validate((string)$req['field_name'], $value);

            if ($error !== null) {
                throw new \RuntimeException($error);
            }

            $driverId = (int)$req['driver_id'];

            Database::update(
                'drivers',
                [(string)$req['field_name'] => $value],
                'id = ?',
                [$driverId]
            );

            $after = Driver::find($driverId);

            DriverHistory::recordDiff(
                $driverId,
                $driver,
                $after ?? [],
                'approved_request',
                (string)$req['reason'],
                $reviewer,
                $id,
                $effectiveDate ?? ($req['effective_date'] !== null
                    ? (string)$req['effective_date'] : null)
            );

            Database::update(
                'driver_change_requests',
                [
                    'status'       => 'approved',
                    'reviewed_by'  => $reviewer,
                    'reviewed_at'  => now(),
                    'review_note'  => $note !== null ? mb_substr(trim($note), 0, 500) : null,
                    'updated_at'   => now(),
                ],
                'id = ?',
                [$id]
            );

            audit_log(
                'driver-change-approved',
                'driver_change_requests',
                $id,
                $req,
                ['status' => 'approved', 'field_name' => $req['field_name']]
            );

            return $after ?? $driver;
        });
    }


    /**
     * Reject: record the decision and the reviewer's note.
     *
     * Writes NOTHING to `drivers` and NOTHING to `driver_history`. A change
     * that never happened has no business in a record of things that did -
     * the request row itself is the full audit trail for a refusal.
     */
    public static function reject(int $id, int $reviewer, string $note): void
    {
        Database::tx(static function () use ($id, $reviewer, $note) {
            $req = Database::one(
                'SELECT * FROM driver_change_requests WHERE id = ? FOR UPDATE',
                [$id]
            );

            if ($req === null) {
                throw new \RuntimeException('That change request could not be found.');
            }

            if ((string)$req['status'] !== 'pending') {
                throw new \RuntimeException(
                    'That change request has already been decided.'
                );
            }

            Database::update(
                'driver_change_requests',
                [
                    'status'      => 'rejected',
                    'reviewed_by' => $reviewer,
                    'reviewed_at' => now(),
                    'review_note' => mb_substr(trim($note), 0, 500),
                    'updated_at'  => now(),
                ],
                'id = ?',
                [$id]
            );

            audit_log(
                'driver-change-rejected',
                'driver_change_requests',
                $id,
                $req,
                ['status' => 'rejected', 'review_note' => trim($note)]
            );
        });
    }

    /**
     * Withdraw a request. Only the person who filed it may do this - a
     * reviewer uses reject() instead, so the two decisions stay
     * distinguishable in the queue.
     */
    public static function cancel(int $id, int $userId): void
    {
        Database::tx(static function () use ($id, $userId) {
            $req = Database::one(
                'SELECT * FROM driver_change_requests WHERE id = ? FOR UPDATE',
                [$id]
            );

            if ($req === null) {
                throw new \RuntimeException('That change request could not be found.');
            }

            if ((string)$req['status'] !== 'pending') {
                throw new \RuntimeException(
                    'Only a pending request can be withdrawn.'
                );
            }

            if ((int)$req['requested_by'] !== $userId) {
                throw new \RuntimeException(
                    'Only the person who filed this request can withdraw it.'
                );
            }

            Database::update(
                'driver_change_requests',
                ['status' => 'cancelled', 'updated_at' => now()],
                'id = ?',
                [$id]
            );

            audit_log(
                'driver-change-cancelled',
                'driver_change_requests',
                $id,
                $req,
                ['status' => 'cancelled']
            );
        });
    }

    /** @return string|null the TEXT form a TEXT column would hold */
    private static function toText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_scalar($value) ? (string)$value : '';

        return $text === '' ? null : $text;
    }
}

