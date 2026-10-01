<?php

declare(strict_types=1);

$candidates = [
    // Standalone package install
    dirname(__DIR__) . '/vendor/autoload.php',
    // OT_cap / Bedrock host project (vendor/wonderwp/service)
    dirname(__DIR__, 3) . '/autoload.php',
];

$autoload = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, "Unable to locate Composer autoload.php for wonderwp/service tests.\n");
    exit(1);
}

require_once $autoload;
require_once __DIR__ . '/stub.php';

// Host-project autoload only maps src/; register tests namespace explicitly.
spl_autoload_register(static function (string $class): void {
    $prefix = 'WonderWp\\Component\\Service\\Tests\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Trait lives beside the testable class file.
require_once __DIR__ . '/TestableServicesAutoloaderService.php';

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wwp-service-tests-content');
}

if (!is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}
