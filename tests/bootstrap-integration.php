<?php
/**
 * Lightweight bootstrap for Integration provider unit tests.
 *
 * The integration service classes (IntegrationProvider, ExternalIntegrationRouter,
 * OpenProjectProvider, IntegrationRegistry) do not require the full Nextcloud
 * server environment. This bootstrap loads only the Composer autoloader and
 * stubs the minimal OCP interfaces needed so PHPUnit mock creation works.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-6
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__ . '/../vendor/autoload.php';

// Stub minimal OCP\IConfig so it can be mocked.
if (interface_exists('\OCP\IConfig') === false) {
    eval('namespace OCP; interface IConfig { public function getSystemValue(string $valueName, mixed $default = ""): mixed; public function getSystemValueString(string $key, string $default = ""): string; public function getUserValue(string $userId, string $appName, string $key, string $default = ""): string; public function setUserValue(string $userId, string $appName, string $key, string $value): void; }');
}
