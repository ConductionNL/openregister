<?php

/**
 * CalendarWeekNumbering: week numbers counted from a calendar's declared first week of the year.
 *
 * Three declarations, all weeks starting on Monday:
 *  - `iso` (the default): week 1 holds the year's first Thursday (ISO 8601);
 *  - `containsJanuary1`: week 1 is the week holding 1 January;
 *  - `firstFullWeek`: week 1 is the first week lying wholly in the year.
 * A day before its year's week 1 belongs to the last week of the year before,
 * so a week number is only meaningful together with its week-year.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Numbers weeks from one declared first week of the year.
 */
final class CalendarWeekNumbering {

	public const ISO = 'iso';

	public const CONTAINS_JANUARY_1 = 'containsJanuary1';

	public const FIRST_FULL_WEEK = 'firstFullWeek';

	public const DECLARATIONS = [self::ISO, self::CONTAINS_JANUARY_1, self::FIRST_FULL_WEEK];

	/**
	 * The declaration in force.
	 *
	 * @var string
	 */
	public readonly string $firstWeek;

	/**
	 * Validate the declaration.
	 *
	 * @param string $slug  The calendar slug, for messages.
	 * @param mixed  $value The declared `firstWeekOfYear`, or null for ISO.
	 *
	 * @throws FlowTimerValidationException When it names no known declaration.
	 */
	public function __construct(string $slug, mixed $value) {
		if ($value === null || (is_string($value) === true && trim($value) === '')) {
			$this->firstWeek = self::ISO;
			return;
		}

		if (is_string($value) === false || in_array(trim($value), self::DECLARATIONS, true) === false) {
			$shown = gettype($value);
			if (is_scalar($value) === true) {
				$shown = (string) $value;
			}

			throw new FlowTimerValidationException(
				message: sprintf(
					"Working calendar '%s' declares firstWeekOfYear '%s'; it must be one of %s.",
					$slug,
					$shown,
					implode(', ', self::DECLARATIONS)
				)
			);
		}

		$this->firstWeek = trim($value);
	}//end __construct()

	/**
	 * The week-year and week number of a day.
	 *
	 * @param DateTimeInterface $moment The day, read in its own zone.
	 *
	 * @return array{year: int, week: int}
	 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function weekOf(DateTimeInterface $moment): array {
		$day  = new DateTimeImmutable($moment->format('Y-m-d'), new DateTimeZone('UTC'));
		$year = (int) $day->format('Y');

		$start = $this->weekOneMonday(year: $year);
		if ($day < $start) {
			$year--;
			$start = $this->weekOneMonday(year: $year);
		} elseif ($day >= $this->weekOneMonday(year: ($year + 1))) {
			$year++;
			$start = $this->weekOneMonday(year: $year);
		}

		$days = (int) $start->diff($day)->days;

		return ['year' => $year, 'week' => (intdiv($days, 7) + 1)];
	}//end weekOf()

	/**
	 * The Monday that starts week 1 of a year.
	 *
	 * @param int $year The year.
	 *
	 * @return DateTimeImmutable
	 */
	private function weekOneMonday(int $year): DateTimeImmutable {
		$january1 = new DateTimeImmutable(sprintf('%04d-01-01', $year), new DateTimeZone('UTC'));
		$weekday  = (int) $january1->format('N');

		return match ($this->firstWeek) {
			self::CONTAINS_JANUARY_1 => self::mondayOf(day: $january1),
			self::FIRST_FULL_WEEK => $january1->modify(sprintf('+%d days', ((8 - $weekday) % 7))),
			default => self::mondayOf(day: new DateTimeImmutable(sprintf('%04d-01-04', $year), new DateTimeZone('UTC'))),
		};
	}//end weekOneMonday()

	/**
	 * The Monday of the week holding a day.
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return DateTimeImmutable
	 */
	private static function mondayOf(DateTimeImmutable $day): DateTimeImmutable {
		return $day->modify(sprintf('-%d days', ((int) $day->format('N') - 1)));
	}//end mondayOf()
}//end class
