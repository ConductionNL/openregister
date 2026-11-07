<?php

/**
 * OpenRegister DigestScheduleEvaluator
 *
 * Evaluates a rule's fixed-time-of-day `digest` schedule
 * (`{schedule: "daily"|"weekly", at: "HH:MM", timezone?, weekday?}`) LIVE
 * against the current wall clock, rather than trusting a precomputed
 * instant — mirroring `NotificationDeliveryWindowService::isInsideWindow()`
 * (see openspec/changes/notification-delivery-windows/design.md "Live
 * re-evaluation at flush time" and "Timezone handling").
 *
 * A queued row is "due" once the schedule's most recent occurrence at-or-
 * before `now` falls AFTER the row was enqueued — i.e. the scheduled flush
 * time has genuinely passed since the event arrived.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-delivery-windows/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;

/**
 * Live evaluator for the `digest` fixed-time schedule dialect key.
 */
class DigestScheduleEvaluator {
	/**
	 * Constructor.
	 *
	 * @param NotificationDeliveryWindowService $windowService Reused solely for its
	 *                                                         timezone-resolution helper
	 *                                                         (declared name, else server default).
	 */
	public function __construct(
		private readonly NotificationDeliveryWindowService $windowService,
	) {
	}//end __construct()

	/**
	 * Whether a rule's `digest` block is well-formed enough to evaluate.
	 *
	 * @param mixed $digest Candidate `digest` block from the rule spec.
	 *
	 * @return bool
	 */
	public function isValidDigestSpec(mixed $digest): bool {
		if (is_array($digest) === false) {
			return false;
		}

		$schedule = ($digest['schedule'] ?? null);
		if (in_array($schedule, ['daily', 'weekly'], true) === false) {
			return false;
		}

		return $this->isTimeOfDay(at: ($digest['at'] ?? null)) === true
			&& ($schedule !== 'weekly' || $this->isWeekday(weekday: ($digest['weekday'] ?? null)) === true);
	}//end isValidDigestSpec()

	/**
	 * Whether a value is an "HH:MM" 24h time string.
	 *
	 * @param mixed $at Candidate `digest.at`.
	 *
	 * @return bool
	 */
	private function isTimeOfDay(mixed $at): bool {
		return is_string($at) === true && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $at) === 1;
	}//end isTimeOfDay()

	/**
	 * Whether a value is a weekday number, 0 to 6.
	 *
	 * @param mixed $weekday Candidate `digest.weekday`.
	 *
	 * @return bool
	 */
	private function isWeekday(mixed $weekday): bool {
		return is_int($weekday) === true && $weekday >= 0 && $weekday <= 6;
	}//end isWeekday()

	/**
	 * The most recent scheduled occurrence at-or-before `$now`.
	 *
	 * @param array<string, mixed> $digest Validated `digest` block.
	 * @param DateTimeImmutable $now The instant to evaluate from.
	 *
	 * @return DateTimeImmutable
	 */
	public function lastOccurrence(array $digest, DateTimeImmutable $now): DateTimeImmutable {
		$localNow = $now->setTimezone($this->windowService->resolveTimezone(tzName: $this->timezoneName(digest: $digest)));
		[$hour, $minute] = array_map('intval', explode(':', (string)($digest['at'] ?? '00:00')));
		$candidate       = $localNow->setTime($hour, $minute, 0);

		for ($i = 0; $i < 8; $i++) {
			if ($this->isScheduledDay(digest: $digest, day: $candidate) === true && $candidate <= $localNow) {
				return $candidate;
			}

			$candidate = $candidate->modify('-1 day');
		}

		// Should be unreachable (a week always contains one matching
		// weekday), but fall back to "one week ago" rather than throwing.
		return $candidate;
	}//end lastOccurrence()

	/**
	 * The block's declared timezone name, null when absent or not a string.
	 *
	 * @param array<string, mixed> $digest Validated `digest` block.
	 *
	 * @return string|null
	 */
	private function timezoneName(array $digest): ?string {
		if (is_string($digest['timezone'] ?? null) === true) {
			return $digest['timezone'];
		}

		return null;
	}//end timezoneName()

	/**
	 * Whether the schedule fires on a day: every day, or the weekly weekday.
	 *
	 * @param array<string, mixed> $digest Validated `digest` block.
	 * @param DateTimeImmutable    $day    A candidate occurrence.
	 *
	 * @return bool
	 */
	private function isScheduledDay(array $digest, DateTimeImmutable $day): bool {
		return (string)($digest['schedule'] ?? 'daily') !== 'weekly' || (int)$day->format('w') === (int)($digest['weekday'] ?? null);
	}//end isScheduledDay()

	/**
	 * Whether a row enqueued at `$enqueuedAt` is due to flush at `$now`
	 * under the given `digest` schedule: the schedule's most recent
	 * occurrence at-or-before `$now` happened AFTER the row was enqueued.
	 *
	 * @param array<string, mixed> $digest Validated `digest` block.
	 * @param DateTimeImmutable $enqueuedAt When the row was queued.
	 * @param DateTimeImmutable $now The instant to evaluate from.
	 *
	 * @return bool
	 */
	public function isDue(array $digest, DateTimeImmutable $enqueuedAt, DateTimeImmutable $now): bool {
		if ($this->isValidDigestSpec(digest: $digest) === false) {
			// Malformed schedule — fail open (treat as due) so a bad
			// annotation cannot indefinitely trap events in the queue.
			return true;
		}

		$last = $this->lastOccurrence(digest: $digest, now: $now);

		return $last > $enqueuedAt;
	}//end isDue()
}//end class
