<?php

/**
 * The pre-verification of the flow-attribution re-chain, over a real v1 chain.
 *
 * The re-seal destroys the evidence of a break, so the verdict it writes first
 * is the only record left. These tests seed a chain sealed under seed v1, run
 * the repair step over it, and read the verdict it stored: a tampered row is
 * named, a tombstone is carried forward rather than reported as a break, and an
 * interrupted run starts again from the beginning instead of looking done.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-object-attribution/tasks.md
 */

declare(strict_types=1);

namespace Unit\Repair;

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
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pre-verify discrimination and the re-chain's safety properties (tasks 5.2 and 5.3).
 *
 * @covers \OCA\OpenRegister\Repair\RechainAuditTrailForFlowAttribution
 * @uses   \OCA\OpenRegister\Repair\V1ChainVerificationRun
 * @uses   \OCA\OpenRegister\Repair\Support\RepairPlatform
 * @uses   \OCA\OpenRegister\Service\AuditCanonicalV1
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 *
 * @spec openspec/changes/flow-object-attribution/tasks.md
 */
class RechainAuditTrailPreVerifyTest extends TestCase {

	/**
	 * An intact v1 chain verifies, and every row is counted.
	 *
	 * @return void
	 */
	public function testAnIntactChainIsRecordedValid(): void {
		$verdict = $this->verdictOver(rows: self::chain(count: 4));

		self::assertTrue($verdict['valid']);
		self::assertNull($verdict['brokenAt']);
		self::assertSame(4, $verdict['entriesVerified']);
	}//end testAnIntactChainIsRecordedValid()

	/**
	 * A row changed after it was sealed is named in the stored verdict.
	 *
	 * @return void
	 */
	public function testATamperedRowIsNamedInTheVerdict(): void {
		$rows = self::chain(count: 5);
		$rows[2]['action'] = 'delete';

		$verdict = $this->verdictOver(rows: $rows);

		self::assertFalse($verdict['valid']);
		self::assertSame(3, $verdict['brokenAt'], 'the verdict names the id of the tampered row');
		self::assertSame(2, $verdict['entriesVerified'], 'the walk stops at the first break');
	}//end testATamperedRowIsNamedInTheVerdict()

	/**
	 * A row whose stored hash was replaced is named as well.
	 *
	 * @return void
	 */
	public function testARowWithAForeignHashIsNamed(): void {
		$rows = self::chain(count: 3);
		$rows[1]['hash'] = hash('sha256', 'not this row');

		self::assertSame(2, $this->verdictOver(rows: $rows)['brokenAt']);
	}//end testARowWithAForeignHashIsNamed()

	/**
	 * A retention tombstone is carried forward as a link, not reported as a break.
	 *
	 * @return void
	 */
	public function testATombstoneIsCarriedForward(): void {
		$rows = self::chain(count: 4);
		// Purging blanks the content and keeps the sealed hash as the link.
		$rows[1]['action'] = '';
		$rows[1]['changed'] = null;
		$rows[1]['purged_at'] = '2026-09-01 00:00:00';

		$verdict = $this->verdictOver(rows: $rows);

		self::assertTrue($verdict['valid'], 'a declared tombstone is not a break');
		self::assertSame(1, $verdict['purgedTombstones']);
		self::assertSame(3, $verdict['entriesVerified']);
	}//end testATombstoneIsCarriedForward()

	/**
	 * Rows past the first window are verified too.
	 *
	 * @return void
	 */
	public function testABreakBeyondTheFirstWindowIsFound(): void {
		$rows = self::chain(count: 503);
		$rows[501]['object'] = 999;

		self::assertSame(502, $this->verdictOver(rows: $rows)['brokenAt']);
	}//end testABreakBeyondTheFirstWindowIsFound()

	/**
	 * An interrupted re-seal is not marked done, so the next run verifies and re-seals again.
	 *
	 * @return void
	 */
	public function testAnInterruptedReSealRunsAgainFromTheStart(): void {
		$store = [];
		$calls = 0;
		$hashes = $this->createMock(AuditHashService::class);
		$hashes->expects(self::exactly(2))->method('rechainAll')->willReturnCallback(
			static function () use (&$calls): array {
				$calls++;
				if ($calls === 1) {
					throw new RuntimeException('interrupted');
				}

				return ['rechained' => 3, 'tombstonesPreserved' => 0];
			}
		);

		$step = new RechainAuditTrailForFlowAttribution(
			new RepairPlatform($this->db(rows: self::chain(count: 3)), $this->config(store: $store), new NullLogger()),
			$hashes
		);

		try {
			$step->run($this->createMock(IOutput::class));
			self::fail('the interruption reaches the repair runner');
		} catch (RuntimeException $e) {
			self::assertSame('interrupted', $e->getMessage());
		}

		self::assertArrayNotHasKey(RechainAuditTrailForFlowAttribution::DONE_KEY, $store);

		$step->run($this->createMock(IOutput::class));
		self::assertArrayHasKey(RechainAuditTrailForFlowAttribution::DONE_KEY, $store);

		// A third run is a no-op: rechainAll stays at two calls.
		$step->run($this->createMock(IOutput::class));
	}//end testAnInterruptedReSealRunsAgainFromTheStart()

	/**
	 * Run the step over these rows and read the verdict it stored.
	 *
	 * @param list<array<string, mixed>> $rows The audit rows, in id order.
	 *
	 * @return array<string, mixed> The stored verdict.
	 */
	private function verdictOver(array $rows): array {
		$store = [];
		$hashes = $this->createMock(AuditHashService::class);
		$hashes->method('rechainAll')->willReturn(['rechained' => count($rows), 'tombstonesPreserved' => 0]);

		(new RechainAuditTrailForFlowAttribution(
			new RepairPlatform($this->db(rows: $rows), $this->config(store: $store), new NullLogger()),
			$hashes
		))->run($this->createMock(IOutput::class));

		return json_decode($store[RechainAuditTrailForFlowAttribution::VERDICT_KEY], true);
	}//end verdictOver()

	/**
	 * A chain of rows sealed under seed v1, as the table holds them.
	 *
	 * @param int $count How many rows.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private static function chain(int $count): array {
		$rows = [];
		$previous = AuditCanonicalV1::genesisHash();
		for ($id = 1; $id <= $count; $id++) {
			$row = [
				'id' => $id,
				'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
				'action' => 'update',
				'object' => 10 + $id,
				'changed' => json_encode(['title' => ['old' => 'a', 'new' => 'b' . $id]]),
				'user' => 'alice',
				'created' => '2026-08-0' . (($id % 9) + 1) . ' 10:00:00',
			];
			$row['hash'] = AuditCanonicalV1::computeHash(entry: self::entity(row: $row), previousHash: $previous);
			$previous = $row['hash'];
			$rows[] = $row;
		}

		return $rows;
	}//end chain()

	/**
	 * Hydrate a row the way the table is read: snake_case columns to camelCase fields.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return AuditTrail The entity.
	 */
	private static function entity(array $row): AuditTrail {
		$mapped = [];
		foreach ($row as $key => $value) {
			$mapped[lcfirst(str_replace('_', '', ucwords((string)$key, '_')))] = $value;
		}

		$entity = new AuditTrail();
		$entity->hydrate(object: $mapped);

		return $entity;
	}//end entity()

	/**
	 * A database that answers the audit table in windows after the requested id.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return IDBConnection The connection.
	 */
	private function db(array $rows): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($rows): IQueryBuilder {
				$state = new \ArrayObject(['after' => 0, 'limit' => PHP_INT_MAX]);
				$expr = $this->createMock(IExpressionBuilder::class);
				$expr->method('gt')->willReturn('id > :after');

				$qb = $this->createMock(IQueryBuilder::class);
				foreach (['select', 'from', 'where', 'andWhere', 'orderBy'] as $method) {
					$qb->method($method)->willReturnSelf();
				}

				$qb->method('expr')->willReturn($expr);
				$qb->method('createNamedParameter')->willReturnCallback(
					static function (mixed $value) use ($state): string {
						$state['after'] = (int)$value;
						return ':after';
					}
				);
				$qb->method('setMaxResults')->willReturnCallback(
					static function (int $limit) use ($qb, $state): IQueryBuilder {
						$state['limit'] = $limit;
						return $qb;
					}
				);
				$qb->method('executeQuery')->willReturnCallback(
					function () use ($rows, $state): IResult {
						$window = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] > $state['after']));
						$result = $this->createMock(IResult::class);
						$result->method('fetchAll')->willReturn(array_slice($window, 0, $state['limit']));
						return $result;
					}
				);

				return $qb;
			}
		);

		return $db;
	}//end db()

	/**
	 * App config over an array, read back as written.
	 *
	 * @param array<string, string> $store The store.
	 *
	 * @return IAppConfig The config.
	 */
	private function config(array &$store): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$store): string {
				return ($store[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		return $config;
	}//end config()
}//end class
