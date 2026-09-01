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
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use PHPUnit\Framework\TestCase;

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
		$locator->method('flowsForTrigger')->willReturn($ids);

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
				if ($id === \OCA\OpenRegister\Db\FlowVersionMapper::class) {
					return $versions;
				}

				if ($id === \OCA\OpenRegister\Service\Flow\FlowDefinitionPin::class) {
					return $pin;
				}

				throw new \RuntimeException('not available');
			}
		);

		$runner = new FlowRunService(
			$mapper,
			$this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\Flow\FlowEngine::class),
			$this->createMock(\OCA\OpenRegister\Service\Flow\FlowNodeRegistry::class),
			new \Psr\Log\NullLogger(),
			$container
		);

		$service = new FlowTriggerService($locator, $runner, new \Psr\Log\NullLogger());

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
		$runner->expects($this->never())->method('queue');

		$service = new FlowTriggerService($this->locatorReturning([]), $runner, new \Psr\Log\NullLogger());

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
		$runner->method('queue')->willReturnCallback(
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
			$this->locatorReturning(['unpublished', 'healthy']),
			$runner,
			new \Psr\Log\NullLogger()
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
	 * A trigger runs inside a user's save; a failure to queue must be swallowed,
	 * never thrown into that action.
	 */
	public function testAQueueFailureIsSwallowed(): void {
		$runner = $this->createMock(FlowRunService::class);
		$runner->method('queue')->willThrowException(new \RuntimeException('db down'));

		$service = new FlowTriggerService($this->locatorReturning(['f1']), $runner, new \Psr\Log\NullLogger());

		// No exception escapes; returns 0.
		$this->assertSame(0, $service->fire(event: 'object.created'));
	}//end testAQueueFailureIsSwallowed()
}//end class
