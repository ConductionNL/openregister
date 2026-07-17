<?php
/**
 * Lightweight bootstrap for EML parser unit tests.
 *
 * EmlParser and its value objects do not require the full Nextcloud server
 * environment. This bootstrap loads only the Composer autoloader and stubs
 * the OCP\Files\File interface so PHPUnit mock creation works without needing
 * the NC base.php.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__ . '/../vendor/autoload.php';

// Stub minimal OCP interfaces that EmlParser tests reference as mocks.
// This avoids requiring the full Nextcloud bootstrap.
if (interface_exists('\OCP\Files\File') === false) {
    // Dynamically create the interface stub so PHPUnit can createMock() it.
    eval('namespace OCP\Files; interface Node {} interface File extends Node { public function getContent(): string; public function getId(): int; public function getName(): string; }');
}
