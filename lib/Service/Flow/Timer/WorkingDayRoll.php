<?php

/**
 * Moving a computed moment off a non-working day, and saying what moved it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Rolls a moment to the nearest working day, and names the day it left.
 *
 * 🔴 IT ANSWERS WHAT IT DID, not just where it landed. A handler looking at a
 * term that ends on Tuesday has to be able to read that Monday was Tweede
 * Paasdag; a rolled date with no explanation is a date somebody will
 * challenge and nobody can defend.
 *
 * 🔑 THE NAME COMES FROM THE CALENDAR'S OWN RULE, never from a list in this
 * class. `weekend` is the only name this code knows, because it is the only
 * one it decides; every other name is whatever the administrator called the
 * day they declared.
 *
 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
 */
class WorkingDayRoll {

	/**
	 * Days a roll may walk before it gives up.
	 *
	 * A roll crosses a holiday cluster, not a season: the longest in any real
	 * calendar is a handful of days. A calendar that declares every day
	 * non-working would otherwise walk until the clock ran out, and the
	 * deadline would look like a hang.
	 *
	 * @var int
	 */
	private const MAX_ROLL_DAYS = 400;

	/**
	 * Move a moment off a non-working day, and say what moved it.
	 *
	 * 🔴 IT ANSWERS WHAT IT DID, not just where it landed. A handler looking at
	 * a term that ends on Tuesday has to be able to read that Monday was Tweede
	 * Paasdag; a rolled date with no explanation is a date somebody will
	 * challenge and nobody can defend.
	 *
	 * 🔑 THE NAME COMES FROM THE CALENDAR'S OWN RULE, never from a list in this
	 * class. `weekend` is the only name this code knows, because it is the only
	 * one it decides; every other name is whatever the administrator called the
	 * day they declared.
	 *
	 * @param DateTimeInterface    $moment   The computed moment.
	 * @param string               $roll     One of ROLLS.
	 * @param WorkingCalendar|null $calendar The resolved calendar.
	 *
	 * @return array{at: DateTimeImmutable, unrolledAt: ?DateTimeImmutable, rolledBy: ?string} Where it ended up.
	 *
	 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
	 */
	public function apply(DateTimeInterface $moment, string $roll, ?WorkingCalendar $calendar): array {
		$instant = DateTimeImmutable::createFromInterface($moment);
		$unrolled = ['at' => $instant, 'unrolledAt' => null, 'rolledBy' => null];

		if ($roll === SlaCalculator::ROLL_NONE || $calendar === null || $calendar->isWorkingDay(moment: $instant) === true) {
			return $unrolled;
		}

		// The rule that stopped the FIRST day is the one that moved the term.
		// Reporting the last day walked past would name Easter Monday for a
		// term that was really stopped by the Saturday before it.
		$rolledBy = $this->nonWorkingReason(moment: $instant, calendar: $calendar);

		$modifier = '+1 day';
		if ($roll === SlaCalculator::ROLL_PREVIOUS) {
			$modifier = '-1 day';
		}

		$walked = $instant;
		for ($step = 0; $step < self::MAX_ROLL_DAYS; $step++) {
			$walked = $this->shift(moment: $walked, modifier: $modifier);
			if ($calendar->isWorkingDay(moment: $walked) === true) {
				return ['at' => $walked, 'unrolledAt' => $instant, 'rolledBy' => $rolledBy];
			}
		}

		throw new FlowTimerValidationException(
			message: sprintf(
				'No working day within %d days of %s on calendar %s: the calendar declares no working days to roll to.',
				self::MAX_ROLL_DAYS,
				$instant->format('Y-m-d'),
				$calendar->getSlug()
			)
		);
	}//end apply()

	/**
	 * Why a day is not a working day, in the calendar's own words.
	 *
	 * @param DateTimeImmutable $moment   The day.
	 * @param WorkingCalendar   $calendar The calendar.
	 *
	 * @return string The declared name, or `weekend`.
	 */
	private function nonWorkingReason(DateTimeImmutable $moment, WorkingCalendar $calendar): string {
		$named = ($calendar->nonWorkingDates(year: (int)$moment->format('Y'))[$moment->format('Y-m-d')] ?? null);
		if (is_string($named) === true && $named !== '') {
			return $named;
		}

		// Not a declared date, so it is a day the working WEEK excludes. This
		// is the one name this class decides, because it is the one rule it
		// knows without being told.
		return 'weekend';
	}//end nonWorkingReason()

	/**
	 * Apply a relative modifier, refusing PHP's silent `false`.
	 *
	 * @param DateTimeImmutable $moment The instant.
	 * @param string $modifier A relative modifier such as `+1 day`.
	 *
	 * @return DateTimeImmutable The shifted instant.
	 *
	 * @throws FlowTimerValidationException When the modifier is unparseable.
	 */
	private function shift(DateTimeImmutable $moment, string $modifier): DateTimeImmutable {
		$shifted = $moment->modify($modifier);
		if ($shifted === false) {
			throw new FlowTimerValidationException(message: sprintf("Date modifier '%s' is not parseable.", $modifier));
		}

		return $shifted;
	}//end shift()

}//end class
