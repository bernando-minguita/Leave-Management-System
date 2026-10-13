<?php
/**
 * Print / CSV / Excel for a report screen.
 *
 * These live in the PAGE HEADER, not down in the filter form, and that is the
 * whole point: the shared "Columns" (show/hide) control anchors itself to
 * .page-head - see anchorFor() in assets/js/table-prefs.js - so a report that
 * only rendered the ad-hoc header div it used to had nowhere to put it and
 * the button was dropped into a row of its own below the filters. Putting the
 * exports here gives every report one toolbar at the top holding all three
 * view controls together.
 *
 * Uses: $action (absolute URL of this report), $filters (current query)
 *       $badge (optional HTML - extra context for this specific report)
 */
$action  = $action ?? '';
$filters = $filters ?? [];
$badge   = $badge ?? '';

// $action is already absolute (url('reports/leave') -> "/lms/reports/leave").
// Wrapping it in url() a second time prefixed the base again - the same rule
// partials/report-toolbar follows.
$exportUrl = static function (string $kind) use ($action, $filters): string {
    return $action . '?' . http_build_query(array_merge($filters, ['export' => $kind]));
};
?>

<div class="d-flex flex-wrap gap-2 align-items-center">
    <?= $badge ?>
    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Print
    </button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e($exportUrl('csv')) ?>">
        <i class="bi bi-file-earmark-csv me-1"></i>CSV
    </a>
    <a class="btn btn-outline-success btn-sm" href="<?= e($exportUrl('xls')) ?>">
        <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
</div>
