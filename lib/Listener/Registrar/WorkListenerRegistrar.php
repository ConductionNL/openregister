<?php

/**
 * WorkListenerRegistrar: the event listeners of the Work component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Registrar;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listeners of the Work component on events no other component listens to.
 *
 * An event with listeners from more than one component stays in
 * ListenerRegistrar, in one ordered list, because listeners of one event run
 * in registration order.
 */
final class WorkListenerRegistrar {

	/**
	 * Calendar-object event class => inbound calendar-link listener.
	 *
	 * @var array<string, string>
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public const CALENDAR_LINK_LISTENERS = [
		'OCP\\Calendar\\Events\\CalendarObjectCreatedEvent' => \OCA\OpenRegister\Listener\CalendarObjectChangedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectUpdatedEvent' => \OCA\OpenRegister\Listener\CalendarObjectChangedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectMovedEvent' => \OCA\OpenRegister\Listener\CalendarObjectChangedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectRestoredEvent' => \OCA\OpenRegister\Listener\CalendarObjectChangedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectDeletedEvent' => \OCA\OpenRegister\Listener\CalendarObjectDeletedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectMovedToTrashEvent' => \OCA\OpenRegister\Listener\CalendarObjectDeletedListener::class,
	];

	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listeners carry their own specs.
	 */
	public static function register(IRegistrationContext $context): void {
		// The safety-net write-back hook (design D-6): calendar writes that
		// reached the backend without traversing the Sabre plugin are
		// reverted to the engine's truth, and the actor is told why.
		$context->registerEventListener(
			\OCP\Calendar\Events\CalendarObjectUpdatedEvent::class,
			\OCA\OpenRegister\Listener\TaskVtodoWriteBackListener::class
		);
		$context->registerEventListener(
			\OCP\Calendar\Events\CalendarObjectDeletedEvent::class,
			\OCA\OpenRegister\Listener\TaskVtodoWriteBackListener::class
		);

		// The inbound half of the calendar leaf (calendar-leaf-inbound,
		// REQ-CAL-INB-001): calendar-side changes refresh, create and delete
		// the link rows. Registered after the write-back hook, so a reverted
		// projection is what the link rows see.
		self::registerPresent(context: $context, listeners: self::CALENDAR_LINK_LISTENERS);
	}//end register()

	/**
	 * Register each listener of the map whose event class exists, so a server
	 * without one of the calendar events keeps the outbound-only behaviour
	 * instead of failing at boot.
	 *
	 * @param IRegistrationContext  $context   The registration context.
	 * @param array<string, string> $listeners Event class => listener class.
	 *
	 * @return int How many were registered.
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public static function registerPresent(IRegistrationContext $context, array $listeners): int {
		$registered = 0;
		foreach ($listeners as $event => $listener) {
			if (class_exists($event) === true) {
				$context->registerEventListener($event, $listener);
				$registered++;
			}
		}

		return $registered;
	}//end registerPresent()
}//end class
