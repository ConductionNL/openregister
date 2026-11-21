<?php

/**
 * Inbound half of the calendar leaf: a created, updated, moved or restored
 * calendar object refreshes the link rows that point at it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\CalendarEvent\CalendarLinkInboundSync;
use OCA\OpenRegister\Service\CalendarEvent\InboundCalendarObject;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Created and restored objects gain their link row when tagged; every change
 * refreshes the cached fields of the rows that already exist. A failure here is
 * logged and never reaches the calendar write that fired the event.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */
class CalendarObjectChangedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CalendarLinkInboundSync $sync   The link-row sync.
	 * @param LoggerInterface         $logger Failure reporting.
	 */
	public function __construct(
		private readonly CalendarLinkInboundSync $sync,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a calendar-object event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public function handle(Event $event): void {
		try {
			$this->dispatch(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CalendarObjectChangedListener] Calendar link refresh failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end handle()

	/**
	 * Route the event to the sync.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 */
	private function dispatch(Event $event): void {
		if ($event instanceof CalendarObjectMovedEvent) {
			$this->sync->refresh(
				object: InboundCalendarObject::fromEventPayload(
					calendarId: $event->getTargetCalendarId(),
					calendarData: $event->getTargetCalendarData(),
					objectData: $event->getObjectData()
				),
				source: InboundCalendarObject::fromEventPayload(
					calendarId: $event->getSourceCalendarId(),
					calendarData: $event->getSourceCalendarData(),
					objectData: $event->getObjectData()
				)
			);
			return;
		}

		if (($event instanceof AbstractCalendarObjectEvent) === false) {
			return;
		}

		$object = InboundCalendarObject::fromEventPayload(
			calendarId: $event->getCalendarId(),
			calendarData: $event->getCalendarData(),
			objectData: $event->getObjectData()
		);

		if ($event instanceof CalendarObjectCreatedEvent || $event instanceof CalendarObjectRestoredEvent) {
			$this->sync->ensureLinked(object: $object);
			$this->sync->refresh(object: $object, source: $object);
			return;
		}

		if ($event instanceof CalendarObjectUpdatedEvent) {
			$this->sync->refresh(object: $object, source: $object);
		}
	}//end dispatch()
}//end class
