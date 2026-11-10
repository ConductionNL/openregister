<?php

/**
 * Characterisation test for the register+schema listing and batch-save entry points of MagicMapper.
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

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicListRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Pins what findAllListed(), findAllInRegisterSchemaTable() and saveObjectsToRegisterSchemaTable()
 * hand to the per-table search and save.
 */
class MagicMapperTableListingTest extends TestCase {

	/**
	 * The queries handed to the per-table search.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * A mapper whose per-table search and save are recorded.
	 *
	 * @return MagicMapper&MockObject
	 */
	private function mapper(): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['searchObjectsInRegisterSchemaTable', 'ensureTableForRegisterSchema', 'getTableNameForRegisterSchema', 'saveObjectToRegisterSchemaTable'])
			->getMock();
		(new ReflectionProperty(MagicMapper::class, 'logger'))->setValue($mapper, new NullLogger());
		$mapper->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query): array {
				$this->queries[] = $query;
				$entity = new ObjectEntity();
				return [$entity];
			}
		);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');
		$mapper->method('ensureTableForRegisterSchema')->willReturn(true);

		return $mapper;
	}//end mapper()

	/**
	 * A register and schema with ids.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function table(): array {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		return [$register, $schema];
	}//end table()

	public function testFindAllThreadsPageSortIdsSearchAndUses(): void {
		[$register, $schema] = $this->table();
		$found = $this->mapper()->findAllListed(
			new MagicListRequest(
				table: new RegisterSchema(register: $register, schema: $schema),
				query: new ListQuery(limit: 10, offset: 5, filters: ['name' => 'x'], sort: ['name' => 'ASC'], search: 'term'),
				ids: ['u-1'],
				uses: 'rel-1'
			)
		);

		$this->assertCount(1, $found);
		$this->assertSame('orm', $found[0]->getSource());
		$this->assertSame(
			[['_limit' => 10, '_offset' => 5, '_order' => ['name' => 'ASC'], 'name' => 'x', '_ids' => ['u-1'], '_search' => 'term', '_relations_contains' => 'rel-1']],
			$this->queries
		);
	}//end testFindAllThreadsPageSortIdsSearchAndUses()

	public function testFindAllWithoutLimitOrFiltersAsksForEveryRow(): void {
		[$register, $schema] = $this->table();
		$this->mapper()->findAllListed(
			new MagicListRequest(
				table: new RegisterSchema(register: $register, schema: $schema),
				query: new ListQuery(limit: ListQuery::UNBOUNDED, search: '  '),
				ids: []
			)
		);

		$this->assertSame([[]], $this->queries);
	}//end testFindAllWithoutLimitOrFiltersAsksForEveryRow()

	public function testFindAllWithoutSchemaFindsNothing(): void {
		[$register] = $this->table();

		$this->assertSame([], $this->mapper()->findAllListed(new MagicListRequest(table: null, query: new ListQuery(limit: 3))));
		$this->assertSame([], $this->queries);
	}//end testFindAllWithoutSchemaFindsNothing()

	public function testFindAllInRegisterSchemaTablePassesZeroLimitAndOffset(): void {
		[$register, $schema] = $this->table();
		$this->mapper()->findAllInRegisterSchemaTable(new RegisterSchema($register, $schema), new ListQuery(0, 0));
		$this->mapper()->findAllInRegisterSchemaTable(new RegisterSchema($register, $schema), new ListQuery(ListQuery::UNBOUNDED, null, ['a' => 1], ['b' => 'DESC']));

		$this->assertSame([['_limit' => 0, '_offset' => 0], ['_order' => ['b' => 'DESC'], 'a' => 1]], $this->queries);
	}//end testFindAllInRegisterSchemaTablePassesZeroLimitAndOffset()

	public function testSaveObjectsSavesEachAndSkipsNone(): void {
		[$register, $schema] = $this->table();
		$mapper = $this->mapper();
		$mapper->method('saveObjectToRegisterSchemaTable')->willReturnOnConsecutiveCalls('u-1', 'u-2');

		$this->assertSame(['u-1', 'u-2'], $mapper->saveObjectsToRegisterSchemaTable([['a' => 1], ['a' => 2]], $register, $schema));
	}//end testSaveObjectsSavesEachAndSkipsNone()
}//end class
