<?php

/**
 * OpenRegister Calendar Event Transformer
 *
 * Transforms ObjectEntity data into VEVENT-compatible arrays
 * for the Nextcloud Calendar app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Transforms OpenRegister objects into VEVENT-compatible arrays
 *
 * This class converts ObjectEntity instances into the array format expected
 * by Nextcloud's ICalendar::search() return type, following RFC 5545 VEVENT
 * conventions.
 *
 * @package OCA\OpenRegister\Calendar
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CalendarEventTransformer {

	/**
	 * Default calendar color when not configured
	 *
	 * @var string
	 */
	public const DEFAULT_COLOR = '#0082C9';

	/**
	 * Transform an ObjectEntity into a VEVENT-compatible array
	 *
	 * @param ObjectEntity       $object   The object to transform
	 * @param CalendarDefinition $calendar The schema this object belongs to and its calendar configuration
	 *
	 * @return array|null The VEVENT array, or null if the object lacks required date data
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function transform(ObjectEntity $object, CalendarDefinition $calendar): ?array {
		if ($this->startValue(object: $object, calendar: $calendar) === null) {
			return null;
		}

		$calendarKey = 'openregister-schema-' . $calendar->schema->getId();

		return [
			'id' => $this->eventUid(object: $object, calendar: $calendar),
			'type' => 'VEVENT',
			'calendar-key' => $calendarKey,
			'calendar-uri' => $calendarKey,
			'objects' => [
				$this->veventProperties(object: $object, calendar: $calendar),
			],
		];
	}//end transform()

	/**
	 * The object's start value, when the calendar declares a start field and the object fills it.
	 *
	 * @param ObjectEntity       $object   The object
	 * @param CalendarDefinition $calendar The calendar the object appears on
	 *
	 * @return mixed The start value, or null when there is no (non-empty) start
	 */
	private function startValue(ObjectEntity $object, CalendarDefinition $calendar): mixed {
		$dtstartField = $calendar->config['dtstart'] ?? null;
		if ($dtstartField === null) {
			return null;
		}

		$dtstartValue = $object->getObject()[$dtstartField] ?? null;
		if (empty($dtstartValue) === true) {
			return null;
		}

		return $dtstartValue;
	}//end startValue()

	/**
	 * The stable event UID of an object on a schema calendar.
	 *
	 * @param ObjectEntity       $object   The object
	 * @param CalendarDefinition $calendar The calendar the object appears on
	 *
	 * @return string The UID
	 */
	private function eventUid(ObjectEntity $object, CalendarDefinition $calendar): string {
		return 'openregister-' . $calendar->schema->getId() . '-' . $object->getUuid();
	}//end eventUid()

	/**
	 * The VEVENT properties of an object with a start value.
	 *
	 * @param ObjectEntity       $object   The object
	 * @param CalendarDefinition $calendar The calendar the object appears on
	 *
	 * @return array<string, array> The properties, each as [value, parameters]
	 */
	private function veventProperties(ObjectEntity $object, CalendarDefinition $calendar): array {
		$objectData = $object->getObject();
		$veventProperties = [
			'UID' => [$this->eventUid(object: $object, calendar: $calendar), []],
			'SUMMARY' => [$this->summary(object: $object, calendar: $calendar), []],
			'DTSTART' => $this->formatDateValue(value: $objectData[$calendar->config['dtstart']], allDay: $calendar->allDay()),
			'DTEND' => $this->buildDtend(objectData: $objectData, calendar: $calendar),
			'STATUS' => [$this->resolveStatus(objectData: $objectData, calendarConfig: $calendar->config), []],
			'TRANSP' => ['TRANSPARENT', []],
			'CATEGORIES' => [['OpenRegister', $calendar->schema->getTitle() ?? 'Schema'], []],
		];

		$veventProperties += $this->optionalProperties(objectData: $objectData, calendarConfig: $calendar->config);
		$veventProperties['URL'] = [$this->objectUrl(object: $object, calendar: $calendar), []];

		return $veventProperties;
	}//end veventProperties()

	/**
	 * The event title: the calendar's title template filled from the object, or the object UUID.
	 *
	 * @param ObjectEntity       $object   The object
	 * @param CalendarDefinition $calendar The calendar the object appears on
	 *
	 * @return string The title
	 */
	private function summary(ObjectEntity $object, CalendarDefinition $calendar): string {
		return $this->interpolateTemplate(
			template: $calendar->config['titleTemplate'] ?? $object->getUuid(),
			objectData: $object->getObject()
		);
	}//end summary()

	/**
	 * The URL of the object in OpenRegister.
	 *
	 * @param ObjectEntity       $object   The object
	 * @param CalendarDefinition $calendar The calendar the object appears on
	 *
	 * @return string The URL
	 */
	private function objectUrl(ObjectEntity $object, CalendarDefinition $calendar): string {
		return '/apps/openregister/objects/' . $object->getRegister() . '/' . $calendar->schema->getId() . '/' . $object->getUuid();
	}//end objectUrl()

	/**
	 * The optional DESCRIPTION and LOCATION properties, each present only when configured and non-empty.
	 *
	 * @param array $objectData     The object data
	 * @param array $calendarConfig The calendar provider configuration
	 *
	 * @return array<string, array{0: mixed, 1: array}> The properties.
	 */
	private function optionalProperties(array $objectData, array $calendarConfig): array {
		$properties = [];
		if (empty($calendarConfig['descriptionTemplate']) === false) {
			$description = $this->interpolateTemplate(
				template: $calendarConfig['descriptionTemplate'],
				objectData: $objectData
			);
			$properties['DESCRIPTION'] = [$description, []];
		}

		if (empty($calendarConfig['locationField']) === false) {
			$locationValue = $objectData[$calendarConfig['locationField']] ?? null;
			if (empty($locationValue) === false) {
				$properties['LOCATION'] = [$locationValue, []];
			}
		}

		return $properties;
	}//end optionalProperties()

	/**
	 * Format a date value into iCalendar format
	 *
	 * Honours timezone information present in the source string per
	 * RFC 5545:
	 *
	 * - All-day values emit a `DATE` form (no time, no zone).
	 * - Source strings carrying a non-UTC named zone (e.g. parsed from
	 *   `Europe/Amsterdam` context) emit the local-time form with a
	 *   `TZID` parameter.
	 * - Source strings carrying a non-UTC numeric offset are converted
	 *   to the equivalent UTC instant and emitted with the `Z` suffix
	 *   (this preserves the correct moment in time; the original offset
	 *   label is lost because RFC 5545 TZID values must reference named
	 *   timezones, not bare offsets).
	 * - Source strings that are already UTC (suffix `Z` or `+00:00`) or
	 *   naive (no zone designator at all) emit the `Z` suffix unchanged,
	 *   matching the historical default.
	 *
	 * Malformed input falls back to the naive-UTC encoding so that
	 * downstream `ICalendar::search()` consumers never receive a
	 * partially-built VEVENT.
	 *
	 * @param string $value The date/datetime string
	 * @param bool $allDay Whether this is an all-day event
	 *
	 * @return array The formatted [value, params] array
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function formatDateValue(string $value, bool $allDay): array {
		try {
			$date = new DateTime($value);
		} catch (Exception $e) {
			return $this->placeholderDate(allDay: $allDay);
		}

		if ($allDay === true) {
			return [$date->format('Ymd'), ['VALUE' => 'DATE']];
		}

		return $this->dateTimeValue(date: $date, value: $value);
	}//end formatDateValue()

	/**
	 * The placeholder for malformed date input.
	 *
	 * Malformed input falls back to a safe placeholder so the VEVENT is still
	 * serialisable; consumers will treat it as a naive UTC value.
	 *
	 * @param bool $allDay Whether to emit a DATE rather than a DATE-TIME
	 *
	 * @return array [value, parameters] for the Unix epoch
	 */
	private function placeholderDate(bool $allDay): array {
		$fallback = new DateTime('@0');
		if ($allDay === true) {
			return [$fallback->format('Ymd'), ['VALUE' => 'DATE']];
		}

		return [$fallback->format('Ymd\THis\Z'), ['VALUE' => 'DATE-TIME']];
	}//end placeholderDate()

	/**
	 * Format a parsed moment as an iCalendar DATE-TIME, keeping its zone class.
	 *
	 * @param DateTime $date  The parsed moment
	 * @param string   $value The source text, whose zone notation decides the form
	 *
	 * @return array [value, parameters]
	 */
	private function dateTimeValue(DateTime $date, string $value): array {
		$sourceZone = $this->detectSourceTimezone(value: $value);

		// Naive input or explicit UTC: emit `Z` form. For naive inputs we
		// intentionally do NOT shift the wall-clock — DateTime treats the
		// string as UTC by default and that matches the historical
		// contract.
		if ($sourceZone === null || $sourceZone === 'utc') {
			return [$date->format('Ymd\THis\Z'), ['VALUE' => 'DATE-TIME']];
		}

		// Named non-UTC zone (e.g. `Europe/Amsterdam`): emit local time
		// with a TZID parameter so calendar clients can render the
		// original wall-clock alongside the correct instant.
		if ($sourceZone !== 'offset') {
			return $this->localTimeWithZone(date: $date);
		}

		// Non-UTC numeric offset: convert to the equivalent UTC instant
		// and emit `Z`. This fixes the previous behaviour where a
		// literal `Z` was appended to a non-UTC wall-clock, silently
		// mis-stating the event's actual moment.
		$utcDate = clone $date;
		$utcDate->setTimezone(new DateTimeZone('UTC'));
		return [$utcDate->format('Ymd\THis\Z'), ['VALUE' => 'DATE-TIME']];
	}//end dateTimeValue()

	/**
	 * Format a moment as local wall-clock time with its named zone as TZID.
	 *
	 * @param DateTime $date The parsed moment in a named zone
	 *
	 * @return array [value, parameters]
	 */
	private function localTimeWithZone(DateTime $date): array {
		$tzName = $date->getTimezone()->getName();
		return [
			$date->format('Ymd\THis'),
			[
				'VALUE' => 'DATE-TIME',
				'TZID' => $tzName,
			],
		];
	}//end localTimeWithZone()

	/**
	 * Detect what kind of timezone designator (if any) the source string carries
	 *
	 * Returns one of:
	 * - `null`     when the string has no zone marker (naive)
	 * - `'utc'`    when the string ends in `Z` or `+00:00` / `-00:00`
	 * - `'offset'` when the string ends in a non-zero numeric offset
	 * - `'named'`  when the string includes an Olson-style name (rare)
	 *
	 * The detection is intentionally string-based because PHP's
	 * DateTime collapses every offset and naive input into something
	 * indistinguishable after construction.
	 *
	 * @param string $value The raw source string
	 *
	 * @return string|null One of `null|'utc'|'offset'|'named'`
	 */
	private function detectSourceTimezone(string $value): ?string {
		$trimmed = trim($value);

		if (preg_match('/[Zz]$/', $trimmed) === 1) {
			return 'utc';
		}

		if (preg_match('/([+-])(\d{2}):?(\d{2})$/', $trimmed, $matches) === 1) {
			$hours = (int)$matches[2];
			$minutes = (int)$matches[3];
			if ($hours === 0 && $minutes === 0) {
				return 'utc';
			}

			return 'offset';
		}

		// Olson-style name appended in brackets (`...[Europe/Amsterdam]`)
		// is not standard ISO-8601 but DateTime accepts it via the
		// constructor's lax parser; treat as a named zone.
		if (preg_match('/\[[A-Za-z_]+\/[A-Za-z_]+\]$/', $trimmed) === 1) {
			return 'named';
		}

		return null;
	}//end detectSourceTimezone()

	/**
	 * Build DTEND value from configuration
	 *
	 * @param array              $objectData The object data
	 * @param CalendarDefinition $calendar   The calendar configuration (dtstart/dtend fields, all-day mode)
	 *
	 * @return array The formatted [value, params] array for DTEND
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function buildDtend(array $objectData, CalendarDefinition $calendar): array {
		$configured = $this->configuredDtend(objectData: $objectData, calendar: $calendar);
		if ($configured !== null) {
			return $configured;
		}

		return $this->defaultDtend(dtstartValue: $objectData[$calendar->dtstartField()], allDay: $calendar->allDay());
	}//end buildDtend()

	/**
	 * DTEND from the calendar's end field, when it is configured and the object fills it.
	 *
	 * @param array              $objectData The object data
	 * @param CalendarDefinition $calendar   The calendar the object appears on
	 *
	 * @return array|null [value, parameters], or null when there is no end value
	 */
	private function configuredDtend(array $objectData, CalendarDefinition $calendar): ?array {
		$calendarConfig = $calendar->config;
		if (empty($calendarConfig['dtend']) === true) {
			return null;
		}

		$dtendValue = $objectData[$calendarConfig['dtend']] ?? null;
		if (empty($dtendValue) === true) {
			return null;
		}

		return $this->formatDateValue(value: $dtendValue, allDay: $calendar->allDay());
	}//end configuredDtend()

	/**
	 * Default DTEND: one day after an all-day start, one hour after a timed start.
	 *
	 * We round-trip through the ISO-8601 representation so `formatDateValue()`
	 * re-detects the source timezone and emits a TZID-correct value (DTEND must
	 * not silently flip to UTC `Z` when DTSTART carried an offset).
	 *
	 * @param string $dtstartValue The start value
	 * @param bool   $allDay       Whether the event is all-day
	 *
	 * @return array [value, parameters]
	 */
	private function defaultDtend(string $dtstartValue, bool $allDay): array {
		try {
			$date = new DateTime($dtstartValue);
		} catch (Exception $e) {
			// Malformed start — produce a safe placeholder so the
			// transformer still returns a usable VEVENT.
			return $this->formatDateValue(value: $dtstartValue, allDay: $allDay);
		}

		if ($allDay === true) {
			$date->add(new DateInterval('P1D'));
			return [$date->format('Ymd'), ['VALUE' => 'DATE']];
		}

		$date->add(new DateInterval('PT1H'));

		// Emit the recomputed instant in ISO-8601 with the original zone
		// information preserved (`c` is `Y-m-d\TH:i:sP`) so that
		// `formatDateValue()` re-detects the same timezone class as the
		// source value.
		return $this->formatDateValue(value: $date->format('c'), allDay: $allDay);
	}//end defaultDtend()

	/**
	 * Interpolate a template string with object data
	 *
	 * Replaces {property} placeholders with values from object data.
	 * Missing properties are replaced with empty strings.
	 *
	 * @param string $template The template string with {property} placeholders
	 * @param array $objectData The object data array
	 *
	 * @return string The interpolated string
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function interpolateTemplate(string $template, array $objectData): string {
		// Match Mustache-style `{{ key }}` tokens, matching the convention
		// used by the notification dispatcher's interpolate() helper. The
		// previous single-brace `{key}` pattern silently corrupted any
		// template that used the standard `{{...}}` form: `{{title}}`
		// would render as `}` because the regex consumed the leading
		// `{{title}` greedily and left the trailing `}` behind.
		return preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
			function ($matches) use ($objectData) {
				$key = $matches[1];
				$value = $objectData[$key] ?? '';

				if (is_array($value) === true) {
					return json_encode($value);
				}

				return (string)$value;
			},
			$template
		) ?? $template;
	}//end interpolateTemplate()

	/**
	 * Resolve the VEVENT STATUS from object data using status mapping
	 *
	 * @param array $objectData The object data
	 * @param array $calendarConfig The calendar configuration
	 *
	 * @return string The VEVENT STATUS value (CONFIRMED, CANCELLED, TENTATIVE)
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function resolveStatus(array $objectData, array $calendarConfig): string {
		if (empty($calendarConfig['statusMapping']) === true || empty($calendarConfig['statusField']) === true) {
			return 'CONFIRMED';
		}

		$statusValue = $objectData[$calendarConfig['statusField']] ?? null;

		if ($statusValue === null) {
			return 'CONFIRMED';
		}

		return $calendarConfig['statusMapping'][$statusValue] ?? 'CONFIRMED';
	}//end resolveStatus()
}//end class
