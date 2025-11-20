<?php
/**
 * Bootstrap file for Integration Tests
 *
 * Integration tests use HTTP client (Guzzle) to test the API
 * and don't need Nextcloud environment loaded
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

// No Nextcloud bootstrap needed for integration tests.
// They use HTTP client to test the running API.

