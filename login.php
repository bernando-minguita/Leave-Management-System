<?php

declare(strict_types=1);

use App\Core\View;

$GLOBALS['app_context'] = 'web';

require __DIR__ . '/app/bootstrap.php';

if (is_logged_in()) {
    redirect('dashboard');
}

View::render('auth/login', [
    'error'    => null,
    'username' => '',
    'redirect' => '',
], 'Sign in', '', [], 'layouts/auth');
