<?php
/**
 * Action menu for a leave record row.
 *
 * Expects: $row (leave record with joined driver columns)
 * Renders View / Edit / Mark Returned / Cancel as appropriate.
 */
$row  = $row ?? [];
$id   = (int)($row['id'] ?? 0);
$status = (string)($row['status'] ?? '');
$open   = $status !== 'cancelled';

// Server rule, not a second opinion: planned trips are excluded because the
// driver has not left yet. LeaveRecord::canMarkReturned() is what the API
// enforces, so the menu and the endpoint can never disagree.
$canReturn = \App\Models\LeaveRecord::canMarkReturned($row);

// The mirror image of Mark Returned: close the leave out with no arrival.
// Same permission, but a different eligibility rule - canMarkNotReturned()
// is the server-side truth both this menu and the endpoint use.
$canNotReturn = can('leave.return')
    && \App\Models\LeaveRecord::canMarkNotReturned($row);

// Administrator valve for the dead-end statuses: same gate the API uses
// (route map: settings.system), same eligibility rule server-side.
$canChange = can('settings.system')
    && \App\Models\LeaveRecord::canChangeStatus($row);

// Permissions, never roles. A viewer only ever gets to look; Operations is
// granted leave.cancel and leave.edit only if an administrator adds them,
// which is exactly what the spec asks for.
$canEdit   = can('leave.edit') && $open;
$canMark   = can('leave.return') && $canReturn;
$canCancel = can('leave.cancel') && $open;
$canDelete = can('leave.delete');

// View is the one entry every role gets; everything else is a grant. When
// this reader holds none of them - Operations on Leave Records, Viewer
// anywhere - the menu would open on a single item, and the eye the reader
// came for stays buried one click away, where the other leave lists show it
// outright. Render the eye itself in that case and stop: no menu, no
// empty dropdown.
$viewOnly = !($canEdit || $canMark || $canNotReturn || $canChange
    || $canCancel || $canDelete);

if ($viewOnly): ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('leave/' . $id)) ?>"
       title="View"><i class="bi bi-eye"></i></a>
<?php return; endif; ?>


<div class="dropdown d-inline-block text-nowrap">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
            data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li>
            <a class="dropdown-item" href="<?= e(url('leave/' . $id)) ?>">
                <i class="bi bi-eye me-2"></i>View
            </a>
        </li>
        <?php if ($canEdit): ?>
            <li>
                <a class="dropdown-item" href="<?= e(url('leave/' . $id . '/edit')) ?>">
                    <i class="bi bi-pencil me-2"></i>Edit
                </a>
            </li>
        <?php endif; ?>
        <?php if ($canMark): ?>
            <li>
                <button class="dropdown-item" type="button"
                        data-action="mark-returned"
                        data-id="<?= $id ?>"
                        data-driver="<?= e((string)($row['driver_name'] ?? '')) ?>"
                        data-badge="<?= e((string)($row['badge_number'] ?? '')) ?>"
                        data-expected="<?= e((string)($row['expected_arrival_date'] ?? '')) ?>"
                        data-start="<?= e((string)($row['leave_start_date'] ?? '')) ?>">
                    <i class="bi bi-check2-circle me-2"></i>Mark Returned
                </button>
            </li>
        <?php endif; ?>
        <?php if ($canNotReturn): ?>
            <li>
                <?php // Opens the modal: reason, date and remarks are all
                      // required, so there is nowhere to type in a confirm(). ?>
                <button class="dropdown-item text-warning" type="button"
                        data-action="not-returned"
                        data-id="<?= $id ?>"
                        data-driver="<?= e((string)($row['driver_name'] ?? '')) ?>"
                        data-badge="<?= e((string)($row['badge_number'] ?? '')) ?>"
                        data-expected="<?= e((string)($row['expected_arrival_date'] ?? '')) ?>"
                        data-start="<?= e((string)($row['leave_start_date'] ?? '')) ?>">
                    <i class="bi bi-person-x me-2"></i>Not Returned
                </button>
            </li>
        <?php endif; ?>
        <?php if ($canChange): ?>
            <li>
                <?php // Corrects a mistaken closure (the driver reappears, the
                      // leave was extended). Opens the modal, which collects
                      // the dates that keep the new status stable. ?>
                <button class="dropdown-item" type="button"
                        data-action="change-status"
                        data-id="<?= $id ?>"
                        data-driver="<?= e((string)($row['driver_name'] ?? '')) ?>"
                        data-badge="<?= e((string)($row['badge_number'] ?? '')) ?>"
                        data-status-label="<?= e(status_label($status)) ?>"
                        data-start="<?= e((string)($row['leave_start_date'] ?? '')) ?>"
                        data-expected="<?= e((string)($row['expected_arrival_date'] ?? '')) ?>">
                    <i class="bi bi-arrow-repeat me-2"></i>Change Status
                </button>
            </li>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <li><hr class="dropdown-divider"></li>
            <li>
                <button class="dropdown-item text-danger" type="button"
                        data-action="cancel-leave"
                        data-id="<?= $id ?>"
                        data-driver="<?= e((string)($row['driver_name'] ?? '')) ?>">
                    <i class="bi bi-slash-circle me-2"></i>Cancel Leave
                </button>
            </li>
        <?php endif; ?>

        <?php if ($canDelete): ?>
            <?php if (!$canCancel): ?>
                <li><hr class="dropdown-divider"></li>
            <?php endif; ?>
            <li>
                <form method="post"
                      action="<?= e(url('leave/' . $id . '/delete')) ?>"
                      data-confirm="Delete this leave record for <?= e((string)($row['driver_name'] ?? '')) ?>? The driver and leave type are kept, but the record cannot be recovered.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="<?= e((string)($returnTo ?? 'leave/records')) ?>">
                    <button class="dropdown-item text-danger" type="submit">
                        <i class="bi bi-trash me-2"></i>Delete
                    </button>
                </form>
            </li>
        <?php endif; ?>
    </ul>
</div>
