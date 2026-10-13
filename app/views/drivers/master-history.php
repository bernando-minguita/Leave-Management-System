<?php
/**
 * Master-data history for one driver - how their record came to look like this.
 *
 * Uses: $driver, $rows, $total, $filters, $fieldOptions, $sources,
 *       $requests, $canRequest
 *
 * This screen is READ-ONLY by design. There is no edit and no delete control
 * here, and the model behind it exposes no update() - a history row describes
 * something that happened, so the only way to alter it is to cause something
 * else to happen and write a new row alongside it.
 */
$driver       = $driver ?? [];
$rows         = $rows ?? [];
$filters      = $filters ?? [];
$fieldOptions = $fieldOptions ?? [];
$sources      = $sources ?? [];
$requests     = $requests ?? [];
$canRequest   = (bool)($canRequest ?? false);

$sourceBadge = static function (string $s) use ($sources): string {
    $style = [
        'initial'           => 'secondary',
        'approved_request'  => 'primary',
        'direct_correction' => 'warning',
        'import'            => 'info',
        'deactivation'      => 'danger',
        'reactivation'      => 'success',
    ][$s] ?? 'secondary';

    return '<span class="badge text-bg-' . $style . '">'
        . e($sources[$s] ?? $s) . '</span>';
};
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Driver History · ' . (string)$driver['driver_name'],
    'description' => count($rows) . ' change' . (count($rows) === 1 ? '' : 's')
        . ' recorded against this master record.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="'
        . e(url('drivers/' . (int)$driver['id'])) . '">'
        . '<i class="bi bi-person me-1"></i>Back to profile</a>'
        . ($canRequest
            ? '<a class="btn btn-primary btn-sm" href="'
              . e(url('driver-changes/new')) . '?driver_id=' . (int)$driver['id'] . '">'
              . '<i class="bi bi-pencil-square me-1"></i>Request a change</a>'
            : ''),
]) ?>

<!-- Filters ------------------------------------------------------------ -->
<form method="get"
      action="<?= e(url('drivers/' . (int)$driver['id'] . '/history')) ?>"
      class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="h_field">Field</label>
                <select class="form-select form-select-sm" name="field" id="h_field">
                    <option value="">All</option>
                    <?php foreach ($fieldOptions as $key): ?>
                        <option value="<?= e($key) ?>"
                            <?= ($filters['field'] ?? '') === $key ? 'selected' : '' ?>>
                            <?= e(\App\Models\Driver::GOVERNABLE[$key] ?? $key) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="h_source">Source</label>
                <select class="form-select form-select-sm" name="source" id="h_source">
                    <option value="">All</option>
                    <?php foreach ($sources as $key => $label): ?>
                        <option value="<?= e($key) ?>"
                            <?= ($filters['source'] ?? '') === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="h_from">From</label>
                <input class="form-control form-control-sm" type="date" name="date_from"
                       id="h_from" value="<?= e((string)($filters['date_from'] ?? '')) ?>">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="h_to">To</label>
                <input class="form-control form-control-sm" type="date" name="date_to"
                       id="h_to" value="<?= e((string)($filters['date_to'] ?? '')) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm" type="submit">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a class="btn btn-outline-secondary btn-sm"
                   href="<?= e(url('drivers/' . (int)$driver['id'] . '/history')) ?>">Clear</a>
            </div>
        </div>
    </div>
</form>

<!-- Open requests ------------------------------------------------------- -->
<?php $open = array_filter($requests, static fn (array $r): bool => $r['status'] === 'pending'); ?>
<?php if ($open !== []): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
        <i class="bi bi-hourglass-split mt-1"></i>
        <div>
            <strong><?= count($open) ?> request(s) awaiting a decision.</strong>
            They are not history yet - nothing below changes until one is approved.
            <div class="mt-1">
                <?php foreach ($open as $r): ?>
                    <a class="btn btn-outline-dark btn-sm me-2 mb-1"
                       href="<?= e(url('driver-changes/' . (int)$r['id'])) ?>">
                        #<?= (int)$r['id'] ?> ·
                        <?= e(\App\Models\Driver::GOVERNABLE[(string)$r['field_name']]
                            ?? $r['field_name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 data-table"
               data-dd-table
               data-dd-prefs="driver-history"
               data-dd-size="<?= (int)per_page() ?>"
               data-dd-initial="desc|1">
            <thead class="table-light">
                <tr>
                    <th>When</th>
                    <th>Field</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Reason</th>
                    <th>Source</th>
                    <th>Changed By</th>
                    <th>Effective</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="8" class="text-center text-body-secondary py-4">
                        <i class="bi bi-clock-history fs-3 d-block mb-2"></i>
                        Nothing has changed on this record yet.
                    </td>
                </tr>
            <?php else: foreach ($rows as $h):
                $f = (string)$h['field_name'];
            ?>
                <tr>
                    <td class="text-nowrap small"><?= e((string)$h['created_at']) ?></td>
                    <td><strong><?= e(\App\Models\Driver::GOVERNABLE[$f] ?? $f) ?></strong></td>
                    <td><span class="text-body-secondary"><?= e(
                        \App\Models\Driver::displayGovernedValue($f, $h['old_value'])
                    ) ?></span></td>
                    <td><strong><?= e(
                        \App\Models\Driver::displayGovernedValue($f, $h['new_value'])
                    ) ?></strong></td>
                    <td>
                        <?php if (($h['reason'] ?? '') !== ''): ?>
                            <span class="small"><?= e((string)$h['reason']) ?></span>
                        <?php else: ?>
                            <span class="text-body-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $sourceBadge((string)$h['source']) ?></td>
                    <td class="small">
                        <?= ($h['changed_by'] ?? null) !== null
                            ? e((string)($h['full_name'] ?: $h['username']))
                            : '<span class="text-body-secondary">System</span>' ?>
                    </td>
                    <td class="small text-nowrap">
                        <?= ($h['effective_date'] ?? null) !== null
                            ? e((string)$h['effective_date'])
                            : '<span class="text-body-secondary">Immediate</span>' ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

    <?php // A row produced by an approved request keeps its request_id, so the
          // full conversation (who asked, who answered) stays one click away.
          // Rendering the link inline needs the request rows, which the profile
          // tab already shows - see requests list on the profile. ?>

