<?php
/**
 * Two-factor challenge - shown after the password is correct but BEFORE any
 * authenticated session exists.
 * Uses: $error, $username, $minutes
 */
$error = $error ?? null;
$username = $username ?? '';
$minutes = (int)($minutes ?? 5);
?>

<div class="card border-0 shadow auth-card">
    <div class="card-body p-4">
        <div class="text-center mb-3">
            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10"
                  style="width:52px;height:52px">
                <i class="bi bi-shield-lock-fill text-primary fs-4"></i>
            </span>
        </div>

        <h1 class="h5 mb-1 text-center">Two-factor verification</h1>
        <p class="text-body-secondary small mb-4 text-center">
            Enter the 6-digit code from your authenticator app for
            <strong><?= e($username) ?></strong>.
        </p>

        <?php if (!empty($error) && is_string($error)): ?>
            <div class="alert alert-danger py-2 small">
                <i class="bi bi-exclamation-octagon-fill me-1"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('login/2fa')) ?>" autocomplete="off" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label" for="code">Authentication code</label>
                <input type="text" inputmode="numeric" pattern="[0-9]*"
                       class="form-control form-control-lg text-center letter-spacing"
                       id="code" name="code" maxlength="19"
                       autocomplete="one-time-code" autofocus required
                       placeholder="000000">
                <div class="form-text">
                    Or paste one of your single-use recovery codes.
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i>Verify and sign in
            </button>
        </form>

        <p class="small text-body-secondary text-center mt-3 mb-0">
            <i class="bi bi-clock me-1"></i>
            This step expires in about <?= (int)$minutes ?> minute(s).
            <a href="<?= e(url('login')) ?>" class="ms-1">Start over</a>
        </p>
    </div>
</div>
