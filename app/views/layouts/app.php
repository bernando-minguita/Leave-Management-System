<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <!-- Settings > Display date format, as a date() pattern. Any date a script
         renders (the Mark Return modal, the leave helpers) uses this so it
         matches every server-rendered date on the page. -->
    <meta name="date-format" content="<?= e(date_format_pattern()) ?>">
    <!-- Which screen this is. The column-preferences script builds ONE
         Columns control from it, so a table never has to carry its own key -
         a grid added to this screen later joins automatically. Falls back to
         the (base-stripped) path when a screen has no sidebar key. NB: do not
         spell that script's filename here - it now loads from this very
         <head>, and the load-order test looks for the FIRST mention of the
         filename in this document and asserts it is still in the head. -->
    <meta name="page-key" content="<?= e($activeNav !== '' ? $activeNav : request_path()) ?>">
    <title><?= e($pageTitle !== '' ? $pageTitle . ' · ' : '') ?><?= e(defined('APP_NAME') ? APP_NAME : 'LMS') ?></title>

    <!-- All libraries are vendored locally: the application needs no internet
         access and no Node.js build step. -->
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/print.css')) ?>" media="print">

    <link rel="icon" href="<?= e(url('assets/images/favicon.svg')) ?>">

    <?php // Column show/hide + resize. This one deliberately loads in the HEAD,
          // while every other script still loads at the end of the document.
          //
          // It has to be downloaded, parsed and EXECUTED before the body
          // exists: table-prefs.js re-applies the reader's saved preferences
          // from a MutationObserver while the parser is still working, and an
          // observer callback is a microtask - it runs before the browser's
          // rendering step. Placed at the end of the body it ran on
          // DOMContentLoaded, which is AFTER the grid has been painted, so
          // every load and every filtered query flashed the full set of
          // columns first and hid them a beat later.
          //
          // It reads nothing from table.js, so the order between the two no
          // longer matters; what matters is that this one is early.
          // Versioned off its own mtime so a patch upload cannot leave a
          // browser running an older copy. ?>
    <script src="<?= e(url('assets/js/table-prefs.js')) ?>?v=<?= (int)@filemtime(APP_ROOT . '/assets/js/table-prefs.js') ?>"></script>
</head>
<body>
<div class="app-shell">

    <?php require APP_ROOT . '/app/views/partials/sidebar.php'; ?>

    <div class="app-main">
        <?php require APP_ROOT . '/app/views/partials/topbar.php'; ?>

        <main class="app-content">
            <?php require APP_ROOT . '/app/views/partials/flash.php'; ?>

            <?php if (!empty($breadcrumb)): ?>
                <nav aria-label="breadcrumb" class="mb-2">
                    <ol class="breadcrumb small mb-0">
                        <?php foreach ($breadcrumb as $i => $crumb): ?>
                            <?php $last = $i === count($breadcrumb) - 1; ?>
                            <li class="breadcrumb-item<?= $last ? ' active' : '' ?>"
                                <?= $last ? 'aria-current="page"' : '' ?>>
                                <?php if (!$last && !empty($crumb['url'])): ?>
                                    <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
                                <?php else: ?>
                                    <?= e($crumb['label']) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<!-- Shared modals (mark returned / cancel) live outside the page content so
     every screen can open them. -->
<?php require APP_ROOT . '/app/views/partials/modals.php'; ?>
<?php require APP_ROOT . '/app/views/partials/modals-confirm.php'; ?>

<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>?v=<?= (int)filemtime(APP_ROOT . '/assets/js/app.js') ?>"></script>
<?php // The table engine: sorting, paging and rows-per-page happen in place
      // instead of reloading the page. Versioned off its own mtime so a patch
      // upload can never leave a browser running an older copy. ?>
<script src="<?= e(url('assets/js/table.js')) ?>?v=<?= (int)@filemtime(APP_ROOT . '/assets/js/table.js') ?>"></script>
<?php // Column show/hide + resize (data-dd-prefs) is NOT here: it loads in the
      // head, because it has to apply the reader's saved preferences while the
      // grid is still being parsed - see the comment above </head>. ?>
<?php if (!empty($extraScripts)): foreach ((array)$extraScripts as $script): ?>
    <script src="<?= e(url('assets/js/' . basename((string)$script))) ?>?v=<?= is_file((string)$script) ? (int)filemtime((string)$script) : 0 ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
