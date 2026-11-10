<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunStores;
use OCA\OpenRegister\Service\Flow\FlowRunWalkServices;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Flow\FlowRunOrigin;
use OCA\OpenRegister\Service\Flow\FlowRunPass;

/**
 * The trigger service's contract: a fired event queues one run per wired flow,
 * and nothing it does may escape into the action that fired it.
 *
 * The resolver-arbitration tests that used to live here are gone with the
 * concept: flows live in one native store, so "which resolver owns this flow id"
 * and "dedupe ids across resolvers" no longer describe anything. What replaced
 * them is FlowLocatorTest, which covers the store read directly.
 */
class FlowTriggerServiceTest extends TestCase {
	use \OCA\OpenRegister\Tests\Unit\Service\Flow\PublishedVersionDouble;

	/**
	 * A locator that reports the given flow ids for any trigger.
	 *
	 * @param array<int, string> $ids The flow ids to report.
	 *
	 * @return FlowLocator The stubbed locator.
	 */
	private function locatorReturning(array $ids): FlowLocator {
		$locator = $this->createMock(FlowLocator::class);
		$locator->method('flowsForEvent')->willReturn($ids);

		return $locator;
	}//end locatorReturning()

	public function testFiringQueuesARunForEveryWiredFlow(): void {
		$locator = $this->locatorReturning(['f1', 'f2']);

		$queued = [];
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('insert')->willReturnCallback(
			function (FlowRun $r) use (&$queued) {
				$queued[] = $r->getFlowId();
				return $r;
			}
		);

		// No OrganisationService in the container — a run queued with no session
		// is recorded unattributed rather than guessed at.
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		// Since versioning, queue() refuses a flow with no published version.
		// These fixtures are about what a run DOES, so they say version 1 is
		// live; the refusal itself has its own tests.
		$versions = $this->publishedVersionMapper();
		$pin = $this->pinReturning();
		$container->method('get')->willReturnCallback(
			function (string $id) use ($versions, $pin): object {
				if ($id === \OCA\OpenRegister\Db\FlowVersionMapperInterface::class) {
					return $versions;
				}

				if ($id === \OCA\OpenRegister\Service\Flow\FlowDefinitionPin::class) {
					return $pin;
				}

				throw new \RuntimeException('not available');
			}
		);

		$runner = new FlowRunService(
			stores: new FlowRunStores(
				mapper: $mapper,
				stateMapper: $this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class)
			),
			walk: new FlowRunWalkServices(engine: $this->createMock(\OCA\OpenRegister\Service\Flow\FlowEngine::class), registry: $this->createMock(\OCA\OpenRegister\Service\Flow\FlowNodeRegistry::class)),
			logger: new \Psr\Log\NullLogger(),
			container: $container
		);

		$service = new FlowTriggerService(new \OCA\OpenRegister\Service\Flow\FlowExecution($locator, $runner, new \Psr\Log\NullLogger()));

		$count = $service->fire(
			event: 'object.created',
			subject: ['uuid' => 'u1', 'register' => 'hermiq', 'schema' => 'agent'],
			user: 'alice'
		);

		$this->assertSame(2, $count);
		$this->assertSame(['f1', 'f2'], $queued);
	}//end testFiringQueuesARunForEveryWiredFlow()

	public function testAnEventWithNoWiredFlowQueuesNothing(): void {
		$runner = $this->createMock(FlowRunService::class);
		$runner->expects($this->never())->method('queueRun');

		$service = new FlowTriggerService(new \OCA\OpenRegister\Service\Flow\FlowExecution($this->locatorReturning([]), $runner, new \Psr\Log\NullLogger()));

		$this->assertSame(0, $service->fire(event: 'object.created', subject: ['register' => 'x', 'schema' => 'y']));
	}//end testAnEventWithNoWiredFlowQueuesNothing()

	/**
	 * 🔴 ONE BAD FLOW MUST NOT SILENCE ITS SIBLINGS. An enabled-but-unpublished
	 * flow on an event is refused by the queue path with "no published
	 * version" — a fact about THAT flow. Thrown out of the fan-out loop it
	 * aborted the whole event: the healthy flow wired to the same event queued
	 * nothing, and the case it should have picked up got no run. Per-flow
	 * isolation: the refusal is logged and skipped, the rest queue.
	 */
	public function testOneUnpublishedFlowDoesNotAbortTheFanOutForItsSiblings(): void {
		$queued = [];
		$runner = $this->createMock(FlowRunService::class);
		$runner->method('queueRun')->willReturnCallback(
			function (string $flowId) use (&$queued): FlowRun {
				if ($flowId === 'unpublished') {
					throw new \OCA\OpenRegister\Service\Flow\FlowLifecycleRefused(
						reason: \OCA\OpenRegister\Service\Flow\FlowLifecycleRefused::REASON_NO_PUBLISHED_VERSION,
						flowId: $flowId,
						state: null
					);
				}

				$queued[] = $flowId;
				$run = new FlowRun();
				$run->setUuid('run-' . $flowId);
				$run->setFlowId($flowId);

				return $run;
			}
		);

		$service = new FlowTriggerService(
			new \OCA\OpenRegister\Service\Flow\FlowExecution($this->locatorReturning(['unpublished', 'healthy']), $runner, new \Psr\Log\NullLogger())
		);

		$count = $service->fire(
			event: 'object.created',
			subject: ['uuid' => 'u1', 'register' => 'dossiq', 'schema' => 'case'],
			user: 'alice'
		);

		$this->assertSame(1, $count, 'the healthy flow still queues');
		$this->assertSame(['healthy'], $queued, 'the refused flow is skipped, not fatal');
	}//end testOneUnpublishedFlowDoesNotAbortTheFanOutForItsSiblings()

	/**
	 * A LOCATOR failure is the whole-event failure: nothing can be matched, so
	 * fire() reports zero and swallows, never throwing into the user's save.
	 */
	public function testALocatorFailureIsSwallowedAsZero(): void {
		$locator = $this->createMock(FlowLocator::class);
		$locator->method('flowsForEvent')->willThrowException(new \RuntimeException('index gone'));
		$runner = $this->createMock(FlowRunService::class);
		$runner->expects($this->never())->method('queueRun');

		$service = new FlowTriggerService(new \OCA\OpenRegister\Service\Flow\FlowExecution($locator, $runner, new \Psr\Log\NullLogger()));

		$this->assertSame(0, $service->fire(event: 'object.created'));
	}//end testALocatorFailureIsSwallowedAsZero()

	/**
	 * A sync flow runs inline with its resolved subject, and an inline FAILURE
	 * keeps the queued run counted: the worker drains it, so reporting zero
	 * would deny a run that exists. Neither outcome may abort the siblings.
	 */
	public function testAnInlineFailureKeepsTheQueuedRunCounted(): void {
		$locator = $this->createMock(FlowLocator::class);
		$locator->method('flowsForEvent')->willReturn(['sync-1']);
		$locator->method('resolveFlow')->willReturn(['id' => 'sync-1', 'executionMode' => 'sync']);
		$locator->method('resolveObject')->willReturn(new \stdClass());

		$runner = $this->createMock(FlowRunService::class);
		$run = new FlowRun();
		$run->setUuid('run-sync-1');
		$runner->method('queueRun')->willReturn($run);
		$runner->method('executePass')->willThrowException(new \RuntimeException('step blew up'));

		$service = new FlowTriggerService(new \OCA\OpenRegister\Service\Flow\FlowExecution($locator, $runner, new \Psr\Log\NullLogger()));

		$this->assertSame(
			1,
			$service->fire(event: 'object.created', subject: ['uuid' => 'u1', 'register' => 'r', 'schema' => 's']),
			'the run exists and the worker takes over; the count must say so'
		);
	}//end testAnInlineFailureKeepsTheQueuedRunCounted()

	/**
	 * A subjectless sync flow is seeded from the payload its trigger recorded,
	 * and executes inline against a bare holder.
	 */
	public function testASubjectlessSyncFlowSeedsFromThePayload(): void {
		$locator = $this->createMock(FlowLocator::class);
		$locator->method('flowsForEvent')->willReturn(['sync-1']);
		$locator->method('resolveFlow')->willReturn(['id' => 'sync-1', 'executionMode' => 'sync']);

		$run = new FlowRun();
		$run->setUuid('run-sync-2');
		$run->setContext(['payload' => ['path' => '/f.txt']]);

		$seen = null;
		$runner = $this->createMock(FlowRunService::class);
		$runner->method('queueRun')->willReturn($run);
		$runner->expects($this->once())->method('executePass')->willReturnCallback(
			function (FlowRunPass $pass, FlowRunOrigin $origin) use (&$seen, $run): FlowRun {
				$seen = $origin->items;
				return $run;
			}
		);

		$service = new FlowTriggerService(new \OCA\OpenRegister\Service\Flow\FlowExecution($locator, $runner, new \Psr\Log\NullLogger()));

		$this->assertSame(1, $service->fire(event: 'file.created'));
		// executePass(pass, origin): the origin's seed items carry the payload.
		$this->assertSame('/f.txt', $seen[0]['json']['path']);
	}//end testASubjectlessSyncFlowSeedsFromThePayload()

	/**
	 * A sync flow whose subject cannot be resolved is left queued for the
	 * worker's defensive path, still counted.
	 */
	public function testAnUnresolvableSubjectLeavesTheSyncRunToTheWorker(): void {
		$locator = $this->createMock(FlowLocator::class);
		$locator->method('flowsForEvent')->willReturn(['sync-1']);
		$locator->method('resolveFlow')->willReturn(['id' => 'sync-1', 'executionMode' => 'sync']);
		$locator->method('resolveObject')->willReturn(null);

		$runner = $this->createMock(FlowRunService::class);
		$runner->method('queueRun')->willReturn(new FlowRun());
		$runner->expects($this->never())->method('executePass');

		$service = new FlowTriggerService(new \OCA\OpenRegister\Service\Flow\FlowExecution($locator, $runner, new \Psr\Log\NullLogger()));

		$this->assertSame(1, $service->fire(event: 'object.created', subject: ['uuid' => 'gone']));
	}//end testAnUnresolvableSubjectLeavesTheSyncRunToTheWorker()

	/**
	 * A trigger runs inside a user's save; a failure to queue must be swallowed,
	 * A trigger runs inside a user's save; a failure to queue must be swallowed,
	 * never thrown into that action.
	 */
	public function testAQueueFailureIsSwallowed(): void {
		$runner = $this->createMock(FlowRunService::class);
		$runner->method('queueRun')->willThrowException(new \RuntimeException('db down'));

		$service = new FlowTriggerService(new \OCA\OpenRegister\Service\Flow\FlowExecution($this->locatorReturning(['f1']), $runner, new \Psr\Log\NullLogger()));

		// No exception escapes; returns 0.
		$this->assertSame(0, $service->fire(event: 'object.created'));
	}//end testAQueueFailureIsSwallowed()
}//end class
