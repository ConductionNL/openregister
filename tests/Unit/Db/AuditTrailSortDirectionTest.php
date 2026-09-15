<?php

/**
 * Which way an audit trail is ordered.
 *
 * `findAll()` takes a sort map and defaults it to `['created' => 'DESC']`, and
 * for as long as that signature has existed it has returned rows ASCENDING.
 * The loop assigned its default back over the variable it was about to test:
 *
 *     $direction = 'ASC';
 *     if (strtoupper($direction) === 'DESC') { … }
 *
 * so the comparison read the default, never the caller's value, and the branch
 * could not be taken. Nothing raised, nothing logged, and the request looked
 * honoured: the endpoint accepts `_sort[created]=DESC`, the controller parses
 * it, the service forwards it, and the mapper drops it in the last three lines
 * before the query is built.
 *
 * What it cost a reader: a case history that reads bottom-up. Dossiq's sidebar
 * asks for newest-first, gets the create at the top and the newest write at the
 * bottom, and on a case with any traffic the row you came to see is off the
 * first page.
 *
 * These tests read the ORDER BY the mapper builds rather than rows from a
 * database, because the defect is in what the query says, and a fixture would
 * have to be long enough to tell the two orders apart to catch it at all.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class AuditTrailSortDirectionTest extends TestCase {

	/** @var array<int, array{0: string, 1: string}> Every addOrderBy the run made. */
	private array $ordered = [];

	/**
	 * A mapper whose query builder records its ORDER BY and returns no rows.
	 *
	 * @return AuditTrailMapper The mapper under test.
	 */
	private function mapper(): AuditTrailMapper {
		$this->ordered = [];

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);

		$expression = $this->createMock(IExpressionBuilder::class);

		$query = $this->createMock(IQueryBuilder::class);
		$query->method('select')->willReturnSelf();
		$query->method('from')->willReturnSelf();
		$query->method('andWhere')->willReturnSelf();
		$query->method('setMaxResults')->willReturnSelf();
		$query->method('setFirstResult')->willReturnSelf();
		$query->method('createNamedParameter')->willReturn(':p');
		$query->method('expr')->willReturn($expression);
		$query->method('executeQuery')->willReturn($result);
		$query->method('addOrderBy')->willReturnCallback(
			function (string $sort, ?string $order = null) use ($query): IQueryBuilder {
				$this->ordered[] = [$sort, (string)$order];
				return $query;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($query);

		return new AuditTrailMapper(
			$db,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end mapper()

	public function testDescendingIsAskedForAndDescendingIsBuilt(): void {
		$this->mapper()->findAll(sort: ['created' => 'DESC']);

		$this->assertSame([['created', 'DESC']], $this->ordered);
	}//end testDescendingIsAskedForAndDescendingIsBuilt()

	public function testTheDefaultSortIsDescending(): void {
		// No sort argument at all: the signature's own default is
		// `['created' => 'DESC']`, and it has to survive the same loop.
		$this->mapper()->findAll();

		$this->assertSame([['created', 'DESC']], $this->ordered);
	}//end testTheDefaultSortIsDescending()

	public function testAscendingStaysAscending(): void {
		// The control. A direction that reaches the query unchanged proves the
		// fix reads the caller's value rather than always answering DESC.
		$this->mapper()->findAll(sort: ['created' => 'asc']);

		$this->assertSame([['created', 'ASC']], $this->ordered);
	}//end testAscendingStaysAscending()

	public function testAnUnknownDirectionFallsBackToAscending(): void {
		$this->mapper()->findAll(sort: ['created' => 'sideways']);

		$this->assertSame([['created', 'ASC']], $this->ordered);
	}//end testAnUnknownDirectionFallsBackToAscending()

	public function testAColumnTheTableDoesNotHaveIsNotOrderedOn(): void {
		$this->mapper()->findAll(sort: ['no_such_column' => 'DESC']);

		$this->assertSame([], $this->ordered);
	}//end testAColumnTheTableDoesNotHaveIsNotOrderedOn()
}//end class
