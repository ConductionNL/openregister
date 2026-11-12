<?php

/**
 * Unit tests for a started flow run: pending or finished, and the body each answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionFlowStart;
use PHPUnit\Framework\TestCase;

class ObjectActionFlowStartTest extends TestCase {

	/**
	 * A start of a run in a state.
	 *
	 * @param string $status The run status.
	 * @param bool   $sync   Whether it was advanced.
	 *
	 * @return ObjectActionFlowStart The start.
	 */
	private function start(string $status, bool $sync): ObjectActionFlowStart {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus($status);

		return new ObjectActionFlowStart($run, 'flow-1', $sync);
	}//end start()

	public function testAnAsyncRunIsQueuedAndASuspendedSyncRunIsSuspended(): void {
		$async     = $this->start(FlowRun::STATUS_COMPLETED, false);
		$suspended = $this->start(FlowRun::STATUS_SUSPENDED, true);
		$done      = $this->start(FlowRun::STATUS_COMPLETED, true);

		$this->assertTrue($async->isPending());
		$this->assertSame(FlowRun::STATUS_QUEUED, $async->pendingStatus());
		$this->assertTrue($suspended->isPending());
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $suspended->pendingStatus());
		$this->assertFalse($done->isPending());
		$this->assertTrue($done->isCompleted());
	}//end testAnAsyncRunIsQueuedAndASuspendedSyncRunIsSuspended()

	public function testTheBodiesNameTheRunAndItsLink(): void {
		$definition = ObjectActionDefinition::fromDeclaration('publish', ['steps' => []]);
		$links      = ['run' => '/api/flow-runs/run-1'];

		$this->assertSame(
			['run' => 'run-1', 'status' => 'queued', 'action' => 'publish', 'kind' => 'steps', 'links' => $links],
			$this->start(FlowRun::STATUS_QUEUED, false)->pendingBody($definition)
		);
		$this->assertSame(
			['error' => 'The run ended failed.', 'run' => 'run-1', 'outcome' => 'failed', 'action' => 'publish', 'links' => $links],
			$this->start(FlowRun::STATUS_FAILED, true)->endedBody('publish')
		);
		$this->assertSame(
			['run' => 'run-1', 'outcome' => 'completed', 'action' => 'publish', 'kind' => 'steps', 'next' => 'stay', 'links' => $links],
			$this->start(FlowRun::STATUS_COMPLETED, true)->completedBody($definition, 'stay')
		);
	}//end testTheBodiesNameTheRunAndItsLink()

	public function testTheOutcomeCarriesTheRunUuid(): void {
		$outcome = $this->start(FlowRun::STATUS_COMPLETED, true)->outcome('completed', [['step' => 's', 'kind' => 'write']]);

		$this->assertSame(['run-1', [['step' => 's', 'kind' => 'write']], 'completed'], [$outcome->run, $outcome->changes, $outcome->outcome]);
	}//end testTheOutcomeCarriesTheRunUuid()
}//end class
