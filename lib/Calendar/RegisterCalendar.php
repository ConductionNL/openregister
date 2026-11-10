<?php

/**
 * OpenRegister Virtual Calendar
 *
 * Implements ICalendar to provide a virtual calendar backed by
 * OpenRegister schema objects with date fields.
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

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCP\Calendar\ICalendar;
use OCP\Constants;

/**
 * Virtual calendar backed by OpenRegister schema objects
 *
 * Each instance represents one calendar-enabled schema. Events are
 * read-only projections of object date fields.
 *
 * @package OCA\OpenRegister\Calendar
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
class RegisterCalendar implements ICalendar {

	/**
	 * Default calendar color
	 *
	 * @var string
	 */
	private const DEFAULT_COLOR = '#0082C9';

	/**
	 * Constructor
	 *
	 * @param CalendarDefinition      $definition   The schema and its calendar configuration
	 * @param string                  $principalUri The principal URI for RBAC filtering
	 * @param RegisterCalendarSources $sources      Register/object lookup, transformer and logger
	 */
	public function __construct(
		private readonly CalendarDefinition $definition,
		private readonly string $principalUri,
		private readonly RegisterCalendarSources $sources,
	) {
	}//end __construct()

	/**
	 * Get the unique key for this calendar
	 *
	 * @return string The calendar key
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getKey(): string {
		return 'openregister-schema-' . $this->definition->schema->getId();
	}//end getKey()

	/**
	 * Get the URI for this calendar
	 *
	 * @return string The calendar URI
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getUri(): string {
		return 'openregister-schema-' . $this->definition->schema->getId();
	}//end getUri()

	/**
	 * Get the display name for this calendar
	 *
	 * @return string|null The display name
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getDisplayName(): ?string {
		return $this->definition->config['displayName'] ?? $this->definition->schema->getTitle();
	}//end getDisplayName()

	/**
	 * Get the display color for this calendar
	 *
	 * @return string|null The CSS hex color
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getDisplayColor(): ?string {
		return $this->definition->config['color'] ?? self::DEFAULT_COLOR;
	}//end getDisplayColor()

	/**
	 * Get the permissions for this calendar (read-only)
	 *
	 * @return int The permission bitmask
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getPermissions(): int {
		return Constants::PERMISSION_READ;
	}//end getPermissions()

	/**
	 * Check if this calendar is deleted
	 *
	 * @return bool Always false for virtual calendars
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function isDeleted(): bool {
		return false;
	}//end isDeleted()

	/**
	 * Search for events in this virtual calendar
	 *
	 * Queries OpenRegister objects by date range and text pattern,
	 * then transforms them into VEVENT arrays.
	 *
	 * @param string $pattern Text pattern to search for
	 * @param array $searchProperties Properties to search in
	 * @param array $options Search options (timerange, etc.)
	 * @param int|null $limit Maximum number of results
	 * @param int|null $offset Result offset for pagination
	 *
	 * @return array Array of VEVENT-compatible arrays
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function search(
		string $pattern = '',
		array $searchProperties = [],
		array $options = [],
		?int $limit = null,
		?int $offset = null,
	): array {
		return $this->searchOrEmpty(
			query: new CalendarSearchQuery(pattern: $pattern, options: $options, limit: $limit, offset: $offset)
		);
	}//end search()

	/**
	 * Run a search, logging a failure and answering it with no events.
	 *
	 * @param CalendarSearchQuery $query The search arguments.
	 *
	 * @return array Array of VEVENT-compatible arrays
	 */
	private function searchOrEmpty(CalendarSearchQuery $query): array {
		try {
			return $this->searchEvents(query: $query);
		} catch (\Exception $e) {
			$this->sources->logger->warning(
				'[RegisterCalendar] Search failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}//end try
	}//end searchOrEmpty()

	/**
	 * Run a search: refuse a principal that is not a user, then collect the
	 * events of every register that uses this schema.
	 *
	 * @param CalendarSearchQuery $query The search arguments.
	 *
	 * @return array Array of VEVENT-compatible arrays
	 */
	private function searchEvents(CalendarSearchQuery $query): array {
		// Extract user ID from principal URI for RBAC.
		$userId = $this->extractUserId(principalUri: $this->principalUri);
		if ($userId === null) {
			return [];
		}

		// Get all registers that use this schema.
		$registers = $this->findRegistersForSchema(schema: $this->definition->schema);

		if (empty($registers) === true) {
			return [];
		}

		$events = [];
		foreach ($registers as $register) {
			$events = array_merge($events, $this->registerEvents(register: $register, query: $query));
		}

		return $events;
	}//end searchEvents()

	/**
	 * The events of one register; a failing register is logged and skipped,
	 * keeping the events it produced before the failure.
	 *
	 * @param Register            $register The register.
	 * @param CalendarSearchQuery $query    The search arguments (timerange, pattern, paging).
	 *
	 * @return array The register's events.
	 */
	private function registerEvents(Register $register, CalendarSearchQuery $query): array {
		$events = [];
		$filters = $this->buildTimerangeFilters(options: $query->options);
		try {
			$objects = $this->sources->catalog->tables()->findAllInRegisterSchemaTable(
				table: new RegisterSchema(register: $register, schema: $this->definition->schema),
				listing: new ListQuery(limit: ($query->limit ?? ListQuery::UNBOUNDED), offset: $query->offset, filters: $filters)
			);

			foreach ($objects as $object) {
				$event = $this->matchingEvent(object: $object, pattern: $query->pattern);
				if ($event !== null) {
					$events[] = $event;
				}
			}
		} catch (\Exception $e) {
			$this->sources->logger->warning(
				'[RegisterCalendar] Failed to query register ' . $register->getId() . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

		return $events;
	}//end registerEvents()

	/**
	 * Transform one object; its event when it has one and it matches the pattern, else null.
	 *
	 * @param ObjectEntity $object  The object.
	 * @param string       $pattern The summary text pattern ('' matches all).
	 *
	 * @return array|null
	 */
	private function matchingEvent(ObjectEntity $object, string $pattern): ?array {
		$event = $this->sources->transformer->transform($object, $this->definition);

		if ($event === null) {
			return null;
		}

		// Apply text pattern filter on summary.
		if ($pattern !== '' && $this->matchesPattern(event: $event, pattern: $pattern) === false) {
			return null;
		}

		return $event;
	}//end matchingEvent()

	/**
	 * Extract user ID from a principal URI
	 *
	 * @param string $principalUri The principal URI (e.g., principals/users/admin)
	 *
	 * @return string|null The user ID or null if not a valid user principal
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function extractUserId(string $principalUri): ?string {
		if (preg_match('/^principals\/users\/(.+)$/', $principalUri, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}//end extractUserId()

	/**
	 * Build MagicMapper query filters from calendar search timerange options
	 *
	 * @param array $options The search options
	 *
	 * @return array|null The filters array, or null if no timerange
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function buildTimerangeFilters(array $options): ?array {
		if (empty($options['timerange']) === true) {
			return null;
		}

		$timerange = $options['timerange'];
		$dtstartField = $this->definition->config['dtstart'] ?? null;

		if ($dtstartField === null) {
			return null;
		}

		// Use canonical operator-filter shape (`field => ['gte' => v, 'lte' => v]`)
		// — the suffix-on-key form (`'field>='`) is not recognised by the
		// magic-table search pipeline and silently filters out everything.
		$rangeOps = $this->rangeOps(timerange: $timerange);

		if (empty($rangeOps) === true) {
			return null;
		}

		return [$dtstartField => $rangeOps];
	}//end buildTimerangeFilters()

	/**
	 * The `gte` / `lte` operators for the set `start` / `end` of a timerange.
	 *
	 * @param mixed $timerange The timerange option.
	 *
	 * @return array<string, string>
	 */
	private function rangeOps(mixed $timerange): array {
		$rangeOps = [];
		foreach (['start' => 'gte', 'end' => 'lte'] as $bound => $operator) {
			if (isset($timerange[$bound]) === true) {
				$rangeOps[$operator] = $this->boundValue(value: $timerange[$bound]);
			}
		}

		return $rangeOps;
	}//end rangeOps()

	/**
	 * A timerange bound as text: a date as ATOM, anything else cast to string.
	 *
	 * @param mixed $value The bound.
	 *
	 * @return string
	 */
	private function boundValue(mixed $value): string {
		if ($value instanceof \DateTimeInterface) {
			return $value->format(\DateTimeInterface::ATOM);
		}

		return (string)$value;
	}//end boundValue()

	/**
	 * Find all registers that contain the given schema
	 *
	 * @param Schema $schema The schema to look for
	 *
	 * @return array Array of Register entities
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function findRegistersForSchema(Schema $schema): array {
		try {
			$allRegisters = $this->sources->catalog->registers()->findAll();
			$matchingRegisters = [];

			foreach ($allRegisters as $register) {
				$schemaIds = $register->getSchemas();
				if (in_array($schema->getId(), $schemaIds, false) === true
					|| in_array((string)$schema->getId(), $schemaIds, false) === true
				) {
					$matchingRegisters[] = $register;
				}
			}

			return $matchingRegisters;
		} catch (\Exception $e) {
			$this->sources->logger->warning(
				'[RegisterCalendar] Failed to find registers for schema ' . $schema->getId() . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}//end try
	}//end findRegistersForSchema()

	/**
	 * Check if an event matches a text pattern
	 *
	 * @param array $event The VEVENT array
	 * @param string $pattern The text pattern
	 *
	 * @return bool True if the event matches
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function matchesPattern(array $event, string $pattern): bool {
		if (empty($event['objects']) === true) {
			return false;
		}

		$vevent = $event['objects'][0];
		$summary = $vevent['SUMMARY'][0] ?? '';

		return stripos($summary, $pattern) !== false;
	}//end matchesPattern()
}//end class
