<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Renders a view file inside the application layout (or standalone for the
 * login page and error pages).
 */
final class View
{
    /**
     * @param array<string, mixed>                  $data
     * @param array<int, array{label:string,url?:string}> $breadcrumb
     */
    public static function render(
        string $view,
        array $data = [],
        string $title = '',
        string $activeNav = '',
        array $breadcrumb = [],
        string $layout = 'layouts/app'
    ): void {
        $content   = self::partial($view, $data);
        $pageTitle = $title;
        $user      = $_SESSION['user'] ?? null;
        $flash     = flash_take();
        $navItems  = self::navigation();

        $layoutPath = APP_ROOT . '/app/views/' . $layout . '.php';

        if (!is_file($layoutPath)) {
            echo $content;
            return;
        }

        // Make the view variables visible to the layout.
        extract($data, EXTR_SKIP);

        require $layoutPath;
    }

    /**
     * Include a partial and return its HTML.
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $view, array $data = []): string
    {
        $path = APP_ROOT . '/app/views/' . $view . '.php';

        if (!is_file($path)) {
            log_fatal('view not found: ' . $view);

            return '<div class="alert alert-danger">This screen is temporarily unavailable.</div>';
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $path;

        return (string)ob_get_clean();
    }

    /** Friendly error page - never leaks SQL/PHP/credential details. */
    public static function error(int $code, string $message = ''): void
    {
        if (!headers_sent()) {
            http_response_code($code);
        }

        if (app_context() === 'api') {
            json_out(['ok' => false, 'message' => $message !== '' ? $message : 'Request failed.'], $code);
            exit;
        }

        $title = match ($code) {
            403   => 'Access denied',
            404   => 'Page not found',
            419   => 'Session expired',
            500   => 'Something went wrong',
            default => 'Error',
        };

        if ($message === '') {
            $message = match ($code) {
                403   => 'You do not have permission to open this screen.',
                404   => 'The page you are looking for does not exist or has been moved.',
                419   => 'Your session has expired. Please refresh the page and try again.',
                500   => 'Your request could not be completed. Please try again.',
                default => 'Please try again.',
            };
        }

        $content  = self::partial('errors/error', [
            'code'    => $code,
            'title'   => $title,
            'message' => $message,
        ]);
        $layoutPath = APP_ROOT . '/app/views/layouts/error.php';

        if (is_file($layoutPath)) {
            $pageTitle = $title;
            require $layoutPath;
        } else {
            echo $content;
        }

        // Always terminates. Without this the caller keeps running, so a 403
        // would print "Access denied" and then render the very admin screen it
        // was meant to block (and a POST would still execute its action).
        exit;
    }

    /**
     * Sidebar navigation. `roles` restricts an entry to specific roles; an
     * absent/empty list means "everyone".
     *
     * @return array<int, array{label:string, items:array<int, array<string, mixed>>}>
     */
    public static function navigation(): array
    {
        $sections = [
            [
                'label' => 'Dashboard',
                'items' => [
                    ['key' => 'dashboard', 'label' => 'Leave Dashboard', 'url' => 'dashboard', 'icon' => 'bi-speedometer2', 'permission' => 'dashboard.view'],
                    ['key' => 'driver-dashboard', 'label' => 'Driver Dashboard', 'url' => 'driver-dashboard', 'icon' => 'bi-people', 'permission' => 'dashboard.drivers'],
                ],
            ],
            [
                'label' => 'Planning & Capacity',
                'items' => [
                    ['key' => 'capacity-planning', 'label' => 'Driver Capacity Planning', 'url' => 'capacity-planning', 'icon' => 'bi-graph-up', 'permission' => 'reports.capacity'],
                ],
            ],
            [
                'label' => 'Address Book',
                'items' => [
                    ['key' => 'drivers', 'label' => 'Drivers', 'url' => 'drivers', 'icon' => 'bi-person-badge', 'permission' => 'drivers.view'],
                    // Visible to whoever can file a request OR whoever can
                    // decide one: a requester follows their own submission and
                    // an approver works the queue, so they share one door.
                    ['key' => 'driver-changes', 'label' => 'Change Requests', 'url' => 'driver-changes', 'icon' => 'bi-arrow-left-right', 'permission' => ['drivers.edit', 'drivers.change_request_approve']],
                ],
            ],
            [
                'label' => 'Leave & Vacation',
                'items' => [
                    ['key' => 'leave-new', 'label' => 'New Leave / Vacation', 'url' => 'leave/new', 'icon' => 'bi-plus-circle', 'permission' => 'leave.create'],
                    ['key' => 'leave-current', 'label' => 'Current Leave', 'url' => 'leave/current', 'icon' => 'bi-airplane', 'permission' => 'leave.view'],
                    ['key' => 'leave-records', 'label' => 'Leave Records', 'url' => 'leave/records', 'icon' => 'bi-journal-text', 'permission' => 'leave.view'],
                    ['key' => 'leave-calendar', 'label' => 'Calendar', 'url' => 'leave/calendar', 'icon' => 'bi-calendar3', 'permission' => 'leave.view'],
                    ['key' => 'leave-returns', 'label' => 'Expected Returns', 'url' => 'leave/returns', 'icon' => 'bi-arrow-repeat', 'badge' => 'returns', 'permission' => 'leave.view'],
                      ['key' => 'leave-applications', 'label' => 'Leave Applications', 'url' => 'leave-applications', 'icon' => 'bi-clipboard-check', 'permission' => 'leave.view'],
                ],
            ],
            [
                'label' => 'Reports',
                'items' => [
                    ['key' => 'report-leave', 'label' => 'Leave Report', 'url' => 'reports/leave', 'icon' => 'bi-file-earmark-text', 'permission' => 'reports.view'],
                    ['key' => 'report-return', 'label' => 'Return Report', 'url' => 'reports/return', 'icon' => 'bi-clipboard-check', 'permission' => 'reports.view'],
                    ['key' => 'report-branch', 'label' => 'Branch Report', 'url' => 'reports/branch', 'icon' => 'bi-diagram-3', 'permission' => 'reports.view'],
                    ['key' => 'report-agency', 'label' => 'Agency Report', 'url' => 'reports/agency', 'icon' => 'bi-building', 'permission' => 'reports.view'],
                ],
            ],
            [
                'label' => 'Settings',
                'items' => [
                    ['key' => 'settings-leave-types', 'label' => 'Leave Types', 'url' => 'settings/leave-types', 'icon' => 'bi-tags', 'permission' => 'settings.leave_types'],
                    ['key' => 'settings-branches', 'label' => 'Branches', 'url' => 'settings/branches', 'icon' => 'bi-geo-alt', 'permission' => 'settings.branches'],
                    ['key' => 'settings-agencies', 'label' => 'Agencies', 'url' => 'settings/agencies', 'icon' => 'bi-briefcase', 'permission' => 'settings.agencies'],
                    ['key' => 'settings-import', 'label' => 'Import / Export', 'url' => 'import', 'icon' => 'bi-arrow-left-right', 'permission' => 'drivers.import', 'admin_only' => true],
                    // Formerly the "User Accounts" tab on System Settings: its
                    // own entry so settings.users alone opens it, without also
                    // granting (or requiring) the system screen.
                    ['key' => 'settings-users', 'label' => 'Users', 'url' => 'settings/users', 'icon' => 'bi-people', 'permission' => 'settings.users'],
                    ['key' => 'settings-roles', 'label' => 'Roles & Permissions', 'url' => 'settings/roles', 'icon' => 'bi-shield-lock', 'permission' => ['settings.roles', 'settings.permissions']],
                    ['key' => 'settings-system', 'label' => 'System Settings', 'url' => 'settings/system', 'icon' => 'bi-gear', 'permission' => 'settings.system'],
                    ['key' => 'settings-audit', 'label' => 'Audit Log', 'url' => 'settings/audit', 'icon' => 'bi-clock-history', 'permission' => 'audit.view'],
                ],
            ],
        ];

        // A nav entry declares the permission it needs - never a role name -
        // so re-pointing a role at a different permission updates the menu
        // without touching this method.
        //
        // admin_only is the one deliberate exception: bulk-importing the
        // Address Book rewrites every driver in one POST, so the shortcut to
        // it is an administrator's even though the permission itself (and the
        // route behind it) still serves the roles that hold drivers.import.
        // That keeps the menu narrower than the permission instead of widening
        // the permission to match the menu.
        foreach ($sections as &$section) {
            $section['items'] = array_values(
                array_filter($section['items'], static function (array $item): bool {
                    $permission = $item['permission'] ?? null;

                    return ($permission === null || can_any((array) $permission))
                        && (empty($item['admin_only']) || is_administrator());
                })
            );
        }
        unset($section);

        return array_values(array_filter($sections, static function (array $section): bool {
            return $section['items'] !== [];
        }));
    }
}

