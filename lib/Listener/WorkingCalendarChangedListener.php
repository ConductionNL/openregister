<?php

/**
 * A working calendar was saved, so the deadlines it governs need re-projecting.
 *
 * 🔑 OBSERVE THE OBJECT, NOT THE PAGE (D-1). The admin page, the objects API
 * and an import all end in a save of a `working-calendar` object, which
 * dispatches `ObjectUpdatedEvent`. Listening there covers every door at once
 * and needs no knowledge of who wrote — which is the row's own finding turned
 * round: "there is no single place a calendar change could be observed" stops
 * being true the moment the calendar is an object.
 *
 * 🔴 IT ONLY QUEUES (ADR-078). The work is thousands of timers, and an
 * administrator pressing Save on a calendar page must not wait for it.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\RecomputeTimersForCalendarJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues one recompute per changed calendar version.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */
class WorkingCalendarChangedListener implements IEventListener {

	/**
	 * The schema whose objects are working calendars.
	 *
	 * @var string
	 */
	public const SCHEMA = 'working-calendar';

	/**
	 * Constructor.
	 *
	 * @param IJobList        $jobs   The job queue.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IJobList $jobs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue a recompute when a working calendar changed.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$object = $event->getNewObject();
		if ($this->isWorkingCalendar(object: $object) === false) {
			return;
		}

		$slug = $this->slugOf(object: $object);
		if ($slug === '') {
			// A calendar with no slug is one nothing can resolve BY, so there
			// is no dependency set to recompute. Logged rather than ignored:
			// the save itself is the thing that should have been refused.
			$this->logger->warning('[WorkingCalendarChangedListener] a working calendar was saved with no slug; nothing queued');
			return;
		}

		// 🔴 THE VERSION IS THE IDEMPOTENCY KEY, so a calendar with none gets
		// no job rather than a job that can never be deduplicated. A recompute
		// that runs again on every save of an unchanged calendar would
		// supersede nothing — the moments would not move — but it would walk
		// every open timer each time, which is the shape of a job that is
		// quietly switched off six months later.
		$version = $this->versionOf(object: $object);
		if ($version === '') {
			$this->logger->warning(
				sprintf('[WorkingCalendarChangedListener] calendar "%s" carries no version; nothing queued', $slug)
			);
			return;
		}

		try {
			$this->jobs->add(RecomputeTimersForCalendarJob::class, ['slug' => $slug, 'version' => $version]);
		} catch (Throwable $e) {
			// The calendar has already been saved. Failing here would report a
			// failed save for a write that happened.
			$this->logger->error(
				sprintf('[WorkingCalendarChangedListener] could not queue a recompute for "%s": %s', $slug, $e->getMessage())
			);
		}
	}//end handle()

	/**
	 * Whether the saved object is a working calendar.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when it is.
	 */
	private function isWorkingCalendar(ObjectEntity $object): bool {
		return (str_contains((string)$object->getSchema(), self::SCHEMA) === true);
	}//end isWorkingCalendar()

	/**
	 * The calendar's slug, from the object's own data.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string The slug, or an empty string.
	 */
	private function slugOf(ObjectEntity $object): string {
		$data = ($object->getObject() ?? []);

		return trim((string)($data['slug'] ?? ''));
	}//end slugOf()

	/**
	 * The object's version, which is what makes a repeat event a duplicate.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string The version, or an empty string.
	 */
	private function versionOf(ObjectEntity $object): string {
		return trim((string)($object->getVersion() ?? ''));
	}//end versionOf()
}//end class
