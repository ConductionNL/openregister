<?php

/**
 * When a reminder declared on a notification rule is due.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\OpenRegister\Notifications\Db\ReminderProgress;
use OCA\OpenRegister\Service\Flow\Timer\SlaAddition;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;

/**
 * A reminder's schedule, from the `reminder` block {@see NotificationReminderValidator} accepted.
 *
 * The first reminder is due at the anchor plus the offset; each repeat is
 * due one interval after the one before, up to `repeat.max` reminders in
 * all (one without a repeat). Amounts are counted by the timers' calculator,
 * so business units are counted on the working calendar. The plan sends one
 * reminder per sweep: a sweep that comes late sends the next one, not every
 * one it missed.
 *
 * The anchor value the reminder is armed from is kept as the fingerprint of
 * its dedupe row. A different value re-arms the reminder from the new date
 * and starts the count over; a stopped reminder stays stopped.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
final class ReminderPlan {

	/**
	 * The longest stored stop reason.
	 *
	 * @var integer
	 */
	private const STOP_REASON_LENGTH = 255;

	/**
	 * The business-time calculator.
	 *
	 * @var SlaCalculatorInterface
	 */
	private readonly SlaCalculatorInterface $calculator;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>        $reminder   The validated `reminder` block.
	 * @param SlaCalculatorInterface|null $calculator The business-time calculator.
	 */
	public function __construct(
		private readonly array $reminder,
		?SlaCalculatorInterface $calculator = null,
	) {
		$this->calculator = ($calculator ?? new SlaCalculator());
	}//end __construct()

	/**
	 * The anchor property.
	 *
	 * @return string The property name.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function anchor(): string {
		return (string)($this->reminder['anchor'] ?? '');
	}//end anchor()

	/**
	 * How many reminders are sent in all.
	 *
	 * @return integer `repeat.max`, or 1 without a repeat.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function total(): int {
		$repeat = ($this->reminder['repeat'] ?? null);
		if (is_array($repeat) === false) {
			return 1;
		}

		return max(1, (int)($repeat['max'] ?? 1));
	}//end total()

	/**
	 * Whether an amount of the reminder is counted in working days.
	 *
	 * @return boolean True when the offset or the interval uses business days.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function needsCalendar(): bool {
		$units = [($this->reminder['offset']['unit'] ?? null), ($this->reminder['repeat']['interval']['unit'] ?? null)];

		return in_array(SlaCalculatorInterface::UNIT_BUSINESS_DAYS, $units, true);
	}//end needsCalendar()

	/**
	 * The stop condition, or null when none is declared.
	 *
	 * @return array<string, mixed>|null The filter.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function stopWhen(): ?array {
		$stopWhen = ($this->reminder['stopWhen'] ?? null);
		if (is_array($stopWhen) === false || $stopWhen === []) {
			return null;
		}

		return $stopWhen;
	}//end stopWhen()

	/**
	 * The reason a stop records: the condition that ended the reminder.
	 *
	 * @return string The reason, at most 255 characters.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function stopReason(): string {
		$reason = 'stopWhen ' . (string)json_encode($this->stopWhen(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return mb_substr($reason, 0, self::STOP_REASON_LENGTH);
	}//end stopReason()

	/**
	 * When a reminder is due.
	 *
	 * @param DateTimeImmutable    $anchor   The anchor date.
	 * @param integer              $sequence 0 for the first reminder, 1 for the first repeat, and so on.
	 * @param WorkingCalendar|null $calendar The calendar business units are counted on.
	 *
	 * @return DateTimeImmutable The moment.
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerValidationException When a business unit has no calendar.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function moment(DateTimeImmutable $anchor, int $sequence, ?WorkingCalendar $calendar): DateTimeImmutable {
		$moment = $this->add(start: $anchor, amount: (array)($this->reminder['offset'] ?? []), calendar: $calendar);
		for ($repeat = 0; $repeat < $sequence; $repeat++) {
			$moment = $this->add(start: $moment, amount: (array)($this->reminder['repeat']['interval'] ?? []), calendar: $calendar);
		}

		return $moment;
	}//end moment()

	/**
	 * What the reminder does for one object now.
	 *
	 * @param mixed                 $anchorValue The object's anchor property value.
	 * @param ReminderProgress|null $stored      The progress on the rule's dedupe row, if any.
	 * @param DateTimeImmutable     $now         The sweep's moment.
	 * @param WorkingCalendar|null  $calendar    The calendar business units are counted on.
	 *
	 * @return ReminderDecision The decision.
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerValidationException When a business unit has no calendar.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function decide(mixed $anchorValue, ?ReminderProgress $stored, DateTimeImmutable $now, ?WorkingCalendar $calendar): ReminderDecision {
		$anchor = self::date(value: $anchorValue, calendar: $calendar);
		if ($anchor === null) {
			return new ReminderDecision(kind: ReminderDecision::NO_ANCHOR, progress: $stored);
		}

		$fingerprint = sha1(trim((string)$anchorValue));
		$progress    = $stored;
		$rearmed     = false;
		if ($stored === null || ($stored->fingerprint !== $fingerprint && $stored->stopped === null)) {
			$progress = new ReminderProgress(fingerprint: $fingerprint);
			$rearmed  = ($stored !== null);
		}

		if ($progress->stopped !== null) {
			return new ReminderDecision(kind: ReminderDecision::STOPPED, progress: $progress);
		}

		if ($progress->sent >= $this->total()) {
			return new ReminderDecision(kind: ReminderDecision::DONE, progress: $progress, rearmed: $rearmed);
		}

		$dueAt = $this->moment(anchor: $anchor, sequence: $progress->sent, calendar: $calendar);
		$kind  = ReminderDecision::WAIT;
		if ($now >= $dueAt) {
			$kind = ReminderDecision::DUE;
		}

		return new ReminderDecision(kind: $kind, progress: $progress, rearmed: $rearmed, dueAt: $dueAt);
	}//end decide()

	/**
	 * Add one `{value, unit}` amount through the timers' calculator.
	 *
	 * @param DateTimeImmutable    $start    The start.
	 * @param array<string, mixed> $amount   The amount.
	 * @param WorkingCalendar|null $calendar The calendar.
	 *
	 * @return DateTimeImmutable The result.
	 */
	private function add(DateTimeImmutable $start, array $amount, ?WorkingCalendar $calendar): DateTimeImmutable {
		$value = (float)($amount['value'] ?? 0);
		if ($value === 0.0) {
			return $start;
		}

		$unit = (string)($amount['unit'] ?? SlaCalculatorInterface::UNIT_CALENDAR_DAYS);

		return $this->calculator->add(addition: new SlaAddition(start: $start, value: $value, unit: $unit, calendar: $calendar));
	}//end add()

	/**
	 * The date an anchor value holds, or null.
	 *
	 * A date without a time is midnight in the calendar's zone, or in UTC
	 * without a calendar.
	 *
	 * @param mixed                $value    The value.
	 * @param WorkingCalendar|null $calendar The calendar.
	 *
	 * @return DateTimeImmutable|null The date.
	 */
	private static function date(mixed $value, ?WorkingCalendar $calendar): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$zone = new DateTimeZone('UTC');
		if ($calendar !== null) {
			$zone = new DateTimeZone($calendar->getTimezone());
		}

		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
			if ($date === false || $date->format('Y-m-d') !== $value) {
				return null;
			}

			return $date;
		}

		try {
			return new DateTimeImmutable($value, $zone);
		} catch (Exception) {
			return null;
		}
	}//end date()
}//end class
