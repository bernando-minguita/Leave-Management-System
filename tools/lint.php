<?php

declare(strict_types=1);


/**
 * Lint every PHP file in the project.
 *
 *   php tools/lint.php
 *
 * Exits non-zero when any file has a parse error, so it can gate a build.
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool must be run from the command line.\n");
}

$root = dirname(__DIR__);

$binary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$errors = 0;
$checked = 0;

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();

    // Skip vendored frontend libraries if they ever appear here.
    if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bootstrap') !== false) {
        continue;
    }

    $checked++;

    $command = escapeshellarg($binary) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    exec($command, $output, $code);

    if ($code !== 0) {
        $errors++;
        echo implode("\n", $output) . "\n";
    }

    $output = [];
}

echo "Checked $checked file(s), $errors error(s).\n";
exit($errors > 0 ? 1 : 0);
