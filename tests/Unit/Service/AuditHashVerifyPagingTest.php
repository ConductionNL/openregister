<?php

/**
 * Tests for verifyChain()'s windowed walk.
 *
 * verifyChain() used to issue ONE unbounded `select *` over the whole audit
 * trail. The database was never the problem — Postgres returns 309,090 rows by
 * index scan in ~350 ms — but the client is: libpq buffers an entire result set
 * before PHP sees a row, so at ~5.8 KB per row that pulled ~1.8 GB into the
 * driver. Because that memory is held in C, memory_get_peak_usage() cheerfully
 * reported 57 MB while the OS SIGKILLed the process, which is a uniquely
 * unhelpful failure: the verification simply vanished, with no PHP fatal and
 * nothing in the log to say the chain had gone unchecked.
 *
 * It now pages by id. That introduces exactly one new way to be wrong — losing
 * previousHash across a window boundary — and these tests exist to hold that
 * down, because getting it wrong FAILS SAFE-LOOKING in one direction (a chain
 * reported broken that isn't) and dangerously in the other.
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Proves verifyChain() pages, and that paging does not change its verdict.
 */
class AuditHashVerifyPagingTest extends TestCase {

	/**
	 * Window size verifyChain() uses, mirroring the service constant.
	 *
	 * @var integer
	 */
	private const BATCH = 500;

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
	 * Windows handed to the service, in order, one per query.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $windows = [];

	/**
	 * How many query builders the service asked for.
	 *
	 * @var integer
	 */
	private int $queries = 0;

	/**
	 * Wire the service against a mock that serves pre-built windows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new AuditHashService(
			$this->db,
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class)
		);

	}//end setUp()

	/**
	 * Serve $this->windows one per executeQuery(), then empty windows forever.
	 *
	 * Returning empty rather than throwing once the windows run out matters:
	 * it lets a test assert that the walk TERMINATES on an empty window instead
	 * of hanging, which is the failure mode of a paging loop whose cursor never
	 * advances.
	 *
	 * @return void
	 */
	private function serveWindows(): void {
		$this->db->method('getQueryBuilder')
			->willReturnCallback(
				function (): IQueryBuilder {
					$rows = ($this->windows[$this->queries] ?? []);
					$this->queries++;

					$cursor = $this->createMock(IResult::class);
					$cursor->method('fetchAll')->willReturn($rows);

					$expr = $this->createMock(IExpressionBuilder::class);
					$expr->method('gt')->willReturn('gt');
					$expr->method('lte')->willReturn('lte');
					$expr->method('gte')->willReturn('gte');
					$expr->method('isNotNull')->willReturn('isnotnull');

					$qb = $this->createMock(IQueryBuilder::class);
					$qb->method('select')->willReturnSelf();
					$qb->method('from')->willReturnSelf();
					$qb->method('where')->willReturnSelf();
					$qb->method('andWhere')->willReturnSelf();
					$qb->method('orderBy')->willReturnSelf();
					$qb->method('setMaxResults')->willReturnSelf();
					$qb->method('expr')->willReturn($expr);
					$qb->method('createNamedParameter')->willReturn(':p');
					$qb->method('executeQuery')->willReturn($cursor);

					return $qb;
				}
			);

	}//end serveWindows()

	/**
	 * Replicate the service's snake_case row to hydrated entity path.
	 *
	 * @param array<string, mixed> $row The raw row.
	 *
	 * @return AuditTrail The hydrated entity.
	 */
	private function entityFromRow(array $row): AuditTrail {
		$mapped = [];
		foreach ($row as $key => $value) {
			$mapped[lcfirst(str_replace('_', '', ucwords($key, '_')))] = $value;
		}

		$entry = new AuditTrail();
		$entry->hydrate($mapped);

		return $entry;
	}//end entityFromRow()

	/**
	 * Build a correctly chained run of rows starting from the genesis hash.
	 *
	 * @param int $count How many rows to build.
	 *
	 * @return array<int, array<string, mixed>> The sealed rows, in id order.
	 */
	private function buildSealedChain(int $count): array {
		$rows = [];
		$previous = $this->service->getGenesisHash();

		for ($i = 1; $i <= $count; $i++) {
			$row = [
				'id' => $i,
				'uuid' => 'uuid-' . $i,
				'action' => 'update',
				'hash' => null,
				'previous_hash' => null,
			];

			$hash = $this->service->computeHash($this->entityFromRow($row), $previous);
			$row['hash'] = $hash;
			$row['previous_hash'] = $previous;
			$previous = $hash;
			$rows[] = $row;
		}

		return $rows;
	}//end buildSealedChain()

	/**
	 * A chain split across two windows verifies clean.
	 *
	 * This is the carry-over test. If previousHash reset at the window
	 * boundary, row 501 would be re-derived from genesis instead of row 500 and
	 * the chain would be declared broken at exactly the batch size — so the
	 * assertion on brokenAt being null is the one that matters, and the
	 * entriesVerified count pins down that BOTH windows were walked rather than
	 * the loop exiting after the first.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testChainSpanningTwoWindowsVerifiesClean(): void {
		$rows = $this->buildSealedChain((self::BATCH + 120));
		$this->windows = [
			array_slice($rows, 0, self::BATCH),
			array_slice($rows, self::BATCH),
		];
		$this->serveWindows();

		$result = $this->service->verifyChain();

		$this->assertTrue($result['valid'], 'A chain split across windows must still verify');
		$this->assertNull($result['brokenAt']);
		$this->assertSame((self::BATCH + 120), $result['entriesVerified']);
		$this->assertSame(0, $result['skippedNullHashes']);

		// Two populated windows plus the empty one that ends the walk.
		$this->assertSame(3, $this->queries, 'verifyChain must page rather than issue one unbounded query');

	}//end testChainSpanningTwoWindowsVerifiesClean()

	/**
	 * The FIRST row of the second window is the boundary; corrupting it is
	 * caught.
	 *
	 * Mutation guard for the carry-over: if the boundary row were verified
	 * against genesis (or against nothing), a tampered row 501 could pass. It
	 * must be caught, and caught AT 501 rather than somewhere downstream.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testTamperedRowAtWindowBoundaryIsDetected(): void {
		$rows = $this->buildSealedChain((self::BATCH + 10));

		// Alter the payload of the boundary row, leaving its stored hash — the
		// signature of an entry edited after it was written.
		$rows[self::BATCH]['action'] = 'tampered';

		$this->windows = [
			array_slice($rows, 0, self::BATCH),
			array_slice($rows, self::BATCH),
		];
		$this->serveWindows();

		$result = $this->service->verifyChain();

		$this->assertFalse($result['valid']);
		$this->assertSame((self::BATCH + 1), $result['brokenAt'], 'The break must be reported at the boundary row');
		$this->assertSame(self::BATCH, $result['entriesVerified']);

	}//end testTamperedRowAtWindowBoundaryIsDetected()

	/**
	 * Unsealed rows at a window boundary do not break the chain.
	 *
	 * verifyChain() skips rows with no hash and carries the last SEALED hash
	 * forward. Paging must not change that: a window whose rows are ALL
	 * unsealed has to leave previousHash untouched, or the next sealed row is
	 * compared against the wrong predecessor and a gap turns into a false
	 * tamper alarm — which is exactly the wrong thing for an audit tool to cry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testUnsealedWindowDoesNotBreakCarryOver(): void {
		$sealed = $this->buildSealedChain(4);

		$unsealed = [];
		for ($i = 0; $i < 3; $i++) {
			$unsealed[] = [
				'id' => (100 + $i),
				'uuid' => 'gap-' . $i,
				'action' => 'update',
				'hash' => null,
				'previous_hash' => null,
			];
		}

		// Window 2 is entirely unsealed; window 3 resumes the chain.
		$this->windows = [
			array_slice($sealed, 0, 2),
			$unsealed,
			array_slice($sealed, 2),
		];
		$this->serveWindows();

		$result = $this->service->verifyChain();

		$this->assertTrue($result['valid'], 'A fully unsealed window must not be read as tampering');
		$this->assertSame(4, $result['entriesVerified']);
		$this->assertSame(3, $result['skippedNullHashes']);

	}//end testUnsealedWindowDoesNotBreakCarryOver()

	/**
	 * An empty first window ends the walk instead of looping.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testEmptyTrailTerminates(): void {
		$this->windows = [];
		$this->serveWindows();

		$result = $this->service->verifyChain();

		$this->assertTrue($result['valid']);
		$this->assertSame(0, $result['entriesVerified']);
		$this->assertSame(1, $this->queries);

	}//end testEmptyTrailTerminates()
}//end class
