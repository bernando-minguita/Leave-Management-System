<?php
/**
 * Shared modals, rendered once by the layout:
 *   #markReturnedModal  - quick return (spec section 18)
 *   #cancelLeaveModal   - cancel with a required reason
 *   #notReturnedModal   - close a leave as a non-return (four facts)
 *   #changeStatusModal  - administrator correction of a closed record
 * See modals-confirm.php for the generic confirmation dialog.
 */
?>

<!-- ============================ MARK RETURNED ============================ -->
<div class="modal fade" id="markReturnedModal" tabindex="-1" aria-hidden="true" aria-labelledby="markReturnedTitle">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="markReturnedForm" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="markReturnedTitle">
                    <i class="bi bi-check2-circle me-2 text-success"></i>Mark Driver as Returned
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="id" id="mr_id" value="">

                <dl class="row mb-3">
                    <dt class="col-sm-5 text-body-secondary fw-normal">Driver</dt>
                    <dd class="col-sm-7 mb-1" id="mr_driver">—</dd>

                    <dt class="col-sm-5 text-body-secondary fw-normal">Badge</dt>
                    <dd class="col-sm-7 mb-1" id="mr_badge">—</dd>

                    <dt class="col-sm-5 text-body-secondary fw-normal">Expected Return</dt>
                    <dd class="col-sm-7 mb-0"><strong id="mr_expected">—</strong></dd>
                </dl>

                <div class="mb-3">
                    <label class="form-label" for="mr_actual">
                        Actual Arrival Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control" id="mr_actual" name="actual_arrival_date"
                           required value="<?= e(today()) ?>">
                    <div class="invalid-feedback" data-error-for="actual_arrival_date"></div>
                    <div class="form-text">Defaults to today (<?= e(fmt_date(today())) ?>).</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="mr_time">Actual Arrival Time</label>
                    <input type="time" class="form-control" id="mr_time" name="actual_arrival_time"
                           value="<?= e(date('H:i')) ?>">
                </div>

                <div class="mb-0">
                    <label class="form-label" for="mr_remarks">Return Remarks</label>
                    <textarea class="form-control" id="mr_remarks" name="return_remarks"
                              rows="2" maxlength="500"
                              placeholder="Flight, delay, condition of arrival..."></textarea>
                </div>

                <div class="alert alert-danger d-none mt-3 mb-0" id="mr_error" role="alert"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success" id="mr_submit">
                    <span class="spinner-border spinner-border-sm d-none me-1" aria-hidden="true"></span>
                    Save Return
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================== CANCEL LEAVE =========================== -->
<div class="modal fade" id="cancelLeaveModal" tabindex="-1" aria-hidden="true" aria-labelledby="cancelLeaveTitle">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="cancelLeaveForm" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="cancelLeaveTitle">
                    <i class="bi bi-slash-circle me-2 text-danger"></i>Cancel Leave Record
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="id" id="cl_id" value="">
                <p class="mb-3">
                    You are about to cancel the leave for <strong id="cl_driver">—</strong>.
                    The record stays in history but stops counting towards current leave.
                </p>

                <div class="mb-0">
                    <label class="form-label" for="cl_reason">
                        Reason <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" id="cl_reason" name="cancel_reason" rows="3"
                              maxlength="255" required
                              placeholder="Why is this leave being cancelled?"></textarea>
                    <div class="invalid-feedback" data-error-for="cancel_reason"></div>
                </div>

                <div class="alert alert-danger d-none mt-3 mb-0" id="cl_error" role="alert"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep Record</button>
                <button type="submit" class="btn btn-danger" id="cl_submit">
                    <span class="spinner-border spinner-border-sm d-none me-1" aria-hidden="true"></span>
                    Cancel Leave
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================ NOT RETURNED ============================= -->
<!-- The four facts (reason, date, by whom, remarks) are mandatory, so this is
     a form rather than a confirm() - there is nowhere to type into a dialog. -->
<div class="modal fade" id="notReturnedModal" tabindex="-1" aria-hidden="true" aria-labelledby="notReturnedTitle">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="notReturnedForm" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="notReturnedTitle">
                    <i class="bi bi-person-x me-2 text-warning"></i>Close as Not Returned
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="id" id="nr_id" value="">

                <p class="mb-3">
                    Close the leave for <strong id="nr_driver">—</strong>
                    (badge <span id="nr_badge">—</span>, expected back
                    <strong id="nr_expected">—</strong>) as <strong>Not Returned</strong>.
                    No arrival date is recorded.
                </p>

                <div class="mb-3">
                    <label class="form-label" for="nr_reason">
                        Not Returned Reason <span class="text-danger">*</span>
                    </label>
                    <select class="form-select" id="nr_reason" name="not_returned_reason" required>
                        <option value="">Choose a reason…</option>
                        <?php foreach (\App\Models\LeaveRecord::NOT_RETURNED_REASONS as $reason): ?>
                            <option value="<?= e($reason) ?>"><?= e($reason) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback" data-error-for="not_returned_reason"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="nr_date">
                        Not Returned Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control" id="nr_date" name="not_returned_date"
                           max="<?= e(today()) ?>" required>
                    <div class="invalid-feedback" data-error-for="not_returned_date"></div>
                    <div class="form-text">When management confirmed the decision. Defaults to today.</div>
                </div>

                <div class="mb-0">
                    <label class="form-label" for="nr_remarks">
                        Remarks <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" id="nr_remarks" name="not_returned_remarks" rows="3"
                              maxlength="500" required
                              placeholder="What was decided, and on what basis?"></textarea>
                    <div class="invalid-feedback" data-error-for="not_returned_remarks"></div>
                </div>

                <div class="alert alert-danger d-none mt-3 mb-0" id="nr_error" role="alert"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep Open</button>
                <button type="submit" class="btn btn-warning" id="nr_submit">
                    <span class="spinner-border spinner-border-sm d-none me-1" aria-hidden="true"></span>
                    Close as Not Returned
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================ CHANGE STATUS ============================= -->
<!-- Administrator correction for a CLOSED record (Not Returned / Cancelled).
     Because the status engine re-derives from the dates, the modal collects
     the dates that make the chosen status stick - the server rejects any
     combination Status::sync() would immediately undo. -->
<div class="modal fade" id="changeStatusModal" tabindex="-1" aria-hidden="true" aria-labelledby="changeStatusTitle">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="changeStatusForm" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="changeStatusTitle">
                    <i class="bi bi-arrow-repeat me-2 text-primary"></i>Change Leave Status
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="id" id="cs_id" value="">

                <dl class="row mb-3">
                    <dt class="col-sm-4 text-body-secondary fw-normal">Driver</dt>
                    <dd class="col-sm-8 mb-1" id="cs_driver">—</dd>

                    <dt class="col-sm-4 text-body-secondary fw-normal">Badge</dt>
                    <dd class="col-sm-8 mb-1" id="cs_badge">—</dd>

                    <dt class="col-sm-4 text-body-secondary fw-normal">Current status</dt>
                    <dd class="col-sm-8 mb-0"><strong id="cs_current">—</strong></dd>
                </dl>

                <div class="mb-3">
                    <label class="form-label" for="cs_target">
                        New status <span class="text-danger">*</span>
                    </label>
                    <select class="form-select" id="cs_target" name="new_status" required>
                        <option value="returned">Returned — the driver is back</option>
                        <option value="on_leave">On leave — reopen; the driver is still out</option>
                        <option value="planned">Planned — the trip had not started</option>
                    </select>
                    <div class="invalid-feedback" data-error-for="new_status"></div>
                </div>

                <div class="mb-3 d-none" id="cs_actual_group">
                    <label class="form-label" for="cs_actual">
                        Actual Arrival Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control" id="cs_actual" name="actual_arrival_date" value="">
                    <div class="invalid-feedback" data-error-for="actual_arrival_date"></div>
                    <div class="form-text">When the driver actually got back. Defaults to today.</div>
                </div>

                <div class="mb-3 d-none" id="cs_expected_group">
                    <label class="form-label" for="cs_expected">New Expected Return Date</label>
                    <input type="date" class="form-control" id="cs_expected" name="expected_arrival_date" value="">
                    <div class="invalid-feedback" data-error-for="expected_arrival_date"></div>
                    <div class="form-text">
                        Required when the current expected date has already passed (the leave
                        was extended); leave empty to keep the existing date.
                    </div>
                </div>

                <div class="mb-0">
                    <label class="form-label" for="cs_remarks">Remarks</label>
                    <textarea class="form-control" id="cs_remarks" name="remarks" rows="2" maxlength="500"
                              placeholder="Why is this status being corrected? Kept in the audit log."></textarea>
                    <div class="invalid-feedback" data-error-for="remarks"></div>
                </div>

                <div class="alert alert-danger d-none mt-3 mb-0" id="cs_error" role="alert"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep Status</button>
                <button type="submit" class="btn btn-primary" id="cs_submit">
                    <span class="spinner-border spinner-border-sm d-none me-1" aria-hidden="true"></span>
                    Apply Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Deactivate driver ----------------------------------------------------- -->
<?php // Unlike the other modals this one posts to the server as a plain form:
      // the Last Working Date has to be validated before anything is written,
      // and a full page round-trip keeps the flash + redirect pattern every
      // other driver action uses. The trigger supplies the action URL. ?>
<div class="modal fade" id="deactivateDriverModal" tabindex="-1" aria-hidden="true"
     aria-labelledby="deactivateDriverTitle">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="deactivateDriverForm" method="post" action="" novalidate>
            <?php // csrf_check() runs in DriverController::deactivate() like every
                  // other mutating action. This was the ONE form in the app that
                  // shipped without the token, so every deactivation posted a
                  // blank _csrf and was refused (403). ?>
            <?= csrf_field() ?>

            <div class="modal-header">
                <h5 class="modal-title" id="deactivateDriverTitle">Deactivate driver</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="return_to" id="dd_return_to" value="">

                <p class="mb-3">
                    You are about to deactivate <strong id="dd_driver">—</strong>
                    (badge <span id="dd_badge">—</span>). Every leave record stays in history;
                    the driver simply stops appearing as available.
                </p>

                <div class="mb-0">
                    <label class="form-label" for="dd_lwd">Last Working Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="dd_lwd" name="last_working_date" required>
                    <div class="form-text">
                        The last day this driver actually worked. They keep counting in Capacity
                        Planning, the driver totals and the leave pickers up to and including this
                        day, and leave counting from the day after it.
                    </div>
                </div>

                <div class="alert alert-danger d-none mt-3 mb-0" id="dd_error" role="alert"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger" id="dd_submit">
                    <i class="bi bi-person-dash me-1"></i>Deactivate
                </button>
            </div>
        </form>
    </div>
</div>

