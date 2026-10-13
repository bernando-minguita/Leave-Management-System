<?php
/**
 * Request a change to one governed field on one driver.
 *
 * Uses: $driver, $drivers, $field, $fields, $branches, $agencies, $statuses,
 *       $opStatuses, $currentValue, $input, $error
 *
 * The screen is deliberately a single field. Picking a different driver or a
 * different field reloads with ?driver_id=&field= so the CURRENT value can be
 * read straight from the master at render time - there is no hidden copy of it
 * that could go stale between page load and submit.
 */
$driver     = $driver ?? null;
$drivers    = $drivers ?? [];
$field      = (string)($field ?? '');
$fields     = $fields ?? [];
$branches   = $branches ?? [];
$agencies   = $agencies ?? [];
$statuses   = $statuses ?? [];
$opStatuses = $opStatuses ?? [];
$input      = $input ?? null;
$error      = (string)($error ?? '');
$ready      = $driver !== null && \App\Models\Driver::isGovernable($field);

// A rejected submit keeps what was typed; a fresh page starts blank.
$typed = is_array($input)
    ? (string)($input['new_value'] ?? '')
    : (string)($driver[$field] ?? '');
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Request a Driver Change',
    'description' => 'One field, one reason, one reviewer. Master data is only '
        . 'written once somebody approves this.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="'
        . e(url('driver-changes')) . '">'
        . '<i class="bi bi-arrow-left me-1"></i>Back to queue</a>',
]) ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="get" action="<?= e(url('driver-changes/new')) ?>"
              class="row g-3 mb-4 pb-3 border-bottom">
            <div class="col-md-5">
                <label class="form-label" for="pick_driver">Driver</label>
                <?php if ($driver !== null): ?>
                    <input class="form-control" id="pick_driver" readonly
                           value="<?= e((string)$driver['badge_number'] . ' - ' . $driver['driver_name']) ?>">
                    <input type="hidden" name="driver_id"
                           value="<?= (int)$driver['id'] ?>">
                <?php else: ?>
                    <select class="form-select" name="driver_id" id="pick_driver"
                            onchange="this.form.submit()" required>
                        <option value="">Choose a driver…</option>
                        <?php foreach ($drivers as $id => $label): ?>
                            <option value="<?= (int)$id ?>"
                                <?= (string)($_GET['driver_id'] ?? '') === (string)$id
                                    ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="pick_field">Field to change</label>
                <select class="form-select" name="field" id="pick_field"
                        onchange="this.form.submit()" <?= $driver === null ? 'disabled' : '' ?>>
                    <option value="">Choose a field…</option>
                    <?php foreach ($fields as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $field === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-outline-primary w-100" type="submit"
                        <?= $driver === null ? 'disabled' : '' ?>>
                    Load
                </button>
            </div>
        </form>

        <?php if ($ready): ?>
        <form method="post" action="<?= e(url('driver-changes')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="driver_id" value="<?= (int)$driver['id'] ?>">
            <input type="hidden" name="field" value="<?= e($field) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="current_value">Current Value</label>
                    <input class="form-control bg-body-tertiary" readonly
                           id="current_value"
                           value="<?= e(\App\Models\Driver::displayGovernedValue(
                               $field, $currentValue
                           )) ?>">
                    <div class="form-text">Read from the master record as this page loaded.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="new_value">
                        New Value <span class="text-danger">*</span>
                    </label>
                    <?php // Field-appropriate control: a free-text box would let an
                          // agency id be typed where a branch is meant. ?>
                    <?php if ($field === 'branch_id'): ?>
                        <select class="form-select" name="new_value" id="new_value" required>
                            <option value="">— none —</option>
                            <?php foreach ($branches as $id => $name): ?>
                                <option value="<?= (int)$id ?>"
                                    <?= (string)$typed === (string)$id ? 'selected' : '' ?>>
                                    <?= e($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($field === 'agency_id'): ?>
                        <select class="form-select" name="new_value" id="new_value" required>
                            <option value="">— none —</option>
                            <?php foreach ($agencies as $id => $name): ?>
                                <option value="<?= (int)$id ?>"
                                    <?= (string)$typed === (string)$id ? 'selected' : '' ?>>
                                    <?= e($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($field === 'status'): ?>
                        <select class="form-select" name="new_value" id="new_value" required>
                            <?php foreach ($statuses as $value => $label): ?>
                                <option value="<?= e($value) ?>"
                                    <?= $typed === (string)$value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($field === 'operational_status'): ?>
                        <select class="form-select" name="new_value" id="new_value">
                            <option value="">— none —</option>
                            <?php foreach ($opStatuses as $value => $label): ?>
                                <option value="<?= e($value) ?>"
                                    <?= $typed === (string)$value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif (in_array($field, ['joining_date', 'last_working_date'], true)): ?>
                        <input class="form-control" type="date" name="new_value"
                               id="new_value" value="<?= e($typed) ?>" required>
                    <?php else: ?>
                        <input class="form-control" type="text" name="new_value"
                               id="new_value" value="<?= e($typed) ?>"
                               maxlength="255" required>
                    <?php endif; ?>
                </div>


                <div class="col-md-8">
                    <label class="form-label" for="reason">
                        Reason <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" name="reason" id="reason" rows="3"
                              minlength="5" maxlength="500" required
                              placeholder="Why does this need to change?"><?= e(
                                  is_array($input)
                                      ? (string)($input['reason'] ?? '')
                                      : ''
                              ) ?></textarea>
                    <div class="form-text">
                        Recorded against the change and shown to whoever reviews it.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="effective_date">Effective Date</label>
                    <input class="form-control" type="date" name="effective_date"
                           id="effective_date" value="<?= e(is_array($input)
                               ? (string)($input['effective_date'] ?? '') : '') ?>">
                    <div class="form-text">
                        Optional. When it should take effect operationally - it does
                        not rewrite snapshots already on file.
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-send me-1"></i>Submit request
                </button>
                <a class="btn btn-outline-secondary"
                   href="<?= e(url('driver-changes')) ?>">Cancel</a>
            </div>
        </form>

        <?php elseif ($driver !== null): ?>
            <p class="text-body-secondary mb-0">Choose which field to change above.</p>
        <?php else: ?>
            <p class="text-body-secondary mb-0">Choose a driver above to begin.</p>
        <?php endif; ?>
    </div>
</div>

