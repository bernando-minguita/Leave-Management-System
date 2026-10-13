<?php
/**
 * Small "Mark Returned" button used inside dashboard/list rows.
 * Uses: $row
 */
$row = $row ?? [];

// Permission first: a viewer holds no leave.return, so the button must not be
// drawn at all. This one guard covers Current Leave, Expected Returns and the
// dashboard "Returning Today" / "Overdue" cards - they all share this partial.
if (!can('leave.return')) {
    return;
}

// Only when the driver is actually away (Status::AWAY). A planned trip has
// not started, so there is nobody to return - same rule the API enforces.
if (!\App\Models\LeaveRecord::canMarkReturned($row)) {
    return;
}
?>

<button class="btn btn-sm btn-success" type="button"
        data-action="mark-returned"
        data-id="<?= (int)$row['id'] ?>"
        data-driver="<?= e((string)($row['driver_name'] ?? '')) ?>"
        data-badge="<?= e((string)($row['badge_number'] ?? '')) ?>"
        data-expected="<?= e((string)($row['expected_arrival_date'] ?? '')) ?>"
        data-start="<?= e((string)($row['leave_start_date'] ?? '')) ?>"
        title="Mark Returned">
    <i class="bi bi-check2-circle"></i>
    <span class="d-none d-xl-inline ms-1">Return</span>
</button>
