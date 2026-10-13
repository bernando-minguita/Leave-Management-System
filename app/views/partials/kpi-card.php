<?php
/**
 * Shared KPI card partial.
 *
 * Uses: e($label), e($value), $tone, $icon, $hint, $href
 * Optional: $span     - Bootstrap column classes (default 4-across)
 *           $progress - 0..100, renders a meter bar (used by the headline card)
 */
$label = $label ?? '';
$value = $value ?? 0;
$tone  = $tone ?? 'primary';
$icon  = $icon  ?? 'bi-graph-up';
$hint  = $hint  ?? '';
$href  = $href  ?? '';
$span  = $span  ?? 'col-6 col-md-4';
// Optional drill-down: a Y-m-d the link opens INSTEAD of navigating. Set it
// and the card renders <a class="cap-drill" data-date>, which the owning page's
// delegated listener picks up - capacity reuses its existing day-row modal
// rather than growing a second list with its own rules.
$drill     = $drill ?? '';
$linkLabel = $linkLabel ?? 'View';
// Optional KPI drill-down: a card key whose rows come from
// /api/dashboard/kpi-drivers. Set it and the link becomes a modal trigger
// instead of a navigation - the card's own predicate travels with it, so the
// list that opens always totals the number printed above it.
$drillKpi = $drillKpi ?? '';
// Three cards are totals over the page-header range rather than over today,
// so they carry "from,to" alongside the key and the API opens that window.
$drillRange = $drillRange ?? '';
// The same drill, for a page that does NOT load dashboard.js - Capacity
// Planning's two range cards. The dashboard's listener lives in dashboard.js,
// so .sd-kpi would be a dead link here; this renders .cap-kpi and capacity.js
// picks it up. Same endpoint (/api/dashboard/kpi-drivers), same rows, and no
// navigation away from the forecast the reader is studying.
$capKpi   = $capKpi ?? '';
$capRange = $capRange ?? '';
$progress = isset($progress) && is_numeric($progress) ? max(0.0, min(100.0, (float)$progress)) : null;
?>

<div class="<?= e($span) ?>">
    <div class="card kpi-card h-100 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div class="w-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label"><?= e($label) ?></div>
                            <div class="kpi-value" data-kpi="<?= e($kpiKey ?? '') ?>"><?= e((string)$value) ?></div>
                        </div>
                        <span class="kpi-icon text-bg-<?= e($tone) ?>">
                            <i class="bi <?= e($icon) ?>"></i>
                        </span>
                    </div>

                    <?php if ($progress !== null): ?>
                        <div class="progress kpi-progress mt-2">
                            <div class="progress-bar bg-<?= e($tone) ?>"
                                 role="progressbar"
                                 style="width: <?= e(rtrim(rtrim(number_format($progress, 2, '.', ''), '0'), '.')) ?>%"
                                 aria-valuenow="<?= e((string)(int)$progress) ?>"
                                 aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($hint !== '' || $href !== '' || $drill !== '' || $drillKpi !== '' || $capKpi !== ''): ?>
                <div class="kpi-foot">
                    <?php if ($hint !== ''): ?>
                        <span class="small text-body-secondary"><?= e($hint) ?></span>
                    <?php endif; ?>
                    <?php if ($drillKpi !== ''): ?>
                        <a class="small link-offset-2 sd-kpi" href="#"
                           data-kpi-card="<?= e($drillKpi) ?>"
                           <?php if ($drillRange !== ''): ?>data-kpi-range="<?= e($drillRange) ?>"<?php endif; ?>
                           title="Show the rows behind this number"><?= e($linkLabel) ?> <i class="bi bi-arrow-right"></i></a>
                    <?php elseif ($capKpi !== ''): ?>
                        <a class="small link-offset-2 cap-kpi" href="#"
                           data-kpi-card="<?= e($capKpi) ?>"
                           <?php if ($capRange !== ''): ?>data-kpi-range="<?= e($capRange) ?>"<?php endif; ?>
                           title="Show the rows behind this number"><?= e($linkLabel) ?> <i class="bi bi-arrow-right"></i></a>
                    <?php elseif ($drill !== ''): ?>
                        <a class="small link-offset-2 cap-drill" href="#"
                           data-date="<?= e($drill) ?>"
                           title="Show the drivers behind this number"><?= e($linkLabel) ?> <i class="bi bi-arrow-right"></i></a>
                    <?php elseif ($href !== ''): ?>
                        <a class="small link-offset-2" href="<?= e($href) ?>"><?= e($linkLabel) ?> <i class="bi bi-arrow-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
