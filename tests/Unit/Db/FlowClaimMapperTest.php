<?php

/**
 * FlowClaimMapper over a fluent query-builder double: the unique-violation
 * refusal, releases, counts and the reaper's read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Claims mapper.
 */
class FlowClaimMapperTest extends TestCase {

	private IDBConnection&MockObject $db;

	private IQueryBuilder&MockObject $qb;

	private FlowClaimMapper $mapper;

	/** @var array<int, string> fluent methods called on the builder */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		foreach (['insert', 'setValue', 'delete', 'update', 'set', 'select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults', 'values', 'forUpdate'] as $fluent) {
			$this->qb->method($fluent)->willReturnCallback(function () use ($fluent): IQueryBuilder {
				$this->calls[] = $fluent;
				return $this->qb;
			});
		}

		$this->qb->method('createNamedParameter')->willReturnCallback(static fn (mixed $v): string => ':p');
		$this->qb->method('createFunction')->willReturnCallback(static fn (string $f): string => $f);
		$this->qb->method('getSQL')->willReturn('SELECT uuid FROM runs');
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('lt')->willReturn('lt');
		$expr->method('in')->willReturn('in');
		$this->qb->method('expr')->willReturn($expr);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$this->qb->method('func')->willReturn($func);
		$this->db->method('getQueryBuilder')->willReturn($this->qb);

		$this->mapper = new FlowClaimMapper($this->db);
	}//end setUp()

	/**
	 * A result cursor over rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param mixed $one The scalar `fetchOne()` answers.
	 *
	 * @return IResult&MockObject The cursor.
	 */
	private function cursor(array $rows = [], mixed $one = null): IResult&MockObject {
		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue): mixed {
			return array_shift($queue) ?? false;
		});
		$result->method('fetchOne')->willReturn($one);

		return $result;
	}//end cursor()

	/**
	 * A claim.
	 *
	 * @return FlowClaim The claim.
	 */
	private function claim(): FlowClaim {
		$claim = new FlowClaim();
		$claim->setRunUuid('run-1');
		$claim->setPlace('a');
		$claim->setOwner('pass');
		$claim->setStreamId('s1');
		$claim->setTransition('T');
		$claim->setClaimedAt(new DateTime('2026-09-01 08:00:00'));

		return $claim;
	}//end claim()

	public function testInsertOrRefuseLandsAClaim(): void {
		$this->qb->method('executeStatement')->willReturn(1);
		$this->qb->method('getLastInsertId')->willReturn(7);

		$this->assertTrue($this->mapper->insertOrRefuse(claim: $this->claim()));
	}//end testInsertOrRefuseLandsAClaim()

	public function testInsertOrRefuseReturnsFalseOnlyOnAUniqueViolation(): void {
		$violation = $this->createMock(DbException::class);
		$violation->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->qb->method('executeStatement')->willThrowException($violation);

		$this->assertFalse($this->mapper->insertOrRefuse(claim: $this->claim()));
	}//end testInsertOrRefuseReturnsFalseOnlyOnAUniqueViolation()

	public function testAnyOtherDatabaseFailurePropagates(): void {
		$other = $this->createMock(DbException::class);
		$other->method('getReason')->willReturn(DbException::REASON_CONNECTION_LOST);
		$this->qb->method('executeStatement')->willThrowException($other);

		$this->expectException(DbException::class);
		$this->mapper->insertOrRefuse(claim: $this->claim());
	}//end testAnyOtherDatabaseFailurePropagates()

	public function testReleaseOfNothingTouchesNothing(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->assertSame(0, $this->mapper->release(runUuid: 'run-1', places: []));
	}//end testReleaseOfNothingTouchesNothing()

	public function testReleaseAndReleaseByOwnerDelete(): void {
		$this->qb->method('executeStatement')->willReturn(2);
		$this->assertSame(2, $this->mapper->release(runUuid: 'run-1', places: ['a', 'b']));
		$this->assertSame(2, $this->mapper->releaseByOwner(runUuid: 'run-1', owner: 'pass'));
		$this->assertSame(2, $this->mapper->deleteByRun(runUuid: 'run-1'));
		$this->assertSame(2, $this->mapper->deleteOrphans());
		$this->assertContains('delete', $this->calls);
	}//end testReleaseAndReleaseByOwnerDelete()

	public function testCountsReadTheScalar(): void {
		$this->qb->method('executeQuery')->willReturn($this->cursor(one: '3'));
		$this->assertSame(3, $this->mapper->countHeldForRun(runUuid: 'run-1'));
		$this->assertSame(3, $this->mapper->countHeldByOwner(owner: 'pass'));
	}//end testCountsReadTheScalar()

	public function testFindByRunAndFindOlderThanMapRows(): void {
		$row = ['id' => 1, 'run_uuid' => 'run-1', 'place' => 'a', 'owner' => 'pass', 'stream_id' => 's1', 'transition' => 'T', 'claimed_at' => '2026-09-01 08:00:00'];
		$this->qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->cursor(rows: [$row]));

		$byRun = $this->mapper->findByRun(runUuid: 'run-1');
		$this->assertCount(1, $byRun);
		$this->assertSame('a', $byRun[0]->getPlace());
		$this->assertSame('pass', $byRun[0]->getOwner());

		$stale = $this->mapper->findOlderThan(before: new DateTime('-1 hour'), limit: 5);
		$this->assertCount(1, $stale);
		$this->assertSame('run-1', $stale[0]->getRunUuid());
		$this->assertContains('setMaxResults', $this->calls);
		$this->assertSame('s1', $stale[0]->jsonSerialize()['streamId']);
	}//end testFindByRunAndFindOlderThanMapRows()
}//end class
