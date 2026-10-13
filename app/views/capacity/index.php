<?php
/**
 * Driver Capacity Planning (spec: driver_capacity_planning_spec.md).
 *
 * Sections: filters, seven KPI cards, daily on-leave chart, capacity
 * movement, day-by-day table (one month), monthly summary, forecast data
 * quality, and the per-date drill-down modal.
 *
 * Uses: $filters, $kpis, $dailyRows, $dailyMonth, $months, $monthly,
 *       $movement, $quality, $today, $todayRow, $modeLabel, $typeFilter,
 *       $clamped, $base, $chart, $query, $branches, $agencies, $leaveTypes,
 *       $leaveTargetPct
 */

$filters    = $filters ?? [];
$kpis       = $kpis ?? [];
$dailyRows  = $dailyRows ?? [];
$months     = $months ?? [];
$monthly    = $monthly ?? [];
$movement   = $movement ?? [];
$quality    = $quality ?? [];
$chart      = $chart ?? [];
$query      = $query ?? [];
$modeLabel  = $modeLabel ?? 'Forecast';
$typeFilter = (bool)($typeFilter ?? false);
$clamped    = (bool)($clamped ?? false);
// The leave ceiling this page measures against (System Settings ->
// leave_percentage). 0 means nothing is configured, in which case the column
// still renders but says so instead of inventing a limit.
$leaveTargetPct = (float)($leaveTargetPct ?? 0);

// Percentages read through one helper (round 6): two decimals where they
// carry information, trailing zeros trimmed - 5.75% stays 5.75% while 5.70%
// reads 5.7%. The values themselves are computed at two decimals.
$fmtPct = static fn ($value): string => pct_label($value);

// Every link on this page carries the same scope - the date window, the mode
// and the three scope filters - whether they arrived in the query string or
// came from the defaults the engine chose. The export links, the month picker
// and the drill-down all build on it, so they can never drift apart.
$scope = [];
foreach (['from', 'to', 'mode', 'daily_month', 'branch_id', 'agency_id', 'leave_type_id'] as $key) {
    $value = $query[$key] ?? ($filters[$key] ?? '');
    if (is_array($value)) {
        $value = implode(',', $value);
    }
    if ($value !== '' && $value !== null) {
        $scope[$key] = (string)$value;
    }
}

// Drill-down asks the API about one date: same scope, no window.
$drillQuery = $scope;
unset($drillQuery['from'], $drillQuery['to'], $drillQuery['daily_month']);

// Export keeps the exact filters that produced the numbers on screen (44-45).
$exportUrl = static fn (string $format, string $table): string =>
    url('capacity-planning?' . http_build_query(array_merge($scope, [
        'export' => $format,
        'table'  => $table,
    ])));

$forecast = $forecast ?? [];
$returnBridge = $returnBridge ?? [
    'dashboard_arrivals' => 0, 'shared_ahead' => 0, 'confirmed' => 0, 'overdue_unreturned' => 0,
];
$resignBridge = $resignBridge ?? [
    'dashboard_applications' => 0, 'pending' => 0, 'rejected' => 0, 'exits' => 0,
    'approved_filed' => 0,
];
$bridge   = $bridge ?? ['status_away' => 0, 'due_back_unconfirmed' => 0];
$dailyMeta  = $dailyMeta ?? ['total' => 0, 'page' => 1, 'pages' => 1, 'size' => 10,
    'pageParam' => 'dy_page', 'sizeParam' => 'dy_size', 'sizes' => [10, 25, 31]];
$mvMeta     = $movementMeta ?? ['total' => 0, 'page' => 1, 'pages' => 1, 'size' => 10,
    'pageParam' => 'mv_page', 'sizeParam' => 'mv_size', 'sizes' => [10, 25, 50, 100, 250]];

// The section heading already carries the date, so the On Leave card only
// has to explain the one figure the dashboard would count differently: the
// dashboard counts a driver away until the return is recorded, this
// forecast counts today as their return day (spec section 64, test 1).
$bridgeHint = 'Away right now';
if ((int)($bridge['due_back_unconfirmed'] ?? 0) > 0) {
    $bridgeHint = (int)$bridge['due_back_unconfirmed'] . ' due back, not yet confirmed';
}

// Two card groups with two different meanings - kept apart on purpose.
//   nowCards   answers "right now" and never moves with the date range.
//   rangeCards answers "across the selected range" and never shows a
//              moment-in-time figure. Mixing the two in one row is what made
//              "Active Drivers Today 454" read as October's capacity.
$nowCards = [
    ['label' => 'Active Drivers', 'value' => (string)(int)($kpis['active_today'] ?? 0),
        'tone' => 'primary', 'icon' => 'bi-people', 'hint' => 'In the active address book'],
    // View All opens the SAME capDriversModal the day rows already use, for
    // TODAY - so the list behind this figure sits next to the figure itself
    // and can be compared driver for driver with the Leave Dashboard's own
    // list. A projected absence shows as the "Expected" chip, which is exactly
    // the row the two screens can legitimately disagree about (see the
    // "Dashboard comparison" row in Data quality below).
    ['label' => 'On Leave', 'value' => (string)(int)($kpis['on_leave_today'] ?? 0),
        'tone' => 'warning', 'icon' => 'bi-airplane', 'hint' => $bridgeHint,
        'drill' => (string)($today ?? ''), 'linkLabel' => 'View All'],
    ['label' => 'On Leave %', 'value' => $fmtPct($kpis['on_leave_pct_today'] ?? 0),
        'tone' => 'danger', 'icon' => 'bi-percent',
        'hint' => (int)($kpis['on_leave_today'] ?? 0) . ' of '
            . (int)($kpis['active_today'] ?? 0) . ' active drivers'],
];

$rangeCards = [
    ['label' => 'Avg Active Drivers', 'value' => (string)(int)($forecast['avg_active'] ?? 0),
        'tone' => 'primary', 'icon' => 'bi-people',
        'hint' => 'across ' . (int)($forecast['days'] ?? 0) . ' day(s)'],
    ['label' => 'Avg On Leave', 'value' => (string)(int)($forecast['avg_on_leave'] ?? 0),
        'tone' => 'warning', 'icon' => 'bi-airplane',
        'hint' => 'across ' . (int)($forecast['days'] ?? 0) . ' day(s)'],
    ['label' => 'Peak On Leave', 'value' => (string)(int)($forecast['peak_on_leave'] ?? 0),
        'tone' => 'info', 'icon' => 'bi-graph-up', 'hint' => 'highest single day'],
    ['label' => 'Peak On Leave %', 'value' => $fmtPct($forecast['peak_on_leave_pct'] ?? 0),
        'tone' => 'secondary', 'icon' => 'bi-graph-up-arrow', 'hint' => 'highest single day'],
    ['label' => 'Expected Returns', 'value' => (string)(int)($forecast['expected_returns'] ?? 0),
        'tone' => 'success', 'icon' => 'bi-box-arrow-in-right',
        // No counts in the footer: say what the card IS - the return still
        // owed on the leave records in this range. The full bridge (still to
        // arrive / already returned / past due) lives in the "Dashboard
        // arrivals comparison" row of Forecast Data Quality.
        'hint' => 'Expected return from leave',
        // A MODAL, not a navigation. This used to send the reader off to
        // /leave/returns and lose the range, the filters and the comparison
        // they were making. The rows come from the same endpoint the Leave
        // Dashboard's own "Expected Returns" card uses, over THIS range - so
        // the two screens agree return for return.
        'capKpi'   => 'expected_arrivals',
        'capRange' => (string)($filters['from'] ?? '') . ',' . (string)($filters['to'] ?? ''),
        'linkLabel' => 'View'],
    ['label' => 'Expected Resignations', 'value' => (string)(int)($forecast['expected_resignations'] ?? 0),
        'tone' => 'dark', 'icon' => 'bi-box-arrow-right',
        // The footer foots with the big number: every resignation filing
        // that is not cancelled in this range - pending and approved
        // combined - so the card matches the dashboard's "Resignation
        // Applications" filing for filing.
        'hint' => 'Total Applications ' . (int)($forecast['expected_resignations'] ?? 0)
            . ' · ' . (int)$resignBridge['approved_filed'] . ' approved'
            . ' · ' . (int)$resignBridge['pending'] . ' pending'
            . ((int)$resignBridge['rejected'] > 0
                ? ' · ' . (int)$resignBridge['rejected'] . ' rejected'
                : ''),
        // Same modal, same reason as the card above - and the same rows the
        // dashboard's "Resignation Applications" card opens for this range.
        'capKpi'   => 'resignation_applications',
        'capRange' => (string)($filters['from'] ?? '') . ',' . (string)($filters['to'] ?? ''),
        'linkLabel' => 'View'],
];

/**
 * Pager for one table: "Showing X-Y of Z" plus page links. Same markup as
 * pagination(), but the parameter names come from the table's meta so the two
 * tables on this screen page independently.
 *
 * `$after` is extra HTML that belongs WITH the page links (round 6: the
 * rows-per-page selector). It rides in the same right-hand group as the
 * numbers rather than in a `justify-content-between` row of its own, which
 * pushed it to the far edge of the card with a gap in between - the two now
 * read as one control.
 */
$mkPager = static function (array $meta, array $scope, string $after = ''): string {
    $perPage   = max(1, (int)$meta['size']);
    $total     = (int)$meta['total'];
    $pages     = max(1, (int)ceil($total / $perPage));
    $page      = min(max(1, (int)$meta['page']), $pages);
    $first     = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $last      = min($total, $page * $perPage);
    $pageParam = (string)$meta['pageParam'];
    $sizeParam = (string)$meta['sizeParam'];

    $href = static function (int $target) use ($scope, $pageParam, $sizeParam, $perPage): string {
        return '?' . http_build_query(array_merge($scope, [
            $pageParam => $target,
            $sizeParam => $perPage,
        ]));
    };

    // Marked so assets/js/table.js can page this grid in place instead of
    // reloading the page and throwing the reader back to the top. The hrefs
    // stay real, so it still navigates without JavaScript.
    $html  = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 pager" data-dd-pager>';
    $html .= '<div class="text-body-secondary small" data-dd-showing>Showing <strong>' . number_format($first)
        . '</strong>&ndash;<strong>' . number_format($last) . '</strong> of <strong>'
        . number_format($total) . '</strong></div>';

    // Everything the block below appends IS the page links, so it can be
    // lifted out afterwards and grouped with whatever else has to sit beside
    // them without rewriting any of it.
    $navStart = strlen($html);

    if ($pages > 1) {
        $window = [];
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
                $window[] = $i;
            }
        }

        $html .= '<nav aria-label="Table pagination" data-dd-nav><ul class="pagination pagination-sm mb-0">';

        $html .= $page > 1
            ? '<li class="page-item"><a class="page-link" href="' . e($href($page - 1)) . '" data-dd-page="'
              . ($page - 1) . '">&lsaquo;</a></li>'
            : '<li class="page-item disabled"><span class="page-link">&lsaquo;</span></li>';

        $previous = null;
        foreach ($window as $number) {
            if ($previous !== null && $number - $previous > 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }

            $html .= $number === $page
                ? '<li class="page-item active"><span class="page-link">' . $number . '</span></li>'
                : '<li class="page-item"><a class="page-link" href="' . e($href($number))
                  . '" data-dd-page="' . $number . '">' . $number . '</a></li>';

            $previous = $number;
        }

        $html .= $page < $pages
            ? '<li class="page-item"><a class="page-link" href="' . e($href($page + 1)) . '" data-dd-page="'
              . ($page + 1) . '">&rsaquo;</a></li>'
            : '<li class="page-item disabled"><span class="page-link">&rsaquo;</span></li>';

        $html .= '</ul></nav>';
    }

    // Pull the page links back out and put them in their own right-hand
    // group, together with anything that has to sit beside them.
    $nav  = substr($html, $navStart);
    $html = substr($html, 0, $navStart);

    if ($nav !== '' || $after !== '') {
        $html .= '<div class="d-flex flex-wrap align-items-center gap-3">'
            . $nav
            . ($after !== ''
                ? '<div class="d-flex align-items-center gap-3">' . $after . '</div>'
                : '')
            . '</div>';
    }

    return $html . '</div>';
};

/** Rows per page (10 / 25 / 31); changing it restarts at page 1. */
$mkSizeForm = static function (array $meta, array $scope): string {
    $pageParam = (string)$meta['pageParam'];
    $sizeParam = (string)$meta['sizeParam'];
    $size      = (int)$meta['size'];

    // mb-0: a <form> carries a default bottom margin, which threw the select
    // a few pixels out of line with the page numbers it sits next to.
    $html = '<form method="get" action="' . e(url('capacity-planning')) . '" class="d-flex align-items-center gap-2 mb-0">';
    foreach ($scope as $key => $value) {
        $html .= '<input type="hidden" name="' . e((string)$key) . '" value="' . e((string)$value) . '">';
    }
    $html .= '<input type="hidden" name="' . e($pageParam) . '" value="1">';
    $html .= '<label class="small text-body-secondary mb-0" for="size_' . e($pageParam) . '">Rows</label>';
    $html .= '<select class="form-select form-select-sm w-auto" id="size_' . e($pageParam) . '" name="'
        . e($sizeParam) . '" data-dd-size'
        // table.js defines ddTableSize(); without it the select falls back to
        // submitting, so a browser with no script still changes page size.
        . ' onchange="window.ddTableSize ? window.ddTableSize(this) : this.form.submit()">';

    foreach (($meta['sizes'] ?? [10, 25, 31]) as $option) {
        $html .= '<option value="' . $option . '"' . ($option === $size ? ' selected' : '') . '>'
            . $option . '</option>';
    }

    return $html . '</select></form>';
};
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Driver Capacity Planning',
    'description' => 'How many drivers are expected to be available over time, and what percentage of '
        . 'the active driver population is expected to be on leave? Calculated day by day, aggregated monthly.',
    'actions'     => '<button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">'
        . '<i class="bi bi-printer me-1"></i>Print</button>',
    // KPI drill page: no screen-wide "Columns" control - its grids sit behind
    // the cards. See data-dd-prefs-none.
    'no_prefs'    => true,
]) ?>

<!-- Filters (spec section 18) -------------------------------------------- -->
<form method="get" action="<?= e(url('capacity-planning')) ?>" class="card border-0 shadow-sm mb-3 no-print filter-card">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="cap_from">From</label>
                <input type="date" class="form-control form-control-sm" id="cap_from" name="from"
                       value="<?= e($filters['from'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="cap_to">To</label>
                <input type="date" class="form-control form-control-sm" id="cap_to" name="to"
                       value="<?= e($filters['to'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="cap_mode">Mode</label>
                <select class="form-select form-select-sm" id="cap_mode" name="mode">
                    <option value="forecast" <?= ($filters['mode'] ?? '') === 'forecast' ? 'selected' : '' ?>>Forecast</option>
                    <option value="actual" <?= ($filters['mode'] ?? '') === 'actual' ? 'selected' : '' ?>>Actual</option>
                </select>
            </div>

            <?= View::partial('partials/check-filter', [
                'name' => 'branch_id', 'label' => 'Branch',
                'pairs' => $branches ?? [], 'selected' => $filters['branch_id'] ?? '',
                'placeholder' => 'All branches', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'agency_id', 'label' => 'Agency',
                'pairs' => $agencies ?? [], 'selected' => $filters['agency_id'] ?? '',
                'placeholder' => 'All agencies', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'leave_type_id', 'label' => 'Leave Type',
                'pairs' => $leaveTypes ?? [], 'selected' => $filters['leave_type_id'] ?? '',
                'placeholder' => 'All leave types', 'col' => 'col-6 col-md-2',
            ]) ?>

            <div class="col-12">
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i>Apply
                    </button>
                    <a href="<?= e(url('capacity-planning')) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i>Reset
                    </a>
                    <span class="small text-body-secondary align-self-center ms-md-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Forecast counts a leave from its start until the expected return; Actual uses
                        the real arrival date where one is recorded.
                        <?= $clamped ? 'The range is capped at ' . \App\Models\CapacityPlanningService::MAX_DAYS . ' days.' : '' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Current status (spec section 17) -------------------------------------- -->
<div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
    <h3 class="h6 mb-0 text-uppercase text-body-secondary">
        <i class="bi bi-hourglass-split me-1"></i>Current Status
        &mdash; <?= e(fmt_date($today ?? today(), 'd M Y')) ?>
    </h3>
    <span class="small text-body-secondary">
        Live right now &middot; the date range above does not change these figures
    </span>
</div>
<div class="row g-3 mb-4">
    <?php foreach ($nowCards as $card): ?>
        <?= View::partial('partials/kpi-card', [
            'label' => $card['label'],
            'value' => $card['value'],
            'tone'  => $card['tone'],
            'icon'  => $card['icon'],
            'hint'  => $card['hint'],
            'span'  => 'col-6 col-md-4',
            // Absent on the cards that have no list behind them, so their
            // markup - and the dashboard's - is unchanged.
            'drill'     => (string)($card['drill'] ?? ''),
            'linkLabel' => (string)($card['linkLabel'] ?? 'View'),
        ]) ?>
    <?php endforeach; ?>
</div>

<!-- Selected period forecast (spec sections 17 and 24) -------------------- -->
<div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
    <h3 class="h6 mb-0 text-uppercase text-body-secondary">
        <i class="bi bi-calendar2-range me-1"></i>Forecast
        &mdash; <?= e(fmt_date($filters['from'] ?? '', 'd M Y')) ?>
        &rarr; <?= e(fmt_date($filters['to'] ?? '', 'd M Y')) ?>
    </h3>
    <span class="small text-body-secondary">
        Aggregated from every day in the selected range &middot; averages and peaks
        are derived from the day-by-day rows, never from application counts
    </span>
</div>
<div class="row g-3 mb-3">
    <?php foreach ($rangeCards as $card): ?>
        <?= View::partial('partials/kpi-card', [
            'label' => $card['label'],
            'value' => $card['value'],
            'tone'  => $card['tone'],
            'icon'  => $card['icon'],
            'hint'  => $card['hint'],
            'span'  => 'col-6 col-md-4',
            'drill'     => (string)($card['drill'] ?? ''),
            'href'      => (string)($card['href'] ?? ''),
            'capKpi'    => (string)($card['capKpi'] ?? ''),
            'capRange'  => (string)($card['capRange'] ?? ''),
            'linkLabel' => (string)($card['linkLabel'] ?? 'View'),
        ]) ?>
    <?php endforeach; ?>
</div>

<!-- Daily on-leave chart (spec section 21) --------------------------------- -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0" id="capacityChartTitle">
            <i class="bi bi-graph-up me-2 text-primary"></i><span id="capacityChartTitleText">Daily Driver On-Leave %</span>
        </h2>
        <span class="d-flex align-items-center gap-2 flex-wrap">
            <?php // One chart, selectable series: same style, layout, range
                  // and mode indicators - only the data behind the line
                  // and the title above change. ?>
            <label class="small text-body-secondary mb-0" for="capacityChartMetric">Series</label>
            <select class="form-select form-select-sm w-auto no-print"
                    id="capacityChartMetric" aria-label="Chart series">
                <option value="onLeave" selected>On-Leave %</option>
                <option value="returns">Expected Returns / Returns</option>
                <option value="expectedLeave">Expected Leave</option>
            </select>
            <span class="small text-body-secondary">
                <?= e($modeLabel) ?> mode &middot; <?= count($chart['labels'] ?? []) ?> day(s) &middot;
                <?= e(fmt_date($filters['from'] ?? '')) ?> &ndash; <?= e(fmt_date($filters['to'] ?? '')) ?>
            </span>
        </span>
    </div>
    <div class="card-body">
        <?php if (empty($chart['labels'])): ?>
            <?= empty_state('bi-graph-up', 'No days in the selected range.', 'Widen the date range.') ?>
        <?php else: ?>
            <div style="position:relative;height:320px">
                <canvas id="capacityChart" aria-label="Daily driver on-leave percentage" role="img"></canvas>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Capacity movement (spec section 22) ----------------------------------- -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">
            <i class="bi bi-arrow-left-right me-2 text-primary"></i>Capacity Movement
            <span class="badge text-bg-light ms-1"><?= (int)$mvMeta['total'] ?></span>
        </h2>
        <div class="d-flex align-items-center gap-3 flex-wrap no-print">
            <span class="small text-body-secondary">Leave starts, expected leaves, returns and resignations in the selected range</span>
            <?php // Round 6: the movement list can be taken away as a
                  // spreadsheet - same scope filters as everything else on
                  // this page. The rows-per-page selector moved down next to
                  // the pager, where it belongs (it acts on the page below). ?>
            <a class="btn btn-sm btn-outline-success" href="<?= e($exportUrl('xls', 'movement')) ?>"
               title="Download the whole movement list as Excel">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0"
               data-dd-table
               data-dd-size="<?= (int)$mvSize ?>"
               data-dd-initial="|asc|<?= (int)$mvPage ?>">
            <thead class="table-light">
            <tr>
                <th>Date</th><th>Event</th><th>Driver</th><th>Badge</th>
                <?php // The three facts behind the event: WHICH branch it is
                      // filed against, what kind of leave it is, and the state
                      // of the row it came from - a record status for starts and
                      // returns, the approval decision for filings. ?>
                <th>Branch</th><th>Leave Type</th><th>Status</th>
                <th class="text-end">Impact</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($movement === []): ?>
                <tr><td colspan="8">
                    <?= empty_state('bi-arrow-left-right', 'No capacity movement in this range.',
                        'Leave starts, expected leaves, returns and resignations will appear here once they fall inside the dates.') ?>
                </td></tr>
            <?php else: foreach ($movement as $event): ?>
                <tr data-dd-row>
                    <td class="text-nowrap fw-semibold"><?= e(fmt_date($event['date'])) ?></td>
                    <td class="text-nowrap">
                        <span class="badge text-bg-<?= $event['impact'][0] === '+' ? 'warning' : 'info' ?>">
                            <?= e($event['event']) ?>
                        </span>
                    </td>
                    <td class="text-nowrap"><?= e($event['driver']) ?></td>
                    <td class="text-nowrap"><?= e($event['badge']) ?></td>
                    <td class="text-nowrap"><?= e(($event['branch'] ?? '') !== '' ? $event['branch'] : '—') ?></td>
                    <td class="text-nowrap"><?= e(($event['leave_type'] ?? '') !== '' ? $event['leave_type'] : '—') ?></td>
                    <td class="text-nowrap"><?php
                        $mvStatus = (string)($event['status'] ?? '');
                        echo $mvStatus !== '' ? status_badge($mvStatus) : '—';
                    ?></td>
                    <td class="text-end text-nowrap fw-semibold"><?= e($event['impact']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php // Round 6: the size selector sits with the page links, inside the
          // pager's right-hand group - not pushed to the far edge of the card.
          // Changing it restarts at page 1, so the two read as one control. ?>
    <?php if ((int)$mvMeta['total'] > 0): ?>
        <?= $mkPager($mvMeta, $scope, $mkSizeForm($mvMeta, $scope)) ?>
    <?php endif; ?>
</div>

<!-- Day-by-day table (spec sections 17 and 20) ---------------------------- -->
<?php
$monthLabel = date('F Y', strtotime(($dailyMonth ?? $filters['from'] ?? today()) . '-01'));
$carry      = $scope;
unset($carry['daily_month']);
$todayDate  = $today ?? today();
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">
            <i class="bi bi-calendar3 me-2 text-primary"></i>Day-by-Day Driver Capacity
            <span class="badge text-bg-light ms-1"><?= e($monthLabel) ?></span>
        </h2>

        <div class="d-flex align-items-center gap-2 flex-wrap no-print">
            <form method="get" action="<?= e(url('capacity-planning')) ?>" class="d-flex align-items-center gap-2">
                <?php foreach ($carry as $key => $value): ?>
                    <input type="hidden" name="<?= e($key) ?>" value="<?= e((string)$value) ?>">
                <?php endforeach; ?>
                <label class="small text-body-secondary mb-0" for="cap_month">Month</label>
                <select class="form-select form-select-sm w-auto" id="cap_month" name="daily_month"
                        onchange="this.form.submit()">
                    <?php foreach ($months as $month): ?>
                        <option value="<?= e($month['value']) ?>"
                            <?= ($dailyMonth ?? '') === $month['value'] ? 'selected' : '' ?>>
                            <?= e($month['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Show</button></noscript>
            </form>

            <span class="vr d-none d-lg-inline-block"></span>
            <?php // Round 6: the rows-per-page selector moved down to the
                  // footer, beside the page links it actually acts on - and
                  // the CSV button is gone: this table's numbers are already
                  // in the Excel download (and in the monthly export), so two
                  // buttons for one table was noise. ?>
            <a class="btn btn-sm btn-outline-success" href="<?= e($exportUrl('xls', 'daily')) ?>"
               title="Download this day-by-day table as Excel">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
        </div>
    </div>

    <?php // The long explanatory paragraph that used to sit under this header
          // is gone on request - it repeated what the Forecast Data Quality
          // panel already says, and it pushed the table half a screen down.
          // The leave-type badge stays: it is a state indicator, not prose,
          // and without it a filtered table would look unfiltered. ?>
    <?php if ($typeFilter): ?>
        <div class="card-body py-2 small text-body-secondary no-print">
            <span class="badge text-bg-info">Leave type filter</span>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0"
               data-dd-table
               data-dd-size="<?= (int)$dySize ?>"
               data-dd-initial="|asc|<?= (int)$dyPage ?>">
            <thead class="table-light">
            <tr>
                <th>Date</th>
                <th class="text-end">Active</th>
                <th class="text-end">On Leave</th>
                <th class="text-end">Available</th>
                <th class="text-end">On-Leave %</th>
                <th class="text-end">Available %</th>
                <th class="text-end">Leave Starts</th>
                <th class="text-end">Expected Leave</th>
                <th class="text-end"><?= $modeLabel === 'Actual' ? 'Returns' : 'Expected Returns' ?></th>
                <th class="text-end">Expected Resignations</th>
                <th class="text-end">Overdue</th>
                <th class="text-center no-print">Drivers</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($dailyRows === []): ?>
                <tr><td colspan="12">
                    <?= empty_state('bi-calendar-x', 'No days in this month fall inside the range.',
                        'Change the month above or widen From / To.') ?>
                </td></tr>
            <?php else: foreach ($dailyRows as $day): ?>
                <tr data-dd-row<?= $day['date'] === $todayDate ? ' class="table-primary"' : '' ?>>
                    <td class="text-nowrap fw-semibold">
                        <?= e(fmt_date($day['date'])) ?>
                        <?php if ($day['date'] === $todayDate): ?>
                            <span class="badge text-bg-primary ms-1">Today</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= (int)$day['active'] ?></td>
                    <td class="text-end fw-semibold"><?= (int)$day['on_leave'] ?></td>
                    <td class="text-end"><?= (int)$day['available'] ?></td>
                    <td class="text-end"><?= e($fmtPct($day['on_leave_pct'])) ?></td>
                    <td class="text-end"><?= e($fmtPct($day['available_pct'])) ?></td>
                    <td class="text-end"><?= (int)$day['starts'] ?></td>
                    <?php // Expectations only: never folded into On Leave / Available. ?>
                    <td class="text-end <?= (int)($day['expected_leave'] ?? 0) > 0 ? 'fw-semibold' : '' ?>">
                        <?= (int)($day['expected_leave'] ?? 0) ?>
                    </td>
                    <td class="text-end"><?= (int)$day['returns'] ?></td>
                    <td class="text-end"><?= (int)$day['resignations'] ?></td>
                    <td class="text-end <?= (int)$day['overdue'] > 0 ? 'text-danger fw-semibold' : '' ?>">
                        <?= (int)$day['overdue'] ?>
                    </td>
                    <td class="text-center no-print">
                        <?php if ((int)$day['on_leave'] > 0): ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary cap-drill"
                                    data-date="<?= e($day['date']) ?>"
                                    title="Show the drivers on leave on this day">
                                <i class="bi bi-eye"></i>
                            </button>
                        <?php else: ?>
                            <span class="text-body-secondary">&mdash;</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php // Same treatment as the movement list: the size selector rides
          // with the page links inside the pager's right-hand group, so the
          // two read as one control instead of living in the card header. ?>
    <?php if ((int)$dailyMeta['total'] > 0): ?>
        <?= $mkPager($dailyMeta, $scope, $mkSizeForm($dailyMeta, $scope)) ?>
    <?php endif; ?>
</div>

<!-- Monthly summary (spec sections 24-26) --------------------------------- -->
<?php
// The column header and its tooltip are written once here so the label can
// name the ceiling it is actually applying (it is a setting, not a constant),
// and so an unconfigured page never claims a limit that does not exist.
// $fmtPct is pct_label(), which already carries the percent sign.
$slotsLabel = $leaveTargetPct > 0
    ? 'Leave Slots to ' . $fmtPct($leaveTargetPct)
    : 'Leave Slots';
$slotsHint  = $leaveTargetPct > 0
    ? 'Drivers that may still start leave this month before a '
        . $fmtPct($leaveTargetPct) . ' ceiling of the active fleet is reached. '
        . 'Measured against the month\'s peak, so the figure holds on its worst '
        . 'day rather than only on an average one.'
    : 'No leave ceiling is configured yet - set leave_percentage under System '
        . 'Settings to show how many approvals each month still has.';
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">
            <i class="bi bi-calendar-month me-2 text-primary"></i>Monthly Summary
        </h2>
        <div class="d-flex align-items-center gap-2 no-print">
            <span class="small text-body-secondary d-none d-md-inline">
                Derived from the daily figures - never from application counts.
                Leave Starts is the same figure as the dashboard trend (trips beginning);
                Expected Leave sums the same-day figures for applications not yet turned into
                a record; Avg On Leave counts drivers away on each day.
            </span>
            <a class="btn btn-sm btn-outline-success" href="<?= e($exportUrl('csv', 'monthly')) ?>"
               title="Download the monthly summary as CSV">
                <i class="bi bi-filetype-csv me-1"></i>CSV
            </a>
            <a class="btn btn-sm btn-outline-success" href="<?= e($exportUrl('xls', 'monthly')) ?>"
               title="Download the monthly summary as Excel">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Month</th>
                <th class="text-end">Avg Active Drivers</th>
                <th class="text-end">Avg On Leave</th>
                <th class="text-end">Avg On-Leave %</th>
                <th class="text-end">Peak On Leave</th>
                <th class="text-end">Peak On-Leave %</th>
                <th class="text-end" title="<?= e($slotsHint) ?>"><?= e($slotsLabel) ?></th>
                <th class="text-end">Leave Starts</th>
                <th class="text-end">Expected Leave</th>
                <th class="text-end">Expected Returns</th>
                <th class="text-end">Expected Resignations</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($monthly === []): ?>
                <tr><td colspan="11">
                    <?= empty_state('bi-calendar-month', 'No months in the selected range.', 'Widen the date range.') ?>
                </td></tr>
            <?php else: foreach ($monthly as $month): ?>
                <tr>
                    <td class="text-nowrap fw-semibold"><?= e($month['label']) ?></td>
                    <td class="text-end"><?= e((string)$month['avg_active']) ?></td>
                    <td class="text-end"><?= e((string)$month['avg_on_leave']) ?></td>
                    <td class="text-end fw-semibold"><?= e($fmtPct($month['avg_on_leave_pct'])) ?></td>
                    <td class="text-end"><?= (int)$month['peak_on_leave'] ?></td>
                    <td class="text-end fw-semibold"><?= e($fmtPct($month['peak_on_leave_pct'])) ?></td>
                    <?php // 0 means the month is at (or past) its ceiling - the one
                          // figure on this row a manager can act on, so it is coloured;
                          // null means no ceiling is configured and reads as an em dash. ?>
                    <?php $slots = $month['leave_slots'] ?? null; ?>
                    <td class="text-end fw-semibold<?= $slots === 0 ? ' text-danger' : '' ?><?= $slots === null ? ' text-body-secondary' : '' ?>"
                        title="<?= e($slotsHint) ?>"><?= $slots === null ? '—' : (int)$slots ?></td>
                    <td class="text-end"><?= (int)$month['starts'] ?></td>
                    <td class="text-end"><?= (int)($month['expected_leave'] ?? 0) ?></td>
                    <td class="text-end"><?= (int)$month['returns'] ?></td>
                    <td class="text-end"><?= (int)$month['resignations'] ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Forecast data quality (spec sections 49-50 and 58) --------------------- -->
<?php // Management detail: shown to the Administrator role only, even when
      // reports.capacity has been granted to someone else. ?>
<?php if (is_administrator()): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white border-bottom">
        <h2 class="h6 mb-0">
            <i class="bi bi-clipboard2-pulse me-2 text-primary"></i>Forecast Data Quality
        </h2>
    </div>
    <div class="list-group list-group-flush">
        <?php foreach ($quality as $item): ?>
            <div class="list-group-item d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <span class="fw-semibold">
                        <?= e($item['label']) ?>
                        <span class="badge text-bg-<?= $item['state'] === 'ok' ? 'success' : ($item['state'] === 'info' ? 'info' : 'warning') ?> ms-1">
                            <?= $item['state'] === 'ok' ? 'OK' : number_format((int)$item['count']) ?>
                        </span>
                    </span>
                    <div class="small text-body-secondary"><?= e($item['detail']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($quality === []): ?>
            <div class="list-group-item">
                <?= empty_state('bi-clipboard2-check', 'No quality checks ran.', 'Widen the date range.') ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; // is_administrator() ?>

<!-- Print-only header (spec section 58) ----------------------------------- -->
<div class="d-none d-print-block mb-3">
    <h1 class="h5 mb-0"><?= e(defined('APP_NAME') ? APP_NAME : 'LMS') ?> - Driver Capacity Planning</h1>
    <div class="small">
        <?= e($modeLabel) ?> mode &middot;
        <?= e(fmt_date($filters['from'] ?? '')) ?> &ndash; <?= e(fmt_date($filters['to'] ?? '')) ?> &middot;
        Generated <?= e(fmt_date_time(now())) ?> (<?= e(APP_TIMEZONE) ?>)
    </div>
</div>

<!-- Drill-down modal (spec section 22) ------------------------------------ -->
<?php // modal-xl: seven columns of real data (driver, badge, leave type and
      // three dates plus Status) were wrapping inside modal-lg, which is what
      // pushed the status chip onto two lines. ?>
<div class="modal fade no-print" id="capDriversModal" tabindex="-1" aria-labelledby="capDriversLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="h6 modal-title" id="capDriversLabel">
                    Drivers on leave
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php // A sub-heading in the same place and the same wording
                      // the other two drill modals use. The date used to sit
                      // INSIDE the title in brackets and the driver count
                      // lived in the footer as "Showing 1-10 of 25", so the
                      // same modal read differently from its neighbours. It
                      // fills in once the rows arrive. ?>
                <div class="small text-body-secondary mb-3 d-none" id="capModalMeta"></div>

                <div id="capModalLoading" class="text-center py-4 text-body-secondary">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...
                </div>
                <div id="capModalError" class="alert alert-danger d-none"></div>
                <div class="table-responsive" id="capModalTable" hidden>
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th class="text-center">#</th>
                            <th>Driver</th><th>Badge</th><th>Leave Type</th>
                            <th>Start</th><th>Expected Return</th><th>Status</th>
                        </tr>
                        </thead>
                        <tbody id="capModalBody"></tbody>
                    </table>
                </div>
                <div id="capModalEmpty" class="text-center text-body-secondary py-3 d-none">
                    No drivers are on leave on this date under the current filters.
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <?php // Round 6: the list for one date can be long (a trip
                      // spans every day it covers), so it pages inside the
                      // modal. It starts with d-none, not d-flex + [hidden]:
                      // Bootstrap's .d-flex is display:flex !important and
                      // outranks the hidden attribute, so that pair could
                      // never hide it - a single-day list still showed
                      // "Page 1 of 1". ?>
                <div class="d-none align-items-center gap-2" id="capModalPager">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            id="capModalPrev" aria-label="Previous page">&lsaquo;</button>
                    <span class="small text-body-secondary" id="capModalPageLabel"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            id="capModalNext" aria-label="Next page">&rsaquo;</button>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php // The same right-hand group as the other two drill
                          // modals: the download, then Close. The "Showing
                          // 1-10 of 25" line is gone - the pager now says
                          // "Page 1 of 3 - 25 row(s)", the wording the other
                          // two use, and two counts for one list is what made
                          // this footer read like a different app. ?>
                    <?php if (can('reports.export')): ?>
                        <?php // Whole day, not the page on screen. ?>
                        <a class="btn btn-outline-success btn-sm disabled" id="capModalExport"
                           href="#" aria-disabled="true" tabindex="-1">
                            <i class="bi bi-file-earmark-excel me-1"></i>Excel
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Range-card drill (Expected Returns / Expected Resignations) ------------ -->
<?php // A SECOND modal, deliberately - not the day-row one above. That one is
      // shaped for a single date's away list (Leave Type / Start / Expected
      // Return); these two are shaped like the Leave Dashboard's own drill and
      // come from the same endpoint, so a reader can compare the two screens
      // cell for cell instead of navigating between them.
      // data-dd-skip: a transient list whose header is rebuilt per card must
      // stay out of the column-preference machinery (see table-prefs.js) - if
      // it joins, applyAll() writes display back over a header cell. ?>
<div class="modal fade no-print" id="capKpiModal" tabindex="-1" aria-labelledby="capKpiLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="h6 modal-title" id="capKpiLabel">
                    <i class="bi bi-list-ul me-2 text-primary"></i><span id="capKpiTitle">Drivers</span>
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="small text-body-secondary mb-3 d-none" id="capKpiMeta"></div>

                <div id="capKpiLoading" class="text-center py-4 text-body-secondary d-none">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...
                </div>
                <div id="capKpiError" class="alert alert-danger d-none mb-0" role="alert"></div>
                <div id="capKpiEmpty" class="text-center text-body-secondary py-4 d-none">
                    No drivers behind this number under the current filters.
                </div>

                <div class="table-responsive" id="capKpiWrap" hidden>
                    <table class="table table-sm table-hover align-middle mb-0"
                           id="capKpiTable" data-dd-skip>
                        <thead class="table-light">
                        <tr>
                            <th class="text-center">#</th>
                            <th>Badge</th>
                            <th>Driver</th>
                            <th>Branch</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <?php // Starts d-none: Bootstrap's .d-flex is display:flex
                      // !important, which outranks the [hidden] attribute - the
                      // pair can never actually hide anything. ?> 
                <div class="d-none align-items-center gap-2" id="capKpiPager">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            id="capKpiPrev" aria-label="Previous page">&lsaquo;</button>
                    <span class="small text-body-secondary" id="capKpiPageLabel"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            id="capKpiNext" aria-label="Next page">&rsaquo;</button>
                </div>
                <?php // Grouped on the right, beside Close - the same footer
                      // shape as the Leave Dashboard's drill modal. Three loose
                      // children under justify-content-between pushed Excel
                      // into the middle of the footer. ?>
                <div class="d-flex align-items-center gap-2">
                    <?php if (can('reports.export')): ?>
                        <?php // Points at the same slice the modal is showing. ?>
                        <a class="btn btn-outline-success btn-sm disabled" id="capKpiExport"
                           href="#" aria-disabled="true" tabindex="-1">
                            <i class="bi bi-file-earmark-excel me-1"></i>Excel
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Data + scripts: the browser only draws what the server calculated. ---- -->
<script>
window.CAPACITY_DATA  = <?= json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
window.CAPACITY_URLS  = {
    drivers: <?= json_encode(url('api/capacity/drivers')) ?>,
    // The two range cards (Expected Returns / Expected Resignations) open the
    // SAME list the Leave Dashboard's cards open, so the two screens agree.
    kpi:     <?= json_encode(url('api/dashboard/kpi-drivers')) ?>,
    driver:  <?= json_encode(url('drivers/')) ?>
};
window.CAPACITY_QUERY = <?= json_encode($drillQuery, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<?php
// Version the first-party script off its own mtime - same reason as
// dashboard.js: a browser must never keep drawing with an older file after a
// patch has been uploaded.
$capJsV = (string)@filemtime(dirname(__DIR__, 3) . '/assets/js/capacity.js');
?>
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/capacity.js')) ?>?v=<?= e($capJsV === '' ? '1' : $capJsV) ?>"></script>






