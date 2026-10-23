<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailStatistics;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The grouped audit trail aggregate: one query, one row per register-schema combination.
 */
class AuditTrailStatisticsTest extends TestCase {
	public function testGroupedByRegisterSchemaRunsOneGroupedQueryAndKeepsIdsAsStored(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['register' => 28, 'schema' => '978', 'total' => '6', 'size' => '60'],
			['register' => null, 'schema' => null, 'total' => '8', 'size' => '80'],
			false
		);
		$result->expects($this->once())->method('closeCursor');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('select')->willReturnSelf();
		$qb->method('selectAlias')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->expects($this->once())->method('groupBy')->with('register', 'schema')->willReturnSelf();
		$qb->expects($this->once())->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('getQueryBuilder')->willReturn($qb);

		$rows = (new AuditTrailStatistics($db))->groupedByRegisterSchema();

		$this->assertSame(
			[
				['register' => '28', 'schema' => '978', 'total' => 6, 'size' => 60],
				['register' => null, 'schema' => null, 'total' => 8, 'size' => 80],
			],
			$rows
		);
	}
}
