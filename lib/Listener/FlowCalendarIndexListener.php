<?php

/**
 * Keeps the calendar-trigger index in step with object writes.
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
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowCalendarIndex;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Places, moves or removes a record's calendar moments after it is written.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowCalendarIndexListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param FlowCalendarIndex $index  The index writer.
	 * @param LoggerInterface   $logger Logs an index write that failed; the save itself stands.
	 */
	public function __construct(
		private readonly FlowCalendarIndex $index,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @listener-placement inline — one indexed lookup and at most one row write per calendar trigger on the schema.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#scenario-a-moved-end-date-moves-the-reminder
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof ObjectDeletedEvent) {
				$this->index->remove(objectUuid: (string)$event->getObject()->getUuid());
				return;
			}

			if ($event instanceof ObjectUpdatedEvent) {
				$this->index->index(object: $event->getNewObject());
				return;
			}

			if ($event instanceof ObjectCreatedEvent) {
				$this->index->index(object: $event->getObject());
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowCalendarIndexListener] Could not update the calendar-trigger index: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end handle()
}//end class
