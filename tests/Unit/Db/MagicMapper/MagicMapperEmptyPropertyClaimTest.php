<?php

/**
 * A claimed write changes the row only while the claimed property is still empty.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/declared-self-assign-action/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\EmptyPropertyClaim;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Exception\PropertyAlreadySetException;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The compare-and-swap of a claim, against a recording query builder.
 */
class MagicMapperEmptyPropertyClaimTest extends TestCase {

	/**
	 * The WHERE terms of the last update, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $where = [];

	/**
	 * The columns the last update set.
	 *
	 * @var array<string, mixed>
	 */
	private array $set = [];

	/**
	 * The terms of the last OR.
	 *
	 * @var array<int, mixed>
	 */
	private array $orTerms = [];

	protected function setUp(): void {
		EmptyPropertyClaim::consume(uuid: 'uuid-1');
		$this->where = [];
		$this->set = [];
	}//end setUp()

	/**
	 * A mapper whose update reports $changed rows.
	 *
	 * @param int $changed The rows the database reports changed.
	 *
	 * @return MagicMapper
	 */
	private function mapperChanging(int $changed): MagicMapper {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, $value): string => $column . ' = ' . var_export($value, true));
		$expr->method('isNull')->willReturnCallback(static fn (string $column): string => $column . ' IS NULL');
		$composite = $this->createMock(ICompositeExpression::class);
		$expr->method('orX')->willReturnCallback(
			function (...$terms) use ($composite): ICompositeExpression {
				$this->orTerms = $terms;
				return $composite;
			}
		);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnCallback(
			function (string $column, $value) use (&$qb) {
				$this->set[$column] = $value;
				return $qb;
			}
		);
		$qb->method('where')->willReturnCallback(
			function ($term) use (&$qb) {
				$this->where[] = $term;
				return $qb;
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			function ($term) use (&$qb) {
				if ($term instanceof ICompositeExpression) {
					$term = '(' . implode(' OR ', $this->orTerms) . ')';
				}

				$this->where[] = $term;
				return $qb;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeStatement')->willReturn($changed);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('db')->setValue($mapper, $db);

		return $mapper;
	}//end mapperChanging()

	/**
	 * Write the stored object as an existing-row update.
	 *
	 * @param MagicMapper $mapper The mapper.
	 *
	 * @return void
	 */
	private function update(MagicMapper $mapper): void {
		(new ReflectionMethod(MagicMapper::class, 'updateExistingObject'))
			->invoke($mapper, self::rowWrite('uuid-1', ['_uuid' => 'uuid-1', 'assigned_to' => 'anna', 'title' => ''], 'openregister_table_1_2'));
	}//end update()

	/**
	 * A claimed write changes the row only while the column is empty, and sets what it was given.
	 *
	 * @return void
	 */
	public function testAClaimedWriteIsConditionalOnTheEmptyColumn(): void {
		EmptyPropertyClaim::hold(uuid: 'uuid-1', property: 'assignedTo');

		$this->update(mapper: $this->mapperChanging(changed: 1));

		$this->assertSame(["_uuid = 'uuid-1'", "(assigned_to IS NULL OR assigned_to = '')"], $this->where);
		$this->assertSame(['assigned_to' => 'anna', 'title' => null], $this->set);
	}//end testAClaimedWriteIsConditionalOnTheEmptyColumn()

	/**
	 * The losing claim changes nothing and is refused.
	 *
	 * @return void
	 */
	public function testTheLosingClaimIsRefused(): void {
		EmptyPropertyClaim::hold(uuid: 'uuid-1', property: 'assignedTo');

		$this->expectException(PropertyAlreadySetException::class);
		$this->update(mapper: $this->mapperChanging(changed: 0));
	}//end testTheLosingClaimIsRefused()

	/**
	 * Only the claimed write is conditional; the next write of the object is ordinary.
	 *
	 * @return void
	 */
	public function testOnlyTheClaimedWriteIsConditional(): void {
		EmptyPropertyClaim::hold(uuid: 'uuid-1', property: 'assignedTo');
		$this->update(mapper: $this->mapperChanging(changed: 1));
		$this->where = [];

		$this->update(mapper: $this->mapperChanging(changed: 0));

		$this->assertSame(["_uuid = 'uuid-1'"], $this->where);
	}//end testOnlyTheClaimedWriteIsConditional()

	/**
	 * A row write of the given uuid, column values and table.
	 *
	 * @param string               $uuid  The object uuid.
	 * @param array<string, mixed> $row   The prepared column values.
	 * @param string               $table The table name.
	 *
	 * @return \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite The write.
	 */
	private static function rowWrite(string $uuid, array $row, string $table): \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite {
		$write = new \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite(objectData: [], register: new \OCA\OpenRegister\Db\Register(), schema: new \OCA\OpenRegister\Db\Schema(), tableName: $table);
		$write->uuid = $uuid;
		$write->row  = $row;

		return $write;
	}//end rowWrite()
}//end class
