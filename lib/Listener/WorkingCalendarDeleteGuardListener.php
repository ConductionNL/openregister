<?php

/**
 * OpenRegister WorkingCalendarDeleteGuardListener
 *
 * Refuses to delete a working calendar that open timers still measure
 * against.
 *
 * `openregister_flow_timers.calendar_slug` names the calendar a timer is
 * measured against, and the resolver refuses an unknown name rather than
 * quietly falling back to weekdays. That refusal is the right behaviour and
 * the reason this guard exists: deleting a calendar an armed timer names does
 * not lose a deadline, it makes the next sweep throw for every timer that
 * named it, at a moment nobody is watching.
 *
 * Fired, cancelled and superseded timers never consult a calendar again, so
 * they do not block: counting them would make a calendar undeletable forever
 * on the strength of work that is already done.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-only-an-administrator-writes-a-calendar-and-a-referenced-one-cannot-be-deleted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Blocks the delete of a referenced working calendar.
 *
 * @template-implements IEventListener<ObjectDeletingEvent>
 */
class WorkingCalendarDeleteGuardListener implements IEventListener {

	/**
	 * The error code the refusal carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'working-calendar-in-use';

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendarWriteGuard $guard Recognises a calendar and counts its open timers.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly WorkingCalendarWriteGuard $guard,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse the delete when open timers name the calendar.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-only-an-administrator-writes-a-calendar-and-a-referenced-one-cannot-be-deleted
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletingEvent === false) {
			return;
		}

		try {
			$object = $event->getObject();
			if ($this->guard->isWorkingCalendar(object: $object) === false) {
				return;
			}

			$data = $object->getObject();
			if (is_array($data) === false) {
				return;
			}

			$slug = trim((string)($data['slug'] ?? ''));
			if ($slug === '') {
				return;
			}

			$blockers = $this->guard->deletionBlockers(calendarSlug: $slug);
			if ($blockers['count'] === 0) {
				return;
			}

			$event->setErrors(
				[
					'code' => self::ERROR_CODE,
					'message' => $this->guard->blockedMessage(calendarSlug: $slug, blockers: $blockers),
					// A referenced row refused is a conflict, not a malformed
					// body; the delete handler reads this and answers 409.
					'status' => 409,
					'calendar' => $slug,
					'openTimers' => $blockers['count'],
					'timers' => $blockers['timers'],
				]
			);
			$event->stopPropagation();
		} catch (Throwable $failure) {
			// A guard that cannot read the timer table must not also become
			// the reason nothing can be deleted. The delete proceeds and the
			// failure is named in the log.
			$this->logger->warning(
				message: '[WorkingCalendarDeleteGuardListener] The guard itself failed, allowing the delete: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try

	}//end handle()
}//end class
