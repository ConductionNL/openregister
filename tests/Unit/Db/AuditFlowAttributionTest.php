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

	public function testAnExecutingStepIsStampedOntoTheRow(): void {
		$context = new FlowRunContext();
		$context->push(runUuid: 'run-abc', nodeId: 'node-1', sequence: 7);

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
		$context->push(runUuid: 'parent', nodeId: 'call-sub', sequence: 1);
		$context->push(runUuid: 'child', nodeId: 'child-node', sequence: 0);

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
		$context->push(runUuid: 'run-1', nodeId: 'n', sequence: 3);

		$stamper = $this->stamper($context);
		$row = new AuditTrail();

		$stamper->apply(auditTrail: $row);
		$stamper->apply(auditTrail: $row);

		$this->assertSame('run-1', $row->getFlowRun());
		$this->assertSame(3, $row->getFlowStep());
	}//end testStampingTwiceWritesTheSameValues()
}//end class
