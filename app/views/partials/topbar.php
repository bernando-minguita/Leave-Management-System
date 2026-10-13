<?php
/** Top bar: hamburger, page title, date/clock, user menu. */
$user = $user ?? ($_SESSION['user'] ?? null);

// One question, answered once, so the Audit shortcut and the Settings menu
// can never disagree with the sidebar.
$canAudit    = can('audit.view');
$canSettings = can_any([
    'settings.users', 'settings.roles', 'settings.permissions',
    'settings.branches', 'settings.agencies', 'settings.leave_types',
    'settings.system',
]);
// A holder of settings.users only must not be sent to System Settings (403):
// the menu lands on whichever settings screen their permission opens.
$settingsUrl = can('settings.system') ? 'settings/system' : 'settings/users';
?>

<header class="app-topbar">
    <div class="topbar-start">
        <button class="btn btn-light border sidebar-toggle d-lg-none" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#sidebarDrawer" aria-label="Open menu">
            <i class="bi bi-list"></i>
        </button>

        <div class="topbar-title">
            <h1 class="h5 mb-0"><?= e($pageTitle ?? '') ?></h1>
            <span class="small text-body-secondary d-none d-md-inline">
                <i class="bi bi-calendar3 me-1"></i><?= e(date('l')) ?>, <?= e(fmt_date(today())) ?>
            </span>
        </div>
    </div>

    <div class="topbar-end">
        <?php if ($canAudit): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('settings/audit')) ?>"
               title="Audit log">
                <i class="bi bi-clock-history"></i>
                <span class="d-none d-sm-inline ms-1">Audit</span>
            </a>
        <?php endif; ?>

        <div class="dropdown">
            <button class="btn btn-light border dropdown-toggle user-menu" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar"><i class="bi bi-person-circle"></i></span>
                <span class="user-copy">
                    <strong><?= e($user['full_name'] ?? 'Guest') ?></strong>
                    <small><?= e(current_role_label()) ?></small>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <span class="dropdown-item-text small text-body-secondary">
                        Signed in as <strong><?= e($user['username'] ?? '') ?></strong>
                    </span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="<?= e(url('profile')) ?>">
                        <i class="bi bi-person-circle me-2"></i>My Profile
                    </a>
                </li>
                <?php if ($canSettings): ?>
                    <li>
                        <a class="dropdown-item" href="<?= e(url($settingsUrl)) ?>">
                            <i class="bi bi-gear me-2"></i>Settings
                        </a>
                    </li>
                <?php endif; ?>
                <li>
                    <a class="dropdown-item text-danger" href="<?= e(url('logout')) ?>">
                        <i class="bi bi-box-arrow-right me-2"></i>Sign out
                    </a>
                </li>
            </ul>
        </div>
    </div>
</header>
