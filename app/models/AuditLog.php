<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Audit trail read/write. Writes happen through audit_log() in
 * app/helpers/audit.php; this class only reads.
 */
final class AuditLog
{
    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function paginate(array $filters, int $limit, int $offset): array
    {
        $sql    = ' FROM audit_logs al
              LEFT JOIN users u ON u.id = al.user_id
                 WHERE 1 = 1';
        $params = [];

        if (($filters['action'] ?? '') !== '') {
            $sql     .= ' AND al.action = ?';
            $params[] = (string)$filters['action'];
        }

        if (($filters['table_name'] ?? '') !== '') {
            $sql     .= ' AND al.table_name = ?';
            $params[] = (string)$filters['table_name'];
        }

        if (($filters['q'] ?? '') !== '') {
            $needle   = wildcard_needle((string)$filters['q']);
            $sql     .= ' AND (al.action LIKE ? OR al.table_name LIKE ? OR u.username LIKE ?)';
            array_push($params, $needle, $needle, $needle);
        }

        if (($filters['date_from'] ?? '') !== '') {
            $sql     .= ' AND al.created_at >= ?';
            $params[] = (string)$filters['date_from'] . ' 00:00:00';
        }

        if (($filters['date_to'] ?? '') !== '') {
            $sql     .= ' AND al.created_at <= ?';
            $params[] = (string)$filters['date_to'] . ' 23:59:59';
        }

        $total = (int)Database::scalar('SELECT COUNT(*)' . $sql, $params);

        $rows = Database::all(
            'SELECT al.*, u.username, u.full_name' . $sql
            . ' ORDER BY al.id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** Distinct action names for the filter dropdown. */
    public static function actions(): array
    {
        $rows = Database::all('SELECT DISTINCT action FROM audit_logs ORDER BY action');

        return array_map(static fn (array $r): string => (string)$r['action'], $rows);
    }
}
