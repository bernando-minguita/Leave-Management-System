<?php
/**
 * Standard page header used by list/report screens:
 *   title + description on the left, action buttons on the right.
 *
 * Uses: $title, $description (optional), $actions (optional HTML),
 *       $no_prefs (optional, default false) - true keeps this screen's
 *       "Columns" control out of the header entirely.
 *
 * The KPI screens (Leave Dashboard, Driver Dashboard, Capacity Planning)
 * pass it: their grids sit behind KPI cards and inside drill modals, so one
 * screen-wide show/hide button there would list columns from several
 * unrelated tables at once. Read by assets/js/table-prefs.js as
 * data-dd-prefs-none.
 */
$title       = $title ?? '';
$description = $description ?? '';
$actions     = $actions ?? '';
$no_prefs    = $no_prefs ?? false;
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 page-head"<?= $no_prefs ? ' data-dd-prefs-none' : '' ?>>
    <div>
        <h2 class="h4 mb-1"><?= e($title) ?></h2>
        <?php if ($description !== ''): ?>
            <p class="text-body-secondary mb-0"><?= e($description) ?></p>
        <?php endif; ?>
    </div>
    <?php if ($actions !== ''): ?>
        <div class="btn-toolbar gap-2 flex-wrap"><?= $actions ?></div>
    <?php endif; ?>
</div>
