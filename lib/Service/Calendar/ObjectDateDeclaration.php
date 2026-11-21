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
 * @spec openspec/specs/calendar-provider/spec.md
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
	 * The schema property carrying the date.
	 *
	 * @var string
	 */
	public readonly string $property;

	/**
	 * One of KINDS.
	 *
	 * @var string
	 */
	public readonly string $kind;

	/**
	 * Template for the event summary.
	 *
	 * @var string|null
	 */
	public readonly ?string $summaryTemplate;

	/**
	 * Days before a deadline to warn, when any.
	 *
	 * @var int|null
	 */
	public readonly ?int $alarmOffsetDays;

	/**
	 * The property carrying the end of a period or appointment.
	 *
	 * @var string|null
	 */
	public readonly ?string $endProperty;

	/**
	 * The length of an appointment without an end property.
	 *
	 * @var int|null
	 */
	public readonly ?int $durationMinutes;

	/**
	 * The flow-timer purpose a deadline reads its date from.
	 *
	 * @var string|null
	 */
	public readonly ?string $timerPurpose;

	/**
	 * The working calendar a deadline resolves against.
	 *
	 * @var string|null
	 */
	public readonly ?string $calendarSlug;

	/**
	 * The property holding the appointment's attendees.
	 *
	 * @var string|null
	 */
	public readonly ?string $attendeesProperty;

	/**
	 * Constructor. Use `fromArray()`, which validates.
	 *
	 * @param string $property The schema property carrying the date.
	 * @param string $kind One of KINDS.
	 * @param ObjectDateLayout $layout How the event is drawn: summary, end, length, attendees.
	 * @param ObjectDateTerm $term How a deadline is computed and announced.
	 */
	private function __construct(
		string $property,
		string $kind,
		ObjectDateLayout $layout,
		ObjectDateTerm $term,
	) {
		$this->property = $property;
		$this->kind = $kind;
		$this->summaryTemplate = $layout->summaryTemplate;
		$this->alarmOffsetDays = $term->alarmOffsetDays;
		$this->endProperty = $layout->endProperty;
		$this->durationMinutes = $layout->durationMinutes;
		$this->timerPurpose = $term->timerPurpose;
		$this->calendarSlug = $term->calendarSlug;
		$this->attendeesProperty = $layout->attendeesProperty;
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
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public static function fromArray(string $property, mixed $config): self {
		if (is_array($config) === false) {
			throw self::notAnObject(property: $property, config: $config);
		}

		$kind = ($config['kind'] ?? null);
		if (is_string($kind) === false || in_array($kind, self::KINDS, true) === false) {
			throw self::notADateKind(property: $property, kind: $kind);
		}

		return self::fromRun(run: new DateDeclarationRun(property: $property, kind: $kind, config: $config));
	}//end fromArray()

	/**
	 * The refusal of a declaration that is not an object.
	 *
	 * @param string $property The property name the declaration is keyed by.
	 * @param mixed  $config   The declaration as stored.
	 *
	 * @return CalendarDateKindException The refusal, naming the property and the type given.
	 */
	private static function notAnObject(string $property, mixed $config): CalendarDateKindException {
		return new CalendarDateKindException(
			message: sprintf(
				"calendarProvider.dates.%s must be an object declaring a kind, %s given.",
				$property,
				get_debug_type($config)
			)
		);
	}//end notAnObject()

	/**
	 * The refusal of a kind that is not one of KINDS.
	 *
	 * @param string $property The property name the declaration is keyed by.
	 * @param mixed  $kind     The declared kind.
	 *
	 * @return CalendarDateKindException The refusal, naming the property, the kind given and the kinds allowed.
	 */
	private static function notADateKind(string $property, mixed $kind): CalendarDateKindException {
		$given = get_debug_type($kind);
		if (is_string($kind) === true) {
			$given = $kind;
		}

		return new CalendarDateKindException(
			message: sprintf(
				"calendarProvider.dates.%s declares kind '%s', which is not a date kind: use %s.",
				$property,
				$given,
				implode(', ', self::KINDS)
			)
		);
	}//end notADateKind()

	/**
	 * Read a declaration whose kind is known: its alarm, its layout, then its timer.
	 *
	 * @param DateDeclarationRun $run The property, kind and stored declaration.
	 *
	 * @return self The validated declaration.
	 *
	 * @throws CalendarDateKindException When a field cannot be used.
	 */
	private static function fromRun(DateDeclarationRun $run): self {
		$alarm  = self::readAlarmOffsetDays(run: $run);
		$layout = self::readLayout(run: $run);
		$term   = new ObjectDateTerm(
			alarmOffsetDays: $alarm,
			timerPurpose: self::readTimerPurpose(run: $run),
			calendarSlug: self::stringOrNull(value: ($run->config['calendar'] ?? null)),
		);

		return new self(
			property: $run->property,
			kind: $run->kind,
			layout: $layout,
			term: $term,
		);
	}//end fromRun()

	/**
	 * Read how the event is drawn: its summary, end, length and attendees.
	 *
	 * @param DateDeclarationRun $run The property, kind and stored declaration.
	 *
	 * @return ObjectDateLayout The layout.
	 *
	 * @throws CalendarDateKindException When the end or the length cannot be used.
	 */
	private static function readLayout(DateDeclarationRun $run): ObjectDateLayout {
		$endProperty = self::readEndProperty(run: $run);
		$duration    = self::readDurationMinutes(property: $run->property, config: $run->config);

		return new ObjectDateLayout(
			summaryTemplate: self::stringOrNull(value: ($run->config['summaryTemplate'] ?? null)),
			endProperty: $endProperty,
			durationMinutes: $duration,
			attendeesProperty: self::stringOrNull(value: ($run->config['attendeesProperty'] ?? null)),
		);
	}//end readLayout()

	/**
	 * Read every declaration on a calendar-provider configuration.
	 *
	 * @param array<string, mixed> $calendarConfig The calendarProvider config.
	 *
	 * @return array<int, self> The declarations, in declaration order.
	 *
	 * @throws CalendarDateKindException When any declaration cannot be used.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public static function allFromConfig(array $calendarConfig): array {
		$declarations = [];
		foreach (self::datesBlock(calendarConfig: $calendarConfig) as $property => $config) {
			$declarations[] = self::fromArray(property: self::entryName(property: $property), config: $config);
		}

		return $declarations;
	}//end allFromConfig()

	/**
	 * The `dates` block of a calendar-provider configuration: empty when absent, refused when not an object.
	 *
	 * @param array<string, mixed> $calendarConfig The calendarProvider config.
	 *
	 * @return array<string|int, mixed> The declarations keyed by property name.
	 *
	 * @throws CalendarDateKindException When the block is not an object.
	 */
	private static function datesBlock(array $calendarConfig): array {
		$dates = ($calendarConfig['dates'] ?? null);

		if ($dates === null) {
			return [];
		}

		if (is_array($dates) === false) {
			throw new CalendarDateKindException(
				message: 'calendarProvider.dates must be an object keyed by property name.'
			);
		}

		return $dates;
	}//end datesBlock()

	/**
	 * The trimmed property name of a `dates` entry, refusing a blank one.
	 *
	 * @param string|int $property The entry key.
	 *
	 * @return string The property name.
	 *
	 * @throws CalendarDateKindException When the name is blank.
	 */
	private static function entryName(string|int $property): string {
		$name = trim((string)$property);
		if ($name === '') {
			throw new CalendarDateKindException(
				message: 'calendarProvider.dates carries an entry with no property name.'
			);
		}

		return $name;
	}//end entryName()

	/**
	 * The alarm offset, in days, refusing a negative or non-integer offset.
	 *
	 * @param DateDeclarationRun $run The property, kind and declaration.
	 *
	 * @return int|null The offset, or null when none is declared.
	 *
	 * @throws CalendarDateKindException When the offset is unusable.
	 */
	private static function readAlarmOffsetDays(DateDeclarationRun $run): ?int {
		$raw = ($run->config['alarmOffsetDays'] ?? null);

		if ($raw === null) {
			return null;
		}

		if ($run->kind !== self::KIND_DEADLINE) {
			throw self::alarmOnANonDeadline(run: $run);
		}

		return self::wholeDays(property: $run->property, raw: $raw);
	}//end readAlarmOffsetDays()

	/**
	 * The refusal of an alarm offset on a kind other than a deadline.
	 *
	 * @param DateDeclarationRun $run The property, kind and declaration.
	 *
	 * @return CalendarDateKindException The refusal.
	 */
	private static function alarmOnANonDeadline(DateDeclarationRun $run): CalendarDateKindException {
		return new CalendarDateKindException(
			message: sprintf(
				"calendarProvider.dates.%s declares alarmOffsetDays on a %s; only a deadline carries an alarm.",
				$run->property,
				$run->kind
			)
		);
	}//end alarmOnANonDeadline()

	/**
	 * A declared alarm offset that is a whole number of days at or above zero.
	 *
	 * @param string $property The property being declared.
	 * @param mixed $raw The declared offset.
	 *
	 * @return int The offset.
	 *
	 * @throws CalendarDateKindException When the offset is unusable.
	 */
	private static function wholeDays(string $property, mixed $raw): int {
		if (is_int($raw) === false || $raw < 0) {
			throw new CalendarDateKindException(
				message: sprintf(
					'calendarProvider.dates.%s declares alarmOffsetDays that is not a whole number of days at or above zero.',
					$property
				)
			);
		}

		return $raw;
	}//end wholeDays()

	/**
	 * The end property, required on a period.
	 *
	 * @param DateDeclarationRun $run The property, kind and declaration.
	 *
	 * @return string|null The end property, or null when none is declared.
	 *
	 * @throws CalendarDateKindException When a period declares no end.
	 */
	private static function readEndProperty(DateDeclarationRun $run): ?string {
		$property = $run->property;
		$kind = $run->kind;
		$endProperty = self::stringOrNull(value: ($run->config['endProperty'] ?? null));

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
	 * @param DateDeclarationRun $run The property, kind and declaration.
	 *
	 * @return string|null The purpose, or null when the deadline reads its raw date.
	 *
	 * @throws CalendarDateKindException When the purpose is not a timer purpose.
	 */
	private static function readTimerPurpose(DateDeclarationRun $run): ?string {
		$purpose = self::stringOrNull(value: ($run->config['timerPurpose'] ?? null));

		if ($purpose === null) {
			return null;
		}

		if ($run->kind !== self::KIND_DEADLINE) {
			throw self::timerOnANonDeadline(run: $run);
		}

		if (in_array($purpose, self::TIMER_PURPOSES, true) === false) {
			throw self::unknownTimerPurpose(property: $run->property, purpose: $purpose);
		}

		return $purpose;
	}//end readTimerPurpose()

	/**
	 * The refusal of a timer purpose on a kind other than a deadline.
	 *
	 * @param DateDeclarationRun $run The property, kind and declaration.
	 *
	 * @return CalendarDateKindException The refusal.
	 */
	private static function timerOnANonDeadline(DateDeclarationRun $run): CalendarDateKindException {
		return new CalendarDateKindException(
			message: sprintf(
				'calendarProvider.dates.%s declares timerPurpose on a %s; only a deadline is computed by the term engine.',
				$run->property,
				$run->kind
			)
		);
	}//end timerOnANonDeadline()

	/**
	 * The refusal of a timer purpose that is not one of TIMER_PURPOSES.
	 *
	 * @param string $property The property being declared.
	 * @param string $purpose  The declared purpose.
	 *
	 * @return CalendarDateKindException The refusal.
	 */
	private static function unknownTimerPurpose(string $property, string $purpose): CalendarDateKindException {
		return new CalendarDateKindException(
			message: sprintf(
				"calendarProvider.dates.%s declares timerPurpose '%s': use %s.",
				$property,
				$purpose,
				implode(' or ', self::TIMER_PURPOSES)
			)
		);
	}//end unknownTimerPurpose()

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
