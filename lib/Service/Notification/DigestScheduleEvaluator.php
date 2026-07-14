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

namespace OCA\OpenRegister\Service\Notification;

use DateTimeImmutable;

/**
 * Live evaluator for the `digest` fixed-time schedule dialect key.
 */
class DigestScheduleEvaluator
{
    /**
     * Constructor.
     *
     * @param NotificationDeliveryWindowService $windowService Reused solely for its
     *                                                         timezone-resolution helper
     *                                                         (declared name, else server default).
     */
    public function __construct(private readonly NotificationDeliveryWindowService $windowService)
    {
    }//end __construct()

    /**
     * Whether a rule's `digest` block is well-formed enough to evaluate.
     *
     * @param mixed $digest Candidate `digest` block from the rule spec.
     *
     * @return bool
     */
    public function isValidDigestSpec(mixed $digest): bool
    {
        if (is_array($digest) === false) {
            return false;
        }

        $schedule = ($digest['schedule'] ?? null);
        if (in_array($schedule, ['daily', 'weekly'], true) === false) {
            return false;
        }

        $at = ($digest['at'] ?? null);
        if (is_string($at) === false || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $at) !== 1) {
            return false;
        }

        if ($schedule === 'weekly') {
            $weekday = ($digest['weekday'] ?? null);
            if (is_int($weekday) === false || $weekday < 0 || $weekday > 6) {
                return false;
            }
        }

        return true;

    }//end isValidDigestSpec()

    /**
     * The most recent scheduled occurrence at-or-before `$now`.
     *
     * @param array<string, mixed> $digest Validated `digest` block.
     * @param DateTimeImmutable    $now    The instant to evaluate from.
     *
     * @return DateTimeImmutable
     */
    public function lastOccurrence(array $digest, DateTimeImmutable $now): DateTimeImmutable
    {
        $timezoneSpec = null;
        if (is_string($digest['timezone'] ?? null) === true) {
            $timezoneSpec = $digest['timezone'];
        }

        $tz       = $this->windowService->resolveTimezone(tzName: $timezoneSpec);
        $schedule = (string) ($digest['schedule'] ?? 'daily');
        $at       = (string) ($digest['at'] ?? '00:00');
        $weekday  = $digest['weekday'] ?? null;

        [$hour, $minute] = array_map('intval', explode(':', $at));

        $localNow  = $now->setTimezone($tz);
        $candidate = $localNow->setTime($hour, $minute, 0);

        for ($i = 0; $i < 8; $i++) {
            $matchesDay = ($schedule !== 'weekly') || ((int) $candidate->format('w') === (int) $weekday);
            if ($matchesDay === true && $candidate <= $localNow) {
                return $candidate;
            }

            $candidate = $candidate->modify('-1 day');
        }

        // Should be unreachable (a week always contains one matching
        // weekday), but fall back to "one week ago" rather than throwing.
        return $candidate;

    }//end lastOccurrence()

    /**
     * Whether a row enqueued at `$enqueuedAt` is due to flush at `$now`
     * under the given `digest` schedule: the schedule's most recent
     * occurrence at-or-before `$now` happened AFTER the row was enqueued.
     *
     * @param array<string, mixed> $digest     Validated `digest` block.
     * @param DateTimeImmutable    $enqueuedAt When the row was queued.
     * @param DateTimeImmutable    $now        The instant to evaluate from.
     *
     * @return bool
     */
    public function isDue(array $digest, DateTimeImmutable $enqueuedAt, DateTimeImmutable $now): bool
    {
        if ($this->isValidDigestSpec(digest: $digest) === false) {
            // Malformed schedule — fail open (treat as due) so a bad
            // annotation cannot indefinitely trap events in the queue.
            return true;
        }

        $last = $this->lastOccurrence(digest: $digest, now: $now);

        return $last > $enqueuedAt;

    }//end isDue()
}//end class
