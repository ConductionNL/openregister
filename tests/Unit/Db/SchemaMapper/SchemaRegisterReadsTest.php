<?php

/**
 * OpenRegister SchemaRegisterReadsTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use OCA\OpenRegister\Db\SchemaMapper\SchemaRegisterReads;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * How many objects a schema holds across registers, and how many registers list it.
 */
#[CoversClass(SchemaRegisterReads::class)]
final class SchemaRegisterReadsTest extends TestCase {
	/**
	 * Tables asked for their live row count, in order.
	 *
	 * @var list<string>
	 */
	private array $counted = [];

	/**
	 * A connection over the given register rows, magic tables and their live counts.
	 *
	 * @param list<array{id:int|string,schemas:?string}> $registers Register rows.
	 * @param array<string,int>                           $tables    Existing table => live rows.
	 *
	 * @return IDBConnection
	 */
	private function connection(array $registers, array $tables): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $name): bool => array_key_exists($name, $tables));
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($registers, $tables): IQueryBuilder {
			$from = '';
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('from')->willReturnCallback(function (string $table) use (&$from, $qb): IQueryBuilder {
				$from = $table;
				return $qb;
			});
			$func = $this->createMock(IFunctionBuilder::class);
			$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
			$qb->method('func')->willReturn($func);
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('isNull')->with('_deleted')->willReturn('_deleted IS NULL');
			$qb->method('expr')->willReturn($expr);
			$qb->method('executeQuery')->willReturnCallback(function () use (&$from, $registers, $tables): IResult {
				$result = $this->createMock(IResult::class);
				if ($from === 'openregister_registers') {
					$result->method('fetchAll')->willReturn($registers);
					return $result;
				}

				$this->counted[] = $from;
				$result->method('fetchOne')->willReturn((string)$tables[$from]);
				return $result;
			});
			return $qb;
		});

		return $db;
	}//end connection()

	public function testAttachedObjectsSumsTheLiveRowsOfEveryRegisterListingTheSchema(): void {
		$reads = new SchemaRegisterReads(
			$this->connection(
				registers: [
					['id' => 1, 'schemas' => '[7, 9]'],
					['id' => '2', 'schemas' => '["7"]'],
					['id' => 3, 'schemas' => '[9]'],
				],
				tables: ['openregister_table_1_7' => 4, 'openregister_table_2_7' => 3, 'openregister_table_3_9' => 50]
			)
		);

		self::assertSame(7, $reads->attachedObjects(schemaId: 7));
		self::assertSame(['openregister_table_1_7', 'openregister_table_2_7'], $this->counted);
	}//end testAttachedObjectsSumsTheLiveRowsOfEveryRegisterListingTheSchema()

	public function testARegisterWithoutTheMagicTableAddsNothing(): void {
		$reads = new SchemaRegisterReads(
			$this->connection(
				registers: [['id' => 1, 'schemas' => '[7]'], ['id' => 2, 'schemas' => '[7]']],
				tables: ['openregister_table_2_7' => 5]
			)
		);

		self::assertSame(5, $reads->attachedObjects(schemaId: 7));
		self::assertSame(['openregister_table_2_7'], $this->counted);
	}//end testARegisterWithoutTheMagicTableAddsNothing()

	public function testASchemaNoRegisterListsHoldsNothing(): void {
		$reads = new SchemaRegisterReads($this->connection(registers: [['id' => 1, 'schemas' => '[8]']], tables: []));

		self::assertSame(0, $reads->attachedObjects(schemaId: 7));
		self::assertSame([], $this->counted);
	}//end testASchemaNoRegisterListsHoldsNothing()

	public function testRegisterCountPerSchemaCountsEachListingAndSkipsUnreadableLists(): void {
		$reads = new SchemaRegisterReads(
			$this->connection(
				registers: [
					['id' => 1, 'schemas' => '[7, 9]'],
					['id' => 2, 'schemas' => '["7"]'],
					['id' => 3, 'schemas' => 'not json'],
					['id' => 4, 'schemas' => null],
				],
				tables: []
			)
		);

		self::assertSame([7 => 2, 9 => 1], $reads->registerCountPerSchema());
	}//end testRegisterCountPerSchemaCountsEachListingAndSkipsUnreadableLists()
}//end class
