<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Business history of a driver's master data.
 *
 * This is deliberately NOT the audit log. audit_logs answers "who touched the
 * system" (a security record kept forever, spans every screen); driver_history
 * answers "how did this driver come to look like this" (a domain record shown
 * on the driver's own profile). Both are kept - neither replaces the other.
 *
 * The contract this class exists to hold:
 *
 *   1. It is the ONLY writer. There is no update() and no delete() here, so a
 *      history row cannot be edited or removed from application code. Reversing
 *      a change writes a NEW row; it never rewrites an old one.
 *   2. It never touches `drivers`, `leave_records` or `leave_applications`.
 *      Recording history is a pure INSERT - the caller has already applied the
 *      change it is describing.
 *   3. `field_name` is checked against Driver::GOVERNABLE on the way in, so a
 *      mistyped or hostile field can never write a row for a column that is
 *      not governed.
 */
final class DriverHistory
{
    /** How a row came to exist - shown as a badge on the History tab. */
    public const SOURCES = [
        'initial'           => 'Initial Driver Setup',
        'approved_request'  => 'Approved request',
        'direct_correction' => 'Direct correction',
        'import'            => 'Import',
        'deactivation'      => 'Deactivation',
        'reactivation'      => 'Reactivation',
    ];

    /**
     * Write one history row. Returns the new id, or 0 when the row was
     * refused (ungoverned field, or no actual change).
     *
     * Values are stored as TEXT exactly as the database held them; display
     * formatting is Driver::displayGovernedValue()'s job, not ours, so the raw
     * value survives for a future "as of" query.
     */
    public static function record(
        int $driverId,
        string $field,
        mixed $old,
        mixed $new,
        string $source,
        ?string $reason = null,
        ?int $changedBy = null,
        ?int $requestId = null,
        ?string $effectiveDate = null
    ): int {
        if ($driverId <= 0 || !Driver::isGovernable($field)) {
            return 0;
        }

        $oldText = self::toText($old);
        $newText = self::toText($new);

        // Nothing happened, so there is nothing to remember. This is what
        // stops an unrelated edit from generating a pile of empty rows.
        if ($oldText === $newText) {
            return 0;
        }

        return Database::insert('driver_history', [
            'driver_id'      => $driverId,
            'field_name'     => $field,
            'old_value'      => $oldText,
            'new_value'      => $newText,
            'reason'         => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'source'         => array_key_exists($source, self::SOURCES)
                ? $source : 'direct_correction',
            'request_id'     => $requestId,
            'effective_date' => $effectiveDate !== null && $effectiveDate !== ''
                ? $effectiveDate : null,
            'changed_by'     => $changedBy,
            'created_at'     => now(),
        ]);
    }

    /**
     * Record every governed field that differs between two row snapshots.
     *
     * The workhorse for direct corrections, approvals and reactivations: the
     * caller applies its UPDATE, then hands us before/after and we write one
     * row per field that actually moved. Fields outside GOVERNABLE are ignored
     * by construction, so an unrelated column changing never produces history.
     *
     * @param array<string, mixed> $before the driver row as it was
     * @param array<string, mixed> $after  the driver row as it is now
     * @return array<string, int> field => history id, only for fields that changed
     */
    public static function recordDiff(
        int $driverId,
        array $before,
        array $after,
        string $source,
        ?string $reason = null,
        ?int $changedBy = null,
        ?int $requestId = null,
        ?string $effectiveDate = null
    ): array {
        $written = [];

        foreach (array_keys(Driver::GOVERNABLE) as $field) {
            $id = self::record(
                $driverId,
                $field,
                $before[$field] ?? null,
                $after[$field] ?? null,
                $source,
                $reason,
                $changedBy,
                $requestId,
                $effectiveDate
            );

            if ($id > 0) {
                $written[$field] = $id;
            }
        }

        return $written;
    }

    /**
     * Full history for one driver, newest first, with the people resolved.
     *
     * @param array<string, mixed> $filters field, source, changed_by, date_from, date_to
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function forDriver(
        int $driverId,
        array $filters = [],
        int $limit = 200,
        int $offset = 0
    ): array {
        $sql    = ' FROM driver_history h
              LEFT JOIN users u ON u.id = h.changed_by
                  WHERE h.driver_id = ?';
        $params = [$driverId];

        if (($filters['field'] ?? '') !== '') {
            $sql     .= ' AND h.field_name = ?';
            $params[]  = (string)$filters['field'];
        }

        if (($filters['source'] ?? '') !== '') {
            $sql     .= ' AND h.source = ?';
            $params[]  = (string)$filters['source'];
        }

        if (($filters['changed_by'] ?? '') !== '') {
            $sql     .= ' AND h.changed_by = ?';
            $params[]  = (int)$filters['changed_by'];
        }

        if (($filters['date_from'] ?? '') !== '') {
            $sql     .= ' AND h.created_at >= ?';
            $params[]  = (string)$filters['date_from'] . ' 00:00:00';
        }

        if (($filters['date_to'] ?? '') !== '') {
            $sql     .= ' AND h.created_at <= ?';
            $params[]  = (string)$filters['date_to'] . ' 23:59:59';
        }

        $total = (int)Database::scalar('SELECT COUNT(*)' . $sql, $params);

        $rows = Database::all(
            'SELECT h.*, u.username, u.full_name' . $sql
            . ' ORDER BY h.created_at DESC, h.id DESC'
            . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }


    /**
     * Governed fields this driver has actually changed - drives the field
     * filter, since an untouched field offers no useful filter option.
     *
     * @return array<int, string> field_name values, alphabetical
     */
    public static function fieldsUsed(int $driverId): array
    {
        $rows = Database::all(
            'SELECT DISTINCT field_name FROM driver_history
              WHERE driver_id = ? ORDER BY field_name',
            [$driverId]
        );

        return array_map(static fn (array $r): string => (string)$r['field_name'], $rows);
    }

    /** History rows for this driver. */
    public static function countForDriver(int $driverId): int
    {
        return (int)Database::scalar(
            'SELECT COUNT(*) FROM driver_history WHERE driver_id = ?',
            [$driverId]
        );
    }

    /**
     * History rows created by anything other than the baseline seed.
     *
     * The delete guard asks THIS rather than countForDriver(): a driver whose
     * only rows are "Initial Driver Setup" is one nobody has governed, so they
     * stay deletable exactly as before this feature existed. That is what keeps
     * the 11 existing driver-delete assertions green.
     */
    public static function countManagedForDriver(int $driverId): int
    {
        return (int)Database::scalar(
            "SELECT COUNT(*) FROM driver_history
              WHERE driver_id = ? AND source <> 'initial'",
            [$driverId]
        );
    }

    /** Normalise any stored value to the string form the TEXT columns hold. */
    private static function toText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = is_scalar($value) ? (string)$value : '';

        return $text === '' ? null : $text;
    }
}

