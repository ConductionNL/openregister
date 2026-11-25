<?php

/**
 * Sealed rows inside a seal-backlog window are byte-identical after the pass.
 *
 * A window's id range holds sealed rows and retention tombstones between the
 * unsealed ones. Rewriting any of them would recompute a link the next row
 * already committed to: a false tamper alarm. These tests drive the real
 * AuditHashService::sealRows() over such a window and pin that the one UPDATE
 * names only the unsealed rows, each chained onto the stored hash before it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-the-seal-pass-shall-never-re-hash-an-already-sealed-row
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drives sealRows() over a window with sealed rows and a tombstone interleaved.
 */
#[CoversClass(AuditHashService::class)]
#[UsesClass(AuditTrail::class)]
class AuditSealBacklogInterleaveTest extends TestCase {

	/**
	 * A query builder whose query yields the given rows.
	 *
	 * @param array $rows  Rows for fetchAll().
	 * @param mixed $fetch Result of fetch().
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function queryBuilder(array $rows, mixed $fetch): IQueryBuilder&MockObject {
		$cursor = $this->createMock(IResult::class);
		$cursor->method('fetchAll')->willReturn($rows);
		$cursor->method('fetch')->willReturn($fetch);

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['gte', 'lte', 'lt', 'eq', 'in', 'isNotNull', 'neq'] as $method) {
			$expr->method($method)->willReturn($method);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chained) {
			$qb->method($chained)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('getTableName')->willReturn('"oc_openregister_audit_trails"');
		$qb->method('executeQuery')->willReturn($cursor);

		return $qb;
	}//end queryBuilder()

	/**
	 * The entity the service hashes for a row, through the same mapping.
	 *
	 * @param array $row The row.
	 *
	 * @return AuditTrail
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
	 * Only the unsealed rows are written; the sealed row and the tombstone give the links.
	 *
	 * @return void
	 */
	public function testSealedRowsAndTombstonesInAWindowAreNeverWritten(): void {
		$sealedHash = str_repeat('a', 64);
		$tombstoneHash = str_repeat('t', 64);
		$row21 = ['id' => 21, 'uuid' => 'uuid-21', 'action' => 'update', 'hash' => null, 'previous_hash' => null];
		$row23 = ['id' => 23, 'uuid' => 'uuid-23', 'action' => 'delete', 'hash' => null, 'previous_hash' => null];
		$links = [
			['id' => 20, 'hash' => $sealedHash],
			['id' => 21, 'hash' => null],
			['id' => 22, 'hash' => $tombstoneHash],
			['id' => 23, 'hash' => null],
		];

		$db = $this->createMock(IDBConnection::class);
		$payload = $this->queryBuilder([$row21, $row23], false);
		$payload->expects($this->once())->method('createNamedParameter')
			->with([21, 23], IQueryBuilder::PARAM_INT_ARRAY)->willReturn(':ids');
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilder($links, false),
			$payload,
			$this->queryBuilder([], ['hash' => str_repeat('p', 64)]),
			$this->queryBuilder([], false)
		);

		$sql = null;
		$params = null;
		$db->expects($this->once())->method('executeStatement')->willReturnCallback(
			function (string $statement, array $values) use (&$sql, &$params): int {
				$sql = $statement;
				$params = $values;
				return 2;
			}
		);

		$service = new AuditHashService(
			$db,
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class)
		);

		$sealed = $service->sealRows([20, 21, 22, 23]);

		$hash21 = $service->computeHash($this->entityFromRow($row21), $sealedHash);
		$hash23 = $service->computeHash($this->entityFromRow($row23), $tombstoneHash);
		$this->assertSame(2, $sealed);
		$this->assertStringContainsString('WHERE id IN (21,23)', $sql);
		$this->assertStringNotContainsString('WHEN 20 ', $sql);
		$this->assertStringNotContainsString('WHEN 22 ', $sql);
		$this->assertSame([$hash21, $hash23, $sealedHash, $tombstoneHash], $params);
	}//end testSealedRowsAndTombstonesInAWindowAreNeverWritten()

	/**
	 * A window whose rows are all sealed by now issues no UPDATE at all.
	 *
	 * @return void
	 */
	public function testAWindowWithNothingLeftToSealIssuesNoUpdate(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilder([['id' => 30, 'hash' => str_repeat('a', 64)], ['id' => 31, 'hash' => str_repeat('b', 64)]], false),
			$this->queryBuilder([], ['hash' => str_repeat('p', 64)])
		);
		$db->expects($this->never())->method('executeStatement');

		$service = new AuditHashService(
			$db,
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class)
		);

		$this->assertSame(0, $service->sealRows([30, 31]));
	}//end testAWindowWithNothingLeftToSealIssuesNoUpdate()
}//end class
