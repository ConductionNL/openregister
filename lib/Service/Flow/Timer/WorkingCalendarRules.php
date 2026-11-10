<?php

/**
 * The non-working-date part of a working calendar's definition, read and
 * VALIDATED: the computed rules and the enumerated one-off exceptions.
 *
 * A rule is a fixed month/day, an Easter offset, or a fixed date with an
 * observed shift. Every malformed shape is refused with its own message.
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

use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Validates and normalises a calendar's rules and exceptions.
 */
final class WorkingCalendarRules {

	/**
	 * Constructor.
	 *
	 * @param string $slug The calendar, named in every refusal.
	 */
	public function __construct(
		private readonly string $slug,
	) {

	}//end __construct()

	/**
	 * Validate the rules.
	 *
	 * @param mixed $value The declared rules.
	 *
	 * @return array<int, array<string, mixed>> The normalised rules.
	 *
	 * @throws FlowTimerValidationException On an unknown kind or a malformed rule.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function validRules(mixed $value): array {
		if (is_array($value) === false) {
			throw new FlowTimerValidationException(message: sprintf("Working calendar '%s' rules must be an array.", $this->slug));
		}

		return array_values(array_map(fn (mixed $rule): array => $this->validRule(rule: $rule), $value));
	}//end validRules()

	/**
	 * Validate one rule of any kind.
	 *
	 * @param mixed $rule The declared rule.
	 *
	 * @return array<string, mixed> The normalised rule.
	 *
	 * @throws FlowTimerValidationException On an unknown kind or a malformed rule.
	 */
	private function validRule(mixed $rule): array {
		$kind = (string)($rule['kind'] ?? '');
		if (is_array($rule) === false || in_array($kind, WorkingCalendar::RULE_KINDS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"Working calendar '%s' has a rule of unknown kind '%s'; known kinds: %s.",
					$this->slug,
					$kind,
					implode(', ', WorkingCalendar::RULE_KINDS)
				)
			);
		}

		if ($kind === 'easter') {
			return $this->validEasterRule(rule: $rule);
		}

		return $this->validFixedRule(rule: $rule, kind: $kind);
	}//end validRule()

	/**
	 * Validate an Easter-offset rule.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return array<string, mixed> The normalised rule.
	 *
	 * @throws FlowTimerValidationException When the offset is not an integer.
	 */
	private function validEasterRule(array $rule): array {
		if (is_int($rule['offset'] ?? null) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s': an easter rule requires an integer offset.", $this->slug)
			);
		}

		return ['kind' => 'easter', 'offset' => (int)$rule['offset'], 'name' => (string)($rule['name'] ?? 'easter')];
	}//end validEasterRule()

	/**
	 * Validate a fixed or observed-shift rule.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param string               $kind `fixed` or `observedShift`.
	 *
	 * @return array<string, mixed> The normalised rule.
	 *
	 * @throws FlowTimerValidationException On a malformed month, day or shift.
	 */
	private function validFixedRule(array $rule, string $kind): array {
		$month = ($rule['month'] ?? null);
		$day = ($rule['day'] ?? null);
		if (self::inRange(value: $month, max: 12) === false || self::inRange(value: $day, max: 31) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s': a %s rule requires an integer month 1..12 and day 1..31.", $this->slug, $kind)
			);
		}

		$normalised = ['kind' => $kind, 'month' => $month, 'day' => $day, 'name' => (string)($rule['name'] ?? $kind)];
		$shift = self::declaredShift(rule: $rule, kind: $kind);
		if ($shift !== null) {
			$normalised['observedShift'] = $this->validShift(shift: $shift);
		}

		return $normalised;
	}//end validFixedRule()

	/**
	 * The shift a rule declares: `observedShift` on a fixed rule, or the flat
	 * `whenWeekday`/`days` shorthand of an `observedShift` rule.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param string               $kind `fixed` or `observedShift`.
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
	 * @param mixed $shift The declared shift.
	 *
	 * @return array{whenWeekday: int, days: int} The normalised shift.
	 *
	 * @throws FlowTimerValidationException On a malformed shift.
	 */
	private function validShift(mixed $shift): array {
		if (is_array($shift) === false || is_int($shift['days'] ?? null) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s': an observed shift requires whenWeekday and an integer days.", $this->slug)
			);
		}

		$weekday = self::shiftWeekday(weekday: ($shift['whenWeekday'] ?? null));
		if (self::inRange(value: $weekday, max: 7) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s': observed shift whenWeekday must be a weekday name or ISO 1..7.", $this->slug)
			);
		}

		return ['whenWeekday' => $weekday, 'days' => (int)$shift['days']];
	}//end validShift()

	/**
	 * A shift's weekday as declared: a name is looked up in {@see ServiceHours::WEEKDAYS}, anything else passes through.
	 *
	 * @param mixed $weekday The declared weekday.
	 *
	 * @return mixed The ISO number for a known name, null for an unknown one, else the value.
	 */
	private static function shiftWeekday(mixed $weekday): mixed {
		if (is_string($weekday) === true) {
			return (ServiceHours::WEEKDAYS[strtolower($weekday)] ?? null);
		}

		return $weekday;
	}//end shiftWeekday()

	/**
	 * Validate the enumerated exceptions.
	 *
	 * @param mixed $value The declared exceptions: `Y-m-d` strings or `{date, name}` objects.
	 *
	 * @return array<string, string> `Y-m-d` => name, sorted.
	 *
	 * @throws FlowTimerValidationException On a malformed date.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function validExceptions(mixed $value): array {
		if (is_array($value) === false) {
			throw new FlowTimerValidationException(message: sprintf("Working calendar '%s' exceptions must be an array.", $this->slug));
		}

		$exceptions = [];
		foreach ($value as $entry) {
			[$date, $name] = $this->validException(entry: $entry);
			$exceptions[$date] = $name;
		}

		ksort($exceptions);

		return $exceptions;
	}//end validExceptions()

	/**
	 * One enumerated exception: a `Y-m-d` string or a `{date, name}` object.
	 *
	 * @param mixed $entry The declared exception.
	 *
	 * @return array{0: string, 1: string} The date and its name.
	 *
	 * @throws FlowTimerValidationException On a malformed date.
	 */
	private function validException(mixed $entry): array {
		$date = $entry;
		$name = 'exception';
		if (is_array($entry) === true) {
			$date = ($entry['date'] ?? null);
			$name = (string)($entry['name'] ?? 'exception');
		}

		if (is_string($date) === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s' has an exception that is not a Y-m-d date: '%s'.", $this->slug, var_export($date, true))
			);
		}

		return [$date, $name];
	}//end validException()

	/**
	 * Whether a value is an integer from 1 to a maximum.
	 *
	 * @param mixed $value The value.
	 * @param int   $max   The largest accepted value.
	 *
	 * @return boolean True when in range.
	 */
	private static function inRange(mixed $value, int $max): bool {
		return is_int($value) === true && $value >= 1 && $value <= $max;
	}//end inRange()
}//end class
