<?php

/**
 * Behaviour test for RunObjectLockMapper: what each read and write decides.
 *
 * Asserts which rows come back from the orphan sweep (merge, de-duplication,
 * limit), which conflict reasons a record() swallows or rethrows, and which
 * filters every query binds.
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
use DateTimeZone;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\RunObjectLock;
use OCA\OpenRegister\Db\RunObjectLockMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class RunObjectLockMapperBehaviourTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function db(array $rows): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return $db;
	}

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
	}

	private function failingMapper(IDBConnection $db, int $reason): RunObjectLockMapper {
		$failure = $this->createMock(DbException::class);
		$failure->method('getReason')->willReturn($reason);
		return new class ($db, $failure) extends RunObjectLockMapper {
			public function __construct(IDBConnection $db, private readonly DbException $failure) {
				parent::__construct($db);
			}

			public function insert(Entity $entity): Entity {
				throw $this->failure;
			}
		};
	}

	private static function rows(array $ids): array {
		return array_map(static fn (int $id): array => ['id' => $id, 'run_uuid' => 'r' . $id, 'object_uuid' => 'o' . $id], $ids);
	}

	private static function ids(array $locks): array {
		return array_map(static fn (RunObjectLock $l): int => $l->getId(), $locks);
	}

	public function testRecordInsertsANewLockWithoutAnUpdate(): void {
		$mapper = new RunObjectLockMapper($this->db([]));
		$mapper->record($this->lock());

		$this->assertContains('#1 insert(openregister_run_object_locks)', $this->queryLog);
		$this->assertNotContains('#2 update(openregister_run_object_locks, NULL)', $this->queryLog);
		$this->assertSame(1, $this->builderCount);
	}

	public function testRecordRefreshesExpiryAndNodeOnADuplicate(): void {
		$db = $this->db([]);
		$this->failingMapper($db, DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION)->record($this->lock());

		$this->assertContains('#1 update(openregister_run_object_locks, NULL)', $this->queryLog);
		$this->assertContains('#1 param :p2 = node-a, 2', $this->queryLog);
		$this->assertContains('#1 param :p3 = run-1, 2', $this->queryLog);
		$this->assertContains('#1 param :p4 = obj-1, 2', $this->queryLog);
		$this->assertContains('#1 executeStatement()', $this->queryLog);
	}

	public function testRecordRethrowsAnyOtherDatabaseFailure(): void {
		$db = $this->db([]);
		try {
			$this->failingMapper($db, DbException::REASON_FOREIGN_KEY_VIOLATION)->record($this->lock());
			$this->fail('A non-duplicate failure must propagate');
		} catch (DbException $e) {
			$this->assertSame(DbException::REASON_FOREIGN_KEY_VIOLATION, $e->getReason());
		}

		$this->assertSame([], $this->queryLog);
	}

	public function testFindByRunFiltersOnTheRunAndHydratesRows(): void {
		$mapper = new RunObjectLockMapper($this->db([self::rows([4, 5])]));
		$found = $mapper->findByRun('run-9');

		$this->assertSame([4, 5], self::ids($found));
		$this->assertSame('o5', $found[1]->getObjectUuid());
		$this->assertContains('#1 param :p1 = run-9, 2', $this->queryLog);
		$this->assertContains('#1 expr.eq(run_uuid, :p1, NULL)', $this->queryLog);
	}

	public function testForgetRunDeletesOnlyByRun(): void {
		$mapper = new RunObjectLockMapper($this->db([]));
		$this->assertSame(1, $mapper->forgetRun('run-2'));
		$this->assertContains('#1 delete(openregister_run_object_locks, NULL)', $this->queryLog);
		$this->assertNotContains('#1 andWhere(eq(object_uuid, :p2, NULL))', $this->queryLog);
	}

	public function testForgetDeletesByRunAndObject(): void {
		$mapper = new RunObjectLockMapper($this->db([]));
		$this->assertSame(1, $mapper->forget('run-2', 'obj-3'));
		$this->assertContains('#1 param :p1 = run-2, 2', $this->queryLog);
		$this->assertContains('#1 param :p2 = obj-3, 2', $this->queryLog);
		$this->assertContains('#1 expr.eq(object_uuid, :p2, NULL)', $this->queryLog);
	}

	public function testFindOrphanedMergesBothSweepsWithoutDuplicates(): void {
		$now = new DateTime('2026-03-04 05:06:07', new DateTimeZone('UTC'));
		$mapper = new RunObjectLockMapper($this->db([self::rows([1, 2]), self::rows([2, 3])]));

		$this->assertSame([1, 2, 3], self::ids($mapper->findOrphaned($now)));
		$this->assertContains('#1 setMaxResults(100)', $this->queryLog);
		$this->assertContains('#2 from(openregister_flow_runs, NULL)', $this->queryLog);
		$this->assertContains('#1 where(run_uuid NOT IN ((subquery #2)))', $this->queryLog);
		$this->assertContains('#1 param :p1 = ' . json_encode(FlowRun::ACTIVE) . ', 102', $this->queryLog);
		$this->assertContains('#3 expr.lt(expires_at, :p2, NULL)', $this->queryLog);
	}

	public function testFindOrphanedCutsTheMergedListAtTheLimit(): void {
		$now = new DateTime('2026-03-04 05:06:07', new DateTimeZone('UTC'));
		$mapper = new RunObjectLockMapper($this->db([self::rows([5, 1]), self::rows([4, 1])]));

		$this->assertSame([5, 1], self::ids($mapper->findOrphaned($now, 2)));
		$this->assertContains('#3 setMaxResults(2)', $this->queryLog);
	}

	public function testFindOrphanedReturnsNothingWhenBothSweepsAreEmpty(): void {
		$now = new DateTime('2026-03-04 05:06:07', new DateTimeZone('UTC'));
		$mapper = new RunObjectLockMapper($this->db([[], []]));
		$this->assertSame([], $mapper->findOrphaned($now, 7));
	}

	public function testALockSerialisesEveryFieldWithIsoDates(): void {
		$lock = $this->lock();
		$lock->setId(12);

		$this->assertSame(
			[
				'id' => 12,
				'runUuid' => 'run-1',
				'objectUuid' => 'obj-1',
				'registerId' => '7',
				'schemaId' => '8',
				'nodeId' => 'node-a',
				'lockedAt' => '2026-03-04T05:00:00+00:00',
				'expiresAt' => '2026-03-04T06:00:00+00:00',
			],
			$lock->jsonSerialize()
		);
	}

	public function testAnEmptyLockSerialisesMissingDatesAsNull(): void {
		$json = (new RunObjectLock())->jsonSerialize();
		$this->assertNull($json['lockedAt']);
		$this->assertNull($json['expiresAt']);
		$this->assertNull($json['runUuid']);
	}
}
