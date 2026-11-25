<?php

/**
 * Tests for the seal-backlog cursor's three id-only reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Service\Audit\AuditSealBacklogCursor;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins what each read asks the database for and how it maps the answer.
 */
#[CoversClass(AuditSealBacklogCursor::class)]
class AuditSealBacklogCursorTest extends TestCase {

	/**
	 * Every expression the query asked for, as "method:column".
	 *
	 * @var list<string>
	 */
	private array $expressions = [];

	/**
	 * A query builder answering with the given rows / single value.
	 *
	 * @param array $rows     Rows for fetchAll().
	 * @param mixed $fetchOne Value for fetchOne().
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function queryBuilder(array $rows, mixed $fetchOne): IQueryBuilder&MockObject {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetchOne')->willReturn($fetchOne);

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['isNull', 'isNotNull', 'neq', 'gt', 'lt'] as $method) {
			$expr->method($method)->willReturnCallback(
				function (string $column) use ($method): string {
					$this->expressions[] = $method . ':' . $column;
					return $method;
				}
			);
		}

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('max')->willReturn($this->createMock(IQueryFunction::class));
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chained) {
			$qb->method($chained)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}//end queryBuilder()

	/**
	 * A cursor over one query builder.
	 *
	 * @param IQueryBuilder $qb The builder.
	 *
	 * @return AuditSealBacklogCursor
	 */
	private function cursor(IQueryBuilder $qb): AuditSealBacklogCursor {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new AuditSealBacklogCursor($db);
	}//end cursor()

	/**
	 * Unsealed ids come back as ints, ascending, limited, read by the index predicate.
	 *
	 * @return void
	 */
	public function testUnsealedIdsAfterReadsIdsOnlyAscendingAndLimited(): void {
		$qb = $this->queryBuilder([['id' => '12'], ['id' => '15']], false);
		$qb->expects($this->once())->method('select')->with('id')->willReturnSelf();
		$qb->expects($this->once())->method('orderBy')->with('id', 'ASC')->willReturnSelf();
		$qb->expects($this->once())->method('setMaxResults')->with(2000)->willReturnSelf();
		$qb->expects($this->once())->method('createNamedParameter')->with(11, IQueryBuilder::PARAM_INT)->willReturn(':after');

		$ids = $this->cursor($qb)->unsealedIdsAfter(11, 2000);

		$this->assertSame([12, 15], $ids);
		$this->assertSame(['isNull:hash', 'gt:id'], $this->expressions);
	}//end testUnsealedIdsAfterReadsIdsOnlyAscendingAndLimited()

	/**
	 * A limit below one reads nothing.
	 *
	 * @return void
	 */
	public function testANonPositiveLimitReadsNothing(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$this->assertSame([], (new AuditSealBacklogCursor($db))->unsealedIdsAfter(0, 0));
	}//end testANonPositiveLimitReadsNothing()

	/**
	 * The newest link is a hashed row that is no tombstone; none at all reads as 0.
	 *
	 * @return void
	 */
	public function testLastLinkIdSkipsUnsealedRowsAndTombstones(): void {
		$this->assertSame(431, $this->cursor($this->queryBuilder([], '431'))->lastLinkId());
		$this->assertSame(['isNotNull:hash', 'neq:hash', 'isNull:purged_at'], $this->expressions);

		$this->assertSame(0, $this->cursor($this->queryBuilder([], null))->lastLinkId());
	}//end testLastLinkIdSkipsUnsealedRowsAndTombstones()

	/**
	 * The count behind a link reads unsealed rows below it.
	 *
	 * @return void
	 */
	public function testCountUnsealedBeforeCountsUnsealedRowsBelowTheId(): void {
		$qb = $this->queryBuilder([], '7');
		$qb->expects($this->once())->method('createNamedParameter')->with(431, IQueryBuilder::PARAM_INT)->willReturn(':before');

		$this->assertSame(7, $this->cursor($qb)->countUnsealedBefore(431));
		$this->assertSame(['isNull:hash', 'lt:id'], $this->expressions);
	}//end testCountUnsealedBeforeCountsUnsealedRowsBelowTheId()
}//end class
