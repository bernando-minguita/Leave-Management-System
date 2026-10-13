<?php
/**
 * New / edit leave application.
 *
 * Dates are the three this register actually keeps: application date, the last
 * working day and the expected return - deliberately not the leave-record set.
 *
 * Uses: $application (null on create), $driver, $errors, $old,
 *       $branches, $leaveTypes, $drivers
 */
$application = $application ?? null;
$driver      = $driver ?? null;
$errors      = $errors ?? [];
$old         = $old ?? [];
$isEdit      = $application !== null;

$val = static function (string $field, $default = '') use ($old, $application): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($application[$field] ?? $default);
};

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? ' is-invalid' : '';
};

$errmsg = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<div class="invalid-feedback">' . e($errors[$field]) . '</div>'
        : '';
};
?>

<?= View::partial('partials/page-head', [
    'title'       => $isEdit ? 'Edit Leave Application' : 'New Leave Application',
    'description' => $isEdit
        ? 'Application #' . (int) $application['id'] . ' · ' . (string) ($driver['driver_name'] ?? '')
        : 'Record a leave or vacation request and the decision taken on it.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="'
        . e(url('leave-applications')) . '">'
        . '<i class="bi bi-x-circle me-1"></i>Cancel</a>',
]) ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <strong>Please correct the following:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $field => $message): ?>
                <li><strong><?= e(ucwords(str_replace('_', ' ', (string) $field))) ?>:</strong> <?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" novalidate id="leaveForm" data-edit-id="<?= $isEdit ? (int) $application['id'] : 0 ?>"
      action="<?= e($isEdit ? url('leave-applications/' . (int) $application['id'] . '/edit') : url('leave-applications/new')) ?>">
    <?= csrf_field() ?>

    <!-- Who and what --------------------------------------------------- -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Application</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="driverPicker">
                        Driver <span class="text-danger">*</span>
                    </label>
                    <div class="position-relative" id="driverPickerWrap">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control<?= $err('driver_id') ?>" id="driverPicker"
                                   autocomplete="off" placeholder="Badge / Name / Iqama number"
                                   value="<?= e($driver !== null
                                       ? ($driver['driver_name'] . ' (' . $driver['badge_number'] . ')')
                                       : $val('driver_name')) ?>"
                                   <?= $driver !== null ? 'readonly' : '' ?>>
                            <button class="btn btn-outline-secondary" type="button" id="clearDriver"
                                    title="Choose a different driver"><i class="bi bi-x"></i></button>
                        </div>
                        <div class="dropdown-menu w-100 p-0 mt-1 shadow" id="driverResults"
                             style="max-height: 320px; overflow-y: auto;"></div>
                    </div>
                    <input type="hidden" name="driver_id" id="driver_id"
                           value="<?= e($val('driver_id', (string) ($driver['id'] ?? ''))) ?>">
                    <div class="invalid-feedback d-block" id="driverPickerError" hidden>
                        Please choose a driver from the search results below.
                    </div>
                    <?= $errmsg('driver_id') ?>
                    <div class="form-text">Type at least 2 characters - search by badge, name, iqama or mobile.</div>

                    <!-- Driver details: always visible, filled once a driver is picked. -->
                    <div class="mt-3 border rounded overflow-hidden" id="driverCard">
                        <div class="bg-body-secondary px-3 py-2 d-flex align-items-center gap-2">
                            <h3 class="h6 mb-0">Driver Information</h3>
                            <span class="badge text-bg-secondary ms-auto" id="dc_state">Not selected</span>
                        </div>
                        <div class="row g-0">
                            <div class="col-6 col-md-4 border-top p-3">
                                <div class="small text-body-secondary">Driver Name</div>
                                <strong id="dc_name" class="d-block">&mdash;</strong>
                            </div>
                            <div class="col-6 col-md-4 border-top border-start-md p-3">
                                <div class="small text-body-secondary">Badge No.</div>
                                <strong id="dc_badge" class="d-block">&mdash;</strong>
                            </div>
                            <div class="col-6 col-md-4 border-top border-start p-3">
                                <div class="small text-body-secondary">Iqama No.</div>
                                <strong id="dc_iqama" class="d-block" dir="ltr">&mdash;</strong>
                            </div>
                            <div class="col-6 col-md-4 border-top p-3">
                                <div class="small text-body-secondary">Passport No.</div>
                                <strong id="dc_passport" class="d-block" dir="ltr">&mdash;</strong>
                            </div>
                            <div class="col-6 col-md-4 border-top border-start-md p-3">
                                <div class="small text-body-secondary">Nationality</div>
                                <strong id="dc_nationality" class="d-block">&mdash;</strong>
                            </div>
                            <div class="col-6 col-md-4 border-top border-start p-3">
                                <div class="small text-body-secondary">Position</div>
                                <strong id="dc_position" class="d-block">&mdash;</strong>
                            </div>
                            <div class="col-6 col-md-4 border-top p-3">
                                <div class="small text-body-secondary">Agency</div>
                                <strong id="dc_agency" class="d-block">&mdash;</strong>
                            </div>
                            <div class="col-6 col-md-4 border-top border-start-md p-3">
                                <div class="small text-body-secondary">Branch</div>
                                <strong id="dc_branch" class="d-block">&mdash;</strong>
                            </div>
                            <div class="col-6 col-md-4 border-top border-start p-3">
                                <div class="small text-body-secondary">Mobile</div>
                                <strong id="dc_mobile" class="d-block" dir="ltr">&mdash;</strong>
                            </div>
                            <div class="col-6 col-md-4 border-top p-3">
                                <div class="small text-body-secondary">Status</div>
                                <strong id="dc_status" class="d-block">&mdash;</strong>
                            </div>
                        </div>
                        <div class="px-3 py-2 small text-body-secondary border-top" id="dc_hint">
                            Search above and pick a driver from the list to fill in these details.
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="leave_type_id">
                        Leave Type <span class="text-danger">*</span>
                    </label>
                    <select class="form-select<?= $err('leave_type_id') ?>" id="leave_type_id"
                            name="leave_type_id" required>
                        <option value="">— Select leave type —</option>
                        <?= options($leaveTypes ?? [], $val('leave_type_id')) ?>
                    </select>
                    <?= $errmsg('leave_type_id') ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="branch_id">Branch</label>
                    <select class="form-select<?= $err('branch_id') ?>" id="branch_id" name="branch_id">
                        <option value="">— No branch —</option>
                        <?= options($branches ?? [], $val('branch_id', (string) ($driver['branch_id'] ?? ''))) ?>
                    </select>
                    <?= $errmsg('branch_id') ?>
                    <div class="form-text">Snapshot for this application, not read live.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="status">Approval Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (\App\Models\LeaveApplication::STATUSES as $s): ?>
                            <option value="<?= e($s) ?>"
                                <?= $val('status', 'pending') === $s ? 'selected' : '' ?>>
                                <?= e(status_label($s)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">New applications start as Pending.</div>
                </div>

                <?php
                // Leave Status is not a decision the form makes: it follows the
                // leave record this application points at, and the controller
                // already keeps whatever that record holds. It is posted as a
                // hidden field so a create still carries a value, but it is no
                // longer shown - an editable control here could only ever
                // disagree with the record that owns it (requirement 3).
                $linkedLeaveId = (int) ($application['linked_leave_id'] ?? 0);
                ?>
                <input type="hidden" name="leave_status"
                       value="<?= e($val('leave_status', 'not_started')) ?>">
            </div>
        </div>
    </div>

    <!-- Dates ----------------------------------------------------------- -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Dates</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="application_date">
                        Application Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control<?= $err('application_date') ?>"
                           id="application_date" name="application_date" required
                           value="<?= e($val('application_date', today())) ?>">
                    <?= $errmsg('application_date') ?>
                    <div class="form-text">When the application was made.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="last_working_date">
                        Last Working Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control<?= $err('last_working_date') ?>"
                           id="last_working_date" name="last_working_date" required
                           value="<?= e($val('last_working_date')) ?>">
                    <?= $errmsg('last_working_date') ?>
                    <div class="form-text">Final day worked. When an arrival date is given it must fall on or before it.</div>
                </div>

                <?php // Resignation / Terminated drivers do not come back, so
                      // the arrival question is dropped from the form entirely.
                      // LeaveApplicationController::payload() applies the same
                      // rule server side, so a value already sitting in the
                      // browser cannot survive the round trip. ?>
                <div class="col-md-3" id="expectedArrivalWrap" data-no-return-wrap>
                    <label class="form-label" for="expected_arrival_date">Expected Return Date</label>
                    <input type="date" class="form-control<?= $err('expected_arrival_date') ?>"
                           id="expected_arrival_date" name="expected_arrival_date"
                           value="<?= e($val('expected_arrival_date')) ?>">
                    <?= $errmsg('expected_arrival_date') ?>
                    <div class="form-text">Optional - when the driver is due back.</div>
                </div>

                <?php // Marked so leave.js hides it beside the arrival field:
                      // both describe a return journey, so neither is shown for
                      // a leave type the driver never comes back from. ?>
                <div class="col-md-3" id="daysAwayWrap" data-no-return-wrap>
                    <label class="form-label" for="daysAway">Duration (info only)</label>
                    <input type="text" class="form-control" id="daysAway" readonly
                           value="<?= e(app_duration($val('last_working_date'), $val('expected_arrival_date'))) ?>"
                           aria-describedby="daysAwayHelp">
                    <div class="form-text" id="daysAwayHelp">
                        Last working date to expected return. Informational - this system does not track leave balances.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Details --------------------------------------------------------- -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Details</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="destination">Destination Country</label>
                    <?php // Countries come from Settings as a PLAIN list - the name
                          // is both the value and the label, so nothing stored
                          // before this field became a dropdown stops matching.
                          // "Not set" is the empty option, not a member of it.
                          // Read once: the same query would otherwise run twice. ?>
                    <?php $countries = \App\Models\Lookup::destinationCountries(); ?>
                    <select class="form-select<?= $err('destination') ?>"
                            id="destination" name="destination">
                        <option value="">— Not set —</option>
                        <?php foreach ($countries as $country): ?>
                            <option value="<?= e($country) ?>"
                                <?= $val('destination') === $country ? 'selected' : '' ?>>
                                <?= e($country) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php // A value the list does not carry - filed before it
                              // existed, or trimmed since - still needs its own
                              // option, or the select would fall back to Not set
                              // and save the row with its destination wiped. ?>
                        <?php if ($val('destination') !== ''
                              && !in_array($val('destination'), $countries, true)): ?>
                            <option value="<?= e($val('destination')) ?>" selected>
                                <?= e($val('destination')) ?>
                            </option>
                        <?php endif; ?>
                    </select>
                    <?= $errmsg('destination') ?>
                    <div class="form-text">Pick a country, or leave it as Not set.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="reason">Reason</label>
                    <input type="text" class="form-control" id="reason" name="reason"
                           maxlength="255" value="<?= e($val('reason')) ?>"
                           placeholder="e.g. Annual vacation">
                    <?= $errmsg('reason') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="remarks">Remarks</label>
                    <input type="text" class="form-control" id="remarks" name="remarks"
                           maxlength="500" value="<?= e($val('remarks')) ?>">
                    <?= $errmsg('remarks') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Save Changes' : 'Record Application' ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= e(url('leave-applications')) ?>">
            <i class="bi bi-x-circle me-1"></i>Cancel
        </a>
    </div>
</form>

<script>
    // Only search + detail are needed here: with no `overlap` key leave.js's
    // overlap check no-ops, which is right - an application is not a leave
    // record yet. It also skips required fields this form does not have.
    window.LEAVE_URLS = {
        search: <?= json_encode(url('api/drivers/search')) ?>,
        detail: <?= json_encode(url('api/drivers/detail')) ?>
    };
</script>
<script src="<?= e(url('assets/js/leave.js')) ?>"></script>
