<?php
/**
 * Add / edit driver form.
 * Sections follow spec section 33.
 *
 * Uses: $driver (null on create), $errors, $old, $branches, $agencies
 */
$driver = $driver ?? null;
$errors = $errors ?? [];
$old    = $old ?? [];
$isEdit = $driver !== null;

$val = static function (string $field, $default = '') use ($old, $driver): string {
    if (array_key_exists($field, $old)) {
        return (string)$old[$field];
    }

    return (string)($driver[$field] ?? $default);
};

$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? ' is-invalid' : '';
};

$errmsg = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<div class="invalid-feedback">' . e($errors[$field]) . '</div>'
        : '';
};

$heading = $isEdit ? 'Edit Driver' : 'Add Driver';
?>

<?= View::partial('partials/page-head', [
    'title'       => $heading,
    'description' => $isEdit
        ? 'Update the master record for ' . (string)$driver['driver_name'] . '.'
        : 'Create a new driver in the address book.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="'
        . e($isEdit ? url('drivers/' . (int)$driver['id']) : url('drivers')) . '">'
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

<form method="post" novalidate
      action="<?= e($isEdit ? url('drivers/' . (int)$driver['id'] . '/edit') : url('drivers/create')) ?>">
    <?= csrf_field() ?>

    <!-- Driver Information -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Driver Information</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="badge_number">Badge Number <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= $err('badge_number') ?>"
                           id="badge_number" name="badge_number" maxlength="30" required
                           value="<?= e($val('badge_number')) ?>"
                           placeholder="e.g. B-021">
                    <?= $errmsg('badge_number') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="driver_name">Driver Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= $err('driver_name') ?>"
                           id="driver_name" name="driver_name" maxlength="120" required
                           value="<?= e($val('driver_name')) ?>">
                    <?= $errmsg('driver_name') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="iqama_number">Iqama Number <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= $err('iqama_number') ?>"
                           id="iqama_number" name="iqama_number" maxlength="30" required
                           value="<?= e($val('iqama_number')) ?>">
                    <?= $errmsg('iqama_number') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="passport_number">Passport Number</label>
                    <input type="text" class="form-control<?= $err('passport_number') ?>"
                           id="passport_number" name="passport_number" maxlength="30"
                           value="<?= e($val('passport_number')) ?>">
                    <?= $errmsg('passport_number') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="nationality">Nationality</label>
                    <input type="text" class="form-control" id="nationality" name="nationality"
                           maxlength="60" list="nationalityList"
                           value="<?= e($val('nationality')) ?>">
                    <datalist id="nationalityList">
                        <?php foreach (['Saudi Arabia', 'India', 'Pakistan', 'Philippines', 'Bangladesh', 'Nepal', 'Egypt', 'Sudan', 'Yemen'] as $n): ?>
                            <option value="<?= e($n) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <?= $errmsg('nationality') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="joining_date">Joining Date</label>
                    <input type="date" class="form-control<?= $err('joining_date') ?>"
                           id="joining_date" name="joining_date" value="<?= e($val('joining_date')) ?>">
                    <?= $errmsg('joining_date') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="status">Employment Status</label>
                    <?php // Options come from Settings (value|Label): the slug is
                          // stored, the label is shown, so renaming an option in
                          // Settings never invalidates an existing driver. ?>
                    <select class="form-select<?= $err('status') ?>" id="status" name="status">
                        <?php foreach (\App\Models\Lookup::employmentStatuses() as $value => $label): ?>
                            <option value="<?= e($value) ?>"
                                <?= $val('status', 'active') === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $errmsg('status') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="operational_status">Operational Status</label>
                    <?php // Not the same question as employment: a driver can be
                          // employed and still off the road (vacation, sick,
                          // suspended...). Blank = not recorded yet. ?>
                    <select class="form-select<?= $err('operational_status') ?>"
                            id="operational_status" name="operational_status">
                        <option value="">— Not set —</option>
                        <?php foreach (\App\Models\Lookup::operationalStatuses() as $value => $label): ?>
                            <option value="<?= e($value) ?>"
                                <?= $val('operational_status') === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $errmsg('operational_status') ?>
                </div>

                <div class="col-md-4 d-none" id="lastWorkingGroup">
                    <label class="form-label" for="last_working_date">Last Working Date</label>
                    <?php // Only offered when the status is a leaving one (shown by the script
                          // below): the date is what keeps the driver in the totals up to that
                          // day, and reactivating clears it. ?>
                    <input type="date" class="form-control<?= $err('last_working_date') ?>"
                           id="last_working_date" name="last_working_date"
                           value="<?= e($val('last_working_date')) ?>">
                    <?= $errmsg('last_working_date') ?>
                    <div class="form-text">
                        The last day this driver works. Capacity Planning counts them through
                        this day; leave from the next day on is outside the head-count.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Information -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Contact Information</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="mobile_number">Mobile Number</label>
                    <input type="text" class="form-control<?= $err('mobile_number') ?>"
                           id="mobile_number" name="mobile_number" maxlength="30"
                           dir="ltr" value="<?= e($val('mobile_number')) ?>">
                    <?= $errmsg('mobile_number') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="alternate_mobile">Alternate Mobile</label>
                    <input type="text" class="form-control<?= $err('alternate_mobile') ?>"
                           id="alternate_mobile" name="alternate_mobile" maxlength="30"
                           dir="ltr" value="<?= e($val('alternate_mobile')) ?>">
                    <?= $errmsg('alternate_mobile') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="city">City</label>
                    <input type="text" class="form-control" id="city" name="city"
                           maxlength="80" value="<?= e($val('city')) ?>">
                    <?= $errmsg('city') ?>
                </div>

                <div class="col-12">
                    <label class="form-label" for="address">Address</label>
                    <input type="text" class="form-control" id="address" name="address"
                           maxlength="255" value="<?= e($val('address')) ?>">
                    <?= $errmsg('address') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Employment -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Employment</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="agency_id">Agency</label>
                    <select class="form-select<?= $err('agency_id') ?>" id="agency_id" name="agency_id">
                        <option value="">— Select agency —</option>
                        <?= options($agencies ?? [], $val('agency_id')) ?>
                    </select>
                    <?= $errmsg('agency_id') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="branch_id">Branch</label>
                    <select class="form-select<?= $err('branch_id') ?>" id="branch_id" name="branch_id">
                        <option value="">— Select branch —</option>
                        <?= options($branches ?? [], $val('branch_id')) ?>
                    </select>
                    <?= $errmsg('branch_id') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="department">Department</label>
                    <input type="text" class="form-control" id="department" name="department"
                           maxlength="100" value="<?= e($val('department')) ?>">
                    <?= $errmsg('department') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="position">Position</label>
                    <input type="text" class="form-control" id="position" name="position"
                           maxlength="100" value="<?= e($val('position')) ?>">
                    <?= $errmsg('position') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Emergency Contact -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Emergency Contact</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="emergency_contact_name">Name</label>
                    <input type="text" class="form-control" id="emergency_contact_name"
                           name="emergency_contact_name" maxlength="120"
                           value="<?= e($val('emergency_contact_name')) ?>">
                    <?= $errmsg('emergency_contact_name') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="emergency_contact_number">Mobile</label>
                    <input type="text" class="form-control" id="emergency_contact_number"
                           name="emergency_contact_number" maxlength="30" dir="ltr"
                           value="<?= e($val('emergency_contact_number')) ?>">
                    <?= $errmsg('emergency_contact_number') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Information -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Additional Information</h2></div>
        <div class="card-body">
            <label class="form-label" for="remarks">Remarks</label>
            <textarea class="form-control" id="remarks" name="remarks" rows="3"
                      maxlength="500"><?= e($val('remarks')) ?></textarea>
            <?= $errmsg('remarks') ?>
        </div>
    </div>

    <?php // Direct correction of master data needs a written reason. Shown only
          // when editing: creating a driver is a setup, not a correction of
          // something that already existed, and nothing is being changed. ?>
    <?php if ($isEdit): ?>
    <div class="card border-0 shadow-sm mb-3 border-start border-warning border-4">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0">
                <i class="bi bi-exclamation-triangle me-1 text-warning"></i>
                Reason for editing master information
            </h2>
        </div>
        <div class="card-body">
            <label class="form-label" for="reason">
                Reason <span class="text-danger">*</span>
            </label>
            <textarea class="form-control" id="reason" name="reason" rows="2"
                      minlength="5" maxlength="500"
                      placeholder="Why is this master record being corrected?"></textarea>
            <?= $errmsg('reason') ?>
            <div class="form-text">
                You are modifying a master driver record directly. This is written to
                Driver History with your name against every field you change - the
                audit log records that you did it, this records why.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Save Changes' : 'Add Driver' ?>
        </button>
        <a class="btn btn-outline-secondary"
           href="<?= e($isEdit ? url('drivers/' . (int)$driver['id']) : url('drivers')) ?>">
            Cancel
        </a>
    </div>
</form>


<script>
/* The Last Working Date belongs to a leaving status: show it when the
 * employment status is anything but active, and never post a stale date for
 * an active driver (the server clears it too - this keeps the form honest). */
(function () {
    var status = document.getElementById('status');
    var group  = document.getElementById('lastWorkingGroup');

    if (!status || !group) { return; }

    function sync() {
        var leaving = status.value !== 'active';

        group.classList.toggle('d-none', !leaving);

        if (!leaving) {
            var input = document.getElementById('last_working_date');
            if (input) { input.value = ''; }
        }
    }

    status.addEventListener('change', sync);
    sync();
})();
</script>

