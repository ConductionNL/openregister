<?php

/**
 * Golden test for RunObjectLockMapper: every query it builds and what it returns.
 *
 * Records each query-builder call made by record(), findByRun(), forgetRun(),
 * forget() and findOrphaned(), with every bound parameter, and the ids the
 * reads hand back. Re-record with UPDATE_GOLDEN=1.
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

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\RunObjectLock;
use OCA\OpenRegister\Db\RunObjectLockMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\RunObjectLockMapper
 */
class RunObjectLockMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait {
		renderArgs as private recordedArgs;
	}

	private const FIXTURE = __DIR__ . '/fixtures/run-object-lock-mapper-golden.json';

	public function testQueriesAndResultsMatchGolden(): void {
		$actual = $this->scenarios();

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertNotEmpty($expected);
		foreach ($expected as $name => $outcome) {
			$this->assertNotEmpty($outcome['queries'] === [] ? ($outcome['thrown'] ?? null) : $outcome['queries'], $name);
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testQueriesAndResultsMatchGolden()

	public function testADuplicateRefreshesTheExistingRecord(): void {
		$outcome = $this->exercise(insertReason: DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION, call: ['record', [$this->lock()]], rows: []);

		$this->assertArrayNotHasKey('thrown', $outcome);
		$this->assertContains('#1 update(openregister_run_object_locks, NULL)', $outcome['queries']);
		$this->assertContains('#1 executeStatement()', $outcome['queries']);
	}//end testADuplicateRefreshesTheExistingRecord()

	private function scenarios(): array {
		$now = new DateTime('2026-03-04 05:06:07', new DateTimeZone('UTC'));
		$rows = static fn (array $ids): array => array_map(static fn (int $id): array => ['id' => $id, 'run_uuid' => 'r' . $id], $ids);

		$cases = [
			'record, new' => [null, ['record', [$this->lock()]], []],
			'record, duplicate' => [DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION, ['record', [$this->lock()]], []],
			'record, other failure' => [DbException::REASON_FOREIGN_KEY_VIOLATION, ['record', [$this->lock()]], []],
			'findByRun' => [null, ['findByRun', ['run-1']], [$rows([1, 2])]],
			'findByRun, none' => [null, ['findByRun', ['run-1']], [[]]],
			'forgetRun' => [null, ['forgetRun', ['run-1']], []],
			'forget' => [null, ['forget', ['run-1', 'obj-1']], []],
			'findOrphaned, default limit' => [null, ['findOrphaned', [$now]], [$rows([1, 2]), $rows([2, 3])]],
			'findOrphaned, limit cuts merged' => [null, ['findOrphaned', [$now, 2]], [$rows([5, 1]), $rows([4, 1])]],
			'findOrphaned, nothing' => [null, ['findOrphaned', [$now, 7]], [[], []]],
			'findOrphaned, only expired' => [null, ['findOrphaned', [$now, 3]], [[], $rows([9])]],
		];

		$actual = [];
		foreach ($cases as $name => [$reason, $call, $rowSets]) {
			$actual[$name] = $this->exercise(insertReason: $reason, call: $call, rows: $rowSets);
		}

		return $actual;
	}//end scenarios()

	private function lock(): RunObjectLock {
		$lock = new RunObjectLock();
		$lock->setRunUuid('run-1');
		$lock->setObjectUuid('obj-1');
		$lock->setRegisterId('7');
		$lock->setSchemaId('8');
		$lock->setNodeId('node-a');
		$lock->setLockedAt(new DateTime('2026-03-04 05:00:00', new DateTimeZone('UTC')));
		$lock->setExpiresAt(new DateTime('2026-03-04 06:00:00', new DateTimeZone('UTC')));
		return $lock;
	}//end lock()

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
			$result = $mapper->$method(...$args);
			$outcome['result'] = $this->render($result);
		} catch (\Throwable $e) {
			$outcome['thrown'] = $e instanceof DbException ? 'DbException reason ' . $e->getReason() : get_class($e);
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end exercise()

	/**
	 * Call arguments as text, with dates written out so their value is pinned.
	 *
	 * @param array<int|string, mixed> $args The arguments.
	 */
	private function renderArgs(array $args): string {
		foreach ($args as $key => $arg) {
			if ($arg instanceof DateTimeInterface) {
				$args[$key] = 'DateTime(' . $arg->format('Y-m-d H:i:s e') . ')';
			}
		}

		return $this->recordedArgs($args);
	}//end renderArgs()

	private function render(mixed $result): mixed {
		if (is_array($result) === false) {
			return $result;
		}

		return array_map(static fn (RunObjectLock $lock): array => [$lock->getId(), $lock->getRunUuid()], $result);
	}//end render()

	private function mapper(IDBConnection $db, ?int $insertReason): RunObjectLockMapper {
		if ($insertReason === null) {
			return new RunObjectLockMapper($db);
		}

		$failure = $this->createMock(DbException::class);
		$failure->method('getReason')->willReturn($insertReason);

		return new class ($db, $failure) extends RunObjectLockMapper {
			public function __construct(IDBConnection $db, private readonly DbException $failure) {
				parent::__construct($db);
			}

			public function insert(Entity $entity): Entity {
				throw $this->failure;
			}
		};
	}//end mapper()
}//end class
