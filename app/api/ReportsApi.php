<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Report;

/**
 * Report rows as JSON - lets a report screen refresh without a full reload
 * (the same filters feed the CSV/Excel exports server side).
 */
final class ReportsApi
{
    /**
     * GET /api/reports/rows?report=leave|return&...filters
     */
    public function rows(): void
    {
        $which = (string)($_GET['report'] ?? 'leave');

        $filters = [
            'q'             => trim((string)($_GET['q'] ?? '')),
            'branch_id'     => (string)($_GET['branch_id'] ?? ''),
            'agency_id'     => (string)($_GET['agency_id'] ?? ''),
            'leave_type_id' => (string)($_GET['leave_type_id'] ?? ''),
            'driver_id'     => (string)($_GET['driver_id'] ?? ''),
            'status'        => (string)($_GET['status'] ?? ''),
            'date_field'    => (string)($_GET['date_field'] ?? 'leave_start_date'),
            'date_from'     => (string)($_GET['date_from'] ?? ''),
            'date_to'       => (string)($_GET['date_to'] ?? ''),
        ];

        $rows = match ($which) {
            'return' => Report::returns($filters),
            'branch' => Report::byBranch(),
            'agency' => Report::byAgency(),
            default  => Report::leave($filters),
        };

        json_out(['ok' => true, 'report' => $which, 'count' => count($rows), 'rows' => $rows]);
    }
}
