<?php

/**
 * Tests for verifyChain()'s cutover classification of unsealed rows.
 *
 * Before the cutover marker existed every row without a hash was skipped as
 * "pre-migration", so a row left unsealed minutes ago passed verification just
 * like a row from before the hash chain. These tests pin the split: rows below
 * the marker stay skipped, rows at or past it fail the chain and are counted.
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
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-verification-shall-distinguish-legacy-unsealed-rows-from-unsealed-rows-written-after-the-cutover
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Migration\Version1Date20261124193002;
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
 * Proves legacy and post-cutover unsealed rows are told apart.
 */
class AuditHashSealCutoverTest extends TestCase {

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Mocked app config holding the marker.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Rows served by the first chain window; later windows are empty.
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
	 * Wire a mock database that serves $this->windows, one per query.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(
			function (): IQueryBuilder {
				$rows = ($this->windows[$this->queries] ?? []);
				$this->queries++;

				$cursor = $this->createMock(IResult::class);
				$cursor->method('fetchAll')->willReturn($rows);
				$cursor->method('fetch')->willReturn(($rows[0] ?? false));

				$expr = $this->createMock(IExpressionBuilder::class);
				$expr->method('gt')->willReturn('gt');
				$expr->method('lte')->willReturn('lte');
				$expr->method('lt')->willReturn('lt');
				$expr->method('neq')->willReturn('neq');
				$expr->method('isNotNull')->willReturn('isnotnull');

				$qb = $this->createMock(IQueryBuilder::class);
				foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chained) {
					$qb->method($chained)->willReturnSelf();
				}

				$qb->method('expr')->willReturn($expr);
				$qb->method('createNamedParameter')->willReturn(':p');
				$qb->method('executeQuery')->willReturn($cursor);

				return $qb;
			}
		);

	}//end setUp()

	/**
	 * A service reading the given cutover marker (0: none recorded).
	 *
	 * @param int $marker The stored marker.
	 *
	 * @return AuditHashService
	 */
	private function serviceWithCutover(int $marker): AuditHashService {
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default) use ($marker): int {
				if ($app === 'openregister' && $key === AuditHashService::SEAL_CUTOVER_KEY) {
					return $marker;
				}

				return $default;
			}
		);

		return new AuditHashService(
			db: $this->db,
			lockingProvider: $this->createMock(ILockingProvider::class),
			logger: $this->createMock(LoggerInterface::class),
			appConfig: $this->appConfig
		);
	}//end serviceWithCutover()

	/**
	 * Rows 1..$count chained from genesis, the ids in $unsealed left without a hash.
	 *
	 * An unsealed row is not a link, so the next sealed row chains over it.
	 *
	 * @param AuditHashService $service  The service that computes the hashes.
	 * @param int              $count    How many rows.
	 * @param list<int>        $unsealed The ids left unsealed.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function chain(AuditHashService $service, int $count, array $unsealed): array {
		$rows = [];
		$previous = $service->getGenesisHash();
		for ($id = 1; $id <= $count; $id++) {
			$row = ['id' => $id, 'uuid' => 'uuid-' . $id, 'action' => 'update', 'hash' => null, 'previous_hash' => null];
			if (in_array($id, $unsealed, true) === false) {
				$entry = new AuditTrail();
				$entry->hydrate(object: ['id' => $id, 'uuid' => 'uuid-' . $id, 'action' => 'update', 'hash' => null, 'previousHash' => null]);
				$row['hash'] = $service->computeHash(entry: $entry, previousHash: $previous);
				$row['previous_hash'] = $previous;
				$previous = $row['hash'];
			}

			$rows[] = $row;
		}

		return $rows;
	}//end chain()

	/**
	 * An unsealed row at or past the marker fails the chain and is not skipped.
	 *
	 * @return void
	 */
	public function testAPostCutoverUnsealedRowBreaksTheChain(): void {
		$service = $this->serviceWithCutover(marker: 4);
		$this->windows = [$this->chain(service: $service, count: 6, unsealed: [2, 4, 6])];

		$report = $service->verifyChain();

		$this->assertFalse($report['valid']);
		$this->assertNull($report['brokenAt'], 'An unsealed row is not a tampered one.');
		$this->assertSame(1, $report['skippedNullHashes'], 'Only row 2 predates the cutover.');
		$this->assertSame(2, $report['unsealedAfterCutover'], 'Rows 4 and 6 should have been sealed.');
		$this->assertSame(3, $report['entriesVerified']);
		$this->assertSame(4, $report['sealCutoverId']);
	}//end testAPostCutoverUnsealedRowBreaksTheChain()

	/**
	 * Legacy rows below the marker still do not break the chain.
	 *
	 * @return void
	 */
	public function testLegacyPreCutoverRowsStillVerify(): void {
		$service = $this->serviceWithCutover(marker: 4);
		$this->windows = [$this->chain(service: $service, count: 6, unsealed: [1, 3])];

		$report = $service->verifyChain();

		$this->assertTrue($report['valid']);
		$this->assertSame(2, $report['skippedNullHashes']);
		$this->assertSame(0, $report['unsealedAfterCutover']);
		$this->assertSame(4, $report['entriesVerified']);
	}//end testLegacyPreCutoverRowsStillVerify()

	/**
	 * Once the post-cutover rows are sealed the chain passes again.
	 *
	 * @return void
	 */
	public function testADrainedBacklogRestoresAPassingChain(): void {
		$service = $this->serviceWithCutover(marker: 4);
		$this->windows = [
			$this->chain(service: $service, count: 6, unsealed: [2, 5]),
			[],
			$this->chain(service: $service, count: 6, unsealed: [2]),
		];

		$before = $service->verifyChain();
		$after  = $service->verifyChain();

		$this->assertFalse($before['valid']);
		$this->assertSame(1, $before['unsealedAfterCutover']);
		$this->assertTrue($after['valid']);
		$this->assertSame(0, $after['unsealedAfterCutover']);
		$this->assertSame(1, $after['skippedNullHashes']);
	}//end testADrainedBacklogRestoresAPassingChain()

	/**
	 * Without a recorded marker every unsealed row is legacy, as before.
	 *
	 * @return void
	 */
	public function testWithoutAMarkerEveryUnsealedRowIsSkipped(): void {
		$service = $this->serviceWithCutover(marker: 0);
		$this->windows = [$this->chain(service: $service, count: 5, unsealed: [2, 5])];

		$report = $service->verifyChain();

		$this->assertTrue($report['valid']);
		$this->assertSame(2, $report['skippedNullHashes']);
		$this->assertSame(0, $report['unsealedAfterCutover']);
		$this->assertNull($report['sealCutoverId']);
	}//end testWithoutAMarkerEveryUnsealedRowIsSkipped()

	/**
	 * A bounded walk counts only the post-cutover rows inside its range.
	 *
	 * @return void
	 */
	public function testARangedWalkCountsOnlyItsOwnRows(): void {
		$service = $this->serviceWithCutover(marker: 3);
		$rows = $this->chain(service: $service, count: 6, unsealed: [4]);
		// The getHashBefore(4) query reads row 3's hash first, then the window of rows 4..5.
		$this->windows = [[['hash' => $rows[2]['hash']]], array_slice($rows, 3, 2)];

		$report = $service->verifyChain(from: 4, to: 5);

		$this->assertFalse($report['valid']);
		$this->assertSame(1, $report['unsealedAfterCutover']);
		$this->assertSame(1, $report['entriesVerified']);
	}//end testARangedWalkCountsOnlyItsOwnRows()

	/**
	 * The migration writes the key verifyChain() reads.
	 *
	 * @return void
	 */
	public function testTheMigrationWritesTheKeyTheServiceReads(): void {
		$this->assertSame(AuditHashService::SEAL_CUTOVER_KEY, Version1Date20261124193002::CUTOVER_KEY);
	}//end testTheMigrationWritesTheKeyTheServiceReads()
}//end class
