<?php

/**
 * Inbound half of the calendar leaf: a deleted calendar object takes its link
 * rows with it.
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
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deleting an event in the Calendar app moves it to the trash bin, so that is
 * the deletion a user makes; a permanent delete afterwards finds no rows left.
 * A restore re-links a tagged event through the changed listener. A failure
 * here is logged and never reaches the calendar write that fired the event.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */
class CalendarObjectDeletedListener implements IEventListener {

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
	 * Handle a calendar-object deletion.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof CalendarObjectDeletedEvent || $event instanceof CalendarObjectMovedToTrashEvent) === false) {
			return;
		}

		try {
			$this->sync->remove(
				object: InboundCalendarObject::fromEventPayload(
					calendarId: $event->getCalendarId(),
					calendarData: $event->getCalendarData(),
					objectData: $event->getObjectData()
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CalendarObjectDeletedListener] Calendar link removal failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end handle()
}//end class
