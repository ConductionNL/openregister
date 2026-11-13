<?php

/**
 * Golden tests for the seal sweep, the batch seal and the ranged chain report.
 *
 * Pins, on the code as it stood before those units were split, what
 * sealUnsealed() returns and writes on each of its exits (nothing to do, a
 * tail too long to rewrite, the seal lock unavailable, a sweep over two
 * windows), what sealRows() writes for a range with a sealed row in the
 * middle, and the exact report verifyChain() gives for a ranged walk that
 * holds and one that breaks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Scripted-database golden tests for the sweep, the batch seal and the report.
 */
class AuditHashSweepGoldenTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var AuditHashService
	 */
	private AuditHashService $service;

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Mocked lock provider.
	 *
	 * @var ILockingProvider&MockObject
	 */
	private ILockingProvider&MockObject $locks;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Scripted results, one per executeQuery() call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $results = [];

	/**
	 * Every executeQuery() call: the bound named parameters of its builder.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $queries = [];

	/**
	 * Every per-row UPDATE: the bound named parameters of its builder.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $updates = [];

	/**
	 * Every raw statement: [sql, parameters].
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $statements = [];

	/**
	 * Wire the service over a scripted connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->statements[] = [$sql, $params];
				return 1;
			}
		);

		$this->service = new AuditHashService(
			$this->db,
			$this->locks,
			$this->logger,
			$this->createMock(IAppConfig::class)
		);
	}//end setUp()

	/**
	 * A query builder that answers executeQuery() from the script.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['gt', 'gte', 'lt', 'lte', 'eq', 'neq', 'isNull', 'isNotNull'] as $op) {
			$expr->method($op)->willReturn($op);
		}

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));

		$bound = [];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'update', 'set'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('getTableName')->willReturn('oc_openregister_audit_trails');
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value) use (&$bound): string {
				$bound[] = $value;
				return ':p' . count($bound);
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$bound): int {
				$this->updates[] = $bound;
				return 1;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$bound): IResult {
				$this->queries[] = $bound;
				$next = (array_shift($this->results) ?? []);
				$cursor = $this->createMock(IResult::class);
				$cursor->method('fetchAll')->willReturn(($next['all'] ?? []));
				$cursor->method('fetchOne')->willReturn(($next['one'] ?? false));
				$cursor->method('fetch')->willReturn(($next['row'] ?? false));
				return $cursor;
			}
		);

		return $qb;
	}//end builder()

	/**
	 * An audit row.
	 *
	 * @param int         $id   Row id.
	 * @param string|null $hash Stored hash, or null when unsealed.
	 *
	 * @return array<string, mixed>
	 */
	private function row(int $id, ?string $hash = null): array {
		return [
			'id' => $id,
			'uuid' => 'uuid-' . $id,
			'action' => 'update',
			'hash' => $hash,
			'previous_hash' => null,
		];
	}//end row()

	/**
	 * The hash the service gives a row over a predecessor.
	 *
	 * @param array<string, mixed> $row      The raw row.
	 * @param string               $previous The predecessor hash.
	 *
	 * @return string
	 */
	private function hashOf(array $row, string $previous): string {
		$mapped = [];
		foreach ($row as $key => $value) {
			$mapped[lcfirst(str_replace('_', '', ucwords($key, '_')))] = $value;
		}

		$entry = new AuditTrail();
		$entry->hydrate($mapped);

		return $this->service->computeHash($entry, $previous);
	}//end hashOf()

	/**
	 * A limit below one does nothing at all.
	 *
	 * @return void
	 */
	public function testSweepWithLimitBelowOneTouchesNothing(): void {
		$this->locks->expects($this->never())->method('acquireLock');

		$this->assertSame(0, $this->service->sealUnsealed(0));
		$this->assertSame([], $this->queries);
	}//end testSweepWithLimitBelowOneTouchesNothing()

	/**
	 * A fully sealed trail is one query and no lock.
	 *
	 * @return void
	 */
	public function testSweepOverSealedTrailOnlyLooks(): void {
		$this->results = [['one' => false]];
		$this->locks->expects($this->never())->method('acquireLock');

		$this->assertSame(0, $this->service->sealUnsealed());
		$this->assertCount(1, $this->queries);
	}//end testSweepOverSealedTrailOnlyLooks()

	/**
	 * A tail beyond the rewrite bound stands the sweep down before the lock.
	 *
	 * @return void
	 */
	public function testSweepStandsDownOnLongTail(): void {
		$this->results = [['one' => '7'], ['one' => '5001']];
		$this->locks->expects($this->never())->method('acquireLock');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Oldest unsealed audit row is id 7, with 5001 row(s) after it')
		);

		$this->assertSame(0, $this->service->sealUnsealed());
		$this->assertSame([[], [7]], $this->queries);
	}//end testSweepStandsDownOnLongTail()

	/**
	 * A tail exactly at the bound still runs.
	 *
	 * @return void
	 */
	public function testSweepRunsAtTheBound(): void {
		$this->results = [['one' => '7'], ['one' => '5000'], ['row' => false], ['all' => []]];
		$this->locks->expects($this->once())->method('acquireLock');
		$this->locks->expects($this->once())->method('releaseLock');

		$this->assertSame(0, $this->service->sealUnsealed());
		$this->assertSame([[], [7], [7, ''], [6]], $this->queries);
	}//end testSweepRunsAtTheBound()

	/**
	 * Without the seal lock the sweep writes nothing and says so.
	 *
	 * @return void
	 */
	public function testSweepWithoutLockWritesNothing(): void {
		$this->results = [['one' => '3'], ['one' => '1']];
		$this->locks->method('acquireLock')->willThrowException(new LockedException('audit-seal'));
		$this->locks->expects($this->never())->method('releaseLock');
		$this->logger->expects($this->once())->method('warning')->with(
			'[AuditHashService] Seal sweep skipped: seal lock unavailable.'
		);

		$this->assertSame(0, $this->service->sealUnsealed());
		$this->assertCount(2, $this->queries);
		$this->assertSame([], $this->updates);
	}//end testSweepWithoutLockWritesNothing()

	/**
	 * A sweep re-chains forward from the nearest sealed predecessor over windows.
	 *
	 * @return void
	 */
	public function testSweepRechainsForwardAcrossWindows(): void {
		$rowTwo = $this->row(2);
		$rowThree = $this->row(3, 'stale');
		$rowFour = $this->row(4);
		$this->results = [
			['one' => 2],
			['one' => 3],
			['row' => ['hash' => 'h1']],
			['all' => [$rowTwo, $rowThree]],
			['all' => [$rowFour]],
			['all' => []],
		];
		$this->locks->expects($this->once())->method('acquireLock');
		$this->locks->expects($this->once())->method('releaseLock');

		$this->assertSame(3, $this->service->sealUnsealed(2));

		$hashTwo = $this->hashOf($rowTwo, 'h1');
		$hashThree = $this->hashOf($rowThree, $hashTwo);
		$hashFour = $this->hashOf($rowFour, $hashThree);
		$this->assertSame(
			[[$hashTwo, 'h1', 2], [$hashThree, $hashTwo, 3], [$hashFour, $hashThree, 4]],
			$this->updates
		);
		$this->assertSame([[], [2], [2, ''], [1], [3], [4]], $this->queries);
	}//end testSweepRechainsForwardAcrossWindows()

	/**
	 * A sweep with no sealed predecessor anchors on genesis.
	 *
	 * @return void
	 */
	public function testSweepAnchorsOnGenesis(): void {
		$rowOne = $this->row(1);
		$this->results = [['one' => 1], ['one' => 1], ['row' => ['hash' => null]], ['all' => [$rowOne]], ['all' => []]];

		$this->assertSame(1, $this->service->sealUnsealed());

		$genesis = $this->service->getGenesisHash();
		$this->assertSame([[$this->hashOf($rowOne, $genesis), $genesis, 1]], $this->updates);
	}//end testSweepAnchorsOnGenesis()

	/**
	 * A batch seal skips sealed rows, chains through them and writes one statement.
	 *
	 * @return void
	 */
	public function testSealRowsWritesOneCaseStatement(): void {
		$rowFive = $this->row(5);
		$rowSix = $this->row(6, 'h6');
		$rowSeven = $this->row(7, '');
		$this->results = [['all' => [$rowFive, $rowSix, $rowSeven]], ['row' => ['hash' => 'h4']]];

		$this->assertSame(2, $this->service->sealRows([7, 5]));

		$hashFive = $this->hashOf($rowFive, 'h4');
		$hashSeven = $this->hashOf($rowSeven, 'h6');
		$this->assertSame([[5, 7], [5, '']], $this->queries);
		$this->assertSame(
			[
				[
					'UPDATE oc_openregister_audit_trails SET hash = CASE id WHEN 5 THEN ? WHEN 7 THEN ? ELSE hash END,'
					. ' previous_hash = CASE id WHEN 5 THEN ? WHEN 7 THEN ? ELSE previous_hash END WHERE id IN (5,7)',
					[$hashFive, $hashSeven, 'h4', 'h6'],
				],
			],
			$this->statements
		);
	}//end testSealRowsWritesOneCaseStatement()

	/**
	 * A batch of already-sealed rows writes nothing.
	 *
	 * @return void
	 */
	public function testSealRowsOverSealedRangeWritesNothing(): void {
		$this->results = [['all' => [$this->row(5, 'a'), $this->row(6, 'b')]], ['row' => false]];

		$this->assertSame(0, $this->service->sealRows([5, 6]));
		$this->assertSame([], $this->statements);
	}//end testSealRowsOverSealedRangeWritesNothing()

	/**
	 * A batch over an empty range does not look up a predecessor.
	 *
	 * @return void
	 */
	public function testSealRowsOverEmptyRangeStops(): void {
		$this->results = [['all' => []]];

		$this->assertSame(0, $this->service->sealRows([9]));
		$this->assertCount(1, $this->queries);
	}//end testSealRowsOverEmptyRangeStops()

	/**
	 * A ranged walk that holds reports the requested range.
	 *
	 * @return void
	 */
	public function testRangedWalkThatHoldsReportsRange(): void {
		$rowTwo = $this->row(2);
		$rowTwo['hash'] = $this->hashOf($rowTwo, 'h1');
		$rowThree = $this->row(3);
		$rowFour = $this->row(4);
		$rowFour['hash'] = 't4';
		$rowFour['purged_at'] = '2026-01-01 00:00:00';
		$this->results = [['row' => ['hash' => 'h1']], ['all' => [$rowTwo, $rowThree, $rowFour]], ['all' => []]];

		$this->assertSame(
			[
				'valid' => true,
				'entriesVerified' => 1,
				'brokenAt' => null,
				'skippedNullHashes' => 1,
				'purgedTombstones' => 1,
				'unkeyedEntries' => 1,
				'keyedFrom' => null,
				'keyBoundaryIntact' => true,
				'range' => ['from' => 2, 'to' => 9],
			],
			$this->service->verifyChain(2, 9)
		);
		$this->assertSame([[2, ''], [1, 9], [4, 9]], $this->queries);
	}//end testRangedWalkThatHoldsReportsRange()

	/**
	 * A walk that breaks stops at the break and fills the open range end.
	 *
	 * @return void
	 */
	public function testRangedWalkThatBreaksStopsAtTheBreak(): void {
		$rowOne = $this->row(1);
		$rowOne['hash'] = $this->hashOf($rowOne, $this->service->getGenesisHash());
		$rowTwo = $this->row(2, 'tampered');
		$this->results = [['all' => [$rowOne, $rowTwo, $this->row(3, 'x')]]];

		$this->assertSame(
			[
				'valid' => false,
				'entriesVerified' => 1,
				'brokenAt' => 2,
				'skippedNullHashes' => 0,
				'purgedTombstones' => 0,
				'unkeyedEntries' => 1,
				'keyedFrom' => null,
				'keyBoundaryIntact' => true,
				'range' => ['from' => 2, 'to' => 5],
			],
			$this->service->verifyChain(null, 5)
		);
		$this->assertCount(1, $this->queries);
	}//end testRangedWalkThatBreaksStopsAtTheBreak()

	/**
	 * An open walk that holds carries no range.
	 *
	 * @return void
	 */
	public function testOpenWalkHasNoRange(): void {
		$this->results = [['all' => []]];

		$this->assertSame(
			[
				'valid' => true,
				'entriesVerified' => 0,
				'brokenAt' => null,
				'skippedNullHashes' => 0,
				'purgedTombstones' => 0,
				'unkeyedEntries' => 0,
				'keyedFrom' => null,
				'keyBoundaryIntact' => true,
			],
			$this->service->verifyChain()
		);
	}//end testOpenWalkHasNoRange()
}//end class
