<?php
/**
 * Address Book - searchable, sortable, paginated driver table.
 *
 * Uses: $rows, $total, $filters, $sort, $branches, $agencies,
 *       $nationalities, $query
 */
$sort    = $sort ?? ['badge_number', 'asc'];
$filters = $filters ?? [];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Drivers',
    'description' => number_format((int)$total) . ' driver' . ((int)$total === 1 ? '' : 's') . ' in the address book.',
    'actions'     => (can('drivers.create')
            ? '<a class="btn btn-primary btn-sm" href="' . e(url('drivers/create')) . '">'
              . '<i class="bi bi-person-plus me-1"></i>Add Driver</a>'
            : '')
        . (can('drivers.import')
            ? '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('import')) . '">'
              . '<i class="bi bi-upload me-1"></i>Import</a>'
            : '')
        . (can('drivers.master_correct') && simledger_enabled()
            ? '<button type="button" class="btn btn-outline-primary btn-sm"'
              . ' data-action="sync-simledger-all"'
              . ' data-url="' . e(url('api/drivers/sync-simledger-all')) . '">'
              . '<i class="bi bi-arrow-repeat me-1"></i>Sync all from SIMLedger</button>'
            : '')
        . (can('drivers.export')
            ? '<a class="btn btn-outline-secondary btn-sm" href="'
              . e(url('drivers?' . http_build_query(
                  sort_params(array_merge((array)$filters, ['export' => 'csv']))
              ))) . '">'
              . '<i class="bi bi-download me-1"></i>Export CSV</a>'
            : ''),
]) ?>

<!-- Filters ------------------------------------------------------------- -->
<form method="get" action="<?= e(url('drivers')) ?>" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="q">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="q" name="q"
                           value="<?= e($filters['q'] ?? '') ?>"
                           placeholder="Name, badge, iqama or mobile">
                    <?php if (($filters['q'] ?? '') !== ''): ?>
                        <a class="input-group-text" href="<?= e(url('drivers')) ?>" title="Clear search">
                            <i class="bi bi-x"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?= View::partial('partials/check-filter', [
                'name' => 'branch_id', 'label' => 'Branch',
                'pairs' => $branches ?? [], 'selected' => $filters['branch_id'] ?? '',
                'placeholder' => 'All', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'agency_id', 'label' => 'Agency',
                'pairs' => $agencies ?? [], 'selected' => $filters['agency_id'] ?? '',
                'placeholder' => 'All', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'nationality', 'label' => 'Nationality',
                // A flat list of names - use each one as both value and label.
                'pairs' => array_combine($nationalities ?? [], $nationalities ?? []),
                'selected' => $filters['nationality'] ?? '',
                'placeholder' => 'All', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'status', 'label' => 'Employment Status',
                // Slugs from Settings (value|Label) - labels stay editable.
                'pairs' => \App\Models\Lookup::employmentStatuses(),
                'selected' => $filters['status'] ?? '',
                'placeholder' => 'All', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name' => 'operational_status', 'label' => 'Operational Status',
                'pairs' => \App\Models\Lookup::operationalStatuses(),
                'selected' => $filters['operational_status'] ?? '',
                'placeholder' => 'All', 'col' => 'col-6 col-md-2',
            ]) ?>

            <?php // The chosen sort is in the URL, and a GET form only sends
                  // the fields it holds - carry it across Apply. ?>
            <?= hold_sort() ?>

            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary btn-sm" type="submit">
                    <i class="bi bi-funnel me-1"></i>Apply
                </button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('drivers')) ?>">
                    <i class="bi bi-x-circle me-1"></i>Reset
                </a>
            </div>
        </div>
    </div>
</form>

<!-- Table ---------------------------------------------------------------- -->
<?php
// Send bulk deletes back to this grid with the current filters applied.
$gridReturn = 'drivers';

if (!empty($query)) {
    $gridReturn .= '?' . http_build_query(array_diff_key((array)$query, ['page' => 1]));
}

// Tick-boxes, toolbar and row menu must all agree on the same answer.
$canDelete = can('drivers.delete');
?>
<div class="card border-0 shadow-sm">
    <?php if ($canDelete): ?>
        <?php // data-confirm lives on the submit button: on the form it would
              // fire for every click inside the table it wraps. ?>
        <form method="post" action="<?= e(url('drivers/bulk-delete')) ?>"
              id="bulkDriverForm" data-bulk-select>
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= e($gridReturn) ?>">
            <div class="card-header bg-white d-flex flex-wrap align-items-center gap-3">
                <span class="small text-body-secondary mb-0">
                    <strong data-bulk-count>0</strong> selected
                </span>
                <button type="submit" class="btn btn-sm btn-outline-danger ms-auto"
                        data-bulk-submit
                        data-confirm="Delete the selected drivers? Only drivers with no leave records can be deleted."
                        disabled>
                    <i class="bi bi-trash me-1"></i>Delete selected
                </button>
            </div>
        </form>
        <?php // Stops before the table: a <form> inside a <form> is dropped by
              // HTML parsers, which would strand every row-level action. The tick
              // boxes join it through form="bulkDriverForm". ?>
    <?php endif; ?>
    <?php // The 300px floor every other list carries (.table-grid): with a
          // single search hit the row-action dropdown used to be clipped at
          // the container edge. ?>
    <div class="table-responsive table-grid">
        <table class="table table-hover align-middle table-striped mb-0 data-table"
               data-dd-table
               data-dd-prefs="drivers"
               data-dd-size="<?= (int)per_page() ?>"
               data-dd-initial="<?= e(($sort[0] ?? '') . '|' . ($sort[1] ?? 'asc') . '|' . page_no()) ?>">
            <thead class="table-light">
            <tr>
                <th class="text-center">#</th>
                <?php if ($canDelete): ?>
                    <th class="ps-3" style="width: 42px">
                        <input type="checkbox" class="form-check-input" data-check-all
                               form="bulkDriverForm"
                               title="Select all on this page" aria-label="Select all">
                    </th>
                <?php endif; ?>
                <?= sort_header('badge_number', 'Badge', $sort, $query ?? []) ?>
                <?= sort_header('driver_name', 'Driver Name', $sort, $query ?? []) ?>
                <?= sort_header('iqama_number', 'Iqama', $sort, $query ?? []) ?>
                <?= sort_header('passport_number', 'Passport', $sort, $query ?? []) ?>
                <?= sort_header('mobile_number', 'Mobile', $sort, $query ?? []) ?>
                <?= sort_header('nationality', 'Nationality', $sort, $query ?? []) ?>
                <?= sort_header('agency', 'Agency', $sort, $query ?? []) ?>
                <?= sort_header('branch', 'Branch', $sort, $query ?? []) ?>
                <?= sort_header('position', 'Position', $sort, $query ?? []) ?>
                <?= sort_header('status', 'Employment Status', $sort, $query ?? []) ?>
                <?= sort_header('operational_status', 'Operational Status', $sort, $query ?? []) ?>
                <?= sort_header('created_at', 'Date Created', $sort, $query ?? []) ?>
                <?= sort_header('updated_at', 'Last Updated', $sort, $query ?? []) ?>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php // Rows ship whole: numbered in order, not from the URL's page. ?>
            <?php $rowNo = 1; ?>
            <?php if (empty($rows)): ?>
                <tr>
                    <?php // 13 / 12 plus the two timestamp columns (Date Created,
                      // Last Updated) = 15 / 14. ?>
                <td colspan="<?= $canDelete ? 15 : 14 ?>">
                        <?= empty_state(
                            'bi-person-x',
                            'No drivers found.',
                            'Try adjusting the search or filters, or add a new driver.',
                            '<a class="btn btn-primary btn-sm" href="' . e(url('drivers/create')) . '">'
                            . '<i class="bi bi-person-plus me-1"></i>Add Driver</a>'
                        ) ?>
                    </td>
                </tr>

            <?php else: foreach ($rows as $row): ?>
                <tr data-dd-row>
                    <td class="text-center"><?= $rowNo++ ?></td>
                    <?php if ($canDelete): ?>
                        <td class="ps-3 align-middle">
                            <input type="checkbox" class="form-check-input" name="ids[]"
                                   form="bulkDriverForm"
                                   value="<?= (int)$row['id'] ?>" data-row-check
                                   aria-label="Select <?= e($row['driver_name']) ?>">
                        </td>
                    <?php endif; ?>
                    <td class="text-nowrap fw-semibold"><?= e($row['badge_number']) ?></td>
                    <td>
                        <a class="link-dark text-decoration-none fw-semibold"
                           href="<?= e(url('drivers/' . (int)$row['id'])) ?>">
                            <?= e($row['driver_name']) ?>
                        </a>
                    </td>
                    <td class="text-nowrap"><?= e($row['iqama_number']) ?></td>
                    <td class="text-nowrap" dir="ltr"><?= e($row['passport_number'] ?? '') ?: '—' ?></td>
                    <td class="text-nowrap" dir="ltr"><?= e($row['mobile_number'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['nationality'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['agency_name'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e($row['position'] ?? '—') ?></td>
                    <td>
                        <?php // Label comes from Settings so a renamed option
                              // never shows its stored slug. ?>
                        <?php $empStatus = (string)$row['status']; ?>
                        <?php if ($empStatus === 'active'): ?>
                            <span class="badge text-bg-success"><?= e(\App\Models\Lookup::employmentLabel($empStatus)) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary"><?= e(\App\Models\Lookup::employmentLabel($empStatus)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $opStatus = (string)($row['operational_status'] ?? ''); ?>
                        <?php if ($opStatus === ''): ?>
                            <span class="text-body-secondary">—</span>
                        <?php else: ?>
                            <span class="badge text-bg-info text-body"><?= e(\App\Models\Lookup::operationalLabel($opStatus)) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php // Two timestamps, not one: "Date Created" answers "is
                          // this record new?", "Last Updated" answers "has
                          // somebody touched it since?" - one column can only
                          // ever answer one of them. Rendered with fmt_date_time
                          // (not fmt_date) to match the Date Created / Last
                          // Updated pair already on Leave Records and Leave
                          // Applications: without the time, "Last Updated" cannot
                          // separate today from six months ago. Placed before
                          // Actions, which is where those registers put theirs. ?>
                    <td class="text-nowrap text-body-secondary">
                        <?= e(fmt_date_time((string)($row['created_at'] ?? ''))) ?>
                    </td>
                    <td class="text-nowrap text-body-secondary">
                        <?= e(fmt_date_time((string)($row['updated_at'] ?? ''))) ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <div class="btn-group btn-group-sm">
                            <a class="btn btn-outline-secondary" href="<?= e(url('drivers/' . (int)$row['id'])) ?>"
                               title="View profile"><i class="bi bi-eye"></i></a>
                            <?php // Two doors, never one that 403s.
                                  // drivers.master_correct opens the record
                                  // itself. drivers.edit only FILES A CHANGE
                                  // REQUEST - that is the right an Operations
                                  // reader actually holds - so it has to point
                                  // at the request form. Gating both on
                                  // drivers.edit handed Operations a pencil
                                  // whose route then refused it. ?>
                            <?php if (can('drivers.master_correct')): ?>
                                <a class="btn btn-outline-secondary"
                                   href="<?= e(url('drivers/' . (int)$row['id'] . '/edit')) ?>"
                                   title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php elseif (can('drivers.edit')): ?>
                                <a class="btn btn-outline-secondary"
                                   href="<?= e(url('driver-changes/new')) ?>?driver_id=<?= (int)$row['id'] ?>"
                                   title="Request a change"><i class="bi bi-pencil-square"></i></a>
                            <?php endif; ?>
                            <?php // Requirement 4: the "+" opens an APPLICATION (every leave
                                  // starts with one), and only for a driver who is still
                                  // active - a deactivated driver gains no new leave (9). ?>
                            <?php if (can('leave.create') && $row['status'] === 'active'): ?>
                                <a class="btn btn-outline-primary"
                                   href="<?= e(url('leave-applications/new')) ?>?driver_id=<?= (int)$row['id'] ?>"
                                   title="New leave application"><i class="bi bi-plus-circle"></i></a>
                            <?php endif; ?>
                            <a class="btn btn-outline-secondary"
                               href="<?= e(url('drivers/' . (int)$row['id'] . '/leave-history')) ?>"
                               title="Leave history"><i class="bi bi-clock-history"></i></a>
                        </div>

                        <?php // The menu is dropped when it would be empty: a viewer
                              // holds neither drivers.deactivate nor drivers.delete,
                              // so the toggle would open on nothing. ?>
                        <?php if (can('drivers.deactivate') || can('drivers.delete')): ?>
                        <div class="dropdown d-inline-block ms-1">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown" aria-label="More actions">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if (!can('drivers.deactivate')): ?>
                                    <?php // Needs drivers.deactivate: show nothing. ?>
                                <?php elseif ($row['status'] === 'active'): ?>
                                    <li>
                                        <?php // A button, not a data-confirm form: the flow
                                              // asks for the Last Working Date first. ?>
                                        <button class="dropdown-item text-danger" type="button"
                                                data-action="deactivate-driver"
                                                data-url="<?= e(url('drivers/' . (int)$row['id'] . '/deactivate')) ?>"
                                                data-driver="<?= e($row['driver_name']) ?>"
                                                data-badge="<?= e($row['badge_number']) ?>"
                                                data-return="<?= e($gridReturn) ?>">
                                            <i class="bi bi-person-dash me-2"></i>Deactivate
                                        </button>
                                    </li>
                                <?php else: ?>
                                    <li>
                                        <form method="post"
                                              action="<?= e(url('drivers/' . (int)$row['id'] . '/activate')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="dropdown-item" type="submit">
                                                <i class="bi bi-person-check me-2"></i>Reactivate
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                                <?php if (can('drivers.delete')): ?>
                                    <li>
                                        <form method="post"
                                              action="<?= e(url('drivers/' . (int)$row['id'] . '/delete')) ?>"
                                              data-confirm="Delete <?= e($row['driver_name']) ?> permanently? A driver with leave records cannot be deleted - deactivate instead.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return_to" value="drivers">
                                            <button class="dropdown-item text-danger" type="submit">
                                                <i class="bi bi-trash me-2"></i>Delete
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </td>

                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer bg-white border-top-0">
        <form method="get" action="<?= e(url('drivers')) ?>">
            <?php foreach (($query ?? []) as $key => $value): ?>
                <?php if (!in_array($key, ['page', 'per_page'], true)): ?>
                    <input type="hidden" name="<?= e((string)$key) ?>" value="<?= e((string)$value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?= pagination((int)$total, per_page(), page_no(), $query ?? []) ?>
        </form>
    </div>
</div>

