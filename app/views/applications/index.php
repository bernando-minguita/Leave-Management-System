<?php
/**
 * Leave Applications - the register of leave / vacation requests and the
 * decision taken on each. Deliberately separate from Leave Records: nothing
 * here reaches the dashboard or the calendar.
 *
 * Uses: $rows, $total, $filters, $sort, $query, $counts, $branches, $leaveTypes
 */
$rows      = $rows ?? [];
$total     = (int) ($total ?? 0);
$filters   = $filters ?? [];
$counts    = $counts ?? [];
$branches  = $branches ?? [];
$leaveTypes = $leaveTypes ?? [];

// The filters below are checkbox multi-selects, which want value => label
// maps. Built once here so the markup stays a single partial call each.
$statusPairs = array_combine(
    \App\Models\LeaveApplication::STATUSES,
    array_map(
        static fn (string $s): string => status_label($s),
        \App\Models\LeaveApplication::STATUSES
    )
);
$leaveStatusPairs = array_combine(
    \App\Models\LeaveApplication::LEAVE_STATUSES,
    array_map(
        static fn (string $s): string => status_label($s),
        \App\Models\LeaveApplication::LEAVE_STATUSES
    )
);

$canCreate = can('leave.create');
$canEdit   = can('leave.edit');
$canDelete = can('leave.delete');
// View is the one permission every role able to open this page holds, so the
// Actions cell is never blank - a viewer still needs a way in.
$canView   = can('leave.view');

$chipUrl = static function (string $status) use ($filters): string {
    // sort_params() as well: a chip is a GET that must keep the column order,
    // not hand the reader back a register sorted by the default again.
    $q = sort_params(array_filter([
        'status'        => $status,
        'leave_status'  => $filters['leave_status'] ?? '',
        'q'             => $filters['q'] ?? '',
        'branch_id'     => $filters['branch_id'] ?? '',
        'leave_type_id' => $filters['leave_type_id'] ?? '',
        'date_field'    => $filters['date_field'] ?? '',
        'date_from'     => $filters['date_from'] ?? '',
        'date_to'       => $filters['date_to'] ?? '',
    ], static fn ($v) => $v !== '' && $v !== null));

    return url('leave-applications') . ($q === [] ? '' : '?' . http_build_query($q));
};
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Leave Applications',
    'description' => number_format($total) . ' application(s) recorded.',
    'actions'     => '<a class="btn btn-outline-success btn-sm" href="'
        . e(url('leave-applications/export?'
            . http_build_query(sort_params(array_merge($filters, ['export' => 'xls']))))) . '">'
        . '<i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
        . ($canCreate
            ? '<a class="btn btn-primary btn-sm" href="' . e(url('leave-applications/new')) . '">'
              . '<i class="bi bi-plus-circle me-1"></i>New Application</a>'
            : ''),
]) ?>

<!-- Status chips -------------------------------------------------------- -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php
    $chips = [
        ''        => ['All', count($counts) > 0 ? array_sum($counts) : $total, 'text-bg-light border'],
        'pending' => ['Pending',  (int) ($counts['pending']  ?? 0), 'text-bg-secondary'],
        'approved'=> ['Approved', (int) ($counts['approved'] ?? 0), 'text-bg-success'],
        'rejected'=> ['Rejected', (int) ($counts['rejected'] ?? 0), 'text-bg-danger'],
        'cancelled'=> ['Cancelled', (int) ($counts['cancelled'] ?? 0), 'text-bg-dark'],
    ];
    $active = (string) ($filters['status'] ?? '');

    // The chip must NOT carry `text-reset`: it is `color: inherit !important`
    // and sits AFTER .text-bg-* in Bootstrap, so it overrode each badge's own
    // contrast colour - on text-bg-dark that meant dark text on a black pill.
    foreach ($chips as $status => [$label, $n, $tone]): ?>
        <a class="badge rounded-pill <?= e($tone) ?> text-decoration-none px-3 py-2"
           href="<?= e($chipUrl($status)) ?>"
           <?= $status === $active ? 'aria-current="page"' : '' ?>>
            <?= e($label) ?> <span class="fw-semibold"><?= (int) $n ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Filters -------------------------------------------------------------- -->
<form method="get" action="<?= e(url('leave-applications')) ?>" class="card border-0 shadow-sm mb-3 filter-card">
    <div class="card-body py-3">
        <!-- Six controls at col-md-2 = exactly 12, so every filter lands on
             ONE row from 768px up; col-6 keeps them two-up on small screens.
             The buttons sit below so the row stays a single line. -->
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md">
                <label class="form-label small mb-1" for="f_q">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="f_q" name="q"
                           value="<?= e($filters['q'] ?? '') ?>"
                           placeholder="Name, badge or iqama">
                </div>
            </div>

            <?= View::partial('partials/check-filter', [
                'name'        => 'status',
                'label'       => 'Approval Status',
                'pairs'       => $statusPairs,
                'selected'    => $filters['status'] ?? '',
                'placeholder' => 'All approval statuses',
                'col'         => 'col-6 col-md',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name'        => 'leave_status',
                'label'       => 'Leave Status',
                'pairs'       => $leaveStatusPairs,
                'selected'    => $filters['leave_status'] ?? '',
                'placeholder' => 'All leave statuses',
                'col'         => 'col-6 col-md',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name'        => 'branch_id',
                'label'       => 'Branch',
                'pairs'       => $branches,
                'selected'    => $filters['branch_id'] ?? '',
                'placeholder' => 'All branches',
                'col'         => 'col-6 col-md',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name'        => 'leave_type_id',
                'label'       => 'Leave Type',
                'pairs'       => $leaveTypes,
                'selected'    => $filters['leave_type_id'] ?? '',
                'placeholder' => 'All leave types',
                'col'         => 'col-6 col-md',
            ]) ?>

            <?= View::partial('partials/check-filter', [
                'name'  => 'date_field',
                'label' => 'Date field',
                // DATE_FIELDS is already value => label.
                'pairs' => \App\Models\LeaveApplication::DATE_FIELDS,
                // Last working date is the date this register is actually
                // about, so it is the default basis rather than the filing
                // date - nothing selected still means it downstream.
                'selected' => ($filters['date_field'] ?? '') !== ''
                    ? $filters['date_field'] : 'last_working_date',
                'placeholder' => 'Last working date',
                'col'         => 'col-6 col-md',
            ]) ?>

            <div class="col-6 col-md">
                <label class="form-label small mb-1" for="f_from">From</label>
                <input type="date" class="form-control form-control-sm" id="f_from"
                       name="date_from" value="<?= e($filters['date_from'] ?? '') ?>">
            </div>

            <div class="col-6 col-md">
                <label class="form-label small mb-1" for="f_to">To</label>
                <input type="date" class="form-control form-control-sm" id="f_to"
                       name="date_to" value="<?= e($filters['date_to'] ?? '') ?>">
            </div>
        </div>

        <?php if (($query['per_page'] ?? '') !== ''): ?>
            <input type="hidden" name="per_page" value="<?= e((string) $query['per_page']) ?>">
        <?php endif; ?>

        <?php // Same reason as per_page above: Apply must not drop the sort. ?>
        <?= hold_sort() ?>

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-funnel me-1"></i>Apply
            </button>
            <a href="<?= e(url('leave-applications')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-x-circle me-1"></i>Reset
            </a>
        </div>
    </div>
</form>

<!-- Table ---------------------------------------------------------------- -->
<div class="card border-0 shadow-sm">
    <?php // Same floor as Leave Records: with a single search hit the row
          // action dropdown opens downwards and used to be cut off at the
          // container edge. The 300px floor reserves the room (.table-grid). ?>
    <div class="table-responsive table-grid">
        <table class="table table-sm table-hover align-middle mb-0"
               data-dd-table
               data-dd-prefs="leave-applications"
               data-dd-size="<?= (int)per_page() ?>"
               data-dd-initial="<?= e(($sort[0] ?? '') . '|' . ($sort[1] ?? 'asc') . '|' . page_no()) ?>">
            <thead class="table-light">
            <tr>
                <th class="text-center">#</th>
                <?= sort_header('driver_name', 'Driver', $sort, $query ?? []) ?>
                <?= sort_header('badge_number', 'Badge', $sort, $query ?? []) ?>
                <?= sort_header('branch', 'Branch', $sort, $query ?? []) ?>
                <?= sort_header('leave_type', 'Leave Type', $sort, $query ?? []) ?>
                <?= sort_header('application_date', 'Applied', $sort, $query ?? []) ?>
                <?= sort_header('last_working_date', 'Last Working', $sort, $query ?? []) ?>
                <?= sort_header('expected_arrival_date', 'Expected Return', $sort, $query ?? []) ?>
                <?php // Computed from the two dates on the row, so there is no
                      // column behind it and nothing to sort by. ?>
                <th title="Last working date to expected return - informational only">Duration</th>
                <?= sort_header('status', 'Approval Status', $sort, $query ?? []) ?>
                <?= sort_header('leave_status', 'Leave Status', $sort, $query ?? []) ?>
                <?php // When the row was FILED, as opposed to "Applied" above,
                      // which is the business date the user chose. ?>
                <?= sort_header('created_at', 'Date Created', $sort, $query ?? []) ?>
                <?php // Auxiliary columns, APPENDED after every existing one so
                      // no earlier column changes index - the saved widths and
                      // hidden choices are keyed by position, so inserting in
                      // the middle would silently re-point them.
                      //
                      // Hidden on the server so there is no flash of two extra
                      // columns before the script runs; table-prefs.js reads
                      // this default off the <th> on a reader's first visit and
                      // then owns it - untick/tick in the Columns menu from
                      // there on. ?>
                <?= sort_header('updated_at', 'Last Updated', $sort, $query ?? [], 'display:none') ?>
                <?= sort_header('created_by_name', 'Created By', $sort, $query ?? [], 'display:none') ?>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php // Rows ship whole: numbered in order, not from the URL's page. ?>
            <?php $rowNo = 1; ?>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="15">
                        <?= empty_state(
                            'bi-clipboard-x',
                            'No leave applications match your filters.',
                            'Adjust the filters above, or record a new application.',
                            $canCreate
                                ? '<a class="btn btn-primary btn-sm" href="' . e(url('leave-applications/new')) . '">'
                                  . '<i class="bi bi-plus-circle me-1"></i>New Application</a>'
                                : ''
                        ) ?>
                    </td>
                </tr>
            <?php else: foreach ($rows as $row): ?>
                <tr data-dd-row>
                    <td class="text-center"><?= $rowNo++ ?></td>
                    <td>
                        <a class="link-dark text-decoration-none fw-semibold"
                           href="<?= e(url('drivers/' . (int) $row['driver_id'])) ?>">
                            <?= e((string) $row['driver_name']) ?>
                        </a>
                    </td>
                    <td class="text-nowrap"><?= e((string) $row['badge_number']) ?></td>
                    <td class="text-nowrap"><?= e($row['branch_name'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= e((string) $row['leave_type']) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['application_date'])) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($row['last_working_date'])) ?></td>
                    <td class="text-nowrap fw-semibold"><?= e(fmt_date($row['expected_arrival_date'])) ?></td>
                    <td class="text-nowrap"><?= e(app_duration(
                        $row['last_working_date'] !== null ? (string) $row['last_working_date'] : null,
                        $row['expected_arrival_date'] !== null ? (string) $row['expected_arrival_date'] : null
                    )) ?></td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td><?= status_badge(\App\Models\LeaveApplication::hasPlannedTrip($row)
                        ? 'planned'
                        : (string) ($row['leave_status'] ?? '')) ?></td>
                    <td class="text-nowrap small"><?= e(fmt_date_time($row['created_at'] ?? null)) ?></td>
                    <?php // Same helper and same formatting as Date Created, so
                          // the two timestamps read as a pair. Hidden to match
                          // their headers. ?>
                    <td class="text-nowrap small" style="display:none"><?= e(fmt_date_time($row['updated_at'] ?? null)) ?></td>
                    <td class="text-nowrap small" style="display:none"><?= e($row['created_by_name'] ?? '—') ?></td>
                    <td class="text-end text-nowrap">
                        <div class="dropdown d-inline-block text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($canView): ?>
                                    <li>
                                        <a class="dropdown-item" href="<?= e(url('leave-applications/' . (int) $row['id'])) ?>"
                                           title="View"><i class="bi bi-eye me-2"></i>View</a>
                                    </li>
                                <?php endif; ?>
                                <?php // Requirement 5: an approved application whose leave has
                                      // not started gets the action that turns it into a record;
                                      // once a record exists the menu links to that instead.
                                      //
                                      // Requirement 9 wins over requirement 5: a deactivated
                                      // driver gains NO new leave, so the action is withdrawn
                                      // too. Offering it only to have save() refuse the POST
                                      // (with a redirect to the driver) is worse than not
                                      // offering it - and save() still enforces this for a
                                      // hand-typed URL. ?>
                                <?php $linkedLeave = (int)($row['linked_leave_id'] ?? 0); ?>
                                <?php if ($linkedLeave > 0): ?>
                                    <li>
                                        <a class="dropdown-item" href="<?= e(url('leave/' . $linkedLeave)) ?>">
                                            <i class="bi bi-file-earmark-check me-2"></i>
                                            Leave record #<?= $linkedLeave ?>
                                        </a>
                                    </li>
                                <?php elseif ((string)$row['status'] === 'approved'
                                    && (string)$row['leave_status'] === 'not_started'
                                    && (string)($row['driver_status'] ?? '') === 'active'
                                    && can('leave.create')): ?>
                                    <li>
                                        <a class="dropdown-item"
                                           href="<?= e(url('leave/new')) ?>?application_id=<?= (int)$row['id'] ?>&driver_id=<?= (int)$row['driver_id'] ?>">
                                            <i class="bi bi-plus-circle me-2"></i>Apply New Leave
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                    <li>
                                        <a class="dropdown-item" href="<?= e(url('leave-applications/' . (int) $row['id'] . '/edit')) ?>">
                                            <i class="bi bi-pencil me-2"></i>Edit</a>
                                    </li>
                                    <?php if ((string) $row['status'] !== 'approved'): ?>
                                        <li>
                                            <?php // Decisions change the record, so each asks first: data-confirm
                                                  // opens the shared confirmation modal (see app.js). ?>
                                            <form method="post"
                                                  action="<?= e(url('leave-applications/' . (int) $row['id'] . '/status')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="status" value="approved">
                                                <button type="submit" class="dropdown-item text-success"
                                                        data-confirm="Approve the leave application for <?= e((string) $row['driver_name']) ?>? Its status will become Approved.">
                                                    <i class="bi bi-check-lg me-2"></i>Approve
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ((string) $row['status'] !== 'rejected'): ?>
                                        <li>
                                            <form method="post"
                                                  action="<?= e(url('leave-applications/' . (int) $row['id'] . '/status')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="status" value="rejected">
                                                <button type="submit" class="dropdown-item text-danger"
                                                        data-confirm="Reject the leave application for <?= e((string) $row['driver_name']) ?>? Its status will become Rejected.">
                                                    <i class="bi bi-x-lg me-2"></i>Reject
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ((string) $row['status'] !== 'cancelled'): ?>
                                        <?php // One divider, in front of the danger group - same rule as the
                                              // leave-records menu in partials/row-actions.php. ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="post"
                                                  action="<?= e(url('leave-applications/' . (int) $row['id'] . '/status')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="status" value="cancelled">
                                                <button type="submit" class="dropdown-item text-danger"
                                                        data-confirm="Cancel the leave application for <?= e((string) $row['driver_name']) ?>? Its status will become Cancelled.">
                                                    <i class="bi bi-slash-circle me-2"></i>Cancel
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <?php if (!$canEdit || (string) $row['status'] === 'cancelled'): ?>
                                        <li><hr class="dropdown-divider"></li>
                                    <?php endif; ?>
                                    <li>
                                        <form method="post"
                                              action="<?= e(url('leave-applications/' . (int) $row['id'] . '/delete')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="dropdown-item text-danger"
                                                    data-confirm="Delete this leave application? This cannot be undone.">
                                                <i class="bi bi-trash me-2"></i>Delete
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer bg-white border-top-0">
        <form method="get" action="<?= e(url('leave-applications')) ?>">
            <?php foreach (($query ?? []) as $key => $value): ?>
                <?php if (!in_array($key, ['page', 'per_page'], true)): ?>
                    <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?= pagination($total, per_page(), page_no(), $query ?? []) ?>
        </form>
    </div>
</div>

