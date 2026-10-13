<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Models\Dashboard;
use App\Models\LeaveRecord;
use App\Models\Lookup;

/**
 * Operational dashboard: KPI cards, charts and the live panels.
 *
 * Everything is computed from indexed aggregates so the page stays fast even
 * with thousands of leave records.
 */
final class DashboardController
{
    public function index(array $params = []): void
    {
        require_login();

        $today    = today();
        $tomorrow = date_add_days($today, 1);
        $weekEnd  = date_add_days($today, 6);

        $kpis    = LeaveRecord::kpis();
        $buckets = Dashboard::returnBuckets();

        // Trend defaults to the last 6 months; the date filter overrides it.
        //
        // The two inputs are type="month", so they submit "YYYY-MM". MySQL
        // cannot compare a DATE column with "YYYY-MM" - CAST() yields NULL, the
        // BETWEEN matched no rows and the chart came back as an all-zero flat
        // line (which reads as an empty chart). Expand each month first.
        $from = (string)($_GET['from'] ?? '');
        $to   = (string)($_GET['to'] ?? '');

        if (preg_match('/^(\d{4})-(\d{2})$/', $from, $m)) {
            $from = $m[1] . '-' . $m[2] . '-01';
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $to, $m)) {
            // First day of the CHOSEN month; date('Y-m-t') then gives the last
            // day of that same month (to=2026-09 -> 2026-09-30, not October).
            $first = mktime(0, 0, 0, (int)$m[2], 1, (int)$m[1]);
            $to    = $first === false ? ($m[1] . '-' . $m[2] . '-01') : date('Y-m-t', $first);
        }
        if ($from === '' || strtotime($from) === false) {
            // Default window: three months back and three forward, the current
            // month included - seven calendar months in total.
            $from = date('Y-m-01', strtotime('-3 months'));
        }
        if ($to === '' || strtotime($to) === false) {
            // Last day of the month three months ahead, so the window closes on
            // a month boundary instead of mid-month (which would leave a
            // half-empty final column on every chart).
            $to = date('Y-m-t', strtotime('+3 months'));
        }
        // Never let an inverted range produce an empty series.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $trend = Dashboard::leaveTrend($from, $to);

        // The arrivals/applications chart and the three summary cards share the
        // trend's range, so one date filter drives every monthly figure on the
        // page instead of each block answering a different question.
        $arrivalsTrend = Dashboard::arrivalsTrend($from, $to);
        $summary       = Dashboard::arrivalSummary($from, $to);

        $panels = [
            'away'      => LeaveRecord::currentlyAway([], 8),
            'today'     => LeaveRecord::expectedArrivals($today, $today, [], 8),
            'week'      => LeaveRecord::expectedArrivals($today, $weekEnd, [], 8),
            'overdue'   => LeaveRecord::overdue([], 8),
        ];

        $counts = [
            'away'    => LeaveRecord::countCurrentlyAway(),
            'today'   => $kpis['returning_today'],
            'tomorrow'=> $kpis['returning_tomorrow'],
            'week'    => $kpis['returning_week'],
            'overdue' => $kpis['overdue'],
        ];

        // Chart data (labels + counts) are handed to Chart.js as JSON.
        $chartBranch = array_map(
            static fn (array $r): array => ['label' => (string)$r['label'], 'total' => (int)$r['total']],
            Dashboard::awayByBranch()
        );
        $chartAgency = array_map(
            static fn (array $r): array => ['label' => (string)$r['label'], 'total' => (int)$r['total']],
            Dashboard::awayByAgency()
        );

        // Everything the summary PDF needs, computed once on the server. The
        // browser only formats it - no business rule is decided in JavaScript,
        // so the printed figures are the same ones the dashboard shows.
        //
        // KPIs and the policy are a live snapshot; only the trend respects the
        // chosen date range, which is why both are handed over separately.
        $report = [
            'company'    => Lookup::setting('company_name', ''),
            'today'      => $today,
            'generated'  => now(),
            'range_from' => $from,
            'range_to'   => $to,
            'pending'    => Dashboard::pendingApplications($today),
            'cutoff'     => Dashboard::pendingCutoff($today),
            'window'     => Dashboard::pendingWindowDays(),
            'policy'     => Dashboard::leavePolicy(),
            'breakdown'  => Dashboard::branchBreakdown($today),
            'trend'      => $trend,
            'kpis'       => $kpis,
        ];

        View::render('dashboard/index', [
            'kpis'          => $kpis,
            'buckets'       => $buckets,
            'panels'        => $panels,
            'counts'       => $counts,
            'trend'         => $trend,
            'trendFrom'     => $from,
            'trendTo'       => $to,
            'arrivalsTrend' => $arrivalsTrend,
            'summary'       => $summary,
            'chartBranch'   => $chartBranch,
            'chartAgency'   => $chartAgency,
            'today'         => $today,
            'tomorrow'      => $tomorrow,
            'weekEnd'       => $weekEnd,
            'branches'      => Lookup::branchPairs(false),
            'agencies'      => Lookup::agencyPairs(false),
            'report'        => $report,
        ], 'Leave Dashboard', 'dashboard', [
            ['label' => 'Leave Dashboard'],
        ]);
    }
}
