<?php

/**
 * The reminder step of the scheduled notification sweep.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Notifications\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeKey;
use OCA\OpenRegister\Notifications\Db\ReminderProgress;
use OCA\OpenRegister\Notifications\Service\Notification\ReminderDecision;
use OCA\OpenRegister\Notifications\Service\Notification\ReminderPlan;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterTarget;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;

/**
 * Arms, sends, repeats and stops the reminder of one scheduled rule on one object.
 *
 * Called by {@see ScheduledNotificationJob} for every object a rule with a
 * `reminder` block matches, in place of the fingerprint dedupe a plain
 * scheduled rule uses (design D-4). The stop condition is evaluated before
 * the send (D-5): when it holds, nothing is sent and the condition is
 * recorded on the rule's dedupe row, which then never re-arms.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
final class ScheduledReminders {

	/**
	 * Constructor.
	 *
	 * @param ScheduledObjectSources        $sources  The filter evaluator and the working calendars.
	 * @param ScheduledNotificationDelivery $delivery The dispatcher, the dedupe state and the logger.
	 */
	public function __construct(
		private readonly ScheduledObjectSources $sources,
		private readonly ScheduledNotificationDelivery $delivery,
	) {
	}//end __construct()

	/**
	 * Run the reminder of the fire's rule for one object the rule matched.
	 *
	 * @param ScheduledFire $fire   The rule's fire, carrying the `reminder` block.
	 * @param ObjectEntity  $object The object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function onMatch(ScheduledFire $fire, ObjectEntity $object): void {
		$uuid = (string)$object->getUuid();
		if ($uuid === '') {
			return;
		}

		$plan = new ReminderPlan(reminder: $fire->reminder);
		$key  = new NotificationDedupeKey(schemaId: $fire->schemaId, ruleKey: $fire->name, objectUuid: $uuid);
		$data = (array)($object->getObject() ?? []);

		try {
			$stored   = ReminderProgress::fromState(state: $this->delivery->dedupeMapper->findByKey(key: $key));
			$calendar = $this->calendar(plan: $plan, object: $object);
			$decision = $plan->decide(anchorValue: ($data[$plan->anchor()] ?? null), stored: $stored, now: $fire->nowDt, calendar: $calendar);
		} catch (\Throwable $e) {
			$this->delivery->logger->warning(
				sprintf('[ScheduledNotificationJob] reminder "%s" on object %s not evaluated: %s', $fire->name, $uuid, $e->getMessage())
			);
			return;
		}

		if ($decision->kind === ReminderDecision::DUE) {
			$this->due(fire: $fire, object: $object, plan: $plan, decision: $decision);
			return;
		}

		// Not due: keep a stored row seen, so the retention sweep never forgets
		// a reminder that was sent or stopped and lets it fire again.
		if ($decision->progress !== null && ($stored !== null || $decision->rearmed === true)) {
			$this->record(key: $key, fire: $fire, progress: $decision->progress, dispatched: false);
		}
	}//end onMatch()

	/**
	 * Evaluate the stop condition, then send the due reminder or record the stop.
	 *
	 * @param ScheduledFire    $fire     The fire.
	 * @param ObjectEntity     $object   The object.
	 * @param ReminderPlan     $plan     The plan.
	 * @param ReminderDecision $decision The due decision.
	 *
	 * @return void
	 */
	private function due(ScheduledFire $fire, ObjectEntity $object, ReminderPlan $plan, ReminderDecision $decision): void {
		$key      = new NotificationDedupeKey(schemaId: $fire->schemaId, ruleKey: $fire->name, objectUuid: (string)$object->getUuid());
		$progress = $decision->progress;
		if ($progress === null) {
			return;
		}

		$stopWhen = $plan->stopWhen();
		$target   = new ScheduledFilterTarget(objectData: (array)($object->getObject() ?? []), now: $fire->nowDt);
		if ($stopWhen !== null && $this->sources->filterEvaluator->matchesTarget(filter: $stopWhen, target: $target) === true) {
			$stopped = new ReminderProgress(fingerprint: $progress->fingerprint, sent: $progress->sent, stopped: $plan->stopReason());
			$this->record(key: $key, fire: $fire, progress: $stopped, dispatched: false);
			$fire->deduplicated++;
			return;
		}

		try {
			$this->delivery->dispatcher->dispatch(
				$object,
				'scheduled',
				['notificationName' => $fire->name, 'reminderSequence' => $decision->sequence()]
			);
		} catch (\Throwable $e) {
			$this->delivery->logger->warning(
				sprintf('[ScheduledNotificationJob] reminder "%s" dispatch failed for object %s: %s', $fire->name, $key->objectUuid, $e->getMessage())
			);
			return;
		}

		$sent = new ReminderProgress(fingerprint: $progress->fingerprint, sent: $decision->sequence());
		$this->record(key: $key, fire: $fire, progress: $sent, dispatched: true);
		$fire->dispatched++;
	}//end due()

	/**
	 * Store the reminder's progress; a lost write is logged, not escalated.
	 *
	 * @param NotificationDedupeKey $key        The triple.
	 * @param ScheduledFire         $fire       The fire.
	 * @param ReminderProgress      $progress   The progress.
	 * @param boolean               $dispatched Whether this sweep sent a reminder.
	 *
	 * @return void
	 */
	private function record(NotificationDedupeKey $key, ScheduledFire $fire, ReminderProgress $progress, bool $dispatched): void {
		try {
			$this->delivery->dedupeMapper->recordReminder(key: $key, progress: $progress, now: $fire->seenAt, dispatched: $dispatched);
		} catch (\Throwable $e) {
			$this->delivery->logger->warning(
				sprintf('[ScheduledNotificationJob] reminder "%s" state not stored for object %s: %s', $fire->name, $key->objectUuid, $e->getMessage())
			);
		}
	}//end record()

	/**
	 * The working calendar business units are counted on, when the plan needs one.
	 *
	 * @param ReminderPlan $plan   The plan.
	 * @param ObjectEntity $object The object, whose organisation picks the calendar.
	 *
	 * @return WorkingCalendar|null The calendar.
	 *
	 * @throws \RuntimeException When the plan counts business days and no calendar service is wired.
	 */
	private function calendar(ReminderPlan $plan, ObjectEntity $object): ?WorkingCalendar {
		if ($plan->needsCalendar() === false) {
			return null;
		}

		if ($this->sources->calendars === null) {
			throw new \RuntimeException('it counts business days and no working calendar service is available');
		}

		return $this->sources->calendars->resolve(calendarSlug: null, organisation: $object->getOrganisation());
	}//end calendar()
}//end class
