<?php

/**
 * OpenRegister DeliveryWindowSpan
 *
 * The time-of-day span of a stored delivery window, with its day restriction.
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
 * Whether a local instant falls inside a delivery window's `start`-`end` span.
 *
 * A span whose end is before its start wraps past midnight; its after-midnight
 * leg belongs to the day the window started. With `days` declared, the window
 * only starts on those weekdays (0 = Sunday).
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class DeliveryWindowSpan {

	/**
	 * Start, in minutes since midnight.
	 *
	 * @var integer
	 */
	private int $start;

	/**
	 * End, in minutes since midnight.
	 *
	 * @var integer
	 */
	private int $end;

	/**
	 * Weekdays the window starts on; empty means every day.
	 *
	 * @var array<int|string, mixed>
	 */
	private array $days = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $window A stored window whose `start` and `end` are valid "HH:MM" strings.
	 */
	public function __construct(array $window) {
		$this->start = self::minutesSinceMidnight(time: (string)$window['start']);
		$this->end   = self::minutesSinceMidnight(time: (string)$window['end']);
		if (is_array($window['days'] ?? null) === true) {
			$this->days = $window['days'];
		}
	}//end __construct()

	/**
	 * Whether the local instant is inside the span.
	 *
	 * A zero-length span is never active rather than guessed to be 24h-always-on.
	 *
	 * @param DateTimeImmutable $local The instant, in the window's timezone.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function covers(DateTimeImmutable $local): bool {
		$now     = (((int)$local->format('G') * 60) + (int)$local->format('i'));
		$weekday = (int)$local->format('w');
		if ($this->start === $this->end) {
			return false;
		}

		if ($this->start < $this->end) {
			return $this->coversSameDay(now: $now, weekday: $weekday);
		}

		return $this->coversWrapped(now: $now, weekday: $weekday);
	}//end covers()

	/**
	 * Whether a span that ends the day it starts covers the minute.
	 *
	 * @param integer $now     Minutes since local midnight.
	 * @param integer $weekday Local weekday, 0 = Sunday.
	 *
	 * @return bool
	 */
	private function coversSameDay(int $now, int $weekday): bool {
		return $this->startsOn(weekday: $weekday) === true && $now >= $this->start && $now < $this->end;
	}//end coversSameDay()

	/**
	 * Whether a span that wraps past midnight covers the minute: today's leg
	 * from `start`, or the leg after midnight of a window that started yesterday.
	 *
	 * @param integer $now     Minutes since local midnight.
	 * @param integer $weekday Local weekday, 0 = Sunday.
	 *
	 * @return bool
	 */
	private function coversWrapped(int $now, int $weekday): bool {
		if ($now >= $this->start && $this->startsOn(weekday: $weekday) === true) {
			return true;
		}

		return $now < $this->end && $this->startsOn(weekday: (($weekday + 6) % 7)) === true;
	}//end coversWrapped()

	/**
	 * Whether the window starts on a weekday.
	 *
	 * @param integer $weekday Weekday, 0 = Sunday.
	 *
	 * @return bool
	 */
	private function startsOn(int $weekday): bool {
		return $this->days === [] || in_array($weekday, $this->days, true) === true;
	}//end startsOn()

	/**
	 * Parse an "HH:MM" string into minutes since midnight.
	 *
	 * @param string $time "HH:MM" string (already validated).
	 *
	 * @return integer
	 */
	private static function minutesSinceMidnight(string $time): int {
		[$hours, $minutes] = explode(':', $time);
		return (((int)$hours * 60) + (int)$minutes);
	}//end minutesSinceMidnight()
}//end class
