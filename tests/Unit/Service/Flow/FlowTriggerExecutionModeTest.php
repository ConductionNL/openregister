<?php

/**
 * Tests for whether a triggered flow is queued or run inline.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerService
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 */
class FlowTriggerExecutionModeTest extends TestCase {

	/**
	 * Resolves flows wired to an event.
	 *
	 * @var FlowLocator
	 */
	private FlowLocator $resolvers;

	/**
	 * Queues and executes runs.
	 *
	 * @var FlowRunService
	 */
	private FlowRunService $runs;

	/**
	 * The service under test.
	 *
	 * @var FlowTriggerService
	 */
	private FlowTriggerService $service;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolvers = $this->createMock(FlowLocator::class);
		$this->runs = $this->createMock(FlowRunService::class);

		$this->service = new FlowTriggerService(
			$this->resolvers,
			$this->runs,
			$this->createMock(LoggerInterface::class)
		);

		$this->resolvers->method('flowsForTrigger')->willReturn(['flow-1']);
		$this->runs->method('queue')->willReturn(new FlowRun());
		$this->resolvers->method('resolveSubject')->willReturn(new stdClass());

	}//end setUp()

	/**
	 * A flow that declares nothing keeps today's behaviour: queued only.
	 *
	 * @return void
	 */
	public function testAFlowWithoutAModeIsOnlyQueued(): void {
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'flow-1', 'edges' => []]);
		$this->runs->expects($this->never())->method('execute');

		$this->assertSame(1, $this->service->fire('object.created', ['uuid' => 'u-1']));

	}//end testAFlowWithoutAModeIsOnlyQueued()

	/**
	 * An explicit async flow is queued.
	 *
	 * @return void
	 */
	public function testAnAsyncFlowIsOnlyQueued(): void {
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'flow-1', 'executionMode' => 'async']);
		$this->runs->expects($this->never())->method('execute');

		$this->service->fire('object.created', ['uuid' => 'u-1']);

	}//end testAnAsyncFlowIsOnlyQueued()

	/**
	 * A mode that is neither value falls back to queueing rather than refusing.
	 *
	 * @return void
	 */
	public function testAnInvalidModeFallsBackToQueueing(): void {
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'flow-1', 'executionMode' => 'immediately']);
		$this->runs->expects($this->never())->method('execute');

		$this->service->fire('object.created', ['uuid' => 'u-1']);

	}//end testAnInvalidModeFallsBackToQueueing()

	/**
	 * A sync flow is executed within the triggering call.
	 *
	 * @return void
	 */
	public function testASyncFlowIsExecutedInline(): void {
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'flow-1', 'executionMode' => 'sync']);
		$this->runs->expects($this->once())->method('execute')->willReturn(new FlowRun());

		$this->assertSame(1, $this->service->fire('object.created', ['uuid' => 'u-1']));

	}//end testASyncFlowIsExecutedInline()

	/**
	 * The mode is read case-insensitively, so "Sync" is not silently async.
	 *
	 * @return void
	 */
	public function testTheModeIsCaseInsensitive(): void {
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'flow-1', 'executionMode' => ' SYNC ']);
		$this->runs->expects($this->once())->method('execute')->willReturn(new FlowRun());

		$this->service->fire('object.created', ['uuid' => 'u-1']);

	}//end testTheModeIsCaseInsensitive()

	/**
	 * A throwing sync flow never unwinds the action that triggered it.
	 *
	 * This is the guarantee that makes inline execution safe to offer at all:
	 * the flow runs on a user's save, and a broken flow must not break the save.
	 *
	 * The count is ONE, not zero: by the time inline execution fails the run
	 * has been queued and the worker will drain it, so reporting zero would be
	 * the instrument lying about a run that exists. (It also used to abort the
	 * fan-out for every later flow on the event, which is the D6 poisoning.)
	 *
	 * @return void
	 */
	public function testAThrowingSyncFlowDoesNotEscapeTheTrigger(): void {
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'flow-1', 'executionMode' => 'sync']);
		$this->runs->method('execute')->willThrowException(new RuntimeException('step blew up'));

		// Swallowed; the queued run is still counted, because it still runs.
		$this->assertSame(1, $this->service->fire('object.created', ['uuid' => 'u-1']));

	}//end testAThrowingSyncFlowDoesNotEscapeTheTrigger()

	/**
	 * A flow no resolver owns is left queued for the worker's defensive path.
	 *
	 * @return void
	 */
	public function testAnUnresolvableFlowIsLeftForTheWorker(): void {
		$this->resolvers->method('resolveFlow')->willReturn(null);
		$this->runs->expects($this->never())->method('execute');

		$this->assertSame(1, $this->service->fire('object.created', ['uuid' => 'u-1']));

	}//end testAnUnresolvableFlowIsLeftForTheWorker()
}//end class
