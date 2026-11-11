<?php

/**
 * Characterisation test for MagicMapper::findBySchema().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Pins which tables findBySchema() lists and what it hands each listing.
 */
class MagicMapperFindBySchemaTest extends TestCase {

	/**
	 * The listings asked for: register id, schema id, limit, offset, filters.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A mapper over two tables of schema 7 (registers 1 and 2) and one of schema 8.
	 *
	 * @return MagicMapper
	 */
	private function mapper(): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['getAllRegisterSchemaPairs', 'findAllInRegisterSchemaTable'])
			->getMock();
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int)$id);
				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);
		(new ReflectionProperty(MagicMapper::class, 'logger'))->setValue($mapper, new NullLogger());
		(new ReflectionProperty(MagicMapper::class, 'registerMapper'))->setValue($mapper, $registers);
		(new ReflectionProperty(MagicMapper::class, 'schemaMapper'))->setValue($mapper, $schemas);
		$mapper->method('getAllRegisterSchemaPairs')->willReturn(
			[['registerId' => 1, 'schemaId' => 7], ['registerId' => 1, 'schemaId' => 8], ['registerId' => 2, 'schemaId' => '7']]
		);
		$mapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (RegisterSchema $table, ListQuery $listing): array {
				[$register, $schema, $limit, $offset, $filters] = [$table->register, $table->schema, $listing->limitOrNull(), $listing->offset, ($listing->filters === [] ? null : $listing->filters)];
				$this->calls[] = [$register->getId(), $schema->getId(), $limit, $offset, $filters];
				return [new ObjectEntity()];
			}
		);

		return $mapper;
	}//end mapper()

	public function testListsEveryTableOfTheSchemaWithThePageAndFilter(): void {
		$found = $this->mapper()->findBySchema(7, 10, 20, ['a' => 1]);

		$this->assertCount(2, $found);
		$this->assertSame([[1, 7, 10, 20, ['a' => 1]], [2, 7, 10, 20, ['a' => 1]]], $this->calls);
	}//end testListsEveryTableOfTheSchemaWithThePageAndFilter()

	public function testWithoutLimitAsksEachTableForEveryRow(): void {
		$this->mapper()->findBySchema(8);

		$this->assertCount(1, $this->calls);
		$this->assertSame([1, 8, null, null], array_slice($this->calls[0], 0, 4));
		$this->assertSame([], (array)$this->calls[0][4]);
	}//end testWithoutLimitAsksEachTableForEveryRow()
}//end class
