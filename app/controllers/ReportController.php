<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Models\LeaveRecord;
use App\Models\Lookup;
use App\Models\Report;

/**
 * Reports: leave, return, branch and agency - each with search, filters,
 * print, CSV and Excel-compatible export.
 */
final class ReportController
{
    public function index(array $params = []): void
    {
        redirect('reports/leave');
    }

    private function filters(): array
    {
        return [
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
    }

    private function options(): array
    {
        return [
            'branches'   => Lookup::branchPairs(false),
            'agencies'   => Lookup::agencyPairs(false),
            'leaveTypes' => Lookup::leaveTypePairs(false),
        ];
    }

    /* --------------------------------------------------- leave report --- */

    public function leave(array $params = []): void
    {
        require_login();

        $filters = $this->filters();

        // The download carries the WHOLE report: a reader who clicks Excel
        // wants the data, not the page the screen happens to be showing.
        if (($_GET['export'] ?? '') !== '') {
            $this->exportRows('leave', Report::leave($filters));
        }

        // The screen carries one page of it. Count and rows come from the
        // identical WHERE, so "28 record(s)" in the heading and the rows below
        // it can never be two different answers.
        $total = Report::leaveCount($filters);
        $rows  = Report::leave($filters, per_page(), offset());

        View::render('reports/leave', [
            'rows'    => $rows,
            'total'   => $total,
            'page'    => page_no(),
            'perPage' => per_page(),
            'filters' => $filters,
            'query'   => $_GET,
        ] + $this->options(), 'Leave Report', 'report-leave', [
            ['label' => 'Reports'],
            ['label' => 'Leave Report'],
        ]);
    }

    /* -------------------------------------------------- return report --- */

    public function returnReport(array $params = []): void
    {
        require_login();

        $filters = $this->filters();

        // Whole report to the file, one page to the screen - see leave().
        if (($_GET['export'] ?? '') !== '') {
            $this->exportRows('return', Report::returns($filters));
        }

        $total   = Report::returnsCount($filters);
        $rows    = Report::returns($filters, per_page(), offset());
        // Heading badges, counted over the whole report - NOT over $rows,
        // which is now only the page on screen.
        $summary = Report::returnsSummary($filters);

        View::render('reports/return', [
            'rows'    => $rows,
            'total'   => $total,
            'summary' => $summary,
            'page'    => page_no(),
            'perPage' => per_page(),
            'filters' => $filters,
            'query'   => $_GET,
        ] + $this->options(), 'Return Report', 'report-return', [
            ['label' => 'Reports'],
            ['label' => 'Return Report'],
        ]);
    }

    /* ------------------------------------------------- branch / agency -- */

    public function branch(array $params = []): void
    {
        require_login();

        $filters = $this->summaryFilters();
        $rows    = Report::byBranch($filters);

        if (($_GET['export'] ?? '') !== '') {
            $this->exportSummary('branch', $rows);
        }

        View::render('reports/branch', [
            'rows'    => $rows,
            'filters' => $filters,
            'total'   => count($rows),
        ], 'Branch Report', 'report-branch', [
            ['label' => 'Reports'],
            ['label' => 'Branch Report'],
        ]);
    }

    public function agency(array $params = []): void
    {
        require_login();

        $filters = $this->summaryFilters();
        $rows    = Report::byAgency($filters);

        if (($_GET['export'] ?? '') !== '') {
            $this->exportSummary('agency', $rows);
        }

        View::render('reports/agency', [
            'rows'    => $rows,
            'filters' => $filters,
            'total'   => count($rows),
        ], 'Agency Report', 'report-agency', [
            ['label' => 'Reports'],
            ['label' => 'Agency Report'],
        ]);
    }

    /**
     * Date-range filters for the branch / agency summary reports.
     *
     * Only a whitelisted column name and ISO dates are accepted - anything
     * else falls back to the default or an empty string, so a crafted query
     * string can neither reach the SQL nor widen the range.
     *
     * @return array{date_field: string, date_from: string, date_to: string}
     */
    private function summaryFilters(): array
    {
        $allowed = ['leave_start_date', 'expected_arrival_date', 'actual_arrival_date', 'created_at'];
        $field   = (string) ($_GET['date_field'] ?? 'leave_start_date');
        $from    = (string) ($_GET['date_from'] ?? '');
        $to      = (string) ($_GET['date_to'] ?? '');

        return [
            'date_field' => in_array($field, $allowed, true) ? $field : 'leave_start_date',
            'date_from'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : '',
            'date_to'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : '',
        ];
    }

    /* -------------------------------------------------------- exports --- */

    /** CSV / Excel export shared by the two row based reports. */
    private function exportRows(string $which, array $rows): void
    {
        // Exporting is a separate right from viewing the report, and it arrives
        // as a query string the route map cannot see.
        require_permission('reports.export');

        $format = export_format((string) ($_GET['export'] ?? ''), ['csv', 'xls']);

        if ($which === 'leave') {
            $headers = [
                'Driver', 'Badge', 'Iqama', 'Agency', 'Branch', 'Leave Type',
                'Leave Start', 'Expected Return', 'Actual Arrival', 'Destination Country', 'Status',
                'Not Returned Reason',
            ];
            $data = array_map(static fn (array $r): array => [
                $r['driver_name'], $r['badge_number'], $r['iqama_number'], $r['agency_name'],
                $r['branch_name'], $r['leave_type'], $r['leave_start_date'],
                $r['expected_arrival_date'], $r['actual_arrival_date'], $r['destination'],
                status_label((string)$r['status']),
                (string) ($r['not_returned_reason'] ?? ''),
            ], $rows);
        } else {
            $headers = [
                'Driver', 'Badge', 'Expected Return', 'Actual Arrival', 'Variance (days)',
                'Branch', 'Agency', 'Status',
            ];
            $data = array_map(static fn (array $r): array => [
                $r['driver_name'], $r['badge_number'], $r['expected_arrival_date'],
                $r['actual_arrival_date'], $r['variance_days'], $r['branch_name'],
                $r['agency_name'], status_label((string)$r['status']),
            ], $rows);
        }

        $name = $which . '-report-' . date('Ymd-His');

        if ($format === 'xls') {
            export_xls($headers, $data, $name . '.xls', ucfirst($which) . ' Report');
        }

        export_csv($headers, $data, $name . '.csv');
    }

    /** CSV / Excel export for the two summary reports. */
    private function exportSummary(string $which, array $rows): void
    {
        require_permission('reports.export');

        $headers = [
            $which === 'branch' ? 'Branch' : 'Agency', 'Code', 'Total Leaves',
            'Away Now', 'Overdue', 'Returned', 'Planned', 'Cancelled', 'Not Returned',
        ];

        $data = array_map(static fn (array $r): array => [
            $r['label'], $r['code'], (int)$r['total_leaves'], (int)$r['away_now'],
            (int)$r['overdue_count'], (int)$r['returned_count'],
            (int)$r['planned_count'], (int)$r['cancelled_count'],
            (int)($r['not_returned_count'] ?? 0),
        ], $rows);

        $name   = $which . '-report-' . date('Ymd-His');
        $format = export_format((string) ($_GET['export'] ?? ''), ['csv', 'xls']);

        if ($format === 'xls') {
            export_xls(
                $headers,
                $data,
                $name . '.xls',
                ($which === 'branch' ? 'Branch' : 'Agency') . ' Summary'
            );
        }

        export_csv($headers, $data, $name . '.csv');
    }
}

