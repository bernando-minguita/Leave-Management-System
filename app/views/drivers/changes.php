<?php
/**
 * Driver change requests - the review queue.
 *
 * Uses: $rows, $total, $filters, $sort, $statuses, $fields, $branches,
 *       $requesters, $pending, $query
 *
 * Shares the address book's conventions exactly: sort_header() links, the
 * client-side table engine, and a data-dd-prefs key of its own so column
 * show/hide is remembered per screen.
 */
$rows       = $rows ?? [];
$filters    = $filters ?? [];
$sort       = $sort ?? ['requested_at', 'desc'];
$statuses   = $statuses ?? [];
$fields     = $fields ?? [];
$branches   = $branches ?? [];
$requesters = $requesters ?? [];
$pending    = (int)($pending ?? 0);
$query      = $query ?? [];

/** Badge for a request status - these four are not in the Status lookup. */
$statusBadge = static function (string $s): string {
    $map = [
        'pending'   => ['warning', 'bi-hourglass-split', 'Pending'],
        'approved'  => ['success', 'bi-check-circle-fill', 'Approved'],
        'rejected'  => ['danger', 'bi-x-circle-fill', 'Rejected'],
        'cancelled' => ['secondary', 'bi-slash-circle', 'Cancelled'],
    ];
    [$class, $icon, $label] = $map[$s] ?? ['secondary', 'bi-question-circle', $s];

    return '<span class="badge text-bg-' . $class . '">'
        . '<i class="bi ' . $icon . ' me-1"></i>' . e($label) . '</span>';
};

$exportQuery = e(http_build_query(
    // sort_params() as well: the file has to come out in the order the
    // screen is showing, and ?sort= reaches the export only through here.
    sort_params(array_diff_key($filters, ['export' => 1]))
));
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Driver Change Requests',
    'description' => count($rows) . ' request' . (count($rows) === 1 ? '' : 's')
        . ($pending > 0
            ? ' · <span class="badge text-bg-warning">'
              . $pending . ' awaiting a decision</span>'
            : ''),
    'actions'     => (can('drivers.edit')
            ? '<a class="btn btn-primary btn-sm" href="'
              . e(url('driver-changes/new')) . '">'
              . '<i class="bi bi-plus-circle me-1"></i>Request a change</a>'
            : '')
        . '<a class="btn btn-outline-secondary btn-sm" href="'
          . e(url('driver-changes')) . '?export=xls&amp;' . $exportQuery . '">'
          . '<i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
        . '<a class="btn btn-outline-secondary btn-sm" href="'
          . e(url('driver-changes')) . '?export=csv&amp;' . $exportQuery . '">'
          . '<i class="bi bi-filetype-csv me-1"></i>CSV</a>',
]) ?>

<!-- Filters ------------------------------------------------------------ -->
<form method="get" action="<?= e(url('driver-changes')) ?>"
      class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f_status">Status</label>
                <select class="form-select form-select-sm" name="status" id="f_status">
                    <option value="">All</option>
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= e($key) ?>"
                            <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f_field">Field</label>
                <select class="form-select form-select-sm" name="field" id="f_field">
                    <option value="">All</option>
                    <?php foreach ($fields as $key => $label): ?>
                        <option value="<?= e($key) ?>"
                            <?= ($filters['field'] ?? '') === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f_branch">Branch</label>
                <select class="form-select form-select-sm" name="branch_id" id="f_branch">
                    <option value="">All</option>
                    <?php foreach ($branches as $id => $name): ?>
                        <option value="<?= (int)$id ?>"
                            <?= (string)($filters['branch_id'] ?? '') === (string)$id
                                ? 'selected' : '' ?>>
                            <?= e($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($requesters !== []): ?>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f_req">Requested By</label>
                <select class="form-select form-select-sm" name="requested_by" id="f_req">
                    <option value="">All</option>
                    <?php foreach ($requesters as $id => $name): ?>
                        <option value="<?= (int)$id ?>"
                            <?= (string)($filters['requested_by'] ?? '') === (string)$id
                                ? 'selected' : '' ?>>
                            <?= e($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-3 col-xl-3">
                <label class="form-label small mb-0" for="f_q">Search</label>
                <input class="form-control form-control-sm" type="search" name="q"
                       id="f_q" value="<?= e((string)($filters['q'] ?? '')) ?>"
                       placeholder="Driver, badge or reason">
            </div>
            <?php // Apply must not drop the sort the reader chose. ?>
            <?= hold_sort() ?>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm" type="submit">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a class="btn btn-outline-secondary btn-sm"
                   href="<?= e(url('driver-changes')) ?>">Clear</a>
            </div>
        </div>
    </div>
</form>


<!-- Queue -------------------------------------------------------------- -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-striped mb-0 data-table"
               data-dd-table
               data-dd-prefs="driver-changes"
               data-dd-size="<?= (int)per_page() ?>"
               data-dd-initial="<?= e(($sort[0] ?? '') . '|' . ($sort[1] ?? 'asc') . '|' . page_no()) ?>">
            <thead class="table-light">
                <tr>
                    <?= sort_header('badge_number', 'Badge', $sort, $query) ?>
                    <?= sort_header('driver', 'Driver', $sort, $query) ?>
                    <?= sort_header('field_name', 'Field', $sort, $query) ?>
                    <th>Current Value</th>
                    <th>Requested Value</th>
                    <th>Reason</th>
                    <th>Requested By</th>
                    <?= sort_header('requested_at', 'Requested', $sort, $query) ?>
                    <?= sort_header('status', 'Status', $sort, $query) ?>
                    <?= sort_header('reviewed_at', 'Reviewed', $sort, $query) ?>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="11" class="text-center text-body-secondary py-4">
                        <i class="bi bi-check2-circle fs-3 d-block mb-2"></i>
                        No change requests match these filters.
                    </td>
                </tr>
            <?php else: foreach ($rows as $r):
                $field   = (string)$r['field_name'];
                $uid     = (int)(current_user()['id'] ?? 0);
                $own     = (int)$r['requested_by'] === $uid;
                $open    = (string)$r['status'] === 'pending';
            ?>
                <tr>
                    <td data-dd-hide="0"><strong><?= e((string)$r['badge_number']) ?></strong></td>
                    <td data-dd-hide="1">
                        <a href="<?= e(url('drivers/' . (int)$r['driver_id'])) ?>">
                            <?= e((string)$r['driver_name']) ?>
                        </a>
                        <div class="small text-body-secondary">#<?= (int)$r['id'] ?></div>
                    </td>
                    <td><?= e(\App\Models\Driver::GOVERNABLE[$field] ?? $field) ?></td>
                    <td><span class="text-body-secondary"><?= e(
                        \App\Models\Driver::displayGovernedValue($field, $r['old_value'])
                    ) ?></span></td>
                    <td><strong><?= e(
                        \App\Models\Driver::displayGovernedValue($field, $r['new_value'])
                    ) ?></strong></td>
                    <td data-dd-hide="5">
                        <span class="small"><?= e((string)$r['reason']) ?></span>
                    </td>
                    <td><?= e((string)($r['requested_by_name'] ?? '—')) ?></td>
                    <td data-dd-hide="7" class="text-nowrap small">
                        <?= e((string)$r['requested_at']) ?>
                    </td>
                    <td><?= $statusBadge((string)$r['status']) ?></td>
                    <td data-dd-hide="9" class="small text-nowrap">
                        <?php if (($r['reviewed_at'] ?? null) !== null): ?>
                            <?= e((string)$r['reviewed_at']) ?>
                            <div class="text-body-secondary">
                                by <?= e((string)($r['reviewed_by_name'] ?? '—')) ?>
                            </div>
                        <?php else: ?>
                            <span class="text-body-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-outline-primary btn-sm"
                           href="<?= e(url('driver-changes/' . (int)$r['id'])) ?>">
                            <i class="bi bi-eye me-1"></i>View
                        </a>
                        <?php if ($open && $own && can('drivers.edit')): ?>
                            <form method="post"
                                  action="<?= e(url('driver-changes/' . (int)$r['id'] . '/cancel')) ?>"
                                  class="d-inline"
                                  onsubmit="return confirm('Withdraw this request?');">
                                <?= csrf_field() ?>
                                <button class="btn btn-outline-secondary btn-sm" type="submit">
                                    <i class="bi bi-x me-1"></i>Withdraw
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

