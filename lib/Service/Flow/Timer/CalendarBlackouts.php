<?php

/**
 * CalendarBlackouts: the periods in which a working calendar refuses a kind of booking.
 *
 * A blackout is NOT a closure day (design D-C67-3). A closure day does not
 * count towards a term; a blackout refuses a booking in its period while every
 * term keeps running through it. So nothing here is consulted by
 * {@see WorkingCalendar::isWorkingDay()}: conflating the two would either stop
 * the clock during the zomerstop or schedule a hoorzitting in it.
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
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * The validated blackout periods of one calendar.
 */
final class CalendarBlackouts {

	/**
	 * The periods, each `{name, from, to, refuses}` with inclusive Y-m-d bounds.
	 *
	 * @var array<int, array{name: string, from: string, to: string, refuses: array<int, string>}>
	 */
	private array $periods = [];

	/**
	 * Validate the declared periods.
	 *
	 * @param string $slug  The calendar slug, for messages.
	 * @param mixed  $value The declared `blackouts`, or null.
	 *
	 * @throws FlowTimerValidationException When a period is malformed.
	 */
	public function __construct(
		private readonly string $slug,
		mixed $value,
	) {
		if ($value === null) {
			return;
		}

		if (is_array($value) === false || array_is_list($value) === false) {
			throw new FlowTimerValidationException(message: sprintf("Working calendar '%s' declares blackouts that are not a list.", $this->slug));
		}

		foreach ($value as $index => $period) {
			$this->periods[] = $this->validPeriod(index: $index, period: $period);
		}
	}//end __construct()

	/**
	 * The periods as declared and validated.
	 *
	 * @return array<int, array{name: string, from: string, to: string, refuses: array<int, string>}>
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function all(): array {
		return $this->periods;
	}//end all()

	/**
	 * The first period that refuses this kind of booking on this day, or null.
	 *
	 * The day is read in the moment's own zone; pass a moment already in the
	 * calendar's zone.
	 *
	 * @param DateTimeInterface $moment The booking's moment.
	 * @param string            $kind   The kind of booking, e.g. `hearing`.
	 *
	 * @return array{name: string, from: string, to: string, refuses: array<int, string>}|null
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function refusing(DateTimeInterface $moment, string $kind): ?array {
		$day  = $moment->format('Y-m-d');
		$kind = trim($kind);
		foreach ($this->periods as $period) {
			if ($day >= $period['from'] && $day <= $period['to'] && in_array($kind, $period['refuses'], true) === true) {
				return $period;
			}
		}

		return null;
	}//end refusing()

	/**
	 * One validated period.
	 *
	 * @param int   $index  Its position, for messages.
	 * @param mixed $period The declared period.
	 *
	 * @return array{name: string, from: string, to: string, refuses: array<int, string>}
	 *
	 * @throws FlowTimerValidationException When it is malformed.
	 */
	private function validPeriod(int $index, mixed $period): array {
		if (is_array($period) === false) {
			throw $this->invalid(index: $index, why: 'is not an object');
		}

		$name = trim((string) ($period['name'] ?? ''));
		if ($name === '') {
			throw $this->invalid(index: $index, why: 'has no name');
		}

		$from = $this->validDate(index: $index, value: ($period['from'] ?? null), field: 'from');
		$to   = $this->validDate(index: $index, value: ($period['to'] ?? null), field: 'to');
		if ($to < $from) {
			throw $this->invalid(index: $index, why: sprintf("ends (%s) before it starts (%s)", $to, $from));
		}

		$refuses = ($period['refuses'] ?? null);
		if (is_array($refuses) === false || $refuses === []) {
			throw $this->invalid(index: $index, why: 'names no kind of booking it refuses; a blackout that refuses nothing is not a blackout');
		}

		$kinds = [];
		foreach ($refuses as $kind) {
			if (is_string($kind) === false || trim($kind) === '') {
				throw $this->invalid(index: $index, why: 'refuses a kind that is not a non-empty string');
			}

			$kinds[] = trim($kind);
		}

		return ['name' => $name, 'from' => $from, 'to' => $to, 'refuses' => array_values(array_unique($kinds))];
	}//end validPeriod()

	/**
	 * A Y-m-d date that exists.
	 *
	 * @param int    $index Its period's position, for messages.
	 * @param mixed  $value The declared value.
	 * @param string $field Which bound.
	 *
	 * @return string The date.
	 *
	 * @throws FlowTimerValidationException When it is not a real Y-m-d date.
	 */
	private function validDate(int $index, mixed $value, string $field): string {
		if (is_string($value) === true) {
			$date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
			if ($date !== false && $date->format('Y-m-d') === trim($value)) {
				return trim($value);
			}
		}

		throw $this->invalid(index: $index, why: sprintf("has a '%s' that is not a Y-m-d date", $field));
	}//end validDate()

	/**
	 * The refusal for a malformed period.
	 *
	 * @param int    $index Its position.
	 * @param string $why   What is wrong.
	 *
	 * @return FlowTimerValidationException
	 */
	private function invalid(int $index, string $why): FlowTimerValidationException {
		return new FlowTimerValidationException(message: sprintf("Working calendar '%s': blackout %d %s.", $this->slug, $index, $why));
	}//end invalid()
}//end class
