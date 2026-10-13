<?php
/**
 * CSV import for drivers: upload -> preview with errors -> confirm.
 * Nothing is written until the confirm step (spec section 25).
 *
 * Uses: $plan (array|null), $result (array|null)
 */
$plan   = $plan ?? null;
$result = $result ?? null;
?>

<?= View::partial('partials/page-head', [
    'title'       => 'Import / Export Drivers',
    'description' => 'Upload a CSV, review exactly what will change, then confirm.',
    'actions'     => '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('import?template=1')) . '">'
        . '<i class="bi bi-file-earmark-spreadsheet me-1"></i>Download template</a>'
        . '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('drivers?export=csv')) . '">'
        . '<i class="bi bi-download me-1"></i>Export current list</a>'
        . '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('drivers')) . '">'
        . '<i class="bi bi-people me-1"></i>Back to drivers</a>',
]) ?>

<!-- Result summary from the last confirmed import -->
<?php if (is_array($result)): ?>
    <div class="alert alert-success">
        <h2 class="h6 alert-heading"><i class="bi bi-check2-all me-1"></i>Import summary</h2>
        <div class="d-flex flex-wrap gap-4">
            <div><strong class="fs-5"><?= (int)$result['inserted'] ?></strong> added</div>
            <div><strong class="fs-5"><?= (int)$result['updated'] ?></strong> updated</div>
            <div><strong class="fs-5"><?= (int)$result['skipped'] ?></strong> skipped</div>
            <?php if ((int)($result['agencies_created'] ?? 0) > 0): ?>
                <div><strong class="fs-5"><?= (int)$result['agencies_created'] ?></strong>
                    agencies created</div>
            <?php endif; ?>
            <div class="text-body-secondary align-self-center">
                mode: <code><?= e((string)($result['mode'] ?? 'skip')) ?></code>
            </div>
        </div>
        <?php unset($_SESSION['import_result']); ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- Step 1: upload -->
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">1 · Upload file</h2></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('import')) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="csv_file">CSV file <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="csv_file" name="csv_file"
                               accept=".csv,text/csv" required>
                        <div class="form-text">UTF-8 CSV with a header row.</div>
                    </div>

                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="bi bi-cloud-arrow-up me-1"></i>Validate &amp; preview
                    </button>
                </form>

                <hr>

                <h3 class="h6">Required columns</h3>
                <ul class="small mb-2">
                    <li><code>badge_number</code></li>
                    <li><code>driver_name</code></li>
                    <li><code>iqama_number</code></li>
                </ul>
                <p class="small text-body-secondary mb-0">
                    Optional: mobile_number, alternate_mobile, nationality, agency_code, branch_code,
                    department, position, joining_date, emergency_contact_name,
                    emergency_contact_number, address, city, remarks, status.
                </p>
                <?php // One template link, in the toolbar above: the download
                      // actions (template + export current list) live together
                      // there, and this card's only job is to pick a file. A
                      // second link here looked like a second, different
                      // download - same URL, different icon, different label. ?>
            </div>
        </div>
    </div>

    <!-- Step 2: preview -->
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">2 · Preview &amp; confirm</h2>
            </div>
            <div class="card-body">
                <?php if (!is_array($plan)): ?>
                    <?= empty_state(
                        'bi-file-earmark-arrow-up',
                        'Nothing to preview yet.',
                        'Upload a CSV on the left to see exactly which rows will be added or updated.'
                    ) ?>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        <span class="badge text-bg-success fs-6"><?= (int)$plan['summary']['create'] ?> new</span>
                        <span class="badge text-bg-primary fs-6"><?= (int)$plan['summary']['update'] ?> matching</span>
                        <span class="badge text-bg-danger fs-6"><?= (int)$plan['summary']['errors'] ?>
                            error<?= (int)$plan['summary']['errors'] === 1 ? '' : 's' ?></span>
                    </div>

                    <?php if ($plan['errors'] !== []): ?>
                        <div class="alert alert-danger py-2">
                            <strong class="d-block mb-1">Rows that cannot be imported:</strong>
                            <ul class="mb-0 small">
                                <?php foreach ($plan['errors'] as $line => $message): ?>
                                    <li>Line <?= (int)$line ?>: <?= e($message) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive mb-3" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-sm table-bordered table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                <th class="text-center">#</th>
                                <th>Line</th><th>Action</th><th>Badge</th><th>Name</th>
                                <th>Iqama</th><th>Agency</th><th>Branch</th>
                            </tr>
                            </thead>
                            <tbody>
            <?php $rowNo = offset() + 1; ?>
                            <?php foreach ($plan['rows'] as $row): ?>
                                <tr class="<?= $row['_action'] === 'create' ? 'table-success' : 'table-primary' ?>">
    <td class="text-center"><?= $rowNo++ ?></td>
                                    <td><?= (int)$row['_line'] ?></td>
                                    <td>
                                        <?php if ($row['_action'] === 'create'): ?>
                                            <span class="badge text-bg-success">Add</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-primary">Update #<?= (int)$row['_id'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><?= e($row['badge_number']) ?></td>
                                    <td><?= e($row['driver_name']) ?></td>
                                    <td class="text-nowrap"><?= e($row['iqama_number']) ?></td>
                                    <td class="text-nowrap"><?= e($row['agency_code'] ?? '—') ?></td>
                                    <td class="text-nowrap"><?= e($row['branch_code'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($plan['summary']['create'] + $plan['summary']['update'] > 0): ?>
                        <form method="post" action="<?= e(url('import')) ?>" class="border-top pt-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="step" value="confirm">

                            <div class="mb-3">
                                <span class="form-label d-block">Rows that already exist</span>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="mode"
                                           id="mode_skip" value="skip" checked>
                                    <label class="form-check-label" for="mode_skip">
                                        Skip them <span class="text-body-secondary small">(never overwrite)</span>
                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="mode"
                                           id="mode_update" value="update">
                                    <label class="form-check-label" for="mode_update">
                                        Overwrite their details
                                    </label>
                                </div>
                            </div>

                            <button class="btn btn-success" type="submit"
                                    data-confirm="Import <?= (int)$plan['summary']['create'] ?> new and update <?= (int)$plan['summary']['update'] ?> existing driver(s)?">
                                <i class="bi bi-check2-all me-1"></i>Confirm import
                            </button>
                            <a class="btn btn-outline-secondary" href="<?= e(url('import')) ?>">Discard</a>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">
                            No importable rows. Fix the errors above and upload again.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


