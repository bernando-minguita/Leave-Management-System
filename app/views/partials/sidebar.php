<?php
/**
 * Sidebar navigation. Collapses to an off-canvas drawer below the lg
 * breakpoint (toggled from the topbar hamburger).
 */
$activeNav = $activeNav ?? '';
$navItems  = $navItems ?? [];
$currentPath = request_path();
?>

<aside class="app-sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <span class="brand-mark"><i class="bi bi-truck"></i></span>
        <span class="brand-copy">
            <strong><?= e(defined('APP_NAME') ? APP_NAME : 'LMS') ?></strong>
            <small>Driver Leave Management</small>
        </span>
        <button class="btn btn-sm btn-link sidebar-close d-lg-none" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#sidebarDrawer" aria-label="Close menu">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($navItems as $section): ?>
            <?php if (($section['label'] ?? '') !== ''): ?>
                <div class="sidebar-heading"><?= e($section['label']) ?></div>
            <?php endif; ?>

            <ul class="sidebar-menu">
                <?php foreach ($section['items'] as $item): ?>
                    <?php
                    $isActive = ($activeNav === ($item['key'] ?? ''))
                        || ($item['key'] === 'drivers' && strpos($currentPath, 'drivers') === 0)
                        || ($item['key'] === 'leave-calendar' && $currentPath === 'leave/calendar');
                    ?>
                    <li>
                        <a class="sidebar-link<?= $isActive ? ' is-active' : '' ?>"
                           href="<?= e(url($item['url'])) ?>">
                            <i class="bi <?= e($item['icon'] ?? 'bi-circle') ?>"></i>
                            <span><?= e($item['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="small">
            <i class="bi bi-patch-check me-1"></i>Version 1.0
        </div>
        <div class="small text-body-secondary">
            <a href="mailto:Bernando.Minguita@AlrashedTransport.Com"
               title="Bernando.Minguita@AlrashedTransport.Com"><i
               class="bi bi-envelope-fill me-1 align-middle"></i>Bernando Jr Minguita</a>
        </div>
        <div class="small text-body-secondary">
            <?= e(fmt_date(today())) ?>
        </div>
    </div>
</aside>

<!-- Same navigation, rendered as an off-canvas for tablets and phones. -->
<div class="offcanvas offcanvas-start sidebar-drawer" tabindex="-1" id="sidebarDrawer"
     aria-labelledby="sidebarDrawerLabel">
    <div class="offcanvas-header">
        <h6 class="offcanvas-title" id="sidebarDrawerLabel">
            <i class="bi bi-truck me-2"></i><?= e(defined('APP_NAME') ? APP_NAME : 'LMS') ?>
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
        <nav class="sidebar-nav">
            <?php foreach ($navItems as $section): ?>
                <?php if (($section['label'] ?? '') !== ''): ?>
                    <div class="sidebar-heading"><?= e($section['label']) ?></div>
                <?php endif; ?>
                <ul class="sidebar-menu">
                    <?php foreach ($section['items'] as $item): ?>
                        <li>
                            <a class="sidebar-link<?= $activeNav === ($item['key'] ?? '') ? ' is-active' : '' ?>"
                               href="<?= e(url($item['url'])) ?>"
                               data-bs-dismiss="offcanvas">
                                <i class="bi <?= e($item['icon'] ?? 'bi-circle') ?>"></i>
                                <span><?= e($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>
    </div>
</div>
