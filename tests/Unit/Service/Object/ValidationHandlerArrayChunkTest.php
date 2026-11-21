<?php

/**
 * ValidationHandler::validateAndSaveObjectsBySchema() saves loaded rows that are plain arrays as they are.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Object\SchemaRevalidation;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Db\SchemaCatalog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;

/**
 * A chunk may hold magic-table rows as arrays next to ObjectEntity instances; both reach the bulk save.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidationHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Object\ValidationChunk
 * @uses \OCA\OpenRegister\Service\Object\ValidationRun
 * @uses   \OCA\OpenRegister\Db\RegisterSchema
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Service\Object\SchemaRevalidation
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Db\RegisterSchema
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses \OCA\OpenRegister\Service\Object\SchemaRevalidation
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
				BulkSaveOptions $options = new BulkSaveOptions(),
				mixed ...$flags,
			): array {
				$this->calls[] = [$objects, $options->defaults->register, $options->defaults->schema];
				return ['statistics' => ['saved' => count($objects), 'updated' => 0, 'failed' => 0]];
			}//end saveObjects()
		};

		$handler = new ValidationHandler(
			validateHandler: $this->createMock(originalClassName: ValidateObject::class),
			catalog: new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $magicMapper),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$result = $handler->validateAndSaveObjectsBySchema(revalidation: new SchemaRevalidation(table: new RegisterSchemaRef(register: 3, schema: 4), query: ListQuery::unbounded(), saveCallback: [$saver, 'saveObjects']));

		$this->assertSame(expected: [[[$row, $entity->getObject()], 3, 4]], actual: $saver->calls);
		$this->assertSame(expected: 2, actual: $result['processed']);
		$this->assertSame(expected: 2, actual: $result['updated']);
		$this->assertSame(expected: 0, actual: $result['failed']);
	}//end testArrayRowsAndEntitiesBothReachTheBulkSave()
}//end class
