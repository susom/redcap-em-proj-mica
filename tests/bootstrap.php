<?php

/**
 * PHPUnit bootstrap - deliberately framework-free.
 *
 * Nothing here loads `redcap_connect.php`, and nothing under test may need it. The phase-3 classes
 * take their REDCap dependencies through constructor injection precisely so the suite runs on a
 * bare PHP with no database, no session and no project (see 06-implementation-plan/README.md,
 * cross-cutting decision 1). If a test starts needing a REDCap bootstrap, the class under test has
 * a design problem - fix the class, not this file.
 *
 * Two autoloaders, because the module ships its vendor/ and must not ship a test framework:
 * vendor/ is runtime-only (opis + the Stanford\MICA\ PSR-4 map), tools/vendor/ is PHPUnit.
 */

$module = __DIR__ . '/../vendor/autoload.php';
if (!is_file($module)) {
    fwrite(STDERR, "vendor/autoload.php is missing. Run `composer install`.\n");
    exit(1);
}
require_once $module;

$tools = __DIR__ . '/../tools/vendor/autoload.php';
if (!is_file($tools)) {
    fwrite(STDERR, "The test toolchain is not installed. Run `composer test:install`.\n");
    exit(1);
}
require_once $tools;

// Registered here rather than as `autoload-dev` in the module's composer.json: that section is
// stripped from a --no-dev build, which is exactly what the module ships.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Stanford\\MICA\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
