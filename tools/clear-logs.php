<?php

declare(strict_types=1);


/**
 * Wipe storage/logs (safe to run at any time).
 *
 *   php tools/clear-logs.php
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool must be run from the command line.\n");
}

require dirname(__DIR__) . '/app/config/config.php';

if (!is_dir(LOG_PATH)) {
    echo "No log folder yet.\n";
    exit(0);
}

$removed = 0;
foreach (glob(LOG_PATH . '/*.log') ?: [] as $file) {
    if (is_file($file)) {
        unlink($file);
        $removed++;
    }
}

echo "Removed $removed log file(s).\n";
