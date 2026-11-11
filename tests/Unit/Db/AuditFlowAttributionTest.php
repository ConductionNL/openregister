<?php

/**
 * Unit tests for AuditFlowAttribution — the stamp half.
 *
 * These are about what the stamper does when things are NOT normal, because the
 * normal case is one line. An audit row is evidence: it must be written even
 * when the attribution cannot be worked out, and it must never carry an
 * attribution that was not actually executing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\AuditFlowAttribution;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Flow\FlowRunFrame;

class AuditFlowAttributionTest extends TestCase {

	/**
	 * A stamper whose container yields whatever is given.
	 *
	 * @param mixed $resolved What the container returns for FlowRunContext,
	 *                        or an exception instance to throw.
	 *
	 * @return AuditFlowAttribution The stamper.
	 */
	private function stamper(mixed $resolved): AuditFlowAttribution {
		$container = $this->createMock(ContainerInterface::class);

		if ($resolved instanceof \Throwable) {
			$container->method('get')->willThrowException($resolved);
		} else {
			$container->method('get')->willReturn($resolved);
		}

		return new AuditFlowAttribution($this->createMock(IDBConnection::class), $container);
	}//end stamper()

	/**
	 * The run's writes are what `findByRun()` reports, so a run with hop
	 * auditing on must not gain a hop row per step in that list.
	 */
	public function testFindByRunLeavesOutHopRows(): void {
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->expects($this->once())->method('neq')->with('action', AuditFlowAttribution::ACTION_HOP)->willReturn('not a hop');
		$expr->method('eq')->willReturn('this run');

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'orderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$qb->expects($this->once())->method('andWhere')->with('not a hop')->willReturnSelf();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new AuditFlowAttribution($db, $this->createMock(ContainerInterface::class));

		$this->assertSame([], $mapper->findByRun(runUuid: 'run-1'));
	}//end testFindByRunLeavesOutHopRows()

	public function testAnExecutingStepIsStampedOntoTheRow(): void {
		$context = new FlowRunContext();
		$context->push(frame: new FlowRunFrame(runUuid: 'run-abc', nodeId: 'node-1', sequence: 7));

		$row = new AuditTrail();
		$this->stamper($context)->apply(auditTrail: $row);

		$this->assertSame('run-abc', $row->getFlowRun());
		$this->assertSame('node-1', $row->getFlowNode());
		$this->assertSame(7, $row->getFlowStep());
	}//end testAnExecutingStepIsStampedOntoTheRow()

	/**
	 * 🔴 Outside a run the row carries NO attribution.
	 *
	 * Null is the only way to say "no run" — a run uuid is never an empty
	 * string, so an empty one would be a claim rather than an absence.
	 */
	public function testARowWrittenOutsideAnyRunIsUnattributed(): void {
		$row = new AuditTrail();
		$this->stamper(new FlowRunContext())->apply(auditTrail: $row);

		$this->assertNull($row->getFlowRun());
		$this->assertNull($row->getFlowNode());
		$this->assertNull($row->getFlowStep());
	}//end testARowWrittenOutsideAnyRunIsUnattributed()

	/**
	 * An unresolvable context leaves the row unattributed rather than failing.
	 *
	 * An audit row is evidence and must survive a bookkeeping problem; an
	 * unattributed row is honest about what it does not know.
	 */
	public function testAnUnresolvableContextDoesNotPreventTheRow(): void {
		$row = new AuditTrail();
		$this->stamper(new RuntimeException('no such service'))->apply(auditTrail: $row);

		$this->assertNull($row->getFlowRun());
	}//end testAnUnresolvableContextDoesNotPreventTheRow()

	/**
	 * Something that is not a run context is not trusted to be one.
	 */
	public function testAWrongTypeFromTheContainerIsIgnored(): void {
		$row = new AuditTrail();
		$this->stamper(new \stdClass())->apply(auditTrail: $row);

		$this->assertNull($row->getFlowRun());
	}//end testAWrongTypeFromTheContainerIsIgnored()

	/**
	 * The INNERMOST frame wins, so a sub-flow's writes are its own.
	 */
	public function testTheInnermostFrameIsTheOneStamped(): void {
		$context = new FlowRunContext();
		$context->push(frame: new FlowRunFrame(runUuid: 'parent', nodeId: 'call-sub', sequence: 1));
		$context->push(frame: new FlowRunFrame(runUuid: 'child', nodeId: 'child-node', sequence: 0));

		$row = new AuditTrail();
		$this->stamper($context)->apply(auditTrail: $row);

		$this->assertSame('child', $row->getFlowRun());
	}//end testTheInnermostFrameIsTheOneStamped()

	/**
	 * Stamping twice is idempotent — the builder and the insert path both call
	 * it, and they must not disagree.
	 */
	public function testStampingTwiceWritesTheSameValues(): void {
		$context = new FlowRunContext();
		$context->push(frame: new FlowRunFrame(runUuid: 'run-1', nodeId: 'n', sequence: 3));

		$stamper = $this->stamper($context);
		$row = new AuditTrail();

		$stamper->apply(auditTrail: $row);
		$stamper->apply(auditTrail: $row);

		$this->assertSame('run-1', $row->getFlowRun());
		$this->assertSame(3, $row->getFlowStep());
	}//end testStampingTwiceWritesTheSameValues()
}//end class
