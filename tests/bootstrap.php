<?php

/**
 * PHPUnit bootstrap - deliberately framework-free.
 *
 * Nothing here loads `redcap_connect.php`, and nothing under test may need it. The phase-3 classes
 * take their REDCap dependencies through constructor injection precisely so the suite runs on a
 * bare PHP with no database, no session and no project (see 06-implementation-plan/README.md,
 * cross-cutting decision 1). If a test starts needing a REDCap bootstrap, the class under test has
 * a design problem - fix the class, not this file.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing. Run `composer install` first.\n");
    exit(1);
}
require_once $autoload;
