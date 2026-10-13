<?php
/**
 * Operational dashboard.
 *
 * Uses: $kpis, $panels, $counts, $trend, $trendFrom, $trendTo, $summary,
 *       $arrivalsTrend, $chartBranch, $chartAgency, $today, $weekEnd
 */
$kpis   = $kpis ?? [];
$panels = $panels ?? [];
$counts = $counts ?? [];
$trend  = $trend ?? [];
$today  = $today ?? today();

// One date range drives every monthly figure on this page: both trend charts,
// the three branch tables and the three summary cards. It is edited from the
// page header rather than buried in a chart, so it is visible without
// scrolling and it is obvious what it applies to.
$trendFrom    = $trendFrom ?? '';
$trendTo      = $trendTo ?? '';
$summaryRange = fmt_date($trendFrom, 'M Y') . ' – ' . fmt_date($trendTo, 'M Y');

$rangeForm = '<form class="d-flex align-items-center gap-1"'
    . ' title="Date range for the trend charts and the monthly tables"'
    . ' method="get" action="' . e(url('dashboard')) . '">'
    . '<label class="visually-hidden" for="trendFrom">From month</label>'
    . '<input type="month" class="form-control form-control-sm" id="trendFrom" name="from"'
    . ' style="width:7.75rem;flex:0 0 auto;min-width:0"'
    . ' value="' . e(substr($trendFrom, 0, 7)) . '">'
    . '<label class="visually-hidden" for="trendTo">To month</label>'
    . '<input type="month" class="form-control form-control-sm" id="trendTo" name="to"'
    . ' style="width:7.75rem;flex:0 0 auto;min-width:0"'
    . ' value="' . e(substr($trendTo, 0, 7)) . '">'
    . '<button class="btn btn-sm btn-outline-secondary" type="submit" title="Apply range">'
    . '<i class="bi bi-arrow-right"></i></button>'
    . '</form>';

// On-leave percentage reads through the one helper (round 6): computed at
// two decimals, displayed trimmed - 5.75% stays "5.75%", 5.70% reads "5.7%",
// a whole number stays "45%".
$onLeavePctValue = (float)($kpis['on_leave_pct'] ?? 0);
$onLeavePctLabel = pct_label($onLeavePctValue);
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Leave Dashboard',
    'description' => 'Live view of who is away, who is coming back and what is overdue.',
    // The toolbar carries the range filter and the report button only (round
    // 6): "New Leave" and "Expected Returns" each have their own screen in the
    // sidebar, and repeating them here was two more ways to leave a page whose
    // whole job is showing the numbers.
    'actions'     => $rangeForm
        // Exporting is its own right, same as the Excel/CSV buttons elsewhere,
        // so this one hides for anyone without it rather than failing on click.
        . (can('reports.export')
            ? '<button type="button" class="btn btn-primary btn-sm" id="buildSummaryReport"'
              . ' title="Download the current dashboard as a PDF">'
              . '<i class="bi bi-file-earmark-pdf me-1"></i>Build Summary Report</button>'
            : ''),
    // KPI drill page: no screen-wide "Columns" control. Its grids sit behind
    // the cards and inside the drill modal, so one button up here would list
    // unrelated columns together. See data-dd-prefs-none.
    'no_prefs'    => true,
]) ?>

<!-- --------------------------------------------------------- KPI cards -->
<?php
// Six cards in a 3 x 2 grid (col-6 on phones): 3 + 3 on md and wider.
//
// "Returning" and "Leave Starting" each collapse into a single card: the week
// figure is the headline and today's/tomorrow's slice sits in the hint. The
// 7-day window is applied automatically here - no filter to set by hand.
$weekEnd    = $weekEnd ?? date_add_days($today, 6);
$rangeLabel = fmt_date($today, 'd M') . ' – ' . fmt_date($weekEnd, 'd M');
?>
<div class="row g-3 mb-3">
    <?= View::partial('partials/kpi-card', [
        'label' => 'Total Active Drivers', 'value' => $kpis['total_drivers'] ?? 0,
        'tone' => 'primary', 'icon' => 'bi-person-badge', 'kpiKey' => 'total_drivers',
        'hint' => 'Address book', 'drillKpi' => 'total_drivers',
    ]) ?>
    <?= View::partial('partials/kpi-card', [
        'label' => 'On Leave vs Total Drivers', 'value' => $onLeavePctLabel,
        'tone' => 'warning', 'icon' => 'bi-pie-chart-fill', 'kpiKey' => 'on_leave_pct',
        'progress' => $onLeavePctValue,
        'hint' => (int)($kpis['on_leave'] ?? 0) . ' of '
            . (int)($kpis['total_drivers'] ?? 0) . ' active drivers are away right now',
        // Deliberately NO drillKpi: on_leave_pct opens exactly the same rows
        // as "Currently On Leave" (Dashboard::kpiDrivers maps both keys to one
        // case), so a View here was the same modal a few inches to the right,
        // under a different heading. The number that differs between the two
        // cards is the percentage, and that is already in the hint.
    ]) ?>
    <?= View::partial('partials/kpi-card', [
        'label' => 'Currently On Leave', 'value' => $kpis['on_leave'] ?? 0,
        'tone' => 'info', 'icon' => 'bi-airplane', 'kpiKey' => 'on_leave',
        'hint' => 'Away right now', 'drillKpi' => 'on_leave',
    ]) ?>

    <?= View::partial('partials/kpi-card', [
        'label' => 'Returning This Week', 'value' => $kpis['returning_week'] ?? 0,
        'tone' => 'success', 'icon' => 'bi-calendar-week', 'kpiKey' => 'returning_week',
        'hint' => 'Today ' . (int)($kpis['returning_today'] ?? 0)
            . ' · Tomorrow ' . (int)($kpis['returning_tomorrow'] ?? 0)
            . ' · ' . $rangeLabel,
        'drillKpi' => 'returning_week',
    ]) ?>
    <?= View::partial('partials/kpi-card', [
        'label' => 'Leave Starting This Week', 'value' => $kpis['starting_week'] ?? 0,
        'tone' => 'dark', 'icon' => 'bi-calendar-plus', 'kpiKey' => 'starting_week',
        'hint' => 'Planned trips · ' . $rangeLabel,
        // The window travels inside the key: Dashboard::kpiDrivers() computes
        // the same today..weekEnd span this card counts, so the modal opens on
        // exactly these rows instead of a page that had to be re-filtered.
        'drillKpi' => 'starting_week',
    ]) ?>
    <?= View::partial('partials/kpi-card', [
        'label' => 'Overdue Returns', 'value' => $kpis['overdue'] ?? 0,
        'tone' => 'danger', 'icon' => 'bi-exclamation-triangle-fill', 'kpiKey' => 'overdue',
        'hint' => 'Past expected date', 'drillKpi' => 'overdue',
    ]) ?>
</div>

<!-- --------------------------------- arrivals & applications summary -- -->
<?php
// Three headline counts and, below them, one table per figure. Everything is
// bucketed by month AND by the branch recorded on the row, from the range set
// in the page header, so a card can never disagree with its own grid.
$summary       = $summary ?? ['totals' => [], 'series' => [], 'branches' => []];
$summaryTotals = $summary['totals'] ?? [];
$summarySeries = $summary['series'] ?? [];
$summaryCodes  = $summary['branches'] ?? [];

// key => [heading, icon, tone] for the three tables, in reading order.
$summaryTables = [
    'arrivals'     => ['Expected Returns', 'bi-box-arrow-in-right', 'primary'],
    'resignations' => ['Resignation Applications', 'bi-person-dash', 'warning'],
    'vacations'    => ['Vacation Applications', 'bi-airplane', 'success'],
];
?>
<div class="row g-3 mb-3">
    <?= View::partial('partials/kpi-card', [
        'label' => 'Expected Returns', 'value' => (int)($summaryTotals['arrivals'] ?? 0),
        'tone' => 'primary', 'icon' => 'bi-box-arrow-in-right', 'kpiKey' => 'expected_arrivals',
        'hint' => 'Return not yet recorded · ' . $summaryRange,
        'drillKpi' => 'expected_arrivals', 'drillRange' => $trendFrom . ',' . $trendTo,
    ]) ?>
    <?= View::partial('partials/kpi-card', [
        'label' => 'Resignation Applications', 'value' => (int)($summaryTotals['resignations'] ?? 0),
        'tone' => 'warning', 'icon' => 'bi-person-dash', 'kpiKey' => 'resignation_applications',
        'hint' => 'Filed, not cancelled · by last working date · ' . $summaryRange,
        'drillKpi' => 'resignation_applications', 'drillRange' => $trendFrom . ',' . $trendTo,
    ]) ?>
    <?= View::partial('partials/kpi-card', [
        'label' => 'Vacation Applications', 'value' => (int)($summaryTotals['vacations'] ?? 0),
        'tone' => 'success', 'icon' => 'bi-airplane', 'kpiKey' => 'vacation_applications',
        'hint' => 'By last working date · ' . $summaryRange,
        'drillKpi' => 'vacation_applications', 'drillRange' => $trendFrom . ',' . $trendTo,
    ]) ?>
</div>

<div class="row g-3 mb-3">
<?php foreach ($summaryTables as $key => [$title, $icon, $tone]): ?>
    <?php $table = $summarySeries[$key] ?? ['months' => [], 'byBranch' => [], 'total' => 0]; ?>
    <div class="col-12 col-xxl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center gap-2">
                <h3 class="h6 mb-0"><i class="bi <?= e($icon) ?> me-2 text-<?= e($tone) ?>"></i><?= e($title) ?></h3>
                <span class="d-flex align-items-center gap-2">
                    <span class="small text-body-secondary"><?= e($summaryRange) ?></span>
                    <?php // Opens the shared stacked-bar modal, re-rendered for
                          // whichever card's button was pressed. ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#stackedModal"
                            data-stacked-series="<?= e($key) ?>"
                            data-stacked-title="<?= e($title) ?>"
                            data-stacked-icon="<?= e($icon) ?>"
                            data-stacked-tone="<?= e($tone) ?>"
                            title="Stacked trend by branch"
                            aria-label="Stacked trend by branch for <?= e($title) ?>">
                        <i class="bi bi-bar-chart-steps"></i>
                    </button>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <?php foreach ($summaryCodes as $code => $branchName): ?>
                                <th class="text-end" title="<?= e($branchName) ?>"><?= e($code) ?></th>
                            <?php endforeach; ?>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($table['months'] === []): ?>
                        <tr><td colspan="<?= count($summaryCodes) + 2 ?>">
                            <?= empty_state('bi-table', 'No months to show.', 'Choose a wider range in the page header.') ?>
                        </td></tr>
                    <?php else: foreach ($table['months'] as $month): ?>
                        <?php // Row click opens the drill-down modal listing the
                              // drivers behind every number in this row. ?>
                        <tr class="summary-drill-row"
                            data-summary-series="<?= e($key) ?>"
                            data-summary-month="<?= e((string)($month['key'] ?? '')) ?>"
                            data-summary-title="<?= e($title) ?>"
                            data-summary-label="<?= e((string)$month['label']) ?>"
                            tabindex="0" role="button"
                            title="Show the drivers behind this row">
                            <td class="text-nowrap"><?= e($month['label']) ?></td>
                            <?php foreach ($summaryCodes as $code => $branchName): ?>
                                <td class="text-end"><?= (int)($month['cells'][$code] ?? 0) ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-semibold"><?= (int)$month['total'] ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <?php if ($table['months'] !== []): ?>
                    <tfoot class="table-light">
                        <tr class="fw-semibold">
                            <th>Total</th>
                            <?php foreach ($summaryCodes as $code => $branchName): ?>
                                <th class="text-end"><?= (int)($table['byBranch'][$code] ?? 0) ?></th>
                            <?php endforeach; ?>
                            <th class="text-end"><?= (int)$table['total'] ?></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- ------------------------------------- summary row drill-down modal -- -->
<?php // One dialog for all three summary tables: clicking any month row opens
      // it for that series + month. Deliberately built from plain table rows
      // (no header/body section tags) - dashboard-table-test counts those
      // section tags in this file and must keep seeing exactly five of each. ?>
<div class="modal fade" id="summaryDrillModal" tabindex="-1"
     aria-labelledby="summaryDrillTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="summaryDrillTitle">
                    <i class="bi bi-list-ul me-2 text-primary" id="sdIcon"></i><span id="sdHeading">Drivers</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="small text-body-secondary mb-3" id="sdMeta"></div>

                <div id="sdLoading" class="text-body-secondary text-center py-4 d-none">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    Loading drivers…
                </div>

                <div id="sdError" class="alert alert-danger d-none mb-0" role="alert"></div>

                <div id="sdEmpty" class="text-body-secondary text-center py-4 d-none">
                    <i class="bi bi-inbox d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                    No drivers behind this row.
                </div>

                <div class="table-responsive" id="sdTableWrap" hidden>
                    <?php // The header sits in its own header section and the rows in
                          // their own body section. Shipping a bare <tr> instead meant
                          // the browser filed it under an IMPLICIT body - which is
                          // exactly what renderRows() then emptied, so the header
                          // disappeared the moment the modal opened. dashboard-table-test
                          // counts both sections in this file (6 + 6). ?>
                    <table class="table table-sm table-hover align-middle mb-0"
                           id="sdTable" data-dd-skip>
                        <thead class="table-light">
                        <tr>
                            <th class="text-center">#</th>
                            <th>Badge</th>
                            <th>Driver</th>
                            <th>Branch</th>
                            <th id="sdDateHead">Date</th>
                            <th id="sdAppStatusHead">Application Status</th>
                            <th id="sdLeaveStatusHead">Leave Status</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

            </div>
            <?php // One footer shape for every drill modal in the app. Both
                  // Capacity Planning modals already put it here: the pager on
                  // the LEFT as prev / label / next, and the action buttons
                  // grouped on the RIGHT. This modal used to keep its pager at
                  // the bottom of the BODY, right-aligned and with the label
                  // before the arrows - so the same card opened two different
                  // ways depending on which screen you came from.
                  //
                  // It starts with d-none rather than d-flex + [hidden]:
                  // Bootstrap's .d-flex is display:flex !important and
                  // outranks the hidden attribute, so that pair can never
                  // actually hide anything. dashboard.js swaps d-none for
                  // d-flex when a card really does span pages. ?>
            <div class="modal-footer justify-content-between">
                <div class="d-none align-items-center gap-2" id="sdPager">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            id="sdPrev" aria-label="Previous page">&lsaquo;</button>
                    <span class="small text-body-secondary" id="sdPageLabel"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            id="sdNext" aria-label="Next page">&rsaquo;</button>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <?php // Exporting is its own right, same as every other
                          // Excel button on the site: it hides for anyone
                          // without it rather than failing on click, and
                          // dashboard.js only points it at a list once rows
                          // have actually loaded. ?>
                    <?php if (can('reports.export')): ?>
                    <a class="btn btn-outline-success btn-sm disabled" id="sdExport"
                       href="#" aria-disabled="true" tabindex="-1">
                        <i class="bi bi-file-earmark-excel me-1"></i>Excel
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ------------------------------------------------------------ charts -->
<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom">
                <h3 class="h6 mb-0"><i class="bi bi-diagram-3 me-2 text-primary"></i>Current Leave by Branch</h3>
            </div>
            <div class="card-body chart-box">
                <canvas id="chartBranch" height="220"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom">
                <h3 class="h6 mb-0"><i class="bi bi-building me-2 text-info"></i>Current Leave by Agency</h3>
            </div>
            <div class="card-body chart-box">
                <canvas id="chartAgency" height="220"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <h3 class="h6 mb-0"><i class="bi bi-graph-up me-2 text-success"></i>Leave Trend</h3>
                <span class="small text-body-secondary"><?= e($summaryRange) ?></span>
            </div>
            <div class="card-body chart-box">
                <canvas id="chartTrend" height="220"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <h3 class="h6 mb-0"><i class="bi bi-graph-up-arrow me-2 text-warning"></i>Applications &amp; Arrivals</h3>
                <span class="small text-body-secondary" title="Range set in the page header"><?= e($summaryRange) ?></span>
            </div>
            <div class="card-body chart-box">
                <canvas id="chartArrivals" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ------------------------------------------------------ live panels -->
<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                <h3 class="h6 mb-0">
                    <i class="bi bi-airplane me-2 text-primary"></i>Currently On Leave
                    <span class="badge text-bg-light border ms-1"><?= (int)($counts['away'] ?? 0) ?></span>
                </h3>
                <a class="small link-offset-2" href="<?= e(url('leave/current')) ?>">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                    
    <th class="text-center">#</th>
                        <th>Badge</th><th>Driver</th><th>Agency</th><th>Branch</th>
                        <th>Leave Type</th><th>Start</th><th>Expected</th>
                        <th>Destination Country</th><th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    
<?php $rowNo = offset() + 1; ?>
                    <?php $away = $panels['away'] ?? []; ?>
                    <?php if ($away === []): ?>
                        <tr><td colspan="10">
                            <?= empty_state('bi-airplane', 'No drivers are currently on leave.') ?>
                        </td></tr>
                    <?php else: foreach ($away as $row): ?>
                        
                        <tr>
    <td class="text-center"><?= $rowNo++ ?></td>
                            <td class="text-nowrap"><?= e($row['badge_number']) ?></td>
                            <td>
                                <a class="link-dark fw-semibold text-decoration-none"
                                   href="<?= e(url('drivers/' . (int)($row['driver_id'] ?? 0))) ?>">
                                    <?= e($row['driver_name']) ?>
                                </a>
                            </td>
                            <td class="text-nowrap"><?= e($row['agency_name'] ?? '—') ?></td>
                            <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                            <td class="text-nowrap"><?= e($row['leave_type']) ?></td>
                            <td class="text-nowrap"><?= e(fmt_date($row['leave_start_date'])) ?></td>
                            <td class="text-nowrap"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                            <td class="text-nowrap"><?= e($row['destination'] ?? '—') ?></td>
                            <td><?= status_badge((string)$row['status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                <h3 class="h6 mb-0">
                    <i class="bi bi-box-arrow-in-right me-2 text-success"></i>Returning Today
                    <span class="badge text-bg-light border ms-1"><?= (int)($counts['today'] ?? 0) ?></span>
                </h3>
                <a class="small link-offset-2" href="<?= e(url('leave/returns')) ?>">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th class="text-center">#</th>
                        <th>Badge</th><th>Driver</th><th>Branch</th><th>Expected</th><th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    
<?php $rowNo = offset() + 1; ?>
                    <?php $todayRows = $panels['today'] ?? []; ?>
                    <?php if ($todayRows === []): ?>
                        <tr><td colspan="6">
                            <?= empty_state('bi-check2-circle', 'No drivers are due back today.') ?>
                        </td></tr>
                    <?php else: foreach ($todayRows as $row): ?>
                        
                        <tr>
    <td class="text-center"><?= $rowNo++ ?></td>
                            <td class="text-nowrap"><?= e($row['badge_number']) ?></td>
                            <td><?= e($row['driver_name']) ?></td>
                            <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                            <td class="text-nowrap"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(url('leave/' . (int)$row['id'])) ?>" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?= View::partial('partials/return-button', ['row' => $row]) ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100 overdue-card">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                <h3 class="h6 mb-0 text-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Overdue Returns
                    <span class="badge text-bg-danger ms-1"><?= (int)($counts['overdue'] ?? 0) ?></span>
                </h3>
                <a class="small link-offset-2" href="<?= e(url('leave/returns')) ?>">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                    
    <th class="text-center">#</th>
                        <th>Badge</th><th>Driver</th><th>Branch</th>
                        <th>Expected</th><th>Days Late</th><th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    
<?php $rowNo = offset() + 1; ?>
                    <?php $overdueRows = $panels['overdue'] ?? []; ?>
                    <?php if ($overdueRows === []): ?>
                        <tr><td colspan="7">
                            <?= empty_state('bi-check2-circle', 'No overdue returns.', 'Every driver has come back on time.') ?>
                        </td></tr>
                    <?php else: foreach ($overdueRows as $row): ?>
                        <?php $late = days_between((string)$row['expected_arrival_date'], today()); ?>
                        
                        <tr class="table-danger">
    <td class="text-center"><?= $rowNo++ ?></td>
                            <td class="text-nowrap"><?= e($row['badge_number']) ?></td>
                            <td><?= e($row['driver_name']) ?></td>
                            <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                            <td class="text-nowrap"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                            <td><span class="badge text-bg-danger"><?= max(0, $late) ?>d late</span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(url('leave/' . (int)$row['id'])) ?>" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php // No Return button here: marking a return
                                      // belongs to Current Leave / Leave Records,
                                      // which carry the full record and its actions. ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                <h3 class="h6 mb-0">
                    <i class="bi bi-calendar-week me-2 text-primary"></i>Returning This Week
                    <span class="badge text-bg-light border ms-1"><?= (int)($counts['week'] ?? 0) ?></span>
                </h3>
                <span class="small text-body-secondary">
                    <?= e(fmt_date($today, 'd M')) ?> – <?= e(fmt_date($weekEnd ?? date_add_days($today, 6), 'd M')) ?>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th class="text-center">#</th>
                        <th>Badge</th><th>Driver</th><th>Agency</th><th>Expected</th><th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    
<?php $rowNo = offset() + 1; ?>
                    <?php $weekRows = $panels['week'] ?? []; ?>
                    <?php if ($weekRows === []): ?>
                        <tr><td colspan="6">
                            <?= empty_state('bi-calendar-week', 'No upcoming returns in the next 7 days.') ?>
                        </td></tr>
                    <?php else: foreach ($weekRows as $row): ?>
                        
                        <tr>
    <td class="text-center"><?= $rowNo++ ?></td>
                            <td class="text-nowrap"><?= e($row['badge_number']) ?></td>
                            <td>
                                <a class="link-dark text-decoration-none"
                                   href="<?= e(url('leave/' . (int)$row['id'])) ?>"><?= e($row['driver_name']) ?></a>
                            </td>
                            <td class="text-nowrap"><?= e($row['agency_name'] ?? '—') ?></td>
                            <td class="text-nowrap"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                            <td><?= status_badge((string)$row['status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ================================================= stacked modal =====
     One dialog for all three summary cards: three copies would only drift
     apart, and the chart is re-rendered for whichever card opened it. -->
<div class="modal fade" id="stackedModal" tabindex="-1"
     aria-labelledby="stackedModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="stackedModalTitle">
                    <i class="bi bi-bar-chart-steps me-2" id="smIcon"></i><span id="smTitle"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <span class="small text-body-secondary" id="smRange"></span>
                </div>

                <div class="chart-box" id="smChartBox">
                    <canvas id="stackedChart"></canvas>
                </div>

                <div class="text-center text-body-secondary py-5 d-none" id="smEmpty">
                    <i class="bi bi-graph-up d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                    No data in the selected period.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
// The stacked payload reuses the EXACT month axis and branch columns the
// tables above are printed from, so a bar can never total differently from
// the Total column beside it - the bug class we hit before.
$stacked = ['labels' => [], 'branches' => [], 'series' => [], 'range' => $summaryRange];

if ($summarySeries !== []) {
    // Every series is bucketed on the same month axis, so the first one gives
    // the labels for all three - but all seven of its months do, not just the
    // first (the inner break that would do that is the bug this caught).
    foreach ($summaryTables as $key => $unused) {
        foreach (($summarySeries[$key]['months'] ?? []) as $month) {
            $stacked['labels'][] = (string) $month['label'];
        }
        break;
    }

    // Stack order, base first: Dammam, Riyadh, Jeddah as specified - then any
    // other column (WFT, or "-" for records with no branch) so the stack still
    // adds up to the row's Total instead of quietly losing rows.
    $stackedBranches = [];

    foreach (['dammam', 'riyadh', 'jeddah'] as $wanted) {
        foreach ($summaryCodes as $code => $name) {
            if (strtolower(trim((string) $name)) === $wanted && !isset($stackedBranches[$code])) {
                $stackedBranches[$code] = (string) $name;
            }
        }
    }
    foreach ($summaryCodes as $code => $name) {
        if (!isset($stackedBranches[$code])) {
            $stackedBranches[$code] = (string) $name;
        }
    }

    $stacked['branches'] = $stackedBranches;

    foreach ($summaryTables as $key => $unused) {
        foreach ($stackedBranches as $code => $name) {
            $cells = [];

            foreach (($summarySeries[$key]['months'] ?? []) as $month) {
                $cells[] = (int) ($month['cells'][$code] ?? 0);
            }

            $stacked['series'][$key][$code] = $cells;
        }
    }
}

// Chart.js reads these as plain JSON - no business logic in the browser.
$chartPayload = [
    'branch'   => array_values($chartBranch ?? []),
    'agency'   => array_values($chartAgency ?? []),
    'trend'    => array_values($trend ?? []),
    'arrivals' => $arrivalsTrend ?? [],
    'stacked'  => $stacked,
];
?>
<script>
    window.DASHBOARD_DATA = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<?php
// Version the first-party scripts off their own mtime. These URLs were bare,
// so a browser could keep serving an OLD dashboard.js after a patch was
// uploaded: the old script draws only the charts it knows about, the new
// #chartArrivals canvas is never touched, and the card stays blank. Vendored
// libraries are left alone - they do not change with a feature patch.
$dashJsV = (string)@filemtime(dirname(__DIR__, 3) . '/assets/js/dashboard.js');
$rptJsV  = (string)@filemtime(dirname(__DIR__, 3) . '/assets/js/dashboard-report.js');
?>
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/dashboard.js')) ?>?v=<?= e($dashJsV === '' ? '1' : $dashJsV) ?>"></script>

<?php // The PDF bundle is ~900KB, so it is only pulled in for someone who
      // can actually click the button that needs it. ?>
<?php if (can('reports.export')): ?>
<!-- Off-screen A4 sheet for the export. It is positioned off-screen rather
     than hidden: html2canvas measures the node to size the page, and a
     display:none element reports 0x0, which exports a blank PDF. -->
<?= View::partial('dashboard/report', ['report' => $report ?? []]) ?>
<script src="<?= e(url('assets/vendor/html2pdf/html2pdf.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/dashboard-report.js')) ?>?v=<?= e($rptJsV === '' ? '1' : $rptJsV) ?>"></script>
<?php endif; ?>

