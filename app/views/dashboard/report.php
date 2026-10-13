<?php
/**
 * Hidden A4 body for the dashboard's "Build Summary Report" PDF.
 *
 * Rendered entirely server-side from the dashboard's own figures; the browser
 * only draws the two charts and hands this sheet to html2pdf.js. No business
 * rule is decided in JavaScript, so the PDF cannot disagree with the screen.
 *
 * The sheet sits OFF-SCREEN rather than display:none on purpose: html2canvas
 * measures the node, and a hidden one reports 0x0, which would export a
 * blank page.
 *
 * Uses: $report (company, today, generated, range_from, range_to, pending,
 *       cutoff, window, policy, breakdown, trend, kpis)
 */
$report   = $report ?? [];
$kpis     = $report['kpis'] ?? [];
$policy   = $report['policy'] ?? [];
$branches = $report['breakdown'] ?? [];
$trend    = $report['trend'] ?? [];

// Same palette dashboard.js uses, so the doughnut and the table markers
// agree. Kept here rather than only in JavaScript so a marker is never
// coloured differently from the slice it stands for.
$palette = ['#1e4b7a', '#2563eb', '#0d9488', '#f59e0b', '#7c3aed',
            '#dc2626', '#0891b2', '#65a30d', '#db2777', '#64748b'];

// Fixed colours per BRANCH, identical to branchColours in
// assets/js/dashboard.js, so the PDF doughnut and the on-screen bar chart
// never disagree about which branch is which.
//
// Keyed by NAME rather than by position on purpose: a branch keeps its own
// colour however the rows sort, and adding a branch later cannot re-colour
// the existing ones. Anything not listed falls back to the palette at its
// own index, so an unknown or new branch still gets a colour of its own.
$branchColours = [
    'dammam' => '#1e3a8a',   // dark blue
    'riyadh' => '#16a34a',   // green
    'jeddah' => '#f97316',   // orange
];

$totalOnLeave = 0;
$totOverdue   = 0;
$totPending   = 0;

foreach ($branches as $row) {
    $totalOnLeave += (int) ($row['on_leave'] ?? 0);
    $totOverdue   += (int) ($row['overdue'] ?? 0);
    $totPending   += (int) ($row['pending'] ?? 0);
}

// One colour per row, resolved by name and kept parallel to $branches, so a
// table marker and the doughnut slice it stands for cannot drift apart.
$colours = [];
foreach ($branches as $i => $row) {
    $key = strtolower(trim((string) ($row['label'] ?? '')));
    $colours[$i] = $branchColours[$key] ?? $palette[$i % count($palette)];
}

$livePct = (float) ($policy['live_pct'] ?? 0);
$targetPct = (float) ($policy['target_pct'] ?? 0);

/** 0..100 share of everyone currently away - never divides by zero. */
$pctOf = static function (int $count) use ($totalOnLeave): float {
    return $totalOnLeave > 0 ? round(($count / $totalOnLeave) * 100, 1) : 0.0;
};

$topBranch = $branches[0] ?? null;
$generated = (string) ($report['generated'] ?? '');
$rangeFrom = (string) ($report['range_from'] ?? '');
$rangeTo   = (string) ($report['range_to'] ?? '');
?>
<style>
/* ------------------------------------------------------ sheet geometry -- */
/* A4 portrait at 96dpi = 210mm x 297mm = 794 x 1123 CSS pixels. */
/* The sheet is hidden by an off-screen HOST, never by positioning the sheet
   itself. html2pdf clones #summaryReport into an absolutely positioned
   container that sizes with height:auto: an absolutely positioned clone
   contributes no height, the container collapses to 0, and the exported page
   comes out blank (canvas 794x0). Keeping #summaryReport in normal flow is
   what makes the export render at all - do not add position/left/top here. */
#reportHost {
    position: absolute;
    left: -10000px;
    top: 0;
    /* 210mm, NOT 794px: html2pdf builds its capture container from the jsPDF
       page size minus the margins, and anything wider is clipped away. The
       sheet must match that box exactly or the right-hand columns come off
       (they were cut by 61px when this was 794px against a 194mm container). */
    width: 210mm;
    z-index: -1;
}
#summaryReport {
    position: static;
    width: 210mm;
    background: #eef2f7;
    font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
    color: #1f2937;
    line-height: 1.45;
}
#summaryReport * { box-sizing: border-box; }

.rpt-sheet {
    width: 210mm;
    background: #fff;
    /* Bottom padding is 0 on purpose. The sheet measured EXACTLY 1047px,
       which is exactly the 277mm page budget at margin [10,0,10,0]. At that
       boundary html2pdf's Math.ceil(height / slice) tips over on rounding and
       emits a second, empty page. Removing the trailing whitespace buys real
       headroom; the page margin below the footer is the separation. */
    padding: 34px 10mm 0;
}

/* ------------------------------------------------------------- header --- */
.rpt-head { border-bottom: 3px solid #1e6f9f; padding-bottom: 14px; margin-bottom: 20px; }
.rpt-brand {
    font-size: 10px; letter-spacing: 1.6px; text-transform: uppercase;
    color: #1e6f9f; font-weight: 700; margin-bottom: 4px;
}
.rpt-title { font-size: 21px; font-weight: 700; color: #0f172a; margin: 0 0 8px; }
.rpt-meta {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline;
    gap: 6px 22px; font-size: 11px; color: #64748b;
}
.rpt-meta b { color: #334155; font-weight: 600; }

/* ---------------------------------------------------------------- KPIs -- */
.rpt-kpis { display: flex; gap: 12px; margin-bottom: 20px; }
.rpt-kpi {
    flex: 1; background: #fff; border: 1px solid #e2e8f0; border-top: 3px solid #1e6f9f;
    border-radius: 10px; padding: 12px 13px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .07);
}
.rpt-kpi.is-warning { border-top-color: #dc3545; background: #fff5f5; }
.rpt-kpi-label { font-size: 9.5px; text-transform: uppercase; letter-spacing: .5px; color: #64748b; min-height: 24px; }
.rpt-kpi-value { font-size: 27px; font-weight: 700; color: #0f172a; line-height: 1.15; margin-top: 3px; }
.rpt-kpi.is-warning .rpt-kpi-value { color: #b91c1c; }
.rpt-kpi-hint { font-size: 9.5px; color: #94a3b8; margin-top: 4px; }
.rpt-block { margin-bottom: 20px; }
.rpt-h {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px;
    color: #1e6f9f; margin: 0 0 9px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0;
}

/* -------------------------------------------------------------- charts -- */
.rpt-charts { display: flex; gap: 14px; }
/* min-width:0 is load-bearing: flex items default to min-width:auto, which
   resolves to the canvas's 350px intrinsic width. Two cards + gap then
   needed 766px inside a 718px column, so the overflow all landed on the
   second card and the trend chart was clipped at its right edge. */
.rpt-chart {
    flex: 1 1 0; min-width: 0; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 11px 12px; box-shadow: 0 1px 3px rgba(15, 23, 42, .07);
}
.rpt-chart-title { font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 6px; text-align: center; }
.rpt-canvas-box { width: 100%; height: 200px; position: relative; }
.rpt-canvas-box canvas { display: block; width: 100% !important; height: 100% !important; }

/* --------------------------------------------------------------- table -- */
.rpt-table {
    width: 100%; border-collapse: collapse; font-size: 11px; background: #fff;
    border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(15, 23, 42, .07);
}
.rpt-table thead th {
    background: #1e6f9f; color: #fff; font-weight: 600; font-size: 10px;
    text-transform: uppercase; letter-spacing: .5px; padding: 9px 10px; text-align: left;
}
.rpt-table thead th.num, .rpt-table tbody td.num, .rpt-table tfoot td.num { text-align: right; }
.rpt-table tbody td { padding: 8px 10px; border-top: 1px solid #eef2f7; color: #334155; }
.rpt-table tbody tr:nth-child(even) { background: #f8fafc; }
.rpt-table tfoot td {
    padding: 9px 10px; background: #eef4fa; font-weight: 700; color: #0f172a;
    border-top: 2px solid #1e6f9f;
}
.rpt-dot { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 7px; vertical-align: 1px; }

/* --------------------------------------------------------------- notes -- */
.rpt-notes {
    background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #1e6f9f;
    border-radius: 8px; padding: 13px 16px;
}
.rpt-note { font-size: 11px; color: #334155; margin: 0 0 7px; padding-left: 17px; position: relative; }
.rpt-note:last-child { margin-bottom: 0; }
.rpt-note::before {
    content: ""; position: absolute; left: 0; top: 5px; width: 7px; height: 7px;
    border-radius: 50%; background: #1e6f9f;
}
.rpt-note.is-alert::before { background: #dc3545; }
.rpt-note b { color: #0f172a; }

/* -------------------------------------------------------------- footer -- */
.rpt-foot {
    margin-top: 18px; padding-top: 10px; border-top: 1px solid #e2e8f0;
    font-size: 9.5px; color: #94a3b8; display: flex; justify-content: space-between;
}

/* ------------------------------------------------------- page breaking -- */
.rpt-break { page-break-after: always; break-after: page; height: 0; }
</style>

<div id="reportHost">
<div id="summaryReport" data-today="<?= e((string) ($report['today'] ?? '')) ?>">
<div class="rpt-sheet">

    <!-- ------------------------------------------------------------ head -->
    <div class="rpt-head">
        <div class="rpt-brand"><?= e($report['company'] !== '' ? $report['company'] : 'LMS') ?></div>
        <h1 class="rpt-title">Driver Leave &amp; Scheduling Summary Report</h1>
        <div class="rpt-meta">
            <span><b>Report period:</b> <?= e($rangeFrom !== '' ? fmt_date($rangeFrom) : '—')
                ?> – <?= e($rangeTo !== '' ? fmt_date($rangeTo) : '—') ?></span>
            <span><b>As of:</b> <?= e(fmt_date((string) ($report['today'] ?? ''))) ?></span>
        </div>
    </div>

    <!-- -------------------------------------------------------------- KPIs -->
    <div class="rpt-kpis">
        <div class="rpt-kpi">
            <div class="rpt-kpi-label">Total drivers</div>
            <div class="rpt-kpi-value"><?= (int) ($kpis['total_drivers'] ?? 0) ?></div>
            <div class="rpt-kpi-hint">Active fleet</div>
        </div>

        <div class="rpt-kpi">
            <div class="rpt-kpi-label">Drivers on leave / vacation</div>
            <div class="rpt-kpi-value"><?= (int) ($kpis['on_leave'] ?? 0) ?></div>
            <div class="rpt-kpi-hint"><?= e(pct_label($livePct)) ?> of active fleet<?= ! empty($policy['configured'])
                ? ' · target ' . e(pct_label($targetPct)) : '' ?></div>
        </div>

        <div class="rpt-kpi is-warning">
            <div class="rpt-kpi-label">Leave Overdue</div>
            <div class="rpt-kpi-value"><?= (int) ($kpis['overdue'] ?? 0) ?></div>
            <div class="rpt-kpi-hint">Past expected return</div>
        </div>

        <div class="rpt-kpi">
            <div class="rpt-kpi-label">Pending applications</div>
            <div class="rpt-kpi-value"><?= (int) ($report['pending'] ?? 0) ?></div>
            <div class="rpt-kpi-hint">LWD on or before <?= e($report['cutoff'] !== '' ? fmt_date((string) $report['cutoff']) : '') ?></div>
        </div>
    </div>

    <!-- ----------------------------------------------------------- charts -->
    <div class="rpt-block">
        <div class="rpt-h">Leave distribution</div>
        <div class="rpt-charts">
            <div class="rpt-chart">
                <div class="rpt-chart-title">Leave drivers by branch</div>
                <div class="rpt-canvas-box"><canvas id="rptAgencyChart" width="350" height="200"></canvas></div>
            </div>
            <div class="rpt-chart">
                <div class="rpt-chart-title">Monthly leave trend</div>
                <div class="rpt-canvas-box"><canvas id="rptTrendChart" width="350" height="200"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------------- branch table -->
    <div class="rpt-block">
        <div class="rpt-h">Branch breakdown</div>
        <table class="rpt-table">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th class="num">Drivers on leave</th>
                    <th class="num">% of total leave</th>
                    <th class="num">Overdue returns</th>
                    <th class="num">Pending applications</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($branches === []): ?>
                <tr><td colspan="5">No drivers are currently on leave.</td></tr>
            <?php else: $dot = 0; foreach ($branches as $row): ?>
                <tr>
                    <td><span class="rpt-dot" style="background:<?= e($colours[$dot] ?? $palette[$dot % count($palette)]) ?>"></span><?= e((string) $row['label']) ?></td>
                    <td class="num"><?= (int) $row['on_leave'] ?></td>
                    <td class="num"><?= e(pct_label($pctOf((int) $row['on_leave']))) ?></td>
                    <td class="num"><?= (int) $row['overdue'] ?></td>
                    <td class="num"><?= (int) $row['pending'] ?></td>
                </tr>
                <?php $dot++; ?>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="num"><?= $totalOnLeave ?></td>
                    <td class="num"><?= e(pct_label($pctOf($totalOnLeave))) ?></td>
                    <td class="num"><?= $totOverdue ?></td>
                    <td class="num"><?= $totPending ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- ------------------------------------------------------ notes ---- -->
    <div class="rpt-block">
        <div class="rpt-h">Decision support notes</div>
        <?php
        // Written from the same figures as the cards above - never from a
        // second calculation - so a note can never contradict a KPI.
        $overdue    = (int) ($kpis['overdue'] ?? 0);
        $onLeave    = (int) ($kpis['on_leave'] ?? 0);
        $total      = (int) ($policy['total'] ?? 0);
        $allowed    = (int) ($policy['allowed'] ?? 0);
        $headroom   = (int) ($policy['headroom'] ?? 0);
        $pending    = (int) ($report['pending'] ?? 0);
        $window     = (int) ($report['window'] ?? 30);
        $configured = ! empty($policy['configured']);
        $overTarget = ! empty($policy['over']);
        $lowest     = $branches === [] ? null : $branches[count($branches) - 1];
        ?>
        <div class="rpt-notes">
            <?php if ($overdue > 0): ?>
                <p class="rpt-note is-alert"><b><?= $overdue ?> driver<?= $overdue === 1 ? '' : 's' ?>
                    overdue returning to terminal.</b>
                    Each still holds a leave slot, so <?= $overdue ?> fewer approvals can be granted
                    until they come back.</p>
            <?php else: ?>
                <p class="rpt-note"><b>No overdue returns.</b>
                    Every driver is back inside the expected window, so nothing is holding capacity.</p>
            <?php endif; ?>

            <?php if ($configured && $overTarget): ?>
                <p class="rpt-note is-alert">Leave runs at <b><?= e(pct_label($livePct)) ?></b> against a
                    <b><?= e(pct_label($targetPct)) ?></b> ceiling of <?= $allowed ?> drivers
                    (<?= $onLeave ?> of <?= $total ?>). <b>New vacation approvals are blocked</b>
                    until the share falls back under target.</p>
            <?php elseif ($configured): ?>
                <p class="rpt-note">Leave runs at <b><?= e(pct_label($livePct)) ?></b> against a
                    <b><?= e(pct_label($targetPct)) ?></b> ceiling of <?= $allowed ?> drivers —
                    <b><?= $headroom ?> approval<?= $headroom === 1 ? '' : 's' ?></b> of headroom remain.</p>
            <?php else: ?>
                <p class="rpt-note">No target percentage is configured yet — set
                    <b>leave_percentage</b> in System Settings to apply the fixed limit.</p>
            <?php endif; ?>

            <?php if ($topBranch !== null && $totalOnLeave > 0): ?>
                <p class="rpt-note"><b><?= e((string) $topBranch['label']) ?></b> carries the highest leave
                    concentration: <?= (int) $topBranch['on_leave'] ?> of <?= $totalOnLeave ?> drivers away
                    (<?= e(pct_label($pctOf((int) $topBranch['on_leave']))) ?>).</p>
            <?php endif; ?>

            <p class="rpt-note"><b><?= $pending ?> pending application<?= $pending === 1 ? '' : 's' ?></b>
                with a last working date on or before <?= e($report['cutoff'] !== '' ? fmt_date((string) $report['cutoff']) : '—') ?>,
                counted backwards across a <?= $window ?>-day window.</p>

            <p class="rpt-note"><b>Recommendation:</b>
                <?= $overdue > 0 ? 'prioritise the overdue returns first' : 'clear the pending queue now' ?>,
                stagger new approvals as slots free up, and direct new leave toward
                <?= $lowest !== null && (int) $lowest['on_leave'] < $totalOnLeave
                    ? e((string) $lowest['label']) . ', which has the fewest staff away'
                    : 'the branches with the least staff away' ?>.</p>
        </div>
    </div>

    <!-- ------------------------------------------------------ foot ------ -->
    <div class="rpt-foot">
        <span><?= e($report['company'] !== '' ? $report['company'] : 'LMS') ?> · Driver Leave &amp; Scheduling Summary Report</span>
        <span>Generated <?= e($generated) ?></span>
    </div>

</div>
</div>
</div>
<script>
    // Chart inputs for the sheet above. Everything else in the PDF is already
    // static HTML; only these two canvases are drawn in the browser, with
    // animation off and a fixed size, so html2canvas captures real pixels
    // instead of an in-progress frame.
    window.REPORT_DATA = <?= json_encode([
        'breakdown' => array_map(
            static fn (array $r): array => ['label' => (string) $r['label'], 'total' => (int) $r['on_leave']],
            $branches
        ),
        'trend'  => $trend,
        // Resolved per row by branch name, so slice i always matches row i.
        'colors' => array_values($colours),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
