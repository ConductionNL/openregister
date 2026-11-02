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
use OCA\OpenRegister\Listener\ListenerRegistrar;
use OCA\OpenRegister\Listener\NotificationDedupePruneListener;
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
			if (class_exists($registration['listener']) === false) {
				$missing[] = $registration['listener'];
			}
		}

		$this->assertSame(expected: [], actual: array_values(array_unique($missing)));
	}//end testEveryListenerExists()

	/**
	 * The registrar registers exactly the pinned (event, listener) pairs, in order.
	 *
	 * A count floor stays green when one pair is dropped in a merge, and the
	 * order of listeners on the same event decides which runs first. The
	 * pinned list lives in fixtures/listener-registrations.txt; a change to
	 * the registrar updates it in the same commit.
	 *
	 * @return void
	 */
	public function testRegistersExactlyThePinnedPairsInOrder(): void {
		$context = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $context);

		$lines = file(filename: __DIR__.'/fixtures/listener-registrations.txt', flags: (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
		$this->assertIsArray(actual: $lines);
		$expected = array_values(
			array_filter(
				$lines,
				static fn (string $line): bool => str_starts_with($line, '#') === false
			)
		);

		$actual = array_map(
			static fn (array $registration): string => $registration['event'].' -> '.$registration['listener'],
			$context->listeners
		);

		$this->assertSame(expected: $expected, actual: $actual);
	}//end testRegistersExactlyThePinnedPairsInOrder()

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
