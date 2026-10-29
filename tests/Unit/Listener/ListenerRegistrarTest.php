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
