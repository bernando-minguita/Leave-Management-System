<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\CapacityPlanningService;

/**
 * Capacity drill-down as JSON (spec section 22): the drivers on leave for one
 * date, honouring the same filters as the page that asked for them, so the
 * expanded list can never disagree with the number in the table.
 */
final class CapacityApi
{
    /**
     * GET /api/capacity/drivers?date=YYYY-MM-DD[&page=&size=]&...same filters
     *
     * One date, paged (round 6): a long trip shows on every day it covers, so
     * a busy day can carry a couple of hundred rows. The slicing happens here
     * so the browser never downloads rows it will not draw, and `count` stays
     * the number of drivers away THAT day - the figure the eye icon is
     * standing for - while `rows` is only the page asked for.
     */
    public function drivers(): void
    {
        $date    = (string)($_GET['date'] ?? '');
        $filters = CapacityPlanningService::normalise($_GET);
        $all     = CapacityPlanningService::driversAwayOn($date, $filters);

        // The file carries the WHOLE day, not the ten rows on screen - the
        // same rule as the other drill modals. Checked literally rather than
        // through export_format(): that helper reads an ABSENT value as csv
        // and would 400 the plain drill-down call.
        if (($_GET['export'] ?? '') === 'xls') {
            require_permission('reports.export');
            self::exportDay($date, $all);
        }

        $size  = (int)($_GET['size'] ?? 10);
        $size  = in_array($size, [10, 25, 50, 100], true) ? $size : 10;
        $total = count($all);
        $pages = max(1, (int)ceil($total / $size));
        $page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);

        json_out([
            'ok'    => true,
            'date'  => $date,
            'count' => $total,
            'page'  => $page,
            'pages' => $pages,
            'size'  => $size,
            'rows'  => array_slice($all, ($page - 1) * $size, $size),
        ]);
    }

    /**
     * One day as an Excel file: the same seven columns the modal draws, in the
     * same order, with "#" numbering every driver of that day - so what is on
     * screen and what lands in Excel cannot disagree.
     *
     * @param array<int, array<string, mixed>> $all every driver away that day
     */
    private static function exportDay(string $date, array $all): void
    {
        $headers = ['#', 'Driver', 'Badge', 'Leave Type', 'Start',
            'Expected Return', 'Status'];

        $rows = [];
        foreach (array_values($all) as $i => $row) {
            // Same rule the chip applies: an overdue record reads Overdue
            // whatever its stored status still says.
            $status = !empty($row['is_overdue'])
                ? 'Overdue'
                : (string)($row['status_label'] ?? '');

            $rows[] = [
                $i + 1,
                (string)($row['driver_name'] ?? ''),
                (string)($row['badge_number'] ?? ''),
                (string)($row['leave_type'] ?? ''),
                fmt_date((string)($row['leave_start_date'] ?? '')),
                fmt_date((string)($row['expected_arrival_date'] ?? '')),
                $status,
            ];
        }

        $title = 'Drivers on leave - ' . fmt_date($date);

        export_xls($headers, $rows, 'drivers-on-leave-' . $date . '.xls', $title);
    }
}
