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
use OCA\OpenRegister\Db\Schema;

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
	 * @param ObjectEntity $object The object to transform
	 * @param Schema $schema The schema this object belongs to
	 * @param array $calendarConfig The calendar provider configuration
	 *
	 * @return array|null The VEVENT array, or null if the object lacks required date data
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function transform(
		ObjectEntity $object,
		Schema $schema,
		array $calendarConfig,
	): ?array {
		$objectData = $object->getObject();
		$dtstartField = $calendarConfig['dtstart'] ?? null;

		if ($dtstartField === null) {
			return null;
		}

		$dtstartValue = $objectData[$dtstartField] ?? null;

		if (empty($dtstartValue) === true) {
			return null;
		}

		$schemaId = $schema->getId();
		$objectUuid = $object->getUuid();
		$uid = 'openregister-' . $schemaId . '-' . $objectUuid;
		$calendarKey = 'openregister-schema-' . $schemaId;

		// Determine allDay mode.
		$allDay = $this->determineAllDay(calendarConfig: $calendarConfig, schema: $schema, dtstartField: $dtstartField);

		// Build DTSTART.
		$dtstart = $this->formatDateValue(value: $dtstartValue, allDay: $allDay);

		// Build DTEND.
		$dtend = $this->buildDtend(
			objectData: $objectData,
			calendarConfig: $calendarConfig,
			dtstartValue: $dtstartValue,
			allDay: $allDay
		);

		// Interpolate title.
		$summary = $this->interpolateTemplate(
			template: $calendarConfig['titleTemplate'] ?? $objectUuid,
			objectData: $objectData
		);

		// Build the VEVENT objects array.
		$veventProperties = [
			'UID' => [$uid, []],
			'SUMMARY' => [$summary, []],
			'DTSTART' => $dtstart,
			'DTEND' => $dtend,
			'STATUS' => [$this->resolveStatus(objectData: $objectData, calendarConfig: $calendarConfig), []],
			'TRANSP' => ['TRANSPARENT', []],
			'CATEGORIES' => [['OpenRegister', $schema->getTitle() ?? 'Schema'], []],
		];

		// Optional description.
		if (empty($calendarConfig['descriptionTemplate']) === false) {
			$description = $this->interpolateTemplate(
				template: $calendarConfig['descriptionTemplate'],
				objectData: $objectData
			);
			$veventProperties['DESCRIPTION'] = [$description, []];
		}

		// Optional location.
		if (empty($calendarConfig['locationField']) === false) {
			$locationValue = $objectData[$calendarConfig['locationField']] ?? null;
			if (empty($locationValue) === false) {
				$veventProperties['LOCATION'] = [$locationValue, []];
			}
		}

		// URL to OpenRegister object.
		$register = $object->getRegister();
		$url = '/apps/openregister/objects/' . $register . '/' . $schemaId . '/' . $objectUuid;
		$veventProperties['URL'] = [$url, []];

		return [
			'id' => $uid,
			'type' => 'VEVENT',
			'calendar-key' => $calendarKey,
			'calendar-uri' => $calendarKey,
			'objects' => [
				$veventProperties,
			],
		];
	}//end transform()

	/**
	 * Determine if events should be all-day based on config and schema property format
	 *
	 * @param array $calendarConfig The calendar configuration
	 * @param Schema $schema The schema entity
	 * @param string $dtstartField The dtstart field name
	 *
	 * @return bool True if events should be all-day
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function determineAllDay(array $calendarConfig, Schema $schema, string $dtstartField): bool {
		// Explicit allDay setting takes precedence.
		if (isset($calendarConfig['allDay']) === true) {
			return (bool)$calendarConfig['allDay'];
		}

		// Auto-detect from schema property format.
		$properties = $schema->getProperties() ?? [];
		foreach ($properties as $propName => $propDef) {
			if (is_array($propDef) === true
				&& ($propName === $dtstartField || ($propDef['title'] ?? null) === $dtstartField)
			) {
				$format = $propDef['format'] ?? null;
				if ($format === 'date') {
					return true;
				}

				if ($format === 'date-time') {
					return false;
				}
			}
		}

		// Default: treat as all-day.
		return true;
	}//end determineAllDay()

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
			// Malformed input: fall back to a safe placeholder so the
			// VEVENT is still serialisable; consumers will treat it as
			// a naive UTC value.
			$fallback = new DateTime('@0');
			if ($allDay === true) {
				return [$fallback->format('Ymd'), ['VALUE' => 'DATE']];
			}

			return [$fallback->format('Ymd\THis\Z'), ['VALUE' => 'DATE-TIME']];
		}

		if ($allDay === true) {
			return [$date->format('Ymd'), ['VALUE' => 'DATE']];
		}

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
			$tzName = $date->getTimezone()->getName();
			return [
				$date->format('Ymd\THis'),
				[
					'VALUE' => 'DATE-TIME',
					'TZID' => $tzName,
				],
			];
		}

		// Non-UTC numeric offset: convert to the equivalent UTC instant
		// and emit `Z`. This fixes the previous behaviour where a
		// literal `Z` was appended to a non-UTC wall-clock, silently
		// mis-stating the event's actual moment.
		$utcDate = clone $date;
		$utcDate->setTimezone(new DateTimeZone('UTC'));
		return [$utcDate->format('Ymd\THis\Z'), ['VALUE' => 'DATE-TIME']];
	}//end formatDateValue()

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
	 * @param array $objectData The object data
	 * @param array $calendarConfig The calendar configuration
	 * @param string $dtstartValue The DTSTART raw value
	 * @param bool $allDay Whether this is an all-day event
	 *
	 * @return array The formatted [value, params] array for DTEND
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function buildDtend(
		array $objectData,
		array $calendarConfig,
		string $dtstartValue,
		bool $allDay,
	): array {
		// Check if dtend field is configured and has a value.
		if (empty($calendarConfig['dtend']) === false) {
			$dtendValue = $objectData[$calendarConfig['dtend']] ?? null;
			if (empty($dtendValue) === false) {
				return $this->formatDateValue(value: $dtendValue, allDay: $allDay);
			}
		}

		// Compute default DTEND from DTSTART. We round-trip through the
		// ISO-8601 representation so `formatDateValue()` re-detects the
		// source timezone and emits a TZID-correct value (DTEND must
		// not silently flip to UTC `Z` when DTSTART carried an offset).
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
	}//end buildDtend()

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
