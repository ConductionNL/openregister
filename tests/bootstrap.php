<?php
/**
 * Bootstrap file for PHPUnit tests
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

// Bootstrap Nextcloud if not already done.
if (!defined('OC_CONSOLE')) {
    // Try to include the main Nextcloud bootstrap.
    if (file_exists(__DIR__ . '/../../../lib/base.php')) {
        require_once __DIR__ . '/../../../lib/base.php';
    }
    
    // Load all enabled apps.
    \OC_App::loadApps();
    
    // Load our specific app.
    \OC_App::loadApp('openregister');
    
    // Clear hooks for testing.
    OC_Hook::clear();
} 