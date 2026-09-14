<?php

/**
 * One declared date on a schema: which property it is, and what kind of date.
 *
 * Publishing every date property would fill a caseworker's agenda with
 * `created` and `modified`, so a date publishes only when the schema says what
 * it is. Three kinds cover the corpus: a deadline, an appointment and a
 * period. The kind decides the VEVENT shape and the alarm, so the leaf app
 * declares meaning rather than presentation.
 *
 * The declaration lives under `configuration.calendarProvider.dates`, keyed by
 * property name. This class is the single authority on its shape: the schema
 * validator and the feed generator both build declarations through
 * `fromArray()`, so a save that is accepted is a save the feed can read.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use OCA\OpenRegister\Exception\CalendarDateKindException;

/**
 * A validated date-kind declaration for one schema property.
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
final class ObjectDateDeclaration {

	/**
	 * A statutory or policy term: an all-day event with a declared alarm.
	 *
	 * @var string
	 */
	public const KIND_DEADLINE = 'deadline';

	/**
	 * A meeting: a timed event with a duration and attendees.
	 *
	 * @var string
	 */
	public const KIND_APPOINTMENT = 'appointment';

	/**
	 * A span: an event with a start and an end.
	 *
	 * @var string
	 */
	public const KIND_PERIOD = 'period';

	/**
	 * The kinds a date property may declare.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = [self::KIND_DEADLINE, self::KIND_APPOINTMENT, self::KIND_PERIOD];

	/**
	 * The timer purposes a deadline may bind to, matching FlowTimer::PURPOSES.
	 *
	 * @var array<int, string>
	 */
	public const TIMER_PURPOSES = ['due', 'expiry'];

	/**
	 * The default length of an appointment that declares neither an end
	 * property nor a duration, in minutes.
	 *
	 * @var int
	 */
	public const DEFAULT_APPOINTMENT_MINUTES = 60;

	/**
	 * Constructor. Use `fromArray()`, which validates.
	 *
	 * @param string $property The schema property carrying the date.
	 * @param string $kind One of KINDS.
	 * @param string|null $summaryTemplate Template for the event summary.
	 * @param int|null $alarmOffsetDays Days before a deadline to warn, when any.
	 * @param string|null $endProperty The property carrying the end of a period or appointment.
	 * @param int|null $durationMinutes The length of an appointment without an end property.
	 * @param string|null $timerPurpose The flow-timer purpose a deadline reads its date from.
	 * @param string|null $calendarSlug The working calendar a deadline resolves against.
	 * @param string|null $attendeesProperty The property holding the appointment's attendees.
	 */
	private function __construct(
		public readonly string $property,
		public readonly string $kind,
		public readonly ?string $summaryTemplate,
		public readonly ?int $alarmOffsetDays,
		public readonly ?string $endProperty,
		public readonly ?int $durationMinutes,
		public readonly ?string $timerPurpose,
		public readonly ?string $calendarSlug,
		public readonly ?string $attendeesProperty,
	) {

	}//end __construct()

	/**
	 * Build a declaration from its stored shape, refusing anything unusable.
	 *
	 * Every refusal names the property. A schema author reading
	 * "kind 'deadlne' is not a date kind" and nothing else cannot find which
	 * of forty properties carries the typo.
	 *
	 * @param string $property The property name the declaration is keyed by.
	 * @param mixed $config The declaration as stored.
	 *
	 * @return self The validated declaration.
	 *
	 * @throws CalendarDateKindException When the declaration cannot be used.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public static function fromArray(string $property, mixed $config): self {
		if (is_array($config) === false) {
			throw new CalendarDateKindException(
				message: sprintf(
					"calendarProvider.dates.%s must be an object declaring a kind, %s given.",
					$property,
					get_debug_type($config)
				)
			);
		}

		$kind = ($config['kind'] ?? null);
		if (is_string($kind) === false || in_array($kind, self::KINDS, true) === false) {
			$given = get_debug_type($kind);
			if (is_string($kind) === true) {
				$given = $kind;
			}

			throw new CalendarDateKindException(
				message: sprintf(
					"calendarProvider.dates.%s declares kind '%s', which is not a date kind: use %s.",
					$property,
					$given,
					implode(', ', self::KINDS)
				)
			);
		}

		$alarm = self::readAlarmOffsetDays(property: $property, kind: $kind, config: $config);
		$endProperty = self::readEndProperty(property: $property, kind: $kind, config: $config);
		$duration = self::readDurationMinutes(property: $property, config: $config);
		$timerPurpose = self::readTimerPurpose(property: $property, kind: $kind, config: $config);

		return new self(
			property: $property,
			kind: $kind,
			summaryTemplate: self::stringOrNull(value: ($config['summaryTemplate'] ?? null)),
			alarmOffsetDays: $alarm,
			endProperty: $endProperty,
			durationMinutes: $duration,
			timerPurpose: $timerPurpose,
			calendarSlug: self::stringOrNull(value: ($config['calendar'] ?? null)),
			attendeesProperty: self::stringOrNull(value: ($config['attendeesProperty'] ?? null)),
		);
	}//end fromArray()

	/**
	 * Read every declaration on a calendar-provider configuration.
	 *
	 * @param array<string, mixed> $calendarConfig The calendarProvider config.
	 *
	 * @return array<int, self> The declarations, in declaration order.
	 *
	 * @throws CalendarDateKindException When any declaration cannot be used.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public static function allFromConfig(array $calendarConfig): array {
		$dates = ($calendarConfig['dates'] ?? null);

		if ($dates === null) {
			return [];
		}

		if (is_array($dates) === false) {
			throw new CalendarDateKindException(
				message: 'calendarProvider.dates must be an object keyed by property name.'
			);
		}

		$declarations = [];
		foreach ($dates as $property => $config) {
			$name = trim((string)$property);
			if ($name === '') {
				throw new CalendarDateKindException(
					message: 'calendarProvider.dates carries an entry with no property name.'
				);
			}

			$declarations[] = self::fromArray(property: $name, config: $config);
		}

		return $declarations;
	}//end allFromConfig()

	/**
	 * The alarm offset, in days, refusing a negative or non-integer offset.
	 *
	 * @param string $property The property being declared.
	 * @param string $kind The declared kind.
	 * @param array<string, mixed> $config The declaration.
	 *
	 * @return int|null The offset, or null when none is declared.
	 *
	 * @throws CalendarDateKindException When the offset is unusable.
	 */
	private static function readAlarmOffsetDays(string $property, string $kind, array $config): ?int {
		$raw = ($config['alarmOffsetDays'] ?? null);

		if ($raw === null) {
			return null;
		}

		if ($kind !== self::KIND_DEADLINE) {
			throw new CalendarDateKindException(
				message: sprintf(
					"calendarProvider.dates.%s declares alarmOffsetDays on a %s; only a deadline carries an alarm.",
					$property,
					$kind
				)
			);
		}

		if (is_int($raw) === false || $raw < 0) {
			throw new CalendarDateKindException(
				message: sprintf(
					'calendarProvider.dates.%s declares alarmOffsetDays that is not a whole number of days at or above zero.',
					$property
				)
			);
		}

		return $raw;
	}//end readAlarmOffsetDays()

	/**
	 * The end property, required on a period.
	 *
	 * @param string $property The property being declared.
	 * @param string $kind The declared kind.
	 * @param array<string, mixed> $config The declaration.
	 *
	 * @return string|null The end property, or null when none is declared.
	 *
	 * @throws CalendarDateKindException When a period declares no end.
	 */
	private static function readEndProperty(string $property, string $kind, array $config): ?string {
		$endProperty = self::stringOrNull(value: ($config['endProperty'] ?? null));

		if ($kind === self::KIND_PERIOD && $endProperty === null) {
			throw new CalendarDateKindException(
				message: sprintf(
					'calendarProvider.dates.%s is a period and must declare endProperty, the property carrying its end.',
					$property
				)
			);
		}

		return $endProperty;
	}//end readEndProperty()

	/**
	 * The declared appointment length in minutes.
	 *
	 * @param string $property The property being declared.
	 * @param array<string, mixed> $config The declaration.
	 *
	 * @return int|null The duration, or null when none is declared.
	 *
	 * @throws CalendarDateKindException When the duration is unusable.
	 */
	private static function readDurationMinutes(string $property, array $config): ?int {
		$raw = ($config['durationMinutes'] ?? null);

		if ($raw === null) {
			return null;
		}

		if (is_int($raw) === false || $raw <= 0) {
			throw new CalendarDateKindException(
				message: sprintf(
					'calendarProvider.dates.%s declares durationMinutes that is not a whole number of minutes above zero.',
					$property
				)
			);
		}

		return $raw;
	}//end readDurationMinutes()

	/**
	 * The flow-timer purpose a deadline binds to.
	 *
	 * @param string $property The property being declared.
	 * @param string $kind The declared kind.
	 * @param array<string, mixed> $config The declaration.
	 *
	 * @return string|null The purpose, or null when the deadline reads its raw date.
	 *
	 * @throws CalendarDateKindException When the purpose is not a timer purpose.
	 */
	private static function readTimerPurpose(string $property, string $kind, array $config): ?string {
		$purpose = self::stringOrNull(value: ($config['timerPurpose'] ?? null));

		if ($purpose === null) {
			return null;
		}

		if ($kind !== self::KIND_DEADLINE) {
			throw new CalendarDateKindException(
				message: sprintf(
					'calendarProvider.dates.%s declares timerPurpose on a %s; only a deadline is computed by the term engine.',
					$property,
					$kind
				)
			);
		}

		if (in_array($purpose, self::TIMER_PURPOSES, true) === false) {
			throw new CalendarDateKindException(
				message: sprintf(
					"calendarProvider.dates.%s declares timerPurpose '%s': use %s.",
					$property,
					$purpose,
					implode(' or ', self::TIMER_PURPOSES)
				)
			);
		}

		return $purpose;
	}//end readTimerPurpose()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string, or null.
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end stringOrNull()
}//end class
