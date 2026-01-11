<?php
/**
 * Bootstrap file for Unit Tests (without full Nextcloud bootstrap)
 *
 * This bootstrap loads only the minimal requirements for unit tests,
 * avoiding the full Nextcloud bootstrap that checks config writability.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Load Nextcloud's autoloader to access OCP classes, but skip the full bootstrap.
// This gives us access to Nextcloud interfaces and classes without triggering
// the config writability check or loading the entire application.
if (file_exists(__DIR__ . '/../../../3rdparty/autoload.php')) {
    require_once __DIR__ . '/../../../3rdparty/autoload.php';
}

// Load Nextcloud's lib autoloader for OCP classes.
if (file_exists(__DIR__ . '/../../../lib/composer/autoload.php')) {
    require_once __DIR__ . '/../../../lib/composer/autoload.php';
}

error_log("[UNIT TEST BOOTSTRAP] Minimal bootstrap complete - ready for unit tests");

