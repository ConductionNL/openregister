<?php

/**
 * A named working calendar: which weekdays work, which dates do not, and how
 * many hours a working day holds.
 *
 * Non-working dates are COMPUTED from rules, not read from a table, so the
 * calendar does not expire (design D-5). A rule is a fixed month/day, an
 * Easter offset (Goede Vrijdag −2, Tweede Paasdag +1, Hemelvaart +39, Tweede
 * Pinksterdag +50), or a fixed date with an observed shift (Koningsdag is
 * 27 April, observed on the 26th when the 27th is a Sunday). Enumerated
 * exception dates are allowed ALONGSIDE rules for a genuine one-off closure;
 * a calendar that is ONLY enumerated dates is refused, because that calendar
 * has an expiry date and degrades silently after it — the failure mode live
 * in the fleet today.
 *
 * `hoursPerWorkingDay` is required: without it `hours` and `businessDays`
 * are not commensurable.
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
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * A validated, memoising working calendar.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The sum of the definition
 * validators' refusal branches: every malformed rule shape is named in its own
 * message rather than absorbed, which is the point of refusing rather than
 * downgrading.
 * @SuppressWarnings(PHPMD.ShortVariable) {@see easterSunday()} uses the
 * single-letter names of the published anonymous Gregorian computus so the
 * code can be checked line by line against its reference.
 */
final class WorkingCalendar {

	/**
	 * The seeded national default.
	 *
	 * @var string
	 */
	public const DEFAULT_SLUG = 'nl-national';

	/**
	 * Rule kinds.
	 *
	 * @var array<int, string>
	 */
	public const RULE_KINDS = ['fixed', 'easter', 'observedShift'];

	/**
	 * Weekday names accepted by an observed-shift rule, ISO numbered.
	 *
	 * @var array<string, int>
	 */
	private const WEEKDAYS = [
		'monday' => 1,
		'tuesday' => 2,
		'wednesday' => 3,
		'thursday' => 4,
		'friday' => 5,
		'saturday' => 6,
		'sunday' => 7,
	];

	/**
	 * Non-working dates per year, memoised for the life of this instance.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $yearCache = [];

	/**
	 * Constructor. Use {@see fromArray()}.
	 *
	 * @param string $slug The calendar's name.
	 * @param string|null $organisation The organisation it is configured for, when any.
	 * @param array<int, int> $workingWeekdays ISO weekdays (1 = Monday) that are working days.
	 * @param float $hoursPerWorkingDay Working hours in one working day.
	 * @param array<int, array<string, mixed>> $rules The computed non-working-date rules.
	 * @param array<string, string> $exceptions Enumerated one-off closures, `Y-m-d` => name.
	 */
	private function __construct(
		private readonly string $slug,
		private readonly ?string $organisation,
		private readonly array $workingWeekdays,
		private readonly float $hoursPerWorkingDay,
		private readonly array $rules,
		private readonly array $exceptions,
	) {

	}//end __construct()

	/**
	 * Build and VALIDATE a calendar from its stored definition.
	 *
	 * @param array<string, mixed> $definition The `working-calendar` object data.
	 *
	 * @return self The calendar.
	 *
	 * @throws FlowTimerValidationException On any refused definition, naming the fault.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public static function fromArray(array $definition): self {
		$slug = trim((string)($definition['slug'] ?? ''));
		if ($slug === '') {
			throw new FlowTimerValidationException(message: 'A working calendar requires a non-empty slug.');
		}

		$hours = ($definition['hoursPerWorkingDay'] ?? null);
		if (is_numeric($hours) === false || (float)$hours <= 0 || (float)$hours > 24) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"Working calendar '%s' requires hoursPerWorkingDay between 0 and 24; without it hours and businessDays are not commensurable.",
					$slug
				)
			);
		}

		$weekdays = self::validWeekdays(slug: $slug, value: ($definition['workingWeekdays'] ?? null));
		$rules = self::validRules(slug: $slug, value: ($definition['rules'] ?? []));
		$exceptions = self::validExceptions(slug: $slug, value: ($definition['exceptions'] ?? []));

		if ($rules === [] && $exceptions !== []) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"Working calendar '%s' consists only of enumerated dates and would expire after '%s'; declare computed rules.",
					$slug,
					(string)array_key_last($exceptions)
				)
			);
		}

		$organisation = null;
		if (trim((string)($definition['organisation'] ?? '')) !== '') {
			$organisation = trim((string)$definition['organisation']);
		}

		return new self(
			slug: $slug,
			organisation: $organisation,
			workingWeekdays: $weekdays,
			hoursPerWorkingDay: (float)$hours,
			rules: $rules,
			exceptions: $exceptions
		);
	}//end fromArray()

	/**
	 * The calendar's name.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function getSlug(): string {
		return $this->slug;
	}//end getSlug()

	/**
	 * The organisation this calendar is configured for.
	 *
	 * @return string|null The organisation, or null for a shared calendar.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function getOrganisation(): ?string {
		return $this->organisation;
	}//end getOrganisation()

	/**
	 * Working hours in one working day.
	 *
	 * @return float The hours.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function getHoursPerWorkingDay(): float {
		return $this->hoursPerWorkingDay;
	}//end getHoursPerWorkingDay()

	/**
	 * Whether the calendar day containing this instant is a working day.
	 *
	 * @param DateTimeInterface $moment Any instant on the day.
	 *
	 * @return boolean True on a working weekday that is not a non-working date.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function isWorkingDay(DateTimeInterface $moment): bool {
		if (in_array((int)$moment->format('N'), $this->workingWeekdays, true) === false) {
			return false;
		}

		$nonWorking = $this->nonWorkingDates(year: (int)$moment->format('Y'));

		return array_key_exists($moment->format('Y-m-d'), $nonWorking) === false;
	}//end isWorkingDay()

	/**
	 * The non-working dates of a year, computed from the rules and memoised.
	 *
	 * @param int $year The year.
	 *
	 * @return array<string, string> `Y-m-d` => name.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function nonWorkingDates(int $year): array {
		if (array_key_exists($year, $this->yearCache) === true) {
			return $this->yearCache[$year];
		}

		$dates = [];
		$easter = self::easterSunday(year: $year);
		foreach ($this->rules as $rule) {
			$date = $this->ruleDate(rule: $rule, year: $year, easter: $easter);
			$dates[$date->format('Y-m-d')] = (string)($rule['name'] ?? $rule['kind']);
		}

		foreach ($this->exceptions as $date => $name) {
			if (str_starts_with($date, (string)$year . '-') === true) {
				$dates[$date] = $name;
			}
		}

		ksort($dates);
		$this->yearCache[$year] = $dates;

		return $dates;
	}//end nonWorkingDates()

	/**
	 * Easter Sunday (Gregorian), by the anonymous algorithm — computed, so no year runs out.
	 *
	 * @param int $year The year.
	 *
	 * @return DateTimeImmutable Easter Sunday at midnight UTC.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public static function easterSunday(int $year): DateTimeImmutable {
		$a = ($year % 19);
		$b = intdiv($year, 100);
		$c = ($year % 100);
		$d = intdiv($b, 4);
		$e = ($b % 4);
		$f = intdiv(($b + 8), 25);
		$g = intdiv(($b - $f + 1), 3);
		$h = ((19 * $a + $b - $d - $g + 15) % 30);
		$i = intdiv($c, 4);
		$k = ($c % 4);
		$l = ((32 + 2 * $e + 2 * $i - $h - $k) % 7);
		$m = intdiv(($a + 11 * $h + 22 * $l), 451);
		$month = intdiv(($h + $l - 7 * $m + 114), 31);
		$day = ((($h + $l - 7 * $m + 114) % 31) + 1);

		return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), new DateTimeZone('UTC'));
	}//end easterSunday()

	/**
	 * Resolve one rule to its date in a year.
	 *
	 * @param array<string, mixed> $rule The validated rule.
	 * @param int $year The year.
	 * @param DateTimeImmutable $easter Easter Sunday of that year.
	 *
	 * @return DateTimeImmutable The date.
	 */
	private function ruleDate(array $rule, int $year, DateTimeImmutable $easter): DateTimeImmutable {
		if ($rule['kind'] === 'easter') {
			return self::shift(moment: $easter, modifier: sprintf('%+d days', (int)$rule['offset']));
		}

		$date = new DateTimeImmutable(
			sprintf('%04d-%02d-%02d 00:00:00', $year, (int)$rule['month'], (int)$rule['day']),
			new DateTimeZone('UTC')
		);
		$shift = ($rule['observedShift'] ?? null);
		if (is_array($shift) === true && (int)$date->format('N') === (int)$shift['whenWeekday']) {
			return self::shift(moment: $date, modifier: sprintf('%+d days', (int)$shift['days']));
		}

		return $date;
	}//end ruleDate()

	/**
	 * Validate the working weekdays.
	 *
	 * @param string $slug The calendar, for the message.
	 * @param mixed $value The declared weekdays.
	 *
	 * @return array<int, int> ISO weekdays.
	 *
	 * @throws FlowTimerValidationException When absent, empty or out of range.
	 */
	private static function validWeekdays(string $slug, mixed $value): array {
		if (is_array($value) === false || $value === []) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s' must declare its workingWeekdays (ISO 1..7).", $slug)
			);
		}

		$weekdays = [];
		foreach ($value as $weekday) {
			if (is_int($weekday) === false || $weekday < 1 || $weekday > 7) {
				throw new FlowTimerValidationException(
					message: sprintf("Working calendar '%s' has an invalid weekday '%s'; use ISO 1..7.", $slug, var_export($weekday, true))
				);
			}

			$weekdays[] = $weekday;
		}

		return array_values(array_unique($weekdays));
	}//end validWeekdays()

	/**
	 * Validate the rules.
	 *
	 * @param string $slug The calendar, for the message.
	 * @param mixed $value The declared rules.
	 *
	 * @return array<int, array<string, mixed>> The normalised rules.
	 *
	 * @throws FlowTimerValidationException On an unknown kind or a malformed rule.
	 */
	private static function validRules(string $slug, mixed $value): array {
		if (is_array($value) === false) {
			throw new FlowTimerValidationException(message: sprintf("Working calendar '%s' rules must be an array.", $slug));
		}

		$rules = [];
		foreach ($value as $rule) {
			$kind = (string)($rule['kind'] ?? '');
			if (is_array($rule) === false || in_array($kind, self::RULE_KINDS, true) === false) {
				throw new FlowTimerValidationException(
					message: sprintf("Working calendar '%s' has a rule of unknown kind '%s'; known kinds: %s.", $slug, $kind, implode(', ', self::RULE_KINDS))
				);
			}

			if ($kind === 'easter') {
				if (is_int($rule['offset'] ?? null) === false) {
					throw new FlowTimerValidationException(
						message: sprintf("Working calendar '%s': an easter rule requires an integer offset.", $slug)
					);
				}

				$rules[] = ['kind' => 'easter', 'offset' => (int)$rule['offset'], 'name' => (string)($rule['name'] ?? 'easter')];
				continue;
			}

			$rules[] = self::validFixedRule(slug: $slug, rule: $rule, kind: $kind);
		}

		return $rules;
	}//end validRules()

	/**
	 * Validate a fixed or observed-shift rule.
	 *
	 * @param string $slug The calendar, for the message.
	 * @param array<string, mixed> $rule The rule.
	 * @param string $kind `fixed` or `observedShift`.
	 *
	 * @return array<string, mixed> The normalised rule.
	 *
	 * @throws FlowTimerValidationException On a malformed month, day or shift.
	 */
	private static function validFixedRule(string $slug, array $rule, string $kind): array {
		$month = ($rule['month'] ?? null);
		$day = ($rule['day'] ?? null);
		if (is_int($month) === false || $month < 1 || $month > 12 || is_int($day) === false || $day < 1 || $day > 31) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s': a %s rule requires an integer month 1..12 and day 1..31.", $slug, $kind)
			);
		}

		$normalised = ['kind' => $kind, 'month' => $month, 'day' => $day, 'name' => (string)($rule['name'] ?? $kind)];
		$shift = self::declaredShift(rule: $rule, kind: $kind);
		if ($shift === null) {
			return $normalised;
		}

		$normalised['observedShift'] = self::validShift(slug: $slug, shift: $shift);

		return $normalised;
	}//end validFixedRule()

	/**
	 * The shift a rule declares: `observedShift` on a fixed rule, or the flat
	 * `whenWeekday`/`days` shorthand of an `observedShift` rule.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param string $kind `fixed` or `observedShift`.
	 *
	 * @return mixed The declared shift, or null when the rule has none.
	 */
	private static function declaredShift(array $rule, string $kind): mixed {
		$shift = ($rule['observedShift'] ?? null);
		if ($kind === 'observedShift' && is_array($shift) === false) {
			return ['whenWeekday' => ($rule['whenWeekday'] ?? null), 'days' => ($rule['days'] ?? null)];
		}

		return $shift;
	}//end declaredShift()

	/**
	 * Validate an observed shift: a weekday (name or ISO number) and a day delta.
	 *
	 * @param string $slug The calendar, for the message.
	 * @param mixed $shift The declared shift.
	 *
	 * @return array{whenWeekday: int, days: int} The normalised shift.
	 *
	 * @throws FlowTimerValidationException On a malformed shift.
	 */
	private static function validShift(string $slug, mixed $shift): array {
		if (is_array($shift) === false || is_int($shift['days'] ?? null) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s': an observed shift requires whenWeekday and an integer days.", $slug)
			);
		}

		$weekday = ($shift['whenWeekday'] ?? null);
		if (is_string($weekday) === true) {
			$weekday = (self::WEEKDAYS[strtolower($weekday)] ?? null);
		}

		if (is_int($weekday) === false || $weekday < 1 || $weekday > 7) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s': observed shift whenWeekday must be a weekday name or ISO 1..7.", $slug)
			);
		}

		return ['whenWeekday' => $weekday, 'days' => (int)$shift['days']];
	}//end validShift()

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

	/**
	 * Validate the enumerated exceptions.
	 *
	 * @param string $slug The calendar, for the message.
	 * @param mixed $value The declared exceptions: `Y-m-d` strings or `{date, name}` objects.
	 *
	 * @return array<string, string> `Y-m-d` => name, sorted.
	 *
	 * @throws FlowTimerValidationException On a malformed date.
	 */
	private static function validExceptions(string $slug, mixed $value): array {
		if (is_array($value) === false) {
			throw new FlowTimerValidationException(message: sprintf("Working calendar '%s' exceptions must be an array.", $slug));
		}

		$exceptions = [];
		foreach ($value as $entry) {
			$date = $entry;
			$name = 'exception';
			if (is_array($entry) === true) {
				$date = ($entry['date'] ?? null);
				$name = (string)($entry['name'] ?? 'exception');
			}

			if (is_string($date) === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
				throw new FlowTimerValidationException(
					message: sprintf("Working calendar '%s' has an exception that is not a Y-m-d date: '%s'.", $slug, var_export($date, true))
				);
			}

			$exceptions[$date] = $name;
		}

		ksort($exceptions);

		return $exceptions;
	}//end validExceptions()
}//end class
