<?php

/**
 * Bootstrap file for standalone Unit Tests (no Nextcloud installation required).
 *
 * This bootstrap is used when running unit tests outside of a Nextcloud Docker
 * container. It only loads the Composer autoloader and stubs required NC classes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP namespace manually since nextcloud/ocp has no autoload config.
$loader = new \Composer\Autoload\ClassLoader();
$ocpPath = __DIR__ . '/../vendor/nextcloud/ocp';
if (is_dir($ocpPath)) {
    $loader->addPsr4('OCP\\', $ocpPath . '/OCP');
    $loader->addPsr4('NCU\\', $ocpPath . '/NCU');
    $loader->register(true);
}

// Stub internal OC\ classes that OCP interfaces extend but that are only
// available inside the full Nextcloud environment.
// We create a stub file and include it to work around namespace syntax.
$stubFile = sys_get_temp_dir() . '/oc_hooks_stub_' . getmypid() . '.php';
file_put_contents($stubFile, "<?php\nnamespace OC\\Hooks;\ninterface Emitter {}\n");
require_once $stubFile;
