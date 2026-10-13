<?php
/**
 * Flash messages + toast host.
 * A flash message becomes a visible alert; it is also mirrored into a toast so
 * users who scroll still see the confirmation.
 */
$flash = $flash ?? null;
?>

<?php if (is_array($flash) && !empty($flash['message'])): ?>
    <div class="alert alert-<?= e($flash['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <div class="d-flex align-items-start">
            <i class="bi <?= e(match ($flash['type'] ?? 'info') {
                'success' => 'bi-check-circle-fill',
                'danger'  => 'bi-exclamation-octagon-fill',
                'warning' => 'bi-exclamation-triangle-fill',
                default   => 'bi-info-circle-fill',
            }) ?> me-2 mt-1"></i>
            <div>
                <div><?= e($flash['message']) ?></div>
                <?php if (!empty($flash['errors']) && is_array($flash['errors'])): ?>
                    <ul class="mb-0 small">
                        <?php foreach ($flash['errors'] as $field => $message): ?>
                            <li><strong><?= e(ucwords(str_replace('_', ' ', (string)$field))) ?>:</strong>
                                <?= e((string)$message) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Toast host: app.js pushes flash messages and AJAX results in here. -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastHost" aria-live="polite"></div>
