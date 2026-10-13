<?php

declare(strict_types=1);

$GLOBALS['app_context'] = 'web';

require __DIR__ . '/app/bootstrap.php';

logout();
redirect('login');
