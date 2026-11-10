<?php

/**
 * A schema-wide revalidation reports each chunk it processes: which chunk of
 * how many, its size, and the schema it belongs to.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Object\SchemaRevalidation;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Db\SchemaCatalog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Chunk reporting tests for {@see ValidationHandler}.
 */
class ValidationHandlerChunkLogTest extends TestCase {

	/**
	 * Three rows are processed as one chunk, reported as chunk 1 of 1 with
	 * its size and schema.
	 *
	 * @return void
	 */
	public function testEachChunkIsReportedWithItsPositionAndSize(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturn([['id' => 'a'], ['id' => 'b'], ['id' => 'c']]);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$handler = new ValidationHandler(
			validateHandler: $this->createMock(ValidateObject::class),
			catalog: new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $magicMapper),
			logger: $logger
		);

		$saver = new class {
			/**
			 * Save a chunk, reporting every object saved.
			 *
			 * @param array<int, mixed> $objects The chunk.
			 * @param mixed ...$rest Register, schema and flags.
			 *
			 * @return array<string, mixed> The statistics.
			 */
			public function saveObjects(array $objects, mixed ...$rest): array {
				return ['statistics' => ['saved' => count($objects), 'updated' => 0, 'failed' => 0]];
			}//end saveObjects()
		};

		$handler->validateAndSaveObjectsBySchema(revalidation: new SchemaRevalidation(table: new RegisterSchemaRef(register: 3, schema: 4), query: ListQuery::unbounded(), saveCallback: [$saver, 'saveObjects']));

		$chunks = array_values(array_filter($logged, static fn (array $entry): bool => $entry[0] === '[ValidationHandler] Processing validation chunk'));
		$this->assertCount(1, $chunks);
		$this->assertSame('1/1', $chunks[0][1]['chunk']);
		$this->assertSame(3, $chunks[0][1]['chunk_size']);
		$this->assertSame(4, $chunks[0][1]['schema_id']);
	}//end testEachChunkIsReportedWithItsPositionAndSize()
}//end class
