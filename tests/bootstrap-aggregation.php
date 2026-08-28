<?php

/**
 * Minimal bootstrap for aggregation unit tests.
 * Loads Composer autoloader and registers the OCP namespace from nextcloud/ocp.
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__ . '/../vendor/autoload.php';

// Register the OCP and NCU namespaces from the nextcloud/ocp Composer package.
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
$loader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
// Doctrine DBAL is bundled in NC core 3rdparty.
$loader->addPsr4('Doctrine\\DBAL\\', '/srv/nextcloud/3rdparty/doctrine/dbal/src/');
$loader->addPsr4('Doctrine\\Common\\', '/srv/nextcloud/3rdparty/doctrine/common/src/');
$loader->register(true);
