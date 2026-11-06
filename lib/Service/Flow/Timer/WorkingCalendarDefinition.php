<?php

/**
 * A working calendar's stored definition, read and VALIDATED.
 *
 * Every malformed shape is refused with a message that names the calendar
 * and the fault, rather than downgraded: a calendar that silently drops a
 * rule it could not read counts the wrong days and says nothing.
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

use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * The validated parts of one `working-calendar` definition.
 *
 * The parts are validated in a fixed order (slug, hours, weekdays, rules,
 * exceptions, service hours, opening time, zone), so a definition with
 * several faults is always refused for the same one.
 */
final class WorkingCalendarDefinition {

	/**
	 * The organisation it is configured for, when any.
	 *
	 * @var string|null
	 */
	private ?string $organisation = null;

	/**
	 * ISO weekdays (1 = Monday) that are working days.
	 *
	 * @var array<int, int>
	 */
	private array $workingWeekdays = [];

	/**
	 * Working hours in one working day.
	 *
	 * @var float
	 */
	private float $hoursPerWorkingDay = 0.0;

	/**
	 * The computed non-working-date rules.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rules = [];

	/**
	 * Enumerated one-off closures, `Y-m-d` => name.
	 *
	 * @var array<string, string>
	 */
	private array $exceptions = [];

	/**
	 * Minutes past midnight the working day opens.
	 *
	 * @var integer
	 */
	private int $dayStartsAtMinute = 0;

	/**
	 * The zone the organisation's days are counted in.
	 *
	 * @var string
	 */
	private string $timezone = 'UTC';

	/**
	 * The hours of the day the clock runs, per weekday.
	 *
	 * @var ServiceHours|null
	 */
	private ?ServiceHours $serviceHours = null;

	/**
	 * The calendar's name, trimmed and non-empty.
	 *
	 * @var string
	 */
	private readonly string $slug;

	/**
	 * Read and VALIDATE a stored definition.
	 *
	 * @param array<string, mixed> $raw The `working-calendar` object data.
	 *
	 * @throws FlowTimerValidationException On any refused definition, naming the fault.
	 */
	public function __construct(
		private readonly array $raw,
	) {
		$this->slug = trim((string)($raw['slug'] ?? ''));
		if ($this->slug === '') {
			throw new FlowTimerValidationException(message: 'A working calendar requires a non-empty slug.');
		}

		$this->validate();
	}//end __construct()

	/**
	 * The calendar's name.
	 *
	 * @return string The slug.
	 */
	public function getSlug(): string {
		return $this->slug;
	}//end getSlug()

	/**
	 * The organisation the calendar is configured for.
	 *
	 * @return string|null The organisation, or null for a shared calendar.
	 */
	public function getOrganisation(): ?string {
		return $this->organisation;
	}//end getOrganisation()

	/**
	 * The ISO weekdays that are working days.
	 *
	 * @return array<int, int> ISO weekdays, 1 = Monday.
	 */
	public function getWorkingWeekdays(): array {
		return $this->workingWeekdays;
	}//end getWorkingWeekdays()

	/**
	 * Working hours in one working day, derived from the service hours when declared.
	 *
	 * @return float The hours.
	 */
	public function getHoursPerWorkingDay(): float {
		return $this->hoursPerWorkingDay;
	}//end getHoursPerWorkingDay()

	/**
	 * The computed non-working-date rules.
	 *
	 * @return array<int, array<string, mixed>> The normalised rules.
	 */
	public function getRules(): array {
		return $this->rules;
	}//end getRules()

	/**
	 * The enumerated one-off closures.
	 *
	 * @return array<string, string> `Y-m-d` => name, sorted.
	 */
	public function getExceptions(): array {
		return $this->exceptions;
	}//end getExceptions()

	/**
	 * The minute of the day the working day opens.
	 *
	 * @return integer Minutes past midnight.
	 */
	public function getDayStartsAtMinute(): int {
		return $this->dayStartsAtMinute;
	}//end getDayStartsAtMinute()

	/**
	 * The zone the organisation counts its days in.
	 *
	 * @return string An IANA zone name.
	 */
	public function getTimezone(): string {
		return $this->timezone;
	}//end getTimezone()

	/**
	 * The hours of the day the clock runs, per weekday.
	 *
	 * @return ServiceHours The declared windows, empty when none are declared.
	 */
	public function getServiceHours(): ServiceHours {
		return ($this->serviceHours ?? ServiceHours::none());
	}//end getServiceHours()

	/**
	 * Validate every part, in the fixed order.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException On the first refused part.
	 */
	private function validate(): void {
		$hours = $this->validHours();
		$this->workingWeekdays = $this->validWeekdays();
		$dates = new WorkingCalendarRules(slug: $this->slug);
		$this->rules = $dates->validRules(value: ($this->raw['rules'] ?? []));
		$this->exceptions = $dates->validExceptions(value: ($this->raw['exceptions'] ?? []));
		$this->refuseEnumeratedOnly();
		$this->organisation = $this->validOrganisation();
		$this->serviceHours = ServiceHours::fromArray(
			value: ($this->raw['serviceHours'] ?? null),
			workingWeekdays: $this->workingWeekdays,
			slug: $this->slug
		);
		$this->hoursPerWorkingDay = $this->effectiveHours(hours: $hours);
		$this->dayStartsAtMinute = $this->validDayStart();
		$this->timezone = $this->validTimezone();
	}//end validate()

	/**
	 * Validate the declared hours of a working day.
	 *
	 * @return float The hours.
	 *
	 * @throws FlowTimerValidationException When absent or outside (0, 24].
	 */
	private function validHours(): float {
		$hours = ($this->raw['hoursPerWorkingDay'] ?? null);
		if (is_numeric($hours) === false || (float)$hours <= 0 || (float)$hours > 24) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"Working calendar '%s' requires hoursPerWorkingDay between 0 and 24; without it hours and businessDays are not commensurable.",
					$this->slug
				)
			);
		}

		return (float)$hours;
	}//end validHours()

	/**
	 * The hours a working day holds once service hours are taken into account.
	 *
	 * 🔴 THE WINDOWS WIN OVER THE SCALAR, THEY DO NOT SIT BESIDE IT. A
	 * calendar declaring both has two answers to "how long is a working
	 * day", and a term computed from one while a report reads the other
	 * is the disagreement nobody can see on screen. Deriving the scalar
	 * from the windows leaves one answer. A calendar declaring no windows
	 * keeps the scalar it always had.
	 *
	 * @param float $hours The declared scalar.
	 *
	 * @return float The effective hours.
	 */
	private function effectiveHours(float $hours): float {
		if ($this->getServiceHours()->areDeclared() === true) {
			return $this->getServiceHours()->derivedHoursPerWorkingDay();
		}

		return $hours;
	}//end effectiveHours()

	/**
	 * The organisation, trimmed, or null when blank.
	 *
	 * @return string|null The organisation.
	 */
	private function validOrganisation(): ?string {
		$organisation = trim((string)($this->raw['organisation'] ?? ''));
		if ($organisation === '') {
			return null;
		}

		return $organisation;
	}//end validOrganisation()

	/**
	 * Refuse a calendar that is only enumerated dates: it would expire.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException When there are exceptions but no rules.
	 */
	private function refuseEnumeratedOnly(): void {
		if ($this->rules !== [] || $this->exceptions === []) {
			return;
		}

		throw new FlowTimerValidationException(
			message: sprintf(
				"Working calendar '%s' consists only of enumerated dates and would expire after '%s'; declare computed rules.",
				$this->slug,
				(string)array_key_last($this->exceptions)
			)
		);
	}//end refuseEnumeratedOnly()

	/**
	 * Validate the opening time, defaulting to 09:00.
	 *
	 * `HH:MM`, refused rather than coerced: a calendar that says `9` or
	 * `9am` and is silently read as midnight would move every elapsed
	 * business hour on the instance by nine hours, and nothing on screen
	 * would say why.
	 *
	 * @return integer Minutes past midnight.
	 *
	 * @throws FlowTimerValidationException On a malformed time.
	 */
	private function validDayStart(): int {
		$value = ($this->raw['dayStartsAt'] ?? null);
		if (self::isBlank(value: $value) === true) {
			// 09:00. The default is stated rather than derived, because every
			// derivation of it (midnight, noon minus half the day) is a
			// different number and none of them is what an office does.
			return (9 * 60);
		}

		$parts = self::clockParts(value: $value);
		if ($parts === null) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s' declares dayStartsAt '%s'; it must be HH:MM in 24-hour form.", $this->slug, self::shown(value: $value))
			);
		}

		return (((int)$parts[1] * 60) + (int)$parts[2]);
	}//end validDayStart()

	/**
	 * Validate the zone, defaulting to UTC.
	 *
	 * REFUSED RATHER THAN COERCED, and UTC rather than the server's. A zone
	 * PHP cannot resolve would otherwise fall back to `date_default_timezone`,
	 * which is whatever the instance happens to be set to, so the same
	 * calendar would count different days on two servers and neither would
	 * report anything. UTC as the default is the one answer that is the same
	 * everywhere, and an organisation that needs another says so.
	 *
	 * @return string The zone name.
	 *
	 * @throws FlowTimerValidationException On a zone that does not resolve.
	 */
	private function validTimezone(): string {
		$value = ($this->raw['timezone'] ?? null);
		if (self::isBlank(value: $value) === true) {
			return 'UTC';
		}

		if (is_string($value) === false) {
			throw new FlowTimerValidationException(message: sprintf("Working calendar '%s' declares a timezone that is not a string.", $this->slug));
		}

		$zone = trim($value);
		if (in_array($zone, DateTimeZone::listIdentifiers(), true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s' declares timezone '%s', which is not an IANA zone name.", $this->slug, $zone)
			);
		}

		return $zone;
	}//end validTimezone()

	/**
	 * The working weekdays a calendar declares, as ISO numbers.
	 *
	 * @return array<int, int> The weekdays, ISO 1..7, without duplicates.
	 *
	 * @throws FlowTimerValidationException When the list is empty or holds a day outside 1..7.
	 */
	private function validWeekdays(): array {
		$value = ($this->raw['workingWeekdays'] ?? null);
		if (is_array($value) === false || $value === []) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s' must declare its workingWeekdays (ISO 1..7).", $this->slug)
			);
		}

		$weekdays = array_map(fn (mixed $weekday): int => $this->validWeekday(weekday: $weekday), $value);

		return array_values(array_unique($weekdays));
	}//end validWeekdays()

	/**
	 * One declared working weekday.
	 *
	 * @param mixed $weekday The declared value.
	 *
	 * @return integer The ISO weekday.
	 *
	 * @throws FlowTimerValidationException When it is not an integer 1..7.
	 */
	private function validWeekday(mixed $weekday): int {
		if (is_int($weekday) === false || $weekday < 1 || $weekday > 7) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s' has an invalid weekday '%s'; use ISO 1..7.", $this->slug, var_export($weekday, true))
			);
		}

		return $weekday;
	}//end validWeekday()



	/**
	 * Whether a declared value is absent: null or a blank string.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return boolean True when absent.
	 */
	private static function isBlank(mixed $value): bool {
		return $value === null || (is_string($value) === true && trim($value) === '');
	}//end isBlank()


	/**
	 * The hour and minute of an `HH:MM` 24-hour time.
	 *
	 * @param mixed $value The declared time.
	 *
	 * @return array<int, string>|null The regex groups, or null when malformed.
	 */
	private static function clockParts(mixed $value): ?array {
		if (is_string($value) === false || preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', trim($value), $parts) !== 1) {
			return null;
		}

		return $parts;
	}//end clockParts()

	/**
	 * A declared value as it is shown in a refusal.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string The scalar as a string, otherwise its type.
	 */
	private static function shown(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return gettype($value);
	}//end shown()
}//end class
