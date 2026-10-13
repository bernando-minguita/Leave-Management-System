<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e(($pageTitle ?? '') !== '' ? $pageTitle . ' · ' : '') ?><?= e(defined('APP_NAME') ? APP_NAME : 'LMS') ?></title>

    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <link rel="icon" href="<?= e(url('assets/images/favicon.svg')) ?>">
</head>
<body class="auth-body">
<main class="auth-wrap">
    <div class="auth-brand">
        <span class="brand-mark"><i class="bi bi-truck"></i></span>
        <div>
            <strong><?= e(defined('APP_NAME') ? APP_NAME : 'LMS') ?></strong>
            <small><?= e(defined('APP_TAGLINE') ? APP_TAGLINE : 'Driver Leave & Vacation Management') ?></small>
        </div>
    </div>

    <?= $content ?>

    <p class="auth-foot small text-body-secondary mb-0">
        <?= e(APP_TIMEZONE) ?> · <?= e(fmt_date(today())) ?>
    </p>
</main>

<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
