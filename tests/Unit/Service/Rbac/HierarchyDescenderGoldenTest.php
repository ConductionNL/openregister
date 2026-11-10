<?php

/**
 * Golden test for HierarchyDescender, over an in-memory fake of the magic tables.
 *
 * Pins, on the code before hierarchicalTables/childrenOf/childrenInChunk/
 * ancestorsOf were split, which tables a hierarchy is descended in (and which
 * are refused, with or without a warning), which children a level returns
 * (self-parents, blank rows, limits and chunking), and how far an ancestor walk
 * goes (depth bound, cycles, blank parents, unreadable rows). Allowed AND
 * refused outcomes are both asserted.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use Doctrine\DBAL\Schema\Schema as DbalSchema;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\HierarchyDescender
 */
final class HierarchyDescenderGoldenTest extends TestCase {

	/**
	 * Log lines as [level, message, context-subset].
	 *
	 * @var array<int, array>
	 */
	private array $logs = [];

	/**
	 * Queries run, as [table, kind, values, limit].
	 *
	 * @var array<int, array>
	 */
	private array $queries = [];

	/**
	 * A recording logger.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$logs = &$this->logs;
		return new class($logs) extends AbstractLogger {
			/**
			 * @var array<int, array>
			 */
			private array $logs;

			/**
			 * @param array<int, array> $logs Recorded, by reference.
			 */
			public function __construct(array &$logs) {
				$this->logs = &$logs;
			}

			/**
			 * @param mixed             $level   Level.
			 * @param string|Stringable $message Message.
			 * @param array             $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['file'], $context['line'], $context['exception']);
				$this->logs[] = [$level, (string) $message, $context];
			}
		};
	}//end logger()

	/**
	 * A schema, hierarchical when a parent is given.
	 *
	 * @param integer     $id     Schema id.
	 * @param string|null $parent Parent property.
	 *
	 * @return Schema
	 */
	private function schema(int $id, ?string $parent): Schema {
		$schema = new Schema();
		$schema->setId($id);
		if ($parent !== null) {
			$schema->setConfiguration(
				[HierarchyGrantExpander::ANNOTATION => ['parent' => $parent, 'maxDepth' => 3, 'inheritedVerbs' => ['read']]]
			);
		}

		return $schema;
	}//end schema()

	/**
	 * A register.
	 *
	 * @param integer    $id      Register id.
	 * @param array|null $schemas Its schemas list.
	 *
	 * @return Register
	 */
	private function register(int $id, ?array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSchemas($schemas);
		return $register;
	}//end register()

	/**
	 * A descender over a fake database.
	 *
	 * @param array<string, string[]>             $tables    Table name => columns (DBAL schema).
	 * @param array<string, array<int, array>>    $rows      Table name (unprefixed) => rows.
	 * @param Schema[]                            $schemas   Schemas.
	 * @param Register[]                          $registers Registers.
	 * @param bool                                $schemaFails Whether createSchema throws.
	 *
	 * @return HierarchyDescender
	 */
	private function descender(
		array $tables,
		array $rows,
		array $schemas,
		array $registers,
		bool $schemaFails = false
	): HierarchyDescender {
		$dbal = new DbalSchema();
		foreach ($tables as $name => $columns) {
			$table = $dbal->createTable($name);
			foreach ($columns as $column) {
				$table->addColumn($column, 'string', ['length' => 40, 'notnull' => false]);
			}
		}

		$db = $this->createMock(IDBConnection::class);
		if ($schemaFails === true) {
			$db->method('createSchema')->willThrowException(new RuntimeException('no schema'));
		} else {
			$db->method('createSchema')->willReturn($dbal);
		}

		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder($rows));

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->expects($this->atMost(1))->method('findAll')->willReturn($schemas);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->expects($this->atMost(1))->method('findAll')->willReturn($registers);

		return new HierarchyDescender($db, $schemaMapper, $registerMapper, $this->logger());
	}//end descender()

	/**
	 * A query builder that answers from in-memory rows.
	 *
	 * @param array<string, array<int, array>> $rows Table => rows.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(array $rows): IQueryBuilder {
		$state   = ['table' => null, 'select' => [], 'in' => null, 'eq' => null, 'neq' => false, 'limit' => null];
		$queries = &$this->queries;

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturnCallback(
			function (string $column, $values) use (&$state) {
				$state['in'] = [$column, $values];
				return 'in';
			}
		);
		$expr->method('eq')->willReturnCallback(
			function (string $column, $value) use (&$state) {
				$state['eq'] = [$column, $value];
				return 'eq';
			}
		);
		$expr->method('neq')->willReturnCallback(
			function () use (&$state) {
				$state['neq'] = true;
				return 'neq';
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('select')->willReturnCallback(
			function (...$columns) use (&$state, $qb) {
				$state['select'] = $columns;
				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			function (string $table) use (&$state, $qb) {
				$state['table'] = $table;
				return $qb;
			}
		);
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnCallback(
			function (?int $limit) use (&$state, $qb) {
				$state['limit'] = $limit;
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$state, &$queries, $rows) {
				$table = $state['table'];
				if ($table === 'broken') {
					throw new RuntimeException('read failed');
				}

				$matched = [];
				foreach (($rows[$table] ?? []) as $row) {
					if ($state['in'] !== null && in_array(($row[$state['in'][0]] ?? null), $state['in'][1], true) === false) {
						continue;
					}

					if ($state['eq'] !== null && ($row[$state['eq'][0]] ?? null) !== $state['eq'][1]) {
						continue;
					}

					if ($state['neq'] === true && ($row['_uuid'] ?? null) === ($row[$state['in'][0]] ?? null)) {
						continue;
					}

					$matched[] = $row;
				}

				if ($state['limit'] !== null) {
					$matched = array_slice($matched, 0, $state['limit']);
				}

				$queries[] = [$table, ($state['in'] !== null ? count($state['in'][1]) : $state['eq'][1]), $state['neq'], $state['limit']];

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($matched);
				$result->method('fetch')->willReturn(($matched[0] ?? false));
				return $result;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * Which tables a hierarchy is descended in, and the warnings for the refused ones.
	 *
	 * @return void
	 */
	public function testHierarchicalTablesGolden(): void {
		$descender = $this->descender(
			[
				'oc_openregister_table_1_10' => ['_uuid', 'parent_case'],
				'openregister_table_2_10' => ['_uuid'],
				'oc_openregister_table_2_12' => ['_uuid', 'up'],
				'oc_openregister_table_5_10' => ['_uuid', 'other'],
				'oc_openregister_table_6_12' => ['_uuid', 'UP'],
			],
			[],
			[$this->schema(10, 'parentCase'), $this->schema(11, null), $this->schema(12, 'up')],
			[
				$this->register(1, [10]),
				$this->register(2, [['id' => 12]]),
				$this->register(3, null),
				$this->register(4, [['schema' => '12'], 'x']),
				$this->register(5, ['10']),
				$this->register(6, []),
			]
		);

		$expected = [
			['table' => 'openregister_table_1_10', 'parentColumn' => 'parent_case', 'maxDepth' => 3, 'verbs' => ['read'], 'schemaId' => 10],
			['table' => 'openregister_table_2_12', 'parentColumn' => 'up', 'maxDepth' => 3, 'verbs' => ['read'], 'schemaId' => 12],
			['table' => 'openregister_table_6_12', 'parentColumn' => 'up', 'maxDepth' => 3, 'verbs' => ['read'], 'schemaId' => 12],
		];
		$this->assertSame($expected, $descender->hierarchicalTables());
		// Memoised: the mappers are read once (atMost(1) above).
		$this->assertSame($expected, $descender->hierarchicalTables());

		$warning = '[HierarchyDescender] A schema declares a parent property its table does not carry; nothing is inherited for it';
		$this->assertSame(
			[
				['warning', $warning, ['schemaId' => 10, 'property' => 'parentCase', 'column' => 'parent_case', 'table' => 'openregister_table_5_10']],
				// Register 4's object-shaped entries are normalised away by Register::setSchemas(),
				// so it does not claim schema 12 and its missing table is not worth a warning.
			],
			$this->logs
		);
	}//end testHierarchicalTablesGolden()

	/**
	 * An unreadable instance schema descends nothing and warns for each register that claims it.
	 *
	 * @return void
	 */
	public function testUnreadableTablesDescendNothing(): void {
		$descender = $this->descender(
			[],
			[],
			[$this->schema(10, 'parent')],
			[$this->register(1, [10]), $this->register(2, [])],
			true
		);

		$this->assertSame([], $descender->hierarchicalTables());
		$this->assertSame(
			[
				['warning', '[HierarchyDescender] Could not read the instance\'s tables; nothing is inherited this request', []],
				['warning', '[HierarchyDescender] A schema declares a parent property its table does not carry; nothing is inherited for it', ['schemaId' => 10, 'property' => 'parent', 'column' => 'parent', 'table' => 'openregister_table_1_10']],
			],
			$this->logs
		);
	}//end testUnreadableTablesDescendNothing()

	/**
	 * The children a level returns, with and without a limit; blanks and self-parents dropped.
	 *
	 * @return void
	 */
	public function testChildrenOfGolden(): void {
		$rows = [
			't' => [
				['_uuid' => 'c1', 'p' => 'a'],
				['_uuid' => 'c2', 'p' => 'b'],
				['_uuid' => 'a', 'p' => 'a'],
				['_uuid' => '', 'p' => 'a'],
				['_uuid' => 'c3', 'p' => null],
				['_uuid' => 'c4', 'p' => 'z'],
				['_uuid' => 'c5', 'p' => 'a'],
			],
		];
		$descender = $this->descender([], $rows, [], []);

		// Refused before any query.
		$this->assertSame([], $descender->childrenOf('t', 'p', []));
		$this->assertSame([], $descender->childrenOf('t', 'p', ['a'], 0));
		$this->assertSame([], $descender->childrenOf('t', 'p', ['a'], -1));
		$this->assertSame([], $this->queries);

		// No limit: every child, self-parent and blank rows dropped.
		$this->assertSame(['c1' => 'a', 'c2' => 'b', 'c5' => 'a'], $descender->childrenOf('t', 'p', ['a', 'b']));
		$this->assertSame([['t', 2, false, null]], $this->queries);

		// A limit narrows in SQL.
		$this->queries = [];
		$this->assertSame(['c1' => 'a'], $descender->childrenOf('t', 'p', ['a', 'b'], 1));
		$this->assertSame([['t', 2, true, 1]], $this->queries);

		$this->queries = [];
		$this->assertSame(['c1' => 'a', 'c2' => 'b', 'c5' => 'a'], $descender->childrenOf('t', 'p', ['a', 'b'], 10));
		$this->assertSame([['t', 2, true, 10]], $this->queries);
	}//end testChildrenOfGolden()

	/**
	 * Parents are read in chunks; a limit stops the chunks once it is met.
	 *
	 * @return void
	 */
	public function testChildrenOfChunks(): void {
		$parents = [];
		$rows    = [];
		for ($i = 0; $i < 1200; $i++) {
			$parents[] = 'p'.$i;
			$rows[]    = ['_uuid' => 'c'.$i, 'p' => 'p'.$i];
		}

		$descender = $this->descender([], ['t' => $rows], [], []);

		$this->assertCount(1200, $descender->childrenOf('t', 'p', $parents));
		$this->assertSame([['t', 500, false, null], ['t', 500, false, null], ['t', 200, false, null]], $this->queries);

		$this->queries = [];
		$children = $descender->childrenOf('t', 'p', $parents, 700);
		$this->assertCount(700, $children);
		$this->assertSame('p699', $children['c699']);
		$this->assertArrayNotHasKey('c700', $children);
		$this->assertSame([['t', 500, true, 700], ['t', 500, true, 200]], $this->queries);

		$this->queries = [];
		$this->assertCount(500, $descender->childrenOf('t', 'p', $parents, 500));
		$this->assertSame([['t', 500, true, 500]], $this->queries);
	}//end testChildrenOfChunks()

	/**
	 * How far a walk up goes, and every reason it stops.
	 *
	 * @return void
	 */
	public function testAncestorsOfGolden(): void {
		$rows = [
			'openregister_table_1_10' => [
				['_uuid' => 'leaf', 'parent_case' => 'mid'],
				['_uuid' => 'mid', 'parent_case' => 'top'],
				['_uuid' => 'top', 'parent_case' => 'sky'],
				['_uuid' => 'sky', 'parent_case' => 'space'],
				['_uuid' => 'loop1', 'parent_case' => 'loop2'],
				['_uuid' => 'loop2', 'parent_case' => 'loop1'],
				['_uuid' => 'orphan', 'parent_case' => ''],
				['_uuid' => 'nulled', 'parent_case' => null],
				['_uuid' => 'self', 'parent_case' => 'self'],
				['_uuid' => 'dangling', 'parent_case' => 'missing'],
			],
		];
		$descender = $this->descender(
			['oc_openregister_table_1_10' => ['_uuid', 'parent_case']],
			$rows,
			[$this->schema(10, 'parentCase')],
			[$this->register(1, [10])]
		);

		// Allowed: the chain, bounded by maxDepth 3.
		$this->assertSame(['mid', 'top', 'sky'], $descender->ancestorsOf(1, 10, 'leaf'));
		$this->assertSame(['top', 'sky', 'space'], $descender->ancestorsOf(1, 10, 'mid'));
		$this->assertSame(['loop2'], $descender->ancestorsOf(1, 10, 'loop1'));
		$this->assertSame(['missing'], $descender->ancestorsOf(1, 10, 'dangling'));

		// Refused / stopped.
		$this->assertSame([], $descender->ancestorsOf(1, 10, ''));
		$this->assertSame([], $descender->ancestorsOf(1, 10, 'orphan'));
		$this->assertSame([], $descender->ancestorsOf(1, 10, 'nulled'));
		$this->assertSame([], $descender->ancestorsOf(1, 10, 'self'));
		$this->assertSame([], $descender->ancestorsOf(1, 10, 'unknown'));
		$this->assertSame([], $descender->ancestorsOf(2, 10, 'leaf'));
		$this->assertSame([], $descender->ancestorsOf(1, 11, 'leaf'));
		$this->assertSame([], $this->logs);
	}//end testAncestorsOfGolden()

	/**
	 * A parent read that fails stops the walk and warns.
	 *
	 * @return void
	 */
	public function testAnUnreadableParentStopsTheWalk(): void {
		$db = $this->createMock(IDBConnection::class);
		$dbal = new DbalSchema();
		$dbal->createTable('oc_openregister_table_1_10')->addColumn('parent', 'string', ['length' => 40]);
		$db->method('createSchema')->willReturn($dbal);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('down'));

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$this->schema(10, 'parent')]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$this->register(1, [10])]);

		$descender = new HierarchyDescender($db, $schemaMapper, $registerMapper, $this->logger());
		$this->assertSame([], $descender->ancestorsOf(1, 10, 'leaf'));
		$this->assertSame(
			[['warning', '[HierarchyDescender] Could not read an object\'s parent; the walk up stops here', ['table' => 'openregister_table_1_10', 'object' => 'leaf']]],
			$this->logs
		);
	}//end testAnUnreadableParentStopsTheWalk()
	/**
	 * Which registers claim a schema whose table lacks the parent column (and so warn).
	 *
	 * The registers here are plain objects, so the schemas list reaches the
	 * descender exactly as written, object-shaped entries and failures included.
	 *
	 * @return void
	 */
	public function testWhichRegistersClaimTheSchema(): void {
		$make = static function (int $id, callable $schemas): object {
			return new class($id, $schemas) {
				/**
				 * @param integer  $id      Id.
				 * @param callable $schemas Answers getSchemas().
				 */
				public function __construct(private int $id, private $schemas) {
				}

				/**
				 * @return integer
				 */
				public function getId(): int {
					return $this->id;
				}

				/**
				 * @return mixed
				 */
				public function getSchemas(): mixed {
					return ($this->schemas)();
				}
			};
		};

		$registers = [
			$make(1, static fn () => [['id' => 10]]),
			$make(2, static fn () => [['schema' => '10']]),
			$make(3, static fn () => [['id' => 9, 'schema' => 10]]),
			$make(4, static fn () => [['other' => 10]]),
			$make(5, static fn () => ['9', 10]),
			$make(6, static fn () => 'not-a-list'),
			$make(7, static function () {
				throw new RuntimeException('gone');
			}),
			$make(8, static fn () => []),
		];

		$descender = $this->descender([], [], [$this->schema(10, 'parent')], $registers);
		$this->assertSame([], $descender->hierarchicalTables());
		$this->assertSame(
			['openregister_table_1_10', 'openregister_table_2_10', 'openregister_table_5_10'],
			array_map(static fn (array $log): string => $log[2]['table'], $this->logs)
		);
	}//end testWhichRegistersClaimTheSchema()
}//end class
