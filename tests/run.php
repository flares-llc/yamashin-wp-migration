<?php
/**
 * Standalone test runner.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.0-cli php tests/run.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$files = glob(__DIR__ . '/test-*.php');
sort($files);

foreach ($files as $file) {
    require_once $file;
}

exit(T::summary());
