<?php

/**
 * Registers the inbound calendar-link listeners.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\CalendarEvent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\CalendarEvent;

use OCA\OpenRegister\Listener\CalendarObjectChangedListener;
use OCA\OpenRegister\Listener\CalendarObjectDeletedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The inbound half of the calendar leaf (REQ-CAL-INB-001): calendar-side
 * changes refresh, create and delete the link rows.
 *
 * The listeners belong to the Integrations component, so they are registered
 * from the Integrations wiring rather than from the Work registrar. They run
 * at a priority below the default one the Work component's task write-back
 * hook (TaskVtodoWriteBackListener) uses, so whatever the order of the
 * registrar calls, a reverted projection is what the link rows see.
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */
final class CalendarLinkListenerRegistrar {

	/**
	 * Below the default priority (0) of the task write-back hook: run after it.
	 *
	 * @var int
	 */
	public const PRIORITY = -1;

	/**
	 * Calendar-object event class => inbound calendar-link listener.
	 *
	 * @var array<string, string>
	 */
	public const LISTENERS = [
		'OCP\\Calendar\\Events\\CalendarObjectCreatedEvent'       => CalendarObjectChangedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectUpdatedEvent'       => CalendarObjectChangedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectMovedEvent'         => CalendarObjectChangedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectRestoredEvent'      => CalendarObjectChangedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectDeletedEvent'       => CalendarObjectDeletedListener::class,
		'OCP\\Calendar\\Events\\CalendarObjectMovedToTrashEvent'  => CalendarObjectDeletedListener::class,
	];

	/**
	 * Register the calendar-link listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public static function register(IRegistrationContext $context): void {
		self::registerPresent(context: $context, listeners: self::LISTENERS);
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
				$context->registerEventListener($event, $listener, self::PRIORITY);
				$registered++;
			}
		}

		return $registered;
	}//end registerPresent()
}//end class
