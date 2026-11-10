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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Listener\Support\ConflictVeto;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuardInterface;
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
	use ConflictVeto;

	/**
	 * The error code the refusal carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'working-calendar-in-use';

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendarWriteGuardInterface $guard Recognises a calendar and counts its open timers.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly WorkingCalendarWriteGuardInterface $guard,
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
		if ($event instanceof ObjectDeletingEvent) {
			$this->refuseReferencedCalendar(event: $event);
		}

	}//end handle()

	/**
	 * Refuse the delete of a calendar open timers name, letting it through when the guard itself fails.
	 *
	 * @param ObjectDeletingEvent $event The delete event.
	 *
	 * @return void
	 */
	private function refuseReferencedCalendar(ObjectDeletingEvent $event): void {
		try {
			$this->refuseWhenTimersNameIt(event: $event);
		} catch (Throwable $failure) {
			// A guard that cannot read the timer table must not also become
			// the reason nothing can be deleted. The delete proceeds and the
			// failure is named in the log.
			$this->logger->warning(
				message: '[WorkingCalendarDeleteGuardListener] The guard itself failed, allowing the delete: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try

	}//end refuseReferencedCalendar()

	/**
	 * Refuse the delete when open timers name the calendar being deleted.
	 *
	 * @param ObjectDeletingEvent $event The delete event.
	 *
	 * @return void
	 */
	private function refuseWhenTimersNameIt(ObjectDeletingEvent $event): void {
		$slug = $this->calendarSlugOf(object: $event->getObject());
		if ($slug === null) {
			return;
		}

		$blockers = $this->guard->deletionBlockers(calendarSlug: $slug);
		if ($blockers['count'] === 0) {
			return;
		}

		$this->vetoAsConflict(
			event: $event,
			refusal: ['code' => self::ERROR_CODE, 'message' => $this->guard->blockedMessage(calendarSlug: $slug, blockers: $blockers)],
			detail: ['calendar' => $slug, 'openTimers' => $blockers['count'], 'timers' => $blockers['timers']]
		);
	}//end refuseWhenTimersNameIt()

	/**
	 * The slug of the working calendar being deleted, or null when the object is none.
	 *
	 * @param ObjectEntity $object The object being deleted.
	 *
	 * @return string|null The trimmed slug.
	 */
	private function calendarSlugOf(ObjectEntity $object): ?string {
		if ($this->guard->isWorkingCalendar(object: $object) === false || is_array($object->getObject()) === false) {
			return null;
		}

		$slug = trim((string)($object->getObject()['slug'] ?? ''));
		if ($slug !== '') {
			return $slug;
		}

		return null;
	}//end calendarSlugOf()
}//end class
