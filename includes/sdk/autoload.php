<?php
/**
 * Standalone PSR-4 Autoloader for PayCow PHP SDK
 *
 * Allows using the SDK without Composer in standalone environments.
 *
 * @package PayCow
 */

spl_autoload_register(function ($class) {
    $prefix = 'PayCow\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
