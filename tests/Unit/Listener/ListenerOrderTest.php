<?php

/**
 * The listeners of every event are registered in the pinned order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\ListenerRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Listeners of one event run in registration order, and the registrations are
 * spread over ListenerRegistrar and the component registrars. This pins the
 * order per event, so moving a registration between registrars cannot change
 * it silently. A deliberate change updates fixtures/listener-order.json.
 *
 * @covers \OCA\OpenRegister\Listener\ListenerRegistrar
 * @uses   \OCA\OpenRegister\Listener\Registrar\AccessListenerRegistrar
 * @uses   \OCA\OpenRegister\Listener\Registrar\ConfigurationListenerRegistrar
 * @uses   \OCA\OpenRegister\Listener\Registrar\FlowsListenerRegistrar
 * @uses   \OCA\OpenRegister\Listener\Registrar\McpListenerRegistrar
 * @uses   \OCA\OpenRegister\Listener\Registrar\NotificationsListenerRegistrar
 * @uses   \OCA\OpenRegister\Listener\Registrar\WorkListenerRegistrar
 */
class ListenerOrderTest extends TestCase {

	/**
	 * Every event gets exactly the pinned listeners, in the pinned order.
	 *
	 * @return void
	 */
	public function testEveryEventHasThePinnedListenersInOrder(): void {
		$context = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $context);

		$actual = [];
		foreach ($context->listeners as $registration) {
			$actual[$registration['event']][] = $registration['listener'];
		}

		ksort($actual);

		$expected = json_decode((string) file_get_contents(__DIR__.'/fixtures/listener-order.json'), true);

		$this->assertSame(expected: $expected, actual: $actual);
	}//end testEveryEventHasThePinnedListenersInOrder()

	/**
	 * A component registrar only takes events no other registrar listens to.
	 *
	 * @return void
	 */
	public function testAComponentRegistrarOwnsItsEventsAlone(): void {
		$owners = [];
		foreach (glob(__DIR__.'/../../../lib/Listener/Registrar/*ListenerRegistrar.php') as $path) {
			$class   = 'OCA\\OpenRegister\\Listener\\Registrar\\'.basename($path, '.php');
			$context = new RecordingRegistrationContext();
			$class::register(context: $context);
			foreach ($context->listeners as $registration) {
				$owners[$registration['event']][$class] = true;
			}
		}

		$all = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $all);
		$counts = [];
		foreach ($all->listeners as $registration) {
			$counts[$registration['event']] = ($counts[$registration['event']] ?? 0) + 1;
		}

		$shared = [];
		foreach ($owners as $event => $classes) {
			$own = 0;
			foreach (array_keys($classes) as $class) {
				$context = new RecordingRegistrationContext();
				$class::register(context: $context);
				$own += count(array_filter($context->listeners, fn (array $r): bool => $r['event'] === $event));
			}

			if (count($classes) > 1 || $own !== $counts[$event]) {
				$shared[] = $event;
			}
		}

		$this->assertSame(expected: [], actual: $shared, message: 'an event with listeners in more than one registrar belongs in ListenerRegistrar');
	}//end testAComponentRegistrarOwnsItsEventsAlone()
}//end class
