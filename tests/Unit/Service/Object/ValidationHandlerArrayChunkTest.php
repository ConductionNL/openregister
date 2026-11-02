<?php

/**
 * ValidationHandler::validateAndSaveObjectsBySchema() saves loaded rows that are plain arrays as they are.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A chunk may hold magic-table rows as arrays next to ObjectEntity instances; both reach the bulk save.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidationHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ValidationHandlerArrayChunkTest extends TestCase {

	/**
	 * Array rows pass through unchanged and entities contribute their object data, in order.
	 *
	 * @return void
	 */
	public function testArrayRowsAndEntitiesBothReachTheBulkSave(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['title' => 'from entity']);
		$row = ['title' => 'from row', 'id' => 'r-1'];

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());
		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$magicMapper = $this->createMock(originalClassName: MagicMapper::class);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturn([$row, $entity]);

		$saver = new class {
			/**
			 * Calls received, as [objects, register, schema].
			 *
			 * @var list<array{0: array, 1: mixed, 2: mixed}>
			 */
			public array $calls = [];

			/**
			 * Record a bulk save and report every object as saved.
			 *
			 * @param array $objects  The objects to save.
			 * @param mixed $register The register.
			 * @param mixed $schema   The schema.
			 * @param mixed ...$flags The scope, validation and event flags.
			 *
			 * @return array{statistics: array{saved: int, updated: int, failed: int}}
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
			 */
			public function saveObjects(
				array $objects,
				mixed $register = null,
				mixed $schema = null,
				mixed ...$flags,
			): array {
				$this->calls[] = [$objects, $register, $schema];
				return ['statistics' => ['saved' => count($objects), 'updated' => 0, 'failed' => 0]];
			}//end saveObjects()
		};

		$handler = new ValidationHandler(
			validateHandler: $this->createMock(originalClassName: ValidateObject::class),
			objectEntityMapper: $this->createMock(originalClassName: MagicMapper::class),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			magicMapper: $magicMapper,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$result = $handler->validateAndSaveObjectsBySchema(registerId: 3, schemaId: 4, saveCallback: [$saver, 'saveObjects']);

		$this->assertSame(expected: [[[$row, $entity->getObject()], 3, 4]], actual: $saver->calls);
		$this->assertSame(expected: 2, actual: $result['processed']);
		$this->assertSame(expected: 2, actual: $result['updated']);
		$this->assertSame(expected: 0, actual: $result['failed']);
	}//end testArrayRowsAndEntitiesBothReachTheBulkSave()
}//end class
