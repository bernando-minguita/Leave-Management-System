<?php
/**
 * Generic confirmation dialog. Trigger it from any element carrying:
 *   data-confirm="Question to ask?"
 *   data-confirm-href="/url/to/confirm"   (GET navigation)  OR
 *   data-confirm-form="#someForm"         (submit that form)
 */
?>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true" aria-labelledby="confirmTitle">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmTitle">Are you sure?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="confirmBody">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" id="confirmProceed">Confirm</button>
            </div>
        </div>
    </div>
</div>
