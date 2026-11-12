<?php

/**
 * WorkingCalendarHolidays: the non-working dates a working calendar's rules and exceptions name.
 *
 * Kept apart from {@see WorkingCalendar}, which many callers name, so the rule
 * resolution lives in a class only the calendar uses.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Resolves and memoises one calendar's non-working dates per year.
 */
final class WorkingCalendarHolidays {

	/**
	 * Non-working dates per year, memoised for the life of this instance.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $yearCache = [];

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendarDefinition $definition The validated definition.
	 */
	public function __construct(
		private readonly WorkingCalendarDefinition $definition,
	) {
	}//end __construct()

	/**
	 * The non-working dates of a year already computed, or null.
	 *
	 * @param int $year The year.
	 *
	 * @return array<string, string>|null `Y-m-d` => name, or null when not computed yet.
	 */
	public function cached(int $year): ?array {
		return ($this->yearCache[$year] ?? null);
	}//end cached()

	/**
	 * Compute and memoise the non-working dates of a year.
	 *
	 * @param int               $year   The year.
	 * @param DateTimeImmutable $easter Easter Sunday of that year.
	 *
	 * @return array<string, string> `Y-m-d` => name, sorted by date.
	 */
	public function remember(int $year, DateTimeImmutable $easter): array {
		$dates = array_replace($this->ruleDates(easter: $easter), $this->exceptionsIn(year: $year));
		ksort($dates);
		$this->yearCache[$year] = $dates;
		return $dates;
	}//end remember()

	/**
	 * The dates the rules name in a year, `Y-m-d` => name.
	 *
	 * @param DateTimeImmutable $easter Easter Sunday of the year.
	 *
	 * @return array<string, string> The rule dates.
	 */
	private function ruleDates(DateTimeImmutable $easter): array {
		$dates = [];
		foreach ($this->definition->getRules() as $rule) {
			$date = $this->ruleDate(rule: $rule, easter: $easter);
			$dates[$date->format('Y-m-d')] = (string)($rule['name'] ?? $rule['kind']);
		}

		return $dates;
	}//end ruleDates()

	/**
	 * The enumerated exceptions that fall in a year.
	 *
	 * @param int $year The year.
	 *
	 * @return array<string, string> `Y-m-d` => name.
	 */
	private function exceptionsIn(int $year): array {
		return array_filter(
			$this->definition->getExceptions(),
			fn (string $date): bool => str_starts_with($date, (string)$year . '-'),
			ARRAY_FILTER_USE_KEY
		);
	}//end exceptionsIn()

	/**
	 * Resolve one rule to its date in the year of the given Easter Sunday.
	 *
	 * @param array<string, mixed> $rule   The validated rule.
	 * @param DateTimeImmutable    $easter Easter Sunday of the year.
	 *
	 * @return DateTimeImmutable The date.
	 */
	private function ruleDate(array $rule, DateTimeImmutable $easter): DateTimeImmutable {
		if ($rule['kind'] === 'easter') {
			return self::shift(moment: $easter, modifier: sprintf('%+d days', (int)$rule['offset']));
		}

		$date = new DateTimeImmutable(
			sprintf('%04d-%02d-%02d 00:00:00', (int)$easter->format('Y'), (int)$rule['month'], (int)$rule['day']),
			new DateTimeZone('UTC')
		);
		$shift = ($rule['observedShift'] ?? null);
		if (is_array($shift) === true && (int)$date->format('N') === (int)$shift['whenWeekday']) {
			return self::shift(moment: $date, modifier: sprintf('%+d days', (int)$shift['days']));
		}

		return $date;
	}//end ruleDate()

	/**
	 * Apply a relative modifier, refusing PHP's silent `false`.
	 *
	 * @param DateTimeImmutable $moment The instant.
	 * @param string $modifier A relative modifier such as `+39 days`.
	 *
	 * @return DateTimeImmutable The shifted instant.
	 *
	 * @throws FlowTimerValidationException When the modifier is unparseable.
	 */
	private static function shift(DateTimeImmutable $moment, string $modifier): DateTimeImmutable {
		$shifted = $moment->modify($modifier);
		if ($shifted === false) {
			throw new FlowTimerValidationException(message: sprintf("Date modifier '%s' is not parseable.", $modifier));
		}

		return $shifted;
	}//end shift()
}//end class
