<?php

/**
 * CalendarDefinition — a schema exposed as a calendar, with its calendar configuration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use OCA\OpenRegister\Db\Schema;

/**
 * The schema whose objects become events and the calendar provider
 * configuration that says how (dtstart field, all-day mode, templates).
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final class CalendarDefinition {

	/**
	 * The schema whose objects become events
	 *
	 * @var Schema
	 */
	public readonly Schema $schema;

	/**
	 * The calendar provider configuration
	 *
	 * @var array
	 */
	public readonly array $config;

	/**
	 * Constructor
	 *
	 * @param Schema $schema The schema whose objects become events
	 * @param array  $config The calendar provider configuration
	 */
	public function __construct(Schema $schema, array $config) {
		$this->schema = $schema;
		$this->config = $config;
	}//end __construct()

	/**
	 * The name of the object field that holds the event start, if configured.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function dtstartField(): ?string {
		return $this->config['dtstart'] ?? null;
	}//end dtstartField()

	/**
	 * Whether events are all-day: the explicit `allDay` setting, else the
	 * format of the dtstart schema property (`date-time` means timed), else all-day.
	 *
	 * @return bool True if events should be all-day
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function allDay(): bool {
		// Explicit allDay setting takes precedence.
		if (isset($this->config['allDay']) === true) {
			return (bool) $this->config['allDay'];
		}

		// Auto-detect from schema property format; default: treat as all-day.
		return $this->dtstartFormat() !== 'date-time';
	}//end allDay()

	/**
	 * The first `date` or `date-time` format among the schema properties that describe the dtstart field.
	 *
	 * @return string|null
	 */
	private function dtstartFormat(): ?string {
		$field = $this->dtstartField();
		if ($field === null) {
			return null;
		}

		foreach ($this->propertiesDescribing(field: $field) as $propDef) {
			$format = $this->dateFormatOf(propDef: $propDef);
			if ($format !== null) {
				return $format;
			}
		}

		return null;
	}//end dtstartFormat()

	/**
	 * The schema property definitions that describe a field, by name or by title, in schema order.
	 *
	 * @param string $field The field name.
	 *
	 * @return array<int, mixed> The property definitions.
	 */
	private function propertiesDescribing(string $field): array {
		$described = [];
		foreach (($this->schema->getProperties() ?? []) as $propName => $propDef) {
			if ($propName === $field || ($propDef['title'] ?? null) === $field) {
				$described[] = $propDef;
			}
		}

		return $described;
	}//end propertiesDescribing()

	/**
	 * The `date` / `date-time` format of a property definition, null for anything else.
	 *
	 * @param mixed $propDef The property definition
	 *
	 * @return string|null
	 */
	private function dateFormatOf(mixed $propDef): ?string {
		if (is_array($propDef) === false) {
			return null;
		}

		$format = $propDef['format'] ?? null;
		if ($format === 'date' || $format === 'date-time') {
			return $format;
		}

		return null;
	}//end dateFormatOf()
}//end class
