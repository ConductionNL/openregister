<?php

/**
 * FilePropertyConfigs resolves a file property's configuration.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyConfigs;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyConfigs
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class FilePropertyConfigsTest extends TestCase {

	/**
	 * An array property yields its items' configuration, with the schema's autoShare.
	 *
	 * @return void
	 */
	public function testArrayItemsTakeTheSchemaAutoShare(): void {
		$schema = $this->schema(properties: ['docs' => ['type' => 'array', 'items' => ['type' => 'file']]], configuration: ['autoShare' => true]);

		$this->assertSame(['type' => 'file', 'autoShare' => true], $this->configs()->fileConfigOf($schema, 'docs'));
	}//end testArrayItemsTakeTheSchemaAutoShare()

	/**
	 * The property's own autoShare wins over the schema's.
	 *
	 * @return void
	 */
	public function testThePropertyAutoShareWins(): void {
		$schema = $this->schema(properties: ['doc' => ['type' => 'file', 'autoShare' => false]], configuration: ['autoShare' => true]);

		$this->assertSame(['type' => 'file', 'autoShare' => false], $this->configs()->fileConfigOf($schema, 'doc'));
	}//end testThePropertyAutoShareWins()

	/**
	 * The legacy autoPublish key is warned about and not applied.
	 *
	 * @return void
	 */
	public function testAutoPublishIsWarnedAndIgnored(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$schema = $this->schema(properties: ['doc' => ['type' => 'file']], configuration: ['autoPublish' => true]);

		$this->assertSame(['type' => 'file'], (new FilePropertyConfigs(new FilePropertyLog($logger)))->fileConfigOf($schema, 'doc'));
	}//end testAutoPublishIsWarnedAndIgnored()

	/**
	 * A property missing from the schema is refused.
	 *
	 * @return void
	 */
	public function testAMissingPropertyIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Property 'doc' not found in schema configuration");

		$this->configs()->fileConfigOf($this->schema(properties: [], configuration: null), 'doc');
	}//end testAMissingPropertyIsRefused()

	/**
	 * A property that is not a file is refused.
	 *
	 * @return void
	 */
	public function testANonFilePropertyIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Property 'doc' is not configured as a file property");

		$this->configs()->fileConfigOf($this->schema(properties: ['doc' => ['type' => 'string']], configuration: null), 'doc');
	}//end testANonFilePropertyIsRefused()

	/**
	 * A resolver over a silent logger.
	 *
	 * @return FilePropertyConfigs
	 */
	private function configs(): FilePropertyConfigs {
		return new FilePropertyConfigs(new FilePropertyLog($this->createMock(LoggerInterface::class)));
	}//end configs()

	/**
	 * A schema with these properties and configuration.
	 *
	 * @param array<string, mixed>      $properties    The properties.
	 * @param array<string, mixed>|null $configuration The configuration.
	 *
	 * @return Schema
	 */
	private function schema(array $properties, ?array $configuration): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()
}//end class
