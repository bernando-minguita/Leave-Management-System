<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\LeaveRecord;

/**
 * Dashboard JSON: KPI refresh and individual panel rows, so marking a driver
 * returned can update the cards without a full page reload.
 */
final class DashboardApi
{
    /** GET /api/dashboard/kpis */
    public function kpis(): void
    {
        json_out(['ok' => true, 'kpis' => LeaveRecord::kpis()]);
    }

    /**
     * GET /api/dashboard/panel?name=today|tomorrow|week|overdue|away
     * Returns the rows for one operational panel.
     */
    public function panel(): void
    {
        $name  = (string)($_GET['name'] ?? 'today');
        $today = today();

        [$from, $to, $rows] = match ($name) {
            'tomorrow' => [date_add_days($today, 1), date_add_days($today, 1), null],
            'week'     => [$today, date_add_days($today, 6), null],
            'overdue'  => [$today, $today, LeaveRecord::overdue([], 100)],
            'away'     => [$today, $today, LeaveRecord::currentlyAway([], 100)],
            default    => [$today, $today, null],
        };

        $rows = $rows ?? LeaveRecord::expectedArrivals($from, $to, [], 100);

        json_out([
            'ok'    => true,
            'name'  => $name,
            'count' => count($rows),
            'rows'  => $rows,
        ]);
    }

    /**
     * GET /api/dashboard/summary-drivers?series=arrivals|resignations|vacations
     *                                  &month=YYYY-MM[&branch=CODE]
     *                                  [&page=1][&size=10]
     *
     * The drivers behind one month of a summary table (row-click drill-down
     * modal). `total` is ALWAYS the whole month - the count that sat in the
     * cell that was clicked - while `rows` is the page on screen: 10 per page
     * by default, and the modal's pager steps through them.
     * `total` stays the whole month even when the rows are paged.
     *
     * ?export=xls downloads the WHOLE month regardless of paging, so the file
     * still foots with the cell rather than with whichever page was open.
     */
    public function summaryDrivers(): void
    {
        $series = (string)($_GET['series'] ?? '');
        $month  = (string)($_GET['month'] ?? '');
        $branch = trim((string)($_GET['branch'] ?? ''));

        if (!in_array($series, \App\Models\Dashboard::SUMMARY_SERIES, true)) {
            json_error('Unknown summary series.', 400);
        }

        if (!preg_match('/^\d{4}-\d{2}(?:-\d{2})?$/', $month)) {
            json_error('Month must be YYYY-MM.', 400);
        }

        // The download carries the WHOLE month, not the ten rows the modal is
        // showing: a file that footed with one page instead of the clicked
        // cell would be lying about the number the reader clicked. Checked
        // literally rather than through export_format(): that helper reads an
        // ABSENT value as csv, which is not in the allowed list and would 400
        // the plain drill-down call. Exporting is also a separate right from
        // viewing the list - it arrives as a query string the route map cannot
        // see, so the permission is demanded here.
        $isExport = (($_GET['export'] ?? '') === 'xls');

        if ($isExport) {
            require_permission('reports.export');
        }

        $page = $isExport ? 1 : max(1, (int)($_GET['page'] ?? 1));
        // 0 (no paging) so the file receives every row; 10 is the modal's
        // page size and 100 the screen ceiling - the same pair kpi-drivers
        // applies.
        $size = $isExport
            ? 0
            : min(100, max(1, (int)($_GET['size'] ?? 10)));

        $result = \App\Models\Dashboard::summaryDrivers(
            $series,
            $month,
            $branch === '' ? null : $branch,
            $page,
            $size
        );

        // Human-readable bits the modal shows: the month as a heading and
        // each row's date in the display format chosen in Settings.
        $titles = [
            'arrivals'     => 'Expected Returns',
            'resignations' => 'Resignation Applications',
            'vacations'    => 'Vacation Applications',
        ];

        $result['title'] = $titles[$result['series']] ?? '';
        $result['label'] = date('M Y', (int)strtotime($result['from'] . ' UTC'));
        $result['rows']  = array_map(static function (array $row): array {
            $row['event_date_fmt'] = fmt_date((string)($row['event_date'] ?? ''));

            return $row;
        }, $result['rows']);

        // The file was sized for the whole month above (size 0), so it
        // carries every row the cell counted - not the open page.
        if ($isExport) {
            self::exportSummary($result);
        }

        json_out(['ok' => true] + $result);
    }

    /**
     * GET /api/dashboard/kpi-drivers?kpi=<key>[&page=1][&size=10]
     *
     * The rows behind one Leave Dashboard KPI card, paged. The cards used to
     * navigate away to a page whose filters had to be rebuilt by hand; this
     * lets the card's "View" open its own list instead, carrying the card's
     * own predicate, so the figure and the list cannot disagree.
     *
     * The kpi key is validated in Dashboard::kpiDrivers() against the same map
     * the cards use - an unknown one 400s rather than silently listing
     * something else.
     */
    public function kpiDrivers(): void
    {
        $kpi = (string)($_GET['kpi'] ?? '');

        // The download carries the WHOLE list, not the ten rows the modal is
        // showing: a reader who clicks Excel wants the data, and "the page I
        // happen to be looking at" is not it. Checked literally rather than
        // through export_format(), which reads an ABSENT value as csv and
        // would 400 the plain drill-down call.
        $isExport = (($_GET['export'] ?? '') === 'xls');

        if ($isExport) {
            require_permission('reports.export');
        }

        $page = $isExport ? 1 : max(1, (int)($_GET['page'] ?? 1));
        // 10 is the card modal's default; 100 is a ceiling, never a default.
        // The export deliberately sits above that ceiling - see above.
        $size = $isExport
            ? 100000
            : min(100, max(1, (int)($_GET['size'] ?? 10)));

        // The three headline cards are totals over the page-header range, so
        // the card sends the window it was calculated from. A range card that
        // omits it is rejected rather than silently listing some other period.
        $from = trim((string)($_GET['from'] ?? '')) ?: null;
        $to   = trim((string)($_GET['to'] ?? '')) ?: null;

        $result = \App\Models\Dashboard::kpiDrivers($kpi, $page, $size, $from, $to);

        if (($result['ok'] ?? false) !== true) {
            json_error('Unknown KPI card.', 400);
        }

        if ($isExport) {
            self::exportKpi($result);
        }

        json_out($result);
    }

    /**
     * The KPI card's rows as an Excel file - the WHOLE list, not the page the
     * modal happens to be on, and in the same column order the modal draws so
     * what is on screen and what lands in Excel cannot disagree. The row
     * numbers therefore start at 1 and run to the end, matching the "#"
     * column a reader would count through by hand.
     *
     * @param array<string, mixed> $result result of Dashboard::kpiDrivers()
     */
    private static function exportKpi(array $result): void
    {
        // Total Active Drivers is a population, not a series of events: it has
        // no date to report, and the modal drops the column for exactly that
        // reason. The file has to follow it or the two would not line up.
        $withDate = (string)($result['kpi'] ?? '') !== 'total_drivers';

        $headers = ['#', 'Badge', 'Driver', 'Branch'];
        if ($withDate) {
            $headers[] = 'Date';
        }
        $headers[] = 'Status';

        $offset = max(0, ((int)($result['page'] ?? 1) - 1) * (int)($result['size'] ?? 10));
        $rows   = [];

        foreach ((array)$result['rows'] as $i => $row) {
            $code = (string)($row['branch_code'] ?? '');
            $name = (string)($row['branch_name'] ?? '');

            $line = [
                $offset + (int)$i + 1,
                (string)($row['badge_number'] ?? ''),
                (string)($row['driver_name'] ?? ''),
                // Same rule the Branch cell applies in dashboard.js.
                ($code === '-' || $code === '') ? 'No branch' : ($name !== '' ? $name : $code),
            ];

            if ($withDate) {
                $line[] = (string)($row['event_date_fmt'] ?? '') !== ''
                    ? (string)$row['event_date_fmt']
                    : fmt_date((string)($row['event_date'] ?? ''));
            }

            $line[] = str_replace('_', ' ', (string)($row['status'] ?? ''));
            $rows[] = $line;
        }

        $title = trim((string)($result['title'] ?? '') . ' - ' . (string)($result['label'] ?? ''), ' -');
        $name  = 'dashboard-' . preg_replace('/[^a-z0-9]+/i', '-',
                    strtolower((string)($result['kpi'] ?? 'list')))
               . '-' . date('Ymd-His') . '.xls';

        export_xls($headers, $rows, $name, $title);
    }

    /**
     * The drill-down rows as an Excel file: the SIX columns the modal shows,
     * in the same order, with the same display transforms, so what is on
     * screen and what lands in Excel cannot disagree. The caller passes the
     * whole month (paging is off for exports), so the file foots with the
     * cell that was clicked rather than with the open page.
     *
     * @param array<string, mixed> $result result of Dashboard::summaryDrivers()
     */
    private static function exportSummary(array $result): void
    {
        $headers = ['Badge', 'Driver', 'Branch', 'Date', 'Application Status', 'Leave Status'];

        $rows = array_map(static function (array $row): array {
            $code = (string)($row['branch_code'] ?? '');
            $name = (string)($row['branch_name'] ?? '');

            // Both axes, worded exactly as the modal's badges word them. An
            // arrivals record with no filing behind it has no application
            // status and says so with an em dash - never a blank cell that
            // reads as "missing data".
            $application = (string)($row['application_status'] ?? '');
            $leave       = (string)($row['leave_status'] ?? '');

            return [
                (string)($row['badge_number'] ?? ''),
                (string)($row['driver_name'] ?? ''),
                // Same rule the Branch cell applies in dashboard.js.
                ($code === '-' || $code === '') ? 'No branch' : ($name !== '' ? $name : $code),
                fmt_date((string)($row['event_date'] ?? '')),
                $application === '' ? '—' : str_replace('_', ' ', $application),
                $leave === '' ? '—' : str_replace('_', ' ', $leave),
            ];
        }, (array)$result['rows']);

        $title = trim((string)($result['title'] ?? '') . ' - ' . (string)($result['label'] ?? ''), ' -');
        $name  = 'dashboard-' . (string)($result['series'] ?? 'summary')
               . '-' . (string)($result['month'] ?? '') . '.xls';

        export_xls($headers, $rows, $name, $title);
    }
}
