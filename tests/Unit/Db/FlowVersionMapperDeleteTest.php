<?php

/**
 * `FlowVersionMapper::deleteByFlow()` — the cascade that stops version rows
 * outliving the flow they name.
 *
 * 🔴 THE LEAK THIS CLOSES. `FlowService::delete()` already cascades to trigger
 * rows, runs, steps and state; the version table was added later and never
 * joined that list, so every deleted flow left its versions behind —
 * 38 orphans measured on a dev instance, unreachable through any read path,
 * because every version read is keyed by flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowVersionMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FlowVersionMapperDeleteTest extends TestCase {

	/**
	 * Delete scopes to ONE flow and reports how many rows went.
	 *
	 * 🔑 THE `where` IS THE WHOLE SAFETY PROPERTY. A delete over this table
	 * without it removes every version of every flow on the instance, which
	 * would strand every in-flight run at once. So the test asserts the
	 * predicate is built against the flow uuid, not merely that a delete ran.
	 *
	 * @return void
	 */
	public function testDeleteByFlowScopesToThatFlowAndCountsTheRows(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->expects($this->once())
			->method('eq')
			->with('flow_uuid', 'param-token')
			->willReturn('flow_uuid = :p');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->once())
			->method('delete')
			->with('openregister_flow_versions')
			->willReturnSelf();
		$qb->expects($this->once())
			->method('createNamedParameter')
			->with('flow-1')
			->willReturn('param-token');
		$qb->expects($this->once())
			->method('where')
			->with('flow_uuid = :p')
			->willReturnSelf();
		$qb->expects($this->once())->method('executeStatement')->willReturn(3);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new FlowVersionMapper($db);

		$this->assertSame(3, $mapper->deleteByFlow(flowUuid: 'flow-1'));
	}//end testDeleteByFlowScopesToThatFlowAndCountsTheRows()

	/**
	 * A flow with no versions deletes nothing and says so, rather than
	 * reporting a phantom success.
	 *
	 * @return void
	 */
	public function testDeleteByFlowReportsZeroWhenThereWasNothingToRemove(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('flow_uuid = :p');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('delete')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('param-token');
		$qb->method('where')->willReturnSelf();
		$qb->method('executeStatement')->willReturn(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$this->assertSame(0, (new FlowVersionMapper($db))->deleteByFlow(flowUuid: 'never-versioned'));
	}//end testDeleteByFlowReportsZeroWhenThereWasNothingToRemove()

	/**
	 * The orphan sweep deletes only rows whose flow is GONE.
	 *
	 * 🔴 THE `notIn` IS THE WHOLE SAFETY PROPERTY. Without the predicate this
	 * empties the table — every version of every live flow — which would strand
	 * every in-flight run on the instance. So the test asserts the predicate is
	 * built against the flows table, not merely that a delete ran.
	 *
	 * @return void
	 */
	public function testDeleteOrphanedOnlyRemovesRowsWhoseFlowIsGone(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->expects($this->once())
			->method('notIn')
			->with('flow_uuid', $this->anything())
			->willReturn('flow_uuid NOT IN (...)');

		$sub = $this->createMock(IQueryBuilder::class);
		$sub->method('select')->willReturnSelf();
		$sub->method('from')->willReturnSelf();
		$sub->method('getSQL')->willReturn('SELECT uuid FROM oc_openregister_flows');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->expects($this->once())
			->method('delete')
			->with('openregister_flow_versions')
			->willReturnSelf();
		$qb->expects($this->once())
			->method('where')
			->with('flow_uuid NOT IN (...)')
			->willReturnSelf();
		$qb->expects($this->once())->method('executeStatement')->willReturn(38);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qb, $sub);

		$this->assertSame(38, (new FlowVersionMapper($db))->deleteOrphaned());
	}//end testDeleteOrphanedOnlyRemovesRowsWhoseFlowIsGone()
}//end class
