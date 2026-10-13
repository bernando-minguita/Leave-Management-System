<?php
/**
 * Friendly error body (also rendered without a layout as a last resort).
 * Never shows SQL, stack traces or configuration details.
 */
$code    = $code ?? 500;
$title   = $title ?? 'Something went wrong';
$message = $message ?? 'Please try again.';
?>

<div class="card border-0 shadow-sm text-center error-card">
    <div class="card-body p-4 p-md-5">
        <div class="error-code"><?= e((string)$code) ?></div>

        <h1 class="h4 mb-2"><?= e($title) ?></h1>
        <p class="text-body-secondary mb-4"><?= e($message) ?></p>

        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <?php if (!empty($_SESSION['user_id'])): ?>
                <a href="<?= e(url('dashboard')) ?>" class="btn btn-primary">
                    <i class="bi bi-speedometer2 me-1"></i>Back to Dashboard
                </a>
            <?php else: ?>
                <a href="<?= e(url('login')) ?>" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Go to Sign in
                </a>
            <?php endif; ?>

            <button type="button" class="btn btn-outline-secondary"
                    onclick="window.history.length > 1 ? window.history.back() : window.location.reload()">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Go Back
            </button>
        </div>

        <p class="small text-body-secondary mt-4 mb-0">
            Technical details have been recorded in the application log.
        </p>
    </div>
</div>
