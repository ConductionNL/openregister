<?php
/**
 * Standalone unit test bootstrap — no Nextcloud installation required.
 *
 * Uses the OCP stubs from vendor/nextcloud/ocp and the app's own autoloader.
 * Suitable for CI environments where Nextcloud is not fully installed.
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

// App autoloader (also registers OCP stubs from nextcloud/ocp).
require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP stubs so Nextcloud interfaces are available without a running NC instance.
$ocpSrc = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
if (is_dir($ocpSrc) === true) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('OCP\\', $ocpSrc);
    $loader->register(true);
}
