<?php
/**
 * Minimal bootstrap for unit tests that do not require a running Nextcloud server.
 * Loads only the composer autoloader + OCP stubs provided by nextcloud/ocp.
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__ . '/../vendor/autoload.php';

// OCP has no composer autoload config; register the namespace manually.
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
$loader->addPsr4('OCA\\', __DIR__ . '/../../../apps/');
$loader->register(true);
