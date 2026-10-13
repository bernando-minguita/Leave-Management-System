<?php
/**
 * New / edit leave record.
 *
 * Uses: $leave (null on create), $driver, $errors, $old,
 *       $branches, $agencies, $leaveTypes
 */
$leave  = $leave ?? null;
$driver = $driver ?? null;
$errors = $errors ?? [];
$old    = $old ?? [];
$isEdit = $leave !== null;

$val = static function (string $field, $default = '') use ($old, $leave): string {
    if (array_key_exists($field, $old)) {
        return (string)$old[$field];
    }

    return (string)($leave[$field] ?? $default);
};

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? ' is-invalid' : '';
};

$errmsg = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<div class="invalid-feedback">' . e($errors[$field]) . '</div>'
        : '';
};

// Days between start and expected return - informational only, never a balance.
$duration = app_duration(
    $val('leave_start_date') !== '' ? $val('leave_start_date') : null,
    $val('expected_arrival_date') !== '' ? $val('expected_arrival_date') : null
);
?>

<?= View::partial('partials/page-head', [
    'title'       => $isEdit ? 'Edit Leave Record' : 'New Leave / Vacation',
    'description' => $isEdit
        ? 'Record #' . (int)$leave['id'] . ' · ' . (string)($driver['driver_name'] ?? '')
        : 'Record a driver going on leave. Status is derived from the dates.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="'
        . e($isEdit ? url('leave/' . (int)$leave['id']) : url('leave/records')) . '">'
        . '<i class="bi bi-x-circle me-1"></i>Cancel</a>',
]) ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <strong>Please correct the following:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $field => $message): ?>
                <li><strong><?= e(ucwords(str_replace('_', ' ', (string)$field))) ?>:</strong> <?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" novalidate id="leaveForm" data-edit-id="<?= $isEdit ? (int)$leave['id'] : 0 ?>"
      action="<?= e($isEdit ? url('leave/' . (int)$leave['id'] . '/edit') : url('leave/new')) ?>">
    <?= csrf_field() ?>

    <!-- Driver selection -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Driver</h2></div>
        <div class="card-body">
            <?php if ($isEdit): ?>
                <?php // id matters: the application picker resolves the driver with
                      // getElementById on the edit screen too, so a legacy record
                      // can pick up its application. ?>
                <input type="hidden" name="driver_id" id="driver_id"
                       value="<?= (int)$leave['driver_id'] ?>">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <div><div class="small text-body-secondary">Driver</div>
                        <strong><?= e($driver['driver_name'] ?? '—') ?></strong></div>
                    <div><div class="small text-body-secondary">Badge</div>
                        <strong><?= e($driver['badge_number'] ?? '—') ?></strong></div>
                    <div><div class="small text-body-secondary">Iqama</div>
                        <strong><?= e($driver['iqama_number'] ?? '—') ?></strong></div>
                    <div><div class="small text-body-secondary">Agency</div>
                        <strong><?= e($driver['agency_name'] ?? '—') ?></strong></div>
                    <div><div class="small text-body-secondary">Branch</div>
                        <strong><?= e($driver['branch_name'] ?? '—') ?></strong></div>
                    <div><div class="small text-body-secondary">Mobile</div>
                        <strong dir="ltr"><?= e($driver['mobile_number'] ?? '—') ?></strong></div>
                    <span class="ms-auto"><?= status_badge((string)$leave['status']) ?></span>
                </div>
            <?php else: ?>
                <label class="form-label" for="driverPicker">
                    Search Driver <span class="text-danger">*</span>
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
                       value="<?= e($driver !== null ? (string)$driver['id'] : $val('driver_id')) ?>">
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
                            <strong id="dc_name" class="d-block">—</strong>
                        </div>
                        <div class="col-6 col-md-4 border-top border-start-md p-3">
                            <div class="small text-body-secondary">Badge No.</div>
                            <strong id="dc_badge" class="d-block">—</strong>
                        </div>
                        <div class="col-6 col-md-4 border-top border-start p-3">
                            <div class="small text-body-secondary">Iqama No.</div>
                            <strong id="dc_iqama" class="d-block" dir="ltr">—</strong>
                        </div>
                        <div class="col-6 col-md-4 border-top p-3">
                            <div class="small text-body-secondary">Passport No.</div>
                            <strong id="dc_passport" class="d-block" dir="ltr">—</strong>
                        </div>
                        <div class="col-6 col-md-4 border-top border-start-md p-3">
                            <div class="small text-body-secondary">Nationality</div>
                            <strong id="dc_nationality" class="d-block">—</strong>
                        </div>
                        <div class="col-6 col-md-4 border-top border-start p-3">
                            <div class="small text-body-secondary">Position</div>
                            <strong id="dc_position" class="d-block">—</strong>
                        </div>
                        <div class="col-6 col-md-4 border-top p-3">
                            <div class="small text-body-secondary">Agency</div>
                            <strong id="dc_agency" class="d-block">—</strong>
                        </div>
                        <div class="col-6 col-md-4 border-top border-start-md p-3">
                            <div class="small text-body-secondary">Branch</div>
                            <strong id="dc_branch" class="d-block">—</strong>
                        </div>
                        <div class="col-6 col-md-4 border-top border-start p-3">
                            <div class="small text-body-secondary">Mobile</div>
                            <strong id="dc_mobile" class="d-block" dir="ltr">—</strong>
                        </div>
                        <div class="col-6 col-md-4 border-top p-3">
                            <div class="small text-body-secondary">Status</div>
                            <strong id="dc_status" class="d-block">—</strong>
                        </div>
                    </div>
                    <div class="px-3 py-2 small text-body-secondary border-top" id="dc_hint">
                        Search above and pick a driver from the list to fill in these details.
                    </div>
                </div>
            <?php endif; ?>

            <!-- Classification: same grouping as New Leave Application. -->
            <div class="row g-3 mt-3 pt-3 border-top">
                <div class="col-md-4">
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

                <!-- Snapshotted onto the record: later address-book changes
                     to the passport or branch never rewrite this history. -->
                <div class="col-md-4">
                    <label class="form-label" for="passport_number">Passport No.</label>
                    <input type="text" class="form-control<?= $err('passport_number') ?>"
                           id="passport_number" name="passport_number" maxlength="30" dir="ltr"
                           value="<?= e($val('passport_number', (string) ($driver['passport_number'] ?? ''))) ?>"
                           placeholder="Passport number">
                    <?= $errmsg('passport_number') ?>
                    <div class="form-text">Saved with this record, not read live.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="branch_id">Branch</label>
                    <select class="form-select<?= $err('branch_id') ?>" id="branch_id" name="branch_id">
                        <option value="">— No branch —</option>
                        <?= options($branches ?? [], $val('branch_id', (string) ($driver['branch_id'] ?? ''))) ?>
                    </select>
                    <?= $errmsg('branch_id') ?>
                    <div class="form-text">Saved with this record, not read live.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Leave Application ------------------------------------------------- -->
    <?php
    // Requirement 1/2: a record comes from an approved application, and on
    // create it MUST. The chip shows one that is already attached
    // (?application_id= on the way in, or the record's own
    // leave_application_id when editing); otherwise the search below lists
    // this driver's open applications. Editing an older record that has no
    // application is still allowed - only creating one demands the link.
    $shownApplication = $application ?? null;

    if ($shownApplication === null && $isEdit && !empty($leave['leave_application_id'])) {
        $shownApplication = \App\Models\LeaveApplication::find((int)$leave['leave_application_id']);
    }

    $isLinked = $shownApplication !== null;
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Leave Application</h2></div>
        <div class="card-body">
            <input type="hidden" name="leave_application_id" id="leave_application_id"
                   value="<?= e($val('leave_application_id')) ?>">

            <?php if ($isLinked): ?>
                <div class="alert alert-info d-flex align-items-start gap-2 mb-0 py-2 small"
                     id="applicationChip">
                    <i class="bi bi-link-45deg mt-1"></i>
                    <div class="flex-grow-1">
                        <strong id="applicationChipText">
                            Application #<?= (int)$shownApplication['id'] ?>
                        </strong>
                        <span class="text-body-secondary">
                            · <?= e($shownApplication['status']) ?> · applied
                            <?= e(fmt_date($shownApplication['application_date'])) ?>
                            · <?= e($shownApplication['leave_type'] ?? '') ?>
                        </span>
                        <div class="mt-1">
                            <button class="btn btn-outline-secondary btn-sm" type="button"
                                    id="clearApplication">
                                <i class="bi bi-link-45deg me-1"></i>Link a different application
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div id="applicationSearch" class="<?= $isLinked ? 'd-none' : '' ?>">
                <label class="form-label" for="applicationPicker">
                    Approved application <span class="text-danger">*</span>
                </label>
                <div class="position-relative">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-file-earmark-text"></i></span>
                        <input type="text" class="form-control" id="applicationPicker"
                               autocomplete="off"
                               placeholder="Approved applications waiting for this driver">
                        <button class="btn btn-outline-secondary" type="button" id="clearApplication2"
                                title="Clear the selected application"><i class="bi bi-x"></i></button>
                    </div>
                    <div class="dropdown-menu w-100 p-0 mt-1 shadow" id="applicationResults"
                         style="max-height: 260px; overflow-y: auto;"></div>
                </div>
                <div class="form-text">
                    Required for a new leave: a leave is filed against an approved
                    application. Approved applications whose leave has not started,
                    for the driver selected above - picking one fills in its leave
                    type, branch, last working date, expected return date,
                    destination country, reason and remarks; change anything before
                    saving. <strong>No approved application, no leave record.</strong>
                </div>
                <?php // Shown by leave.js when the form is submitted without a
                      // link, so the browser does not appear to ignore the
                      // click. The server enforces the same rule. ?> 
                <div class="invalid-feedback" id="applicationRequired">
                    Link the approved application this leave was filed under - a new
                    leave cannot be created without one.
                </div>
            </div>

            <?php // Rendered only when the server actually rejected the link.
                  // It used to sit in the markup permanently behind `hidden`,
                  // but `.d-block` - needed to defeat .invalid-feedback's own
                  // display:none - is equal specificity and !important too and
                  // Bootstrap loads the utilities after the reboot, so it won
                  // the tie and this copy showed on every open of the form.
                  // Leaving it out entirely also means there is no dead
                  // fallback text left to read in the source. ?>
            <?php if (isset($errors['leave_application_id'])): ?>
                <div class="invalid-feedback d-block" id="applicationError">
                    <?= e($errors['leave_application_id']) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Dates -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Dates</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="last_working_date">Last Working Date</label>
                    <input type="date" class="form-control<?= $err('last_working_date') ?>"
                           id="last_working_date" name="last_working_date"
                           value="<?= e($val('last_working_date')) ?>">
                    <?= $errmsg('last_working_date') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="leave_start_date">
                        Leave Start Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control<?= $err('leave_start_date') ?>"
                           id="leave_start_date" name="leave_start_date" required
                           value="<?= e($val('leave_start_date')) ?>">
                    <?= $errmsg('leave_start_date') ?>
                </div>

                <?php // Ticket, arrival and Duration all describe a return
                      // journey, so leave.js drops the three of them together
                      // while the leave type is one the driver never comes
                      // back from (Resignation / Terminated / Absconded).
                      // Marking them is what arms that: leave.js selects
                      // '[data-no-return-wrap], #expectedArrivalWrap', and
                      // with none of the three marked it found nothing here,
                      // skipped its whole block, and all three stayed on
                      // screen no matter which type was chosen. ?>
                <div class="col-md-4" id="ticketDateWrap" data-no-return-wrap>
                    <label class="form-label" for="ticket_date">Ticket Date</label>
                    <input type="date" class="form-control<?= $err('ticket_date') ?>"
                           id="ticket_date" name="ticket_date"
                           value="<?= e($val('ticket_date')) ?>">
                    <?= $errmsg('ticket_date') ?>
                </div>

                <div class="col-md-4" id="expectedArrivalWrap" data-no-return-wrap>
                    <label class="form-label" for="expected_arrival_date">
                        Expected Return Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control<?= $err('expected_arrival_date') ?>"
                           id="expected_arrival_date" name="expected_arrival_date" required
                           value="<?= e($val('expected_arrival_date')) ?>">
                    <?= $errmsg('expected_arrival_date') ?>
                </div>

                <?php // Only a record that is already back has an actual arrival
                      // to correct: this field edits an existing date, it does
                      // not close a trip (that is Mark Returned, which audits as
                      // 'driver-returned'). Admin and HR use it to fix a date
                      // typed by hand. ?>
                <?php if ($isEdit && (string) $leave['status'] === \App\Core\Status::RETURNED): ?>
                    <div class="col-md-4">
                        <label class="form-label" for="actual_arrival_date">
                            Actual Arrival Date
                        </label>
                        <input type="date" class="form-control<?= $err('actual_arrival_date') ?>"
                               id="actual_arrival_date" name="actual_arrival_date"
                               min="<?= e($val('leave_start_date')) ?>"
                               value="<?= e($val('actual_arrival_date')) ?>">
                        <?= $errmsg('actual_arrival_date') ?>
                        <div class="form-text">
                            When the driver actually came back - never before the leave start date.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Informational only: never a balance or entitlement. -->
                <!-- Destination Country is filed under Details, below - same
                     as the New Leave Application layout. -->
                <div class="col-md-4" id="daysAwayWrap" data-no-return-wrap>
                    <label class="form-label" for="daysAway">Duration (info only)</label>
                    <input type="text" class="form-control" id="daysAway" readonly
                           value="<?= e($duration) ?>"
                           aria-describedby="daysAwayHelp">
                    <div class="form-text" id="daysAwayHelp">
                        Informational. This system does not track leave balances.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Details -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Details</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="destination">Destination Country</label>
                    <?php // Countries come from Settings as a PLAIN list - the name
                          // is both the value and the label, so a destination copied
                          // across from a leave application still matches. "Not set"
                          // is the empty option, not a member of it.
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
                           maxlength="255" value="<?= e($val('reason')) ?>">
                    <?= $errmsg('reason') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="remarks">Remarks</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="2"
                              maxlength="500"><?= e($val('remarks')) ?></textarea>
                    <?= $errmsg('remarks') ?>
                </div>
            </div>

            <div class="alert alert-warning d-none mt-3 mb-0 py-2 small" id="overlapWarning">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <span id="overlapText"></span>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Save Changes' : 'Create Leave' ?>
        </button>
        <a class="btn btn-outline-secondary"
           href="<?= e($isEdit ? url('leave/' . (int)$leave['id']) : url('leave/records')) ?>">
            Cancel
        </a>
        <span class="ms-auto small text-body-secondary align-self-center">
            <i class="bi bi-info-circle me-1"></i>
            Status is calculated automatically from the dates you enter.
        </span>
    </div>
</form>

<script>
    window.LEAVE_URLS = {
        search: <?= json_encode(url('api/drivers/search')) ?>,
        detail: <?= json_encode(url('api/drivers/detail')) ?>,
        overlap: <?= json_encode(url('api/leave/overlap-check')) ?>,
        // Approved applications a leave may be linked to (requirement 2).
        openApplications: <?= json_encode(url('api/leave/open-applications')) ?>
    };
</script>
<script src="<?= e(url('assets/js/leave.js')) ?>?v=<?= (int)filemtime(APP_ROOT . '/assets/js/leave.js') ?>"></script>


