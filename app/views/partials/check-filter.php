<?php
/**
 * Multi-select filter: a Bootstrap dropdown of checkboxes.
 *
 * The selection travels in one comma-separated hidden input, which is exactly
 * what filter_values()/id_filter() expect downstream - so single and multi
 * selection share one code path and existing links keep working.
 *
 * Uses: $name, $label, $pairs (value => label), $selected
 * Optional: $placeholder, $col (Bootstrap column classes)
 */
$name        = $name ?? '';
$label       = $label ?? '';
$pairs       = $pairs ?? [];
$selected    = filter_values($selected ?? '');
$col         = $col ?? 'col-6 col-md-3 col-xl-2';
$placeholder = $placeholder ?? '';

if ($placeholder === '') {
    $placeholder = 'All ' . strtolower((string)$label);
}

// Button text: name the option when one is picked, otherwise a count.
if (count($selected) === 1) {
    $initial = (string)($pairs[$selected[0]] ?? $selected[0]);
} elseif (count($selected) > 1) {
    $initial = count($selected) . ' selected';
} else {
    $initial = $placeholder;
}
?>

<div class="<?= e($col) ?>">
    <label class="form-label small mb-1"><?= e($label) ?></label>

    <div class="dropdown check-filter" data-check-filter>
        <button class="btn btn-outline-secondary btn-sm w-100 d-flex justify-content-between align-items-center"
                type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside"
                aria-expanded="false" aria-label="<?= e($label) ?>">
            <span class="text-truncate" data-cf-label
                  data-cf-placeholder="<?= e($placeholder) ?>"><?= e($initial) ?></span>
            <span class="d-flex align-items-center gap-1 ms-1">
                <span class="badge text-bg-primary" data-cf-count
                      <?= $selected === [] ? 'hidden' : '' ?>><?= count($selected) ?></span>
                <i class="bi bi-chevron-down small"></i>
            </span>
        </button>

        <div class="dropdown-menu check-filter-menu p-2">
            <div class="d-flex justify-content-between small mb-1 px-1">
                <a href="#" class="link-secondary text-decoration-none" data-cf-all>Select all</a>
                <a href="#" class="link-secondary text-decoration-none" data-cf-none>Clear</a>
            </div>

            <?php foreach ($pairs as $value => $text): ?>
                <label class="check-filter-item">
                    <input class="form-check-input mt-0" type="checkbox"
                           value="<?= e((string)$value) ?>"
                        <?= in_array((string)$value, $selected, true) ? 'checked' : '' ?>>
                    <span class="text-truncate"><?= e((string)$text) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <input type="hidden" name="<?= e($name) ?>" value="<?= e(implode(',', $selected)) ?>" data-cf-input>
    </div>
</div>
