<?php

/**
 * Golden test for FlowStateMapper: every query it builds and what it returns.
 *
 * Records each query-builder call made by findByFlow(), put() and
 * deleteByFlow(), with every bound parameter, over the found, missing, blank,
 * concurrent-insert and failure paths. The `updated` stamp is the wall clock,
 * so it is pinned by type only. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowState;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowStateMapper
 */
class FlowStateMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/flow-state-mapper-golden.json';

	private const ROW = ['id' => 3, 'flow_id' => 'flow-1', 'state' => '{"seen":["a"]}', 'updated' => '2026-01-02 03:04:05'];

	private const UNIQUE = DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;

	public function testQueriesAndResultsMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$insertReason, $call, $rows]) {
			$actual[$name] = $this->exercise(insertReason: $insertReason, call: $call, rows: $rows);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertNotEmpty($expected);
		foreach ($expected as $name => $outcome) {
			$this->assertNotEmpty($outcome, $name);
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testQueriesAndResultsMatchGolden()

	public function testAConcurrentInsertUpdatesTheRowThatWon(): void {
		$outcome = $this->exercise(insertReason: self::UNIQUE, call: ['put', ['flow-1', ['seen' => ['b']]]], rows: [[self::ROW]]);

		$this->assertSame([3, 'flow-1', ['seen' => ['b']], 'DateTime'], $outcome['result']);
		$this->assertContains('#2 update(openregister_flow_state, NULL)', $outcome['queries']);
	}//end testAConcurrentInsertUpdatesTheRowThatWon()

	private static function cases(): array {
		$state = ['seen' => ['b'], 'count' => 2];
		return [
			'findByFlow, found' => [null, ['findByFlow', ['flow-1']], [[self::ROW]]],
			'findByFlow, missing' => [null, ['findByFlow', ['flow-1']], [[]]],
			'findByFlow, empty id' => [null, ['findByFlow', ['']], []],
			'findByFlow, blank id' => [null, ['findByFlow', [" \t"]], []],
			'put, first write' => [null, ['put', ['flow-1', $state]], []],
			'put, empty state' => [null, ['put', ['flow-2', []]], []],
			'put, concurrent insert' => [self::UNIQUE, ['put', ['flow-1', $state]], [[self::ROW]]],
			'put, concurrent insert then delete' => [self::UNIQUE, ['put', ['flow-1', $state]], [[]]],
			'put, concurrent insert, blank id' => [self::UNIQUE, ['put', [' ', $state]], []],
			'put, other failure' => [DbException::REASON_NOT_NULL_CONSTRAINT_VIOLATION, ['put', ['flow-1', $state]], []],
			'deleteByFlow, found' => [null, ['deleteByFlow', ['flow-1']], [[self::ROW]]],
			'deleteByFlow, missing' => [null, ['deleteByFlow', ['flow-1']], [[]]],
			'deleteByFlow, empty id' => [null, ['deleteByFlow', ['']], []],
		];
	}//end cases()

	private function exercise(?int $insertReason, array $call, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$mapper = $this->mapper(db: $db, insertReason: $insertReason);

		[$method, $args] = $call;
		$outcome = [];
		try {
			$outcome['result'] = $this->render($mapper->$method(...$args));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e);
			if ($e instanceof DbException) {
				$outcome['thrown'] = 'DbException reason ' . $e->getReason();
			}
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end exercise()

	private function render(mixed $result): mixed {
		if ($result instanceof FlowState) {
			return [$result->getId(), $result->getFlowId(), $result->getState(), get_class($result->getUpdated())];
		}

		return $result;
	}//end render()

	private function mapper(IDBConnection $db, ?int $insertReason): FlowStateMapper {
		if ($insertReason === null) {
			return new FlowStateMapper($db);
		}

		$failure = $this->createMock(DbException::class);
		$failure->method('getReason')->willReturn($insertReason);

		return new class ($db, $failure) extends FlowStateMapper {
			public function __construct(IDBConnection $db, private readonly DbException $failure) {
				parent::__construct($db);
			}

			public function insert(Entity $entity): Entity {
				throw $this->failure;
			}
		};
	}//end mapper()
}//end class
