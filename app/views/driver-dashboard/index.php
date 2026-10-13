<?php
/**
 * Driver Dashboard - operational availability across the fleet.
 *
 * Sections: header with refresh + cross-links, the data-quality warning, five
 * KPI cards, the stacked branch chart and the overall donut, the branch
 * summary, and the filterable driver list.
 *
 * Every figure comes from ONE array (DriverDashboardService); the same rows
 * are handed to assets/js/driver-dashboard.js, which filters, sorts and pages
 * them in the browser (spec sections 15, 16 and 21).
 *
 * Uses: $updated, $today, $kpis, $branches, $branchTotals, $overall, $unmapped,
 *       $rows, $statusOptions, $branchOptions,
 *       $labels, $tones, $icons, $categories, $query
 */
$kpis         = $kpis ?? [];
$branches     = $branches ?? [];
$branchTotals = $branchTotals ?? [];
$overall      = $overall ?? [];
$rows       = $rows ?? [];
$query      = $query ?? [];
$unmapped   = (int)($unmapped ?? 0);
$categories = $categories ?? ['active', 'on_leave', 'idle', 'gosi', 'other'];
$labels     = $labels ?? [];
$tones      = $tones ?? [];
$icons      = $icons ?? [];

// Percentages read through the shared helper at ONE decimal place (spec 3:
// "Round percentages to one decimal place" - 92.5%, 5.9%, 1.1%, 0.4%). Round
// first, then let pct_label() trim, so this page formats like the rest of the
// application while still honouring the spec's precision.
$fmtPct = static fn ($value): string => pct_label(round((float)$value, 1));

$actions = '<span class="small text-body-secondary align-self-center text-nowrap">'
    . '<i class="bi bi-clock-history me-1"></i>Data updated '
    . e(date('d M Y, H:i', strtotime((string)($updated ?? 'now'))))
    . '</span>'
    // Plain page reload: the figures are computed in PHP, so refreshing has to
    // re-run the query rather than re-draw what is already in the browser.
    . '<a class="btn btn-sm btn-outline-secondary" href="' . e(url('driver-dashboard')) . '"'
    . ' title="Refresh" aria-label="Refresh"><i class="bi bi-arrow-clockwise"></i></a>'
    . '<a class="btn btn-sm btn-outline-secondary" href="' . e(url('dashboard')) . '">'
    . '<i class="bi bi-speedometer2 me-1"></i>Leave Dashboard</a>'
    . '<a class="btn btn-sm btn-outline-secondary" href="' . e(url('drivers')) . '">'
    . '<i class="bi bi-person-badge me-1"></i>Driver Address Book</a>'
    // "Driver Management" is not a separate screen here - the address book
    // carries create, edit and deactivate - so this points at the add-driver
    // form for anyone allowed to reach it (spec section 1).
    . (can('drivers.create')
        ? '<a class="btn btn-sm btn-outline-secondary" href="' . e(url('drivers/create')) . '">'
          . '<i class="bi bi-person-plus me-1"></i>Driver Management</a>'
        : '');
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Driver Dashboard',
    'description' => 'Current driver workforce, operational availability, and branch distribution',
    'actions'     => $actions,
    // KPI drill page: no screen-wide "Columns" control - its grids sit behind
    // the cards. See data-dd-prefs-none.
    'no_prefs'    => true,
]) ?>

<?php if ($unmapped > 0): ?>
    <!-- Data-quality warning: never silently present a figure that does not
         cover every driver (spec section 15). -->
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div><strong><?= $unmapped ?></strong>
            driver<?= $unmapped === 1 ? '' : 's' ?> ha<?= $unmapped === 1 ? 's' : 've' ?> an
            unmapped operational status. Counted under <em>Other / Unavailable</em> rather than dropped.
        </div>
    </div>
<?php endif; ?>

<!-- --------------------------------------------------------- KPI cards -->
<?php
// The five cards. "Total Drivers" has no bucket of its own - clicking it
// clears every filter, which is what a reader expects from the denominator.
$cards = [
    ['key' => '',         'label' => 'Total Drivers',  'tone' => 'primary',
     'icon' => 'bi-people',          'hint' => 'Active employment population'],
    ['key' => 'active',   'label' => 'Active Drivers', 'tone' => 'success',
     'icon' => 'bi-person-check',    'hint' => 'Available for normal operations'],
    ['key' => 'on_leave', 'label' => 'On Leave',       'tone' => 'info',
     'icon' => 'bi-airplane',        'hint' => 'Away right now'],
    ['key' => 'idle',     'label' => 'Idle Drivers',   'tone' => 'warning',
     'icon' => 'bi-hourglass-split', 'hint' => 'Employed, no truck assigned'],
    ['key' => 'gosi',     'label' => 'GOSI',           'tone' => 'danger',
     'icon' => 'bi-heart-pulse',     'hint' => 'Sick / GOSI absence'],
];
?>
<div class="row g-3 mb-3" id="ddKpis">
    <?php foreach ($cards as $card):
        $key   = (string)$card['key'];
        $field = $key === '' ? 'total' : $key;
        $count = (int)($kpis[$field] ?? 0);
        $pct   = (float)($kpis[$field . '_pct'] ?? 0);
        // Only the four buckets carry a share of the total; the total is 100%
        // by definition and would read as noise (spec section 3).
        $showPct = $key !== '';
        ?>
        <div class="col-6 col-md-4 col-xl">
            <button type="button"
                    class="card kpi-card h-100 border-0 shadow-sm w-100 text-start dd-kpi"
                    data-dd-status="<?= e($key) ?>" aria-pressed="false"
                    title="<?= $key === '' ? 'Clear every filter'
                        : 'Filter the list to ' . e($labels[$key] ?? $key) ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label"><?= e($card['label']) ?></div>
                            <div class="kpi-value"><?= number_format($count) ?><?php
                                if ($showPct): ?><span
                                    class="dd-kpi-pct">| <?= e($fmtPct($pct)) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <span class="kpi-icon text-bg-<?= e($card['tone']) ?>">
                            <i class="bi <?= e($card['icon']) ?>"></i>
                        </span>
                    </div>
                    <div class="kpi-foot">
                        <span class="small text-body-secondary"><?= e($card['hint']) ?></span>
                    </div>
                </div>
            </button>
        </div>
    <?php endforeach; ?>
</div>

<!-- ------------------------------------------------------------- charts -->
<?php
// One palette for both charts, the summary markers and the table badges, so
// a category is never coloured two different ways on the same page. Fixed per
// category (not per position) - adding a branch changes nothing.
$colours = [
    'active'   => '#198754',
    'on_leave' => '#0d9488',
    'idle'     => '#f59e0b',
    'gosi'     => '#dc2626',
    'other'    => '#94a3b8',
];
?>
<div class="row g-3 mb-3">
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Operational Status by Branch</span>
                <span class="small text-body-secondary">
                    <i class="bi bi-cursor me-1"></i>Click a bar to filter the list
                </span>
            </div>
            <div class="card-body">
                <!-- Chart.js measures its parent; the box gives it a definite
                     height so a wide card produces a wide, SHORT chart rather
                     than one that scales to ~600px and runs off the screen. -->
                <div class="dd-chart-box">
                    <canvas id="ddBranchChart"
                            aria-label="Operational status by branch" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Overall Operational Status</span>
                <span class="small text-body-secondary"><?= number_format((int)($kpis['total'] ?? 0)) ?> drivers</span>
            </div>
            <div class="card-body">
                <div class="dd-chart-box">
                    <canvas id="ddOverallChart"
                            aria-label="Overall operational status distribution" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
    <?php // The third chart in this row. Agency names are long and there are
          // few of them, so it is a HORIZONTAL bar - the label reads on the
          // axis instead of being rotated under a column - and it is fed from
          // the same rows as the cards above, so its bars add up to the number
          // in its own header. There is no agency filter on this page, so it
          // is display-only: clicking one would either do nothing or need a
          // filter control that does not exist. ?>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Total Drivers By Agency</span>
                <span class="small text-body-secondary"><?= number_format((int)($kpis['total'] ?? 0)) ?> drivers</span>
            </div>
            <div class="card-body">
                <div class="dd-chart-box">
                    <canvas id="ddAgencyChart"
                            aria-label="Total drivers by agency" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ------------------------------------------------------ branch summary -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Branch Summary</span>
        <span class="small text-body-secondary">Click a row to filter the list</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="ddBranchTable">
            <thead class="table-light">
                <tr>
                    <th scope="col" class="dd-sort" data-dd-sort="branch" aria-sort="none">
                        Branch / Terminal <i class="bi bi-arrow-down-up ms-1 dd-sort-icon"></i></th>
                    <th scope="col" class="text-end dd-sort" data-dd-sort="total" aria-sort="none">
                        Total <i class="bi bi-arrow-down-up ms-1 dd-sort-icon"></i></th>
                    <?php foreach ($categories as $cat): ?>
                        <th scope="col" class="text-end dd-sort" data-dd-sort="<?= e($cat) ?>"
                            aria-sort="none"><?= e($labels[$cat] ?? $cat) ?>
                            <i class="bi bi-arrow-down-up ms-1 dd-sort-icon"></i></th>
                    <?php endforeach; ?>
                    <th scope="col" class="text-end dd-sort" data-dd-sort="active_pct" aria-sort="none">
                        Active % <i class="bi bi-arrow-down-up ms-1 dd-sort-icon"></i></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branches as $branch): ?>
                    <tr class="dd-branch-row" tabindex="0"
                        data-dd-branch="<?= (int)$branch['branch_id'] === 0
                            ? '0' : (string)(int)$branch['branch_id'] ?>">
                        <th scope="row" class="fw-semibold">
                            <?= e((string)$branch['branch']) ?>
                            <?php if ((int)$branch['branch_id'] === 0): ?>
                                <span class="badge text-bg-secondary ms-1">no branch</span>
                            <?php endif; ?>
                        </th>
                        <td class="text-end"><?= number_format((int)$branch['total']) ?></td>
                        <?php foreach ($categories as $cat): ?>
                            <td class="text-end">
                                <span class="dd-dot" style="background:<?= e($colours[$cat]) ?>"></span>
                                <?= number_format((int)($branch[$cat] ?? 0)) ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-end fw-semibold">
                            <?= e(pct_label(round((float)($branch['active_pct'] ?? 0), 1))) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($branches === []): ?>
                    <tr><td colspan="<?= 3 + count($categories) ?>"
                            class="text-center text-body-secondary py-4">No drivers found</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($branchTotals !== []): ?>
                <!-- The row every branch above adds up to: summed from the same
                     array the cards, the charts and the table are drawn from,
                     so it cannot disagree with any of them (spec section 15). -->
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <th scope="row" class="fw-semibold">
                            <?= e((string)($branchTotals['branch'] ?? 'Total')) ?>
                        </th>
                        <td class="text-end"><?= number_format((int)($branchTotals['total'] ?? 0)) ?></td>
                        <?php foreach ($categories as $cat): ?>
                            <td class="text-end">
                                <span class="dd-dot" style="background:<?= e($colours[$cat]) ?>"></span>
                                <?= number_format((int)($branchTotals[$cat] ?? 0)) ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-end">
                            <?= e(pct_label(round((float)($branchTotals['active_pct'] ?? 0), 1))) ?>
                        </td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- --------------------------------------------------------- driver list -->
<div class="card border-0 shadow-sm mb-3" id="ddListCard">
    <div class="card-header bg-transparent">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold">Driver List
                <span class="badge text-bg-light ms-2" id="ddCount"></span>
            </span>
            <div class="d-flex flex-wrap gap-2">
                <?php if (can('drivers.export')): ?>
                    <a class="btn btn-sm btn-outline-success" id="ddExportCsv"
                       href="<?= e(url('driver-dashboard?export=csv')) ?>"
                       title="Export the filtered list"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
                    <a class="btn btn-sm btn-outline-success" id="ddExportXls"
                       href="<?= e(url('driver-dashboard?export=xls')) ?>"
                       title="Export the filtered list"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Three filters that work together (spec section 8). -->
        <form class="row g-2 mt-1 mb-1" id="ddFilters" role="search" onsubmit="return false;">
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-1" for="ddStatus">Operational Status</label>
                <select class="form-select form-select-sm" id="ddStatus" name="operational_status">
                    <?php foreach (($statusOptions ?? []) as $value => $label): ?>
                        <option value="<?= e((string)$value) ?>"><?= e((string)$label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-1" for="ddBranch">Branch / Terminal</label>
                <select class="form-select form-select-sm" id="ddBranch" name="branch_id">
                    <?php foreach (($branchOptions ?? []) as $value => $label): ?>
                        <option value="<?= e((string)$value) ?>"><?= e((string)$label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-6">
                <label class="form-label small mb-1" for="ddSearch">Search</label>
                <input type="search" class="form-control form-control-sm" id="ddSearch" name="q"
                       placeholder="Badge, name or nationality" autocomplete="off">
            </div>
            <div class="col-12">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="ddClear">
                    <i class="bi bi-x-circle me-1"></i>Clear Filters
                </button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="ddTable">
            <thead class="table-light">
                <tr>
                    <?php
                    // The nine columns the data source can actually answer (spec
                    // section 7): tractor, attachment and customer do not exist
                    // in the drivers table, so they are not invented.
                    $columns = [
                        'badge_number'    => 'Badge Number',
                        'driver_name'     => 'Driver Name',
                        'branch'          => 'Branch / Terminal',
                        // op_flag, not op_label: the reader wants the flag on
                        // the driver (Settings / Address Book), and the list
                        // only holds the active population anyway - op_label
                        // reads "Active" for 412 of 455 rows.
                        'op_flag'         => 'Operational Status',
                        'agency'          => 'Agency',
                        'nationality'     => 'Nationality',
                        'expected_return' => 'Expected Return',
                        'remarks'         => 'Remarks',
                    ];
                    // Position in the current view, not in the payload: the
                    // browser renumbers it after every sort and page change.
                    ?>
                    <th scope="col" class="text-center">#</th>
                    <?php foreach ($columns as $key => $heading): ?>
                        <th scope="col" class="dd-sort text-nowrap" data-dd-sort="<?= e($key) ?>"
                            aria-sort="none" tabindex="0"><?= e($heading) ?>
                            <i class="bi bi-arrow-down-up ms-1 dd-sort-icon"></i></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody id="ddBody"></tbody>
        </table>

        <!-- Spec section 17: never show an empty grid with totals above it. -->
        <div id="ddEmpty" class="text-center py-5" hidden>
            <i class="bi bi-search d-block mb-2 dd-empty-icon"></i>
            <p class="fw-semibold mb-2">No drivers found</p>
            <button type="button" class="btn btn-sm btn-primary" id="ddEmptyClear">Clear Filters</button>
        </div>
    </div>

    <div class="card-footer bg-transparent">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="small text-body-secondary" id="ddShowing"></span>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <nav aria-label="Driver list pagination">
                    <ul class="pagination pagination-sm mb-0" id="ddPager"></ul>
                </nav>
                <label class="d-flex align-items-center gap-2 mb-0 small text-nowrap">
                    <span class="text-body-secondary">Rows</span>
                    <select class="form-select form-select-sm" id="ddSize" style="width:auto"
                            aria-label="Rows per page">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </label>
            </div>
        </div>
    </div>
</div>

<noscript>
    <div class="alert alert-secondary">
        This list filters in the browser, so JavaScript is required to see the
        drivers. The cards, charts and branch summary above are complete
        without it.
    </div>
</noscript>

<!-- ------------------------------------------- driver details (read only) --
     Spec section 11: visibility, not editing. Every field below already sits
     in the page payload, so opening it costs no request; the link at the
     bottom hands over to the address book for anything else. -->
<div class="modal fade" id="ddDriverModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="h5 modal-title mb-0" id="ddDriverTitle">Driver</h2>
                    <div class="small text-body-secondary" id="ddDriverSub"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <h3 class="h6 text-body-secondary border-bottom pb-1">Driver Information</h3>
                        <dl class="row mb-0" id="ddInfoDriver"></dl>
                    </div>
                    <div class="col-md-6">
                        <h3 class="h6 text-body-secondary border-bottom pb-1">Operational Information</h3>
                        <dl class="row mb-0" id="ddInfoOps"></dl>
                    </div>
                    <div class="col-12">
                        <h3 class="h6 text-body-secondary border-bottom pb-1">Availability Information</h3>
                        <dl class="row mb-0" id="ddInfoAvail"></dl>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline-primary btn-sm" id="ddDriverProfile" href="#">
                    <i class="bi bi-person-badge me-1"></i>Open full profile</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Data + scripts: the browser only draws what the server calculated. ---- -->
<script>
window.DRIVER_DASHBOARD_DATA = <?= json_encode([
    'rows'       => $rows,
    'branches'   => $branches,
    'agencies'   => $agencies,
    'overall'    => $overall,
    'labels'     => $labels,
    'tones'      => $tones,
    'colours'    => $colours,
    'categories' => $categories,
    'statusOptions'     => $statusOptions,
    'branchOptions'     => $branchOptions,
    'initial'    => [
        // The dropdown's own key: the flag from Settings.
        'operational_status' => (string)($query['operational_status'] ?? ''),
        // The KPI cards' and charts' key: the availability bucket.
        'op_status'  => (string)($query['op_status'] ?? ''),
        'branch_id'  => (string)($query['branch_id'] ?? ''),
        'q'          => (string)($query['q'] ?? ''),
    ],
    'urls' => [
        'driver' => url('drivers/'),
        'export' => url('driver-dashboard'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<?php
// Version the first-party script off its own mtime - same reason as
// dashboard.js: a browser must never keep drawing with an older file after a
// patch has been uploaded.
$ddJsV = (string)@filemtime(dirname(__DIR__, 3) . '/assets/js/driver-dashboard.js');
?>
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/driver-dashboard.js')) ?>?v=<?= e($ddJsV === '' ? '1' : $ddJsV) ?>"></script>
