<?php

/**
 * FlowStateMapperTest: a flow's persistent state, including the insert race on first write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowStateMapper
 * @uses   \OCA\OpenRegister\Db\FlowState
 */
class FlowStateMapperTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper on a recording connection.
	 *
	 * @return FlowStateMapper
	 */
	private function mapper(): FlowStateMapper {
		return new FlowStateMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * A mapper whose first builder (the insert) fails with the given reason.
	 *
	 * @param int|null $reason The database exception reason.
	 *
	 * @return FlowStateMapper
	 */
	private function mapperLosingTheInsert(?int $reason): FlowStateMapper {
		$lost = $this->createMock(DbException::class);
		$lost->method('getReason')->willReturn($reason);
		$failing = $this->createMock(IQueryBuilder::class);
		foreach (['insert', 'setValue', 'values'] as $method) {
			$failing->method($method)->willReturnSelf();
		}

		$failing->method('createNamedParameter')->willReturn(':p');
		$failing->method('executeStatement')->willThrowException($lost);
		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$calls, $failing): IQueryBuilder {
				$calls++;
				if ($calls === 1) {
					$this->queries[] = ['lost insert'];
					return $failing;
				}

				return $this->recordingBuilder();
			}
		);

		return new FlowStateMapper($db);
	}//end mapperLosingTheInsert()

	public function testABlankFlowIdHasNoStateAndRunsNoQuery(): void {
		$this->assertNull($this->mapper()->findByFlow('  '));
		$this->assertSame([], $this->queries);
	}//end testABlankFlowIdHasNoStateAndRunsNoQuery()

	public function testFindByFlowReadsTheStateRow(): void {
		$this->rowSets = [[['id' => 2, 'flow_id' => 'f1', 'state' => '{"count":3}']]];

		$state = $this->mapper()->findByFlow('f1');

		$this->assertSame(['count' => 3], $state?->getState());
		$this->assertSame(['select(*)', 'from(openregister_flow_state)', "where(eq(flow_id, 'f1'))", 'setMaxResults(1)', 'executeQuery'], $this->queries[0]);
	}//end testFindByFlowReadsTheStateRow()

	public function testFindByFlowIsNullWithoutARow(): void {
		$this->rowSets = [[]];

		$this->assertNull($this->mapper()->findByFlow('f1'));
	}//end testFindByFlowIsNullWithoutARow()

	public function testPutInsertsTheFirstState(): void {
		$this->lastInsertId = 5;

		$state = $this->mapper()->put('f1', ['count' => 1]);

		$this->assertSame(5, $state->getId());
		$this->assertSame('f1', $state->getFlowId());
		$this->assertInstanceOf(DateTime::class, $state->getUpdated());
		$this->assertContains('insert(openregister_flow_state)', $this->queries[0]);
		$this->assertContains('setValue(state, {"count":1})', $this->queries[0]);
	}//end testPutInsertsTheFirstState()

	public function testPutThatLosesTheInsertRaceUpdatesTheWinnersRow(): void {
		$this->rowSets = [[['id' => 9, 'flow_id' => 'f1', 'state' => '{"count":1}']]];

		$state = $this->mapperLosingTheInsert(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION)->put('f1', ['count' => 2]);

		$this->assertSame(9, $state->getId());
		$this->assertSame(['count' => 2], $state->getState());
		$this->assertContains("where(eq(flow_id, 'f1'))", $this->queries[1]);
		$this->assertContains('update(openregister_flow_state)', $this->queries[2]);
		$this->assertContains('set(state, {"count":2})', $this->queries[2]);
		$this->assertContains('where(eq(id, 9))', $this->queries[2]);
	}//end testPutThatLosesTheInsertRaceUpdatesTheWinnersRow()

	public function testAnInsertFailureOtherThanADuplicateIsRethrown(): void {
		$this->expectException(DbException::class);

		try {
			$this->mapperLosingTheInsert(null)->put('f1', ['count' => 2]);
		} finally {
			$this->assertCount(1, $this->queries);
		}
	}//end testAnInsertFailureOtherThanADuplicateIsRethrown()

	public function testADuplicateWhoseRowIsGoneAgainIsRethrown(): void {
		$this->rowSets = [[]];
		$this->expectException(DbException::class);

		try {
			$this->mapperLosingTheInsert(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION)->put('f1', ['count' => 2]);
		} finally {
			$this->assertCount(2, $this->queries);
		}
	}//end testADuplicateWhoseRowIsGoneAgainIsRethrown()

	public function testDeleteByFlowRemovesAnExistingState(): void {
		$this->rowSets = [[['id' => 4, 'flow_id' => 'f1']]];

		$this->mapper()->deleteByFlow('f1');

		$this->assertSame(['delete(openregister_flow_state)', 'where(eq(id, 4))', 'executeStatement'], $this->queries[1]);
	}//end testDeleteByFlowRemovesAnExistingState()

	public function testDeleteByFlowWithoutStateDeletesNothing(): void {
		$this->rowSets = [[]];

		$this->mapper()->deleteByFlow('f1');

		$this->assertCount(1, $this->queries);
	}//end testDeleteByFlowWithoutStateDeletesNothing()
}//end class
