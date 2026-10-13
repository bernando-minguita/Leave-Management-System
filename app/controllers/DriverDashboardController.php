<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Models\DriverDashboardService;

/**
 * Driver Dashboard: operational availability across the fleet.
 *
 * The controller only gathers what the page needs and picks an export format -
 * the classification, the counts and the filtering all live in
 * DriverDashboardService so the cards, the charts, the table and the export
 * cannot drift apart (spec sections 15 and 16).
 */
final class DriverDashboardController
{
    public function index(array $params = []): void
    {
        require_login();

        // Exporting is its own right, and it arrives as a query string the
        // route map cannot see - same as the address book and the reports.
        if (($_GET['export'] ?? '') !== '') {
            $this->export();
        }

        $data = DriverDashboardService::dataset();

        View::render('driver-dashboard/index', [
            'updated'           => $data['updated'],
            'today'             => $data['today'],
            'kpis'              => $data['kpis'],
            'branches'          => $data['branches'],
            'branchTotals'      => DriverDashboardService::totals($data['branches']),
            // The third chart: one bar per agency, from the same rows the
            // cards above are counted from, so it cannot disagree with them.
            'agencies'          => DriverDashboardService::byAgency($data['rows']),
            'overall'           => $data['overall'],
            'unmapped'          => $data['unmapped'],
            'rows'              => $data['rows'],
            'statusOptions'     => $data['status_options'],
            'branchOptions'     => $data['branch_options'],
            'labels'            => DriverDashboardService::LABELS,
            'tones'             => DriverDashboardService::TONES,
            'icons'             => DriverDashboardService::ICONS,
            'categories'        => DriverDashboardService::CATEGORIES,
            'query'             => $_GET,
        ], 'Driver Dashboard', 'driver-dashboard', [
            ['label' => 'Dashboard'],
            ['label' => 'Driver Dashboard'],
        ]);
    }

    /** CSV / Excel of the list exactly as filtered on screen (spec 18). */
    private function export(): void
    {
        require_permission('drivers.export');

        $format = export_format((string)($_GET['export'] ?? ''), ['csv', 'xls']);

        $data = DriverDashboardService::dataset();
        $rows = DriverDashboardService::filterRows($data['rows'], $_GET);

        [$headers, $out] = DriverDashboardService::exportRows($rows);
        $name            = 'driver-dashboard-' . date('Ymd-His');

        if ($format === 'xls') {
            export_xls($headers, $out, $name . '.xls', 'Driver Dashboard');
        }

        export_csv($headers, $out, $name . '.csv');
    }
}
