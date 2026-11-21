<?php

/**
 * The listener registrar subscribes existing listeners to existing events.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Event\DraftDeletedEvent;
use OCA\OpenRegister\Kernel\Contracts\Processes\ObjectWriteCacheFollowerInterface;
use OCA\OpenRegister\Kernel\Contracts\Processes\ObjectWritePushInterface;
use OCA\OpenRegister\Listener\NotifyPushListener;
use OCA\OpenRegister\Listener\RedactionPolicyCacheListener;
use OCA\OpenRegister\Listener\ListenerRegistrar;
use OCA\OpenRegister\Notifications\Listener\NotificationDedupePruneListener;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\PlatformServiceRegistrar;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

class ListenerRegistrarTest extends TestCase {

	/**
	 * Every registered listener is a class that exists.
	 *
	 * A listener registered under a name nothing answers to never runs, and
	 * nothing reports it.
	 *
	 * @return void
	 */
	public function testEveryListenerExists(): void {
		$context = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $context);

		$this->assertGreaterThan(expected: 200, actual: count($context->listeners));

		$missing = [];
		foreach ($context->listeners as $registration) {
			if (class_exists($registration['listener']) === false && interface_exists($registration['listener']) === false) {
				$missing[] = $registration['listener'];
			}
		}

		$this->assertSame(expected: [], actual: array_values(array_unique($missing)));
	}//end testEveryListenerExists()

	/**
	 * A listener registered by its contract is bound to a listener that implements it.
	 *
	 * The container resolves the contract through the alias, so an unbound one
	 * would fail on the first event it is dispatched for.
	 *
	 * @return void
	 */
	public function testEveryListenerContractIsBoundToAListenerThatImplementsIt(): void {
		$context = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $context);
		$bindings = ContractBindings::BINDINGS + PlatformServiceRegistrar::LISTENER_BINDINGS;

		$contracts = [];
		foreach ($context->listeners as $registration) {
			if (interface_exists($registration['listener']) === true) {
				$contracts[$registration['listener']] = $bindings[$registration['listener']] ?? null;
			}
		}

		$this->assertSame(
			expected: [
				ObjectWriteCacheFollowerInterface::class => RedactionPolicyCacheListener::class,
				ObjectWritePushInterface::class => NotifyPushListener::class,
			],
			actual: $contracts
		);
		foreach ($contracts as $contract => $listener) {
			$this->assertContains(needle: $contract, haystack: class_implements($listener), message: $listener.' implements '.$contract);
			$this->assertContains(needle: IEventListener::class, haystack: class_implements($listener), message: $listener.' is a listener');
		}
	}//end testEveryListenerContractIsBoundToAListenerThatImplementsIt()

	/**
	 * A lock and the archive answer on the deleting event, the one every delete path asks.
	 *
	 * @return void
	 */
	public function testTheObjectStateGuardListensToTheDeletingEvent(): void {
		$context = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $context);

		$events = array_column(
			array_filter(
				$context->listeners,
				static fn (array $registration): bool => $registration['listener'] === \OCA\OpenRegister\Listener\ObjectStateDeleteGuardListener::class
			),
			'event'
		);

		$this->assertSame(expected: [\OCA\OpenRegister\Event\ObjectDeletingEvent::class], actual: $events);
	}//end testTheObjectStateGuardListensToTheDeletingEvent()

	/**
	 * Every OpenRegister event a listener is registered on exists.
	 *
	 * Events dispatch by class name, so a listener on a name no event carries
	 * stays silent.
	 *
	 * @return void
	 */
	public function testEveryOpenRegisterEventExists(): void {
		$context = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $context);

		$missing = [];
		foreach ($context->listeners as $registration) {
			if (str_starts_with($registration['event'], 'OCA\\OpenRegister\\') === true
				&& class_exists($registration['event']) === false
			) {
				$missing[] = $registration['event'];
			}
		}

		$this->assertSame(expected: [], actual: array_values(array_unique($missing)));
	}//end testEveryOpenRegisterEventExists()

	/**
	 * A move asks only the guards of values others hold; webhooks hear no delete of an object that still exists.
	 *
	 * @return void
	 */
	public function testAMoveAsksTheGuardsOfHeldValues(): void {
		$context = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $context);

		$listeners = array_column(
			array_filter(
				$context->listeners,
				static fn (array $registration): bool => $registration['event'] === \OCA\OpenRegister\Event\ObjectLeavingEvent::class
			),
			'listener'
		);

		$this->assertSame(
			expected: [
				\OCA\OpenRegister\Listener\WorkingCalendarDeleteGuardListener::class,
				\OCA\OpenRegister\Listener\ConceptDeleteGuardListener::class,
			],
			actual: $listeners
		);
	}//end testAMoveAsksTheGuardsOfHeldValues()

	/**
	 * The draft listeners are registered last, after every object listener.
	 *
	 * @return void
	 */
	public function testTheDraftListenersComeLast(): void {
		$context = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $context);

		$this->assertSame(
			expected: ['event' => DraftDeletedEvent::class, 'listener' => NotificationDedupePruneListener::class],
			actual: end($context->listeners)
		);
	}//end testTheDraftListenersComeLast()
}//end class
