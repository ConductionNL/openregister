<?php

/**
 * Golden: the pre-re-chain v1 verifier's verdict over a sealed chain with an
 * unsealed row and a purged tombstone, intact and tampered.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Repair\RechainAuditTrailForFlowAttribution;
use OCA\OpenRegister\Repair\Support\RepairPlatform;
use OCA\OpenRegister\Service\AuditCanonicalV1;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Pins verifyUnderV1(): the valid verdict, the broken verdict and the window paging.
 *
 * @covers \OCA\OpenRegister\Repair\RechainAuditTrailForFlowAttribution
 */
class RechainAuditTrailV1VerifierGoldenTest extends TestCase {

	/**
	 * The `id` lower bounds the verifier paged with.
	 *
	 * @var array<int, mixed>
	 */
	private array $afterIds = [];

	/**
	 * The step over a database whose audit query returns the given windows, then nothing.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $windows The row windows, in order.
	 *
	 * @return RechainAuditTrailForFlowAttribution The step.
	 */
	private function step(array $windows): RechainAuditTrailForFlowAttribution {
		$windows[] = [];
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturnOnConsecutiveCalls(...$windows);

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'orderBy', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value): string {
				$this->afterIds[] = $value;
				return ':p';
			}
		);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new RechainAuditTrailForFlowAttribution(
			new RepairPlatform($db, $this->createMock(IAppConfig::class), new NullLogger()),
			$this->createMock(AuditHashService::class)
		);
	}//end step()

	/**
	 * Seal a row under v1 the way the verifier reads it.
	 *
	 * @param array<string, mixed> $row          The raw row, without a hash.
	 * @param string               $previousHash The previous link.
	 *
	 * @return array<string, mixed> The row with its hash.
	 */
	private function seal(array $row, string $previousHash): array {
		$entity = new AuditTrail();
		$entity->hydrate(object: ['id' => $row['id'], 'uuid' => $row['uuid'], 'action' => $row['action']]);
		$row['hash'] = AuditCanonicalV1::computeHash(entry: $entity, previousHash: $previousHash);

		return $row;
	}//end seal()

	/**
	 * Five rows: sealed, unsealed, purged tombstone, sealed, sealed.
	 *
	 * @param bool $tamper Whether row 4's content is changed after sealing.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function chain(bool $tamper): array {
		$first = $this->seal(['id' => 1, 'uuid' => 'a-1', 'action' => 'create'], AuditCanonicalV1::genesisHash());
		$tombstone = ['id' => 3, 'uuid' => 'a-3', 'action' => 'update', 'hash' => str_repeat('c', 64), 'purged_at' => '2026-01-01 00:00:00'];
		$fourth = $this->seal(['id' => 4, 'uuid' => 'a-4', 'action' => 'update'], $tombstone['hash']);
		if ($tamper === true) {
			$fourth['action'] = 'delete';
		}

		$fifth = $this->seal(['id' => 5, 'uuid' => 'a-5', 'action' => 'update'], $fourth['hash']);

		return [$first, ['id' => 2, 'uuid' => 'a-2', 'action' => 'read', 'hash' => null], $tombstone, $fourth, $fifth];
	}//end chain()

	/**
	 * Run the private verifier.
	 *
	 * @param RechainAuditTrailForFlowAttribution $step The step.
	 *
	 * @return array<string, mixed> The verdict.
	 */
	private function verify(RechainAuditTrailForFlowAttribution $step): array {
		return (new ReflectionMethod(RechainAuditTrailForFlowAttribution::class, 'verifyUnderV1'))->invoke($step);
	}//end verify()

	/**
	 * An intact chain across two windows is valid; unsealed rows and tombstones are counted, not checked.
	 *
	 * @return void
	 */
	public function testAnIntactChainIsValid(): void {
		$rows = $this->chain(false);
		$verdict = $this->verify($this->step([array_slice($rows, 0, 3), array_slice($rows, 3)]));

		$this->assertSame(
			['valid' => true, 'entriesVerified' => 3, 'brokenAt' => null, 'skippedNullHashes' => 1, 'purgedTombstones' => 1],
			$verdict
		);
		$this->assertSame([0, 3, 5], $this->afterIds, 'Each window starts after the last id read.');
	}//end testAnIntactChainIsValid()

	/**
	 * A tampered row breaks the chain at its id and the walk stops there.
	 *
	 * @return void
	 */
	public function testATamperedRowIsReportedAndTheWalkStops(): void {
		$rows = $this->chain(true);
		$verdict = $this->verify($this->step([array_slice($rows, 0, 3), array_slice($rows, 3)]));

		$this->assertSame(
			['valid' => false, 'entriesVerified' => 1, 'brokenAt' => 4, 'skippedNullHashes' => 1, 'purgedTombstones' => 1],
			$verdict
		);
		$this->assertSame([0, 3], $this->afterIds, 'No window is read after the break.');
	}//end testATamperedRowIsReportedAndTheWalkStops()

	/**
	 * An empty table is a valid, empty chain.
	 *
	 * @return void
	 */
	public function testAnEmptyTableIsValid(): void {
		$this->assertSame(
			['valid' => true, 'entriesVerified' => 0, 'brokenAt' => null, 'skippedNullHashes' => 0, 'purgedTombstones' => 0],
			$this->verify($this->step([]))
		);
	}//end testAnEmptyTableIsValid()
}//end class
