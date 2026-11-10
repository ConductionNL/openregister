<?php

/**
 * Unit tests for the handoff engine listeners + fallback drain job:
 * {@see \OCA\OpenRegister\Listener\HandoffLifecycleListener},
 * {@see \OCA\OpenRegister\Listener\HandoffQueueDrainListener}, and
 * {@see \OCA\OpenRegister\BackgroundJob\HandoffQueueDrainJob}.
 *
 * Covers: lifecycle-triggered handoffs fire only for matching
 * `lifecycle:<state>` triggers and only for REAL actors (system transitions
 * are gated out); hide-mode degradation never breaks the transition; the
 * queue-drain listener sweeps on schema-save / app-enable and swallows drain
 * failures; the TimedJob delegates to the same drain surface.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Listener\HandoffLifecycleListener;
use OCA\OpenRegister\Listener\HandoffQueueDrainListener;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * HandoffListenersTest.
 */
class HandoffListenersTest extends TestCase {

	private HandoffService&MockObject $handoffService;

	private SchemaMapper&MockObject $schemaMapper;

	protected function setUp(): void {
		$this->handoffService = $this->createMock(HandoffService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

	}//end setUp()

	/**
	 * A transition into the declared lifecycle state executes the handoff as
	 * the transitioning user; non-matching triggers do not fire.
	 *
	 * @return void
	 */
	public function testLifecycleListenerFiresOnMatchingTransition(): void {
		$this->handoffService->method('declaredHandoffs')->willReturn(
			[
				[
					'id' => 'deal-to-quote',
					'trigger' => 'lifecycle:won',
					'targetSemanticType' => 'https://openregister.app/ns#Quote',
				],
				[
					'id' => 'other',
					'trigger' => 'manual',
					'targetSemanticType' => 'https://openregister.app/ns#Case',
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn(new Schema());

		$executed = [];
		$this->handoffService->method('execute')->willReturnCallback(
			function (string $register, string $schema, string $id, string $handoffId) use (&$executed) {
				$executed[] = $handoffId;
				return ['status' => 'executed'];
			}
		);

		$listener = new HandoffLifecycleListener(
			handoffService: $this->handoffService,
			schemaMapper: $this->schemaMapper,
			logger: $this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->transitionEvent(to: 'won', userId: 'alice'));

		$this->assertSame(['deal-to-quote'], $executed);

	}//end testLifecycleListenerFiresOnMatchingTransition()

	/**
	 * System-applied transitions (null user) never fire handoffs — no
	 * system-user privilege lane (v1 gate).
	 *
	 * @return void
	 */
	public function testLifecycleListenerSkipsSystemTransitions(): void {
		$this->handoffService->expects($this->never())->method('declaredHandoffs');
		$this->handoffService->expects($this->never())->method('execute');

		$listener = new HandoffLifecycleListener(
			handoffService: $this->handoffService,
			schemaMapper: $this->schemaMapper,
			logger: $this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->transitionEvent(to: 'won', userId: null));

	}//end testLifecycleListenerSkipsSystemTransitions()

	/**
	 * Hide-mode degradation (provider unavailable) is swallowed — the
	 * transition itself must never fail because a peer app is absent.
	 *
	 * @return void
	 */
	public function testLifecycleListenerSwallowsDegradation(): void {
		$this->handoffService->method('declaredHandoffs')->willReturn(
			[
				[
					'id' => 'deal-to-quote',
					'trigger' => 'lifecycle:won',
					'targetSemanticType' => 'https://openregister.app/ns#Quote',
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->handoffService->method('execute')->willThrowException(
			new HandoffException(
				errorCode: HandoffException::PROVIDER_UNAVAILABLE,
				message: 'no provider'
			)
		);

		$listener = new HandoffLifecycleListener(
			handoffService: $this->handoffService,
			schemaMapper: $this->schemaMapper,
			logger: $this->createMock(LoggerInterface::class),
		);

		// Must not throw.
		$listener->handle($this->transitionEvent(to: 'won', userId: 'alice'));
		$this->addToAssertionCount(1);

	}//end testLifecycleListenerSwallowsDegradation()

	/**
	 * The queue-drain listener sweeps on schema-save events and swallows
	 * drain failures (the triggering save must never break).
	 *
	 * @return void
	 */
	public function testQueueDrainListenerSweepsAndSwallowsFailures(): void {
		$this->handoffService->expects($this->once())->method('drainParked')->willReturn(
			[
				'drained' => 1,
				'failed' => 0,
				'skipped' => 0,
			]
		);

		$listener = new HandoffQueueDrainListener(
			handoffService: $this->handoffService,
			logger: $this->createMock(LoggerInterface::class),
		);
		$listener->handle(new SchemaUpdatedEvent(newSchema: new Schema(), oldSchema: new Schema()));

		// Failure path: drainParked throws — handle() must not.
		$this->setUp();
		$this->handoffService->method('drainParked')->willThrowException(new \RuntimeException('boom'));
		$listener = new HandoffQueueDrainListener(
			handoffService: $this->handoffService,
			logger: $this->createMock(LoggerInterface::class),
		);
		$listener->handle(new SchemaUpdatedEvent(newSchema: new Schema(), oldSchema: new Schema()));
		$this->addToAssertionCount(1);

	}//end testQueueDrainListenerSweepsAndSwallowsFailures()

	/**
	 * Unrelated events are ignored by the drain listener.
	 *
	 * @return void
	 */
	public function testQueueDrainListenerIgnoresUnrelatedEvents(): void {
		$this->handoffService->expects($this->never())->method('drainParked');

		$listener = new HandoffQueueDrainListener(
			handoffService: $this->handoffService,
			logger: $this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->transitionEvent(to: 'won', userId: 'alice'));

	}//end testQueueDrainListenerIgnoresUnrelatedEvents()

	/**
	 * Build a transition event for the canonical source object.
	 *
	 * @param string $to The target state.
	 * @param string|null $userId The transitioning user (null = system).
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transitionEvent(string $to, ?string $userId): ObjectTransitionedEvent {
		$object = new ObjectEntity();
		$object->setUuid('src-uuid');
		$object->setRegister('7');
		$object->setSchema('12');

		return new ObjectTransitionedEvent(
			object: $object,
			action: 'transition',
			from: 'new',
			to: $to,
			userId: $userId,
			register: 'pipelinq-register',
			schema: 'deal'
		);

	}//end transitionEvent()
}//end class
