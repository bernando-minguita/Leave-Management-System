<?php
/**
 * Settings > System Settings (admin only).
 *
 * Application preferences, demo data, bulk delete and backup / restore.
 * User accounts moved to their own screen - see settings/users.php.
 *
 * Uses: $settings (rows), $errors
 */
$settings = $settings ?? [];
$errors   = $errors ?? [];
$usage    = $usage ?? [];
?>

<?= View::partial('partials/page-head', [
    'title'       => 'System Settings',
    'description' => 'Application preferences and the audit trail.',
    'actions'     => (can('settings.users')
            ? '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/users')) . '">'
                . '<i class="bi bi-people me-1"></i>Users</a>'
            : '')
        . '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/audit')) . '">'
        . '<i class="bi bi-clock-history me-1"></i>Audit Log</a>'
        . '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/leave-types')) . '">'
        . '<i class="bi bi-tags me-1"></i>Leave Types</a>'
        . '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/branches')) . '">'
        . '<i class="bi bi-geo-alt me-1"></i>Branches</a>'
        . '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('settings/agencies')) . '">'
        . '<i class="bi bi-briefcase me-1"></i>Agencies</a>',
]) ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $field => $message): ?>
                <li><strong><?= e(ucwords(str_replace('_', ' ', (string)$field))) ?>:</strong> <?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Application</h2></div>
    <form method="post" action="<?= e(url('settings/save')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="kind" value="settings">
        <input type="hidden" name="return_to" value="settings/system">

        <div class="card-body">
            <div class="row g-3">
                <?php if ($settings === []): ?>
                    <div class="col-12">
                        <?= empty_state('bi-sliders', 'No settings yet.',
                            'Settings are created by the installer.') ?>
                    </div>
                <?php else: foreach ($settings as $row): ?>
                    <?php
                    // The option lists are long CSVs - a textarea
                    // (with a worked example underneath) beats a
                    // one-line text box for them. Destination
                    // Countries is a PLAIN list rather than pairs,
                    // so it gets its own wording below.
                    $isOptionList = in_array(
                        $row['setting_key'],
                        ['employment_statuses', 'operational_statuses', 'destination_countries'],
                        true
                    );
                    $isPlainList  = $row['setting_key'] === 'destination_countries';
                    ?>
                    <div class="<?= $isOptionList ? 'col-12' : 'col-md-6' ?>">
                        <label class="form-label"
                               for="set_<?= e($row['setting_key']) ?>">
                            <?= e($row['label'] !== null && $row['label'] !== ''
                                ? $row['label'] : ucwords(str_replace('_', ' ', $row['setting_key']))) ?>
                        </label>
                        <?php if ($isOptionList): ?>
                            <textarea class="form-control font-monospace" rows="3"
                                      id="set_<?= e($row['setting_key']) ?>"
                                      name="settings[<?= e($row['setting_key']) ?>]"
                                      maxlength="500"
                                      spellcheck="false"><?= e($row['setting_value'] ?? '') ?></textarea>
                            <div class="form-text">
                                Key: <code><?= e($row['setting_key']) ?></code> ·
                                <?php if ($isPlainList): ?>
                                    Country names, separated by commas <em>or line
                                    breaks</em> (pasting a column out of a
                                    spreadsheet works), e.g.
                                    <code>Saudi Arabia,India,Philippines</code>.
                                    They fill <strong>Destination Country</strong>
                                    on both leave forms and the name itself is what
                                    gets stored. <code>Not set</code> is offered
                                    first on every form and is not part of this
                                    list. A setting holds 500 characters - widen
                                    the column before going much past ~40.
                                    Removing a name leave rows still use is
                                    allowed: those rows keep their value and stay
                                    filterable.
                                <?php else: ?>
                                    Pairs of <code>value|Label</code>, separated by
                                    commas <em>or line breaks</em>; the value is what
                                    drivers store, the label what the Address Book
                                    shows. An option a driver still holds cannot be
                                    removed - reassign those drivers first.
                                    <?php if ($row['setting_key'] === 'employment_statuses'): ?>
                                        The value <code>active</code> must stay in the list -
                                        every active-driver total counts it.
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php // What a removal would cost: how many records
                                  // hold each configured option. The ones at 0
                                  // are the safe ones to drop. ?>
                            <?php $summary = (array)($usage[$row['setting_key']] ?? []); ?>
                            <?php if ((array)($summary['rows'] ?? []) !== []): ?>
                                <div class="form-text">
                                    <span class="text-body-secondary">In use by records:</span>
                                    <?php foreach (array_slice((array)$summary['rows'], 0, 8) as $i => $entry): ?>
                                        <?= $i > 0 ? '·' : '' ?>
                                        <span class="<?= (int)$entry['count'] === 0 ? 'text-body-secondary' : 'fw-semibold' ?>"><?= e($entry['label']) ?> <?= (int)$entry['count'] ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count((array)$summary['rows']) > 8): ?>
                                        · and <?= count((array)$summary['rows']) - 8 ?> more
                                    <?php endif; ?>
                                    <?php if ((int)($summary['unused'] ?? 0) > 0): ?>
                                        · <span class="text-body-secondary"><?= (int)$summary['unused'] ?> unused</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <input type="text" class="form-control"
                                   id="set_<?= e($row['setting_key']) ?>"
                                   name="settings[<?= e($row['setting_key']) ?>]"
                                   value="<?= e($row['setting_value'] ?? '') ?>"
                                   maxlength="500">
                            <div class="form-text">Key: <code><?= e($row['setting_key']) ?></code></div>
                        <?php endif; ?>
                        <input type="hidden"
                               name="labels[<?= e($row['setting_key']) ?>]"
                               value="<?= e($row['label'] ?? '') ?>">
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div class="card-footer bg-white">
            <button class="btn btn-primary btn-sm" type="submit">
                <i class="bi bi-check-lg me-1"></i>Save Settings
            </button>
        </div>
    </form>
</div>

<!-- ------------------------------------------------- demo data -- -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0 text-danger">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>Demo data
        </h2>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-start">
            <div class="col-12 col-lg-7">
                <p class="mb-1"><strong>Two ways to clean up before going live</strong></p>
                <p class="small text-body-secondary mb-2">
                    Everything the demo shipped with is tagged
                    <code>SAMPLE DATA</code> in the database.
                </p>
                <p class="small text-body-secondary mb-0">
                    <i class="bi bi-shield-check me-1"></i>
                    User accounts are kept either way - you stay signed in with the
                    same password.
                </p>
            </div>
            <div class="col-12 col-lg-5">
                <div class="d-grid gap-2">
                    <button class="btn btn-danger btn-sm" type="button"
                            data-bs-toggle="modal" data-bs-target="#removeSampleModal">
                        <i class="bi bi-trash me-1"></i>Delete sample data
                    </button>
                    <button class="btn btn-outline-danger btn-sm" type="button"
                            data-bs-toggle="modal" data-bs-target="#resetDemoModal">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restore seeded state
                    </button>
                </div>
                <div class="form-text mt-2">
                    <strong>Delete sample data</strong> strips the demo rows and leaves
                    an empty system - nothing is re-seeded.<br>
                    <strong>Restore seeded state</strong> wipes everything and puts the
                    full demo back.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ------------------------------------------------ bulk delete -- -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0 text-danger">
            <i class="bi bi-trash-fill me-2"></i>Bulk delete
        </h2>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-start">
            <div class="col-12 col-lg-7">
                <p class="mb-1"><strong>Empty the address book and the leave history</strong></p>
                <p class="small text-body-secondary mb-2">
                    Deletes <em>every</em> driver, <em>every</em> leave record and
                    <em>every</em> leave application, whether or not it is tagged as
                    sample data.
                </p>
                <p class="small text-body-secondary mb-0">
                    <i class="bi bi-shield-check me-1"></i>
                    Branches, agencies, leave types, application settings and user
                    accounts are kept.
                </p>
            </div>
            <div class="col-12 col-lg-5">
                <div class="d-grid">
                    <button class="btn btn-danger btn-sm" type="button"
                            data-bs-toggle="modal" data-bs-target="#bulkDeleteModal">
                        <i class="bi bi-trash me-1"></i>Delete all drivers &amp; leave records
                    </button>
                </div>
                <div class="form-text mt-2">
                    This cannot be undone - take a backup first.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- -------------------------------------------- backup/restore -- -->
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">
            <i class="bi bi-database-down me-2"></i>Backup &amp; restore
        </h2>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-start">
            <div class="col-12 col-lg-7">
                <p class="mb-1"><strong>Download the database as JSON, import it back later</strong></p>
                <p class="small text-body-secondary mb-2">
                    A backup holds every application table with its real column names:
                    accounts, branches, agencies, leave types, drivers, leave records,
                    leave applications, settings and the audit trail.
                </p>
                <p class="small text-body-secondary mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Importing replaces everything currently in those tables.
                </p>
            </div>
            <div class="col-12 col-lg-5">
                <div class="d-grid gap-2">
                    <a class="btn btn-primary btn-sm" href="<?= e(url('settings/backup')) ?>">
                        <i class="bi bi-download me-1"></i>Download JSON backup
                    </a>
                    <button class="btn btn-outline-primary btn-sm" type="button"
                            data-bs-toggle="modal" data-bs-target="#restoreModal">
                        <i class="bi bi-upload me-1"></i>Import from JSON
                    </button>
                </div>
                <div class="form-text mt-2">
                    Plain JSON - keep a copy somewhere safe.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ------------------------------------------- restore seeded state -- -->
<!-- -------------------------------------------- delete sample data -- -->
<div class="modal fade" id="removeSampleModal" tabindex="-1"
     aria-labelledby="removeSampleLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= e(url('settings/remove-sample-data')) ?>">
            <?= csrf_field() ?>

            <div class="modal-header">
                <h3 class="modal-title h6" id="removeSampleLabel">
                    <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Delete sample data
                </h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger small py-2 mb-3">
                    <strong>This cannot be undone.</strong>
                    Every row tagged <code>SAMPLE DATA</code> is deleted: the demo drivers,
                    their leave records and the demo agencies.
                </div>
                <p class="small mb-2">
                    Nothing is re-seeded, so the system is left empty and ready for your own
                    drivers. Branches, leave types, application settings and user accounts are
                    kept - they are reference data, not sample rows.
                </p>
                <p class="small mb-3">
                    <i class="bi bi-person-check me-1"></i>
                    A driver you added yourself is never tagged, so it survives.
                </p>
                <label class="form-label small mb-1" for="removeConfirm">
                    Type <code>DELETE</code> to confirm
                </label>
                <input class="form-control" type="text" id="removeConfirm" name="confirm"
                       autocomplete="off" autocapitalize="off" spellcheck="false"
                       placeholder="DELETE" data-reset-confirm data-confirm-word="DELETE">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm" disabled data-reset-submit>
                    <i class="bi bi-trash me-1"></i>Delete sample data
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="resetDemoModal" tabindex="-1"
     aria-labelledby="resetDemoLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= e(url('settings/reset-demo')) ?>">
            <?= csrf_field() ?>

            <div class="modal-header">
                <h3 class="modal-title h6" id="resetDemoLabel">
                    <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Restore seeded state
                </h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger small py-2 mb-3">
                    <strong>This cannot be undone.</strong>
                    Every driver, leave record, branch, agency, leave type, application
                    setting and audit entry is deleted and replaced with the shipped
                    sample data.
                </div>
                <p class="small mb-3">
                    Drivers you added or imported, leave records you created and any
                    setting you changed will be lost. The
                    <code>admin</code> account and its password are kept.
                </p>
                <label class="form-label small mb-1" for="resetConfirm">
                    Type <code>RESET</code> to confirm
                </label>
                <input class="form-control" type="text" id="resetConfirm" name="confirm"
                       autocomplete="off" autocapitalize="off" spellcheck="false"
                       placeholder="RESET" data-reset-confirm data-confirm-word="RESET">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm" disabled data-reset-submit>
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Restore seeded state
                </button>
            </div>
        </form>
    </div>
</div>

<!-- -------------------------------------------------------- bulk delete -- -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1"
     aria-labelledby="bulkDeleteLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= e(url('settings/bulk-delete')) ?>">
            <?= csrf_field() ?>

            <div class="modal-header">
                <h3 class="modal-title h6" id="bulkDeleteLabel">
                    <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Bulk delete
                </h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger small py-2 mb-3">
                    <strong>This cannot be undone.</strong>
                    Every driver in the address book, every leave record and every leave
                    application is deleted - hundreds of rows, sample data or not.
                </div>
                <p class="small mb-2">
                    Branches, agencies, leave types, application settings and user accounts
                    are kept, so you stay signed in and can start entering data again.
                </p>
                <p class="small mb-3">
                    <i class="bi bi-database-down me-1"></i>
                    Consider downloading a JSON backup from the card below first.
                </p>
                <label class="form-label small mb-1" for="bulkDeleteConfirm">
                    Type <code>PURGE</code> to confirm
                </label>
                <input class="form-control" type="text" id="bulkDeleteConfirm" name="confirm"
                       autocomplete="off" autocapitalize="off" spellcheck="false"
                       placeholder="PURGE" data-reset-confirm data-confirm-word="PURGE">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm" disabled data-reset-submit>
                    <i class="bi bi-trash me-1"></i>Delete everything
                </button>
            </div>
        </form>
    </div>
</div>

<!-- --------------------------------------------------- restore backup -- -->
<div class="modal fade" id="restoreModal" tabindex="-1"
     aria-labelledby="restoreLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= e(url('settings/restore')) ?>"
              enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="modal-header">
                <h3 class="modal-title h6" id="restoreLabel">
                    <i class="bi bi-upload text-primary me-2"></i>Import a JSON backup
                </h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-warning small py-2 mb-3">
                    <strong>This replaces your current data.</strong>
                    Accounts, branches, agencies, leave types, drivers, leave records,
                    settings and the audit trail are cleared and replaced by the file.
                </div>

                <label class="form-label small mb-1" for="backupFile">
                    Backup file <span class="text-danger">*</span>
                </label>
                <input class="form-control" type="file" id="backupFile" name="backup_file"
                       accept=".json,application/json" required>

                <label class="form-label small mb-3 mt-3" for="restoreConfirm">
                    Type <code>RESTORE</code> to confirm
                </label>
                <input class="form-control" type="text" id="restoreConfirm" name="confirm"
                       autocomplete="off" autocapitalize="off" spellcheck="false"
                       placeholder="RESTORE" data-reset-confirm data-confirm-word="RESTORE">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" disabled data-reset-submit>
                    <i class="bi bi-upload me-1"></i>Import backup
                </button>
            </div>
        </form>
    </div>
</div>
