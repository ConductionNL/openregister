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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCP\Calendar\ICalendar;
use OCP\Constants;
use Psr\Log\LoggerInterface;

/**
 * Virtual calendar backed by OpenRegister schema objects
 *
 * Each instance represents one calendar-enabled schema. Events are
 * read-only projections of object date fields.
 *
 * @package OCA\OpenRegister\Calendar
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RegisterCalendar implements ICalendar {

	/**
	 * Default calendar color
	 *
	 * @var string
	 */
	private const DEFAULT_COLOR = '#0082C9';

	/**
	 * The schema entity backing this calendar
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * The calendar provider configuration
	 *
	 * @var array
	 */
	private array $calendarConfig;

	/**
	 * The MagicMapper for querying objects
	 *
	 * @var MagicMapperInterface
	 */
	private MagicMapperInterface $magicMapper;

	/**
	 * The RegisterMapper for loading registers
	 *
	 * @var RegisterMapperInterface
	 */
	private RegisterMapperInterface $registerMapper;

	/**
	 * The event transformer
	 *
	 * @var CalendarEventTransformer
	 */
	private CalendarEventTransformer $transformer;

	/**
	 * The principal URI for RBAC filtering
	 *
	 * @var string
	 */
	private string $principalUri;

	/**
	 * Logger instance
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param Schema                  $schema         The schema entity
	 * @param array                   $calendarConfig The calendar configuration
	 * @param string                  $principalUri   The principal URI
	 * @param RegisterCalendarSources $sources        Object/register lookup, transformer and logger
	 */
	public function __construct(
		Schema $schema,
		array $calendarConfig,
		string $principalUri,
		RegisterCalendarSources $sources,
	) {
		$this->schema = $schema;
		$this->calendarConfig = $calendarConfig;
		$this->magicMapper = $sources->magicMapper;
		$this->registerMapper = $sources->registerMapper;
		$this->transformer = $sources->transformer;
		$this->principalUri = $principalUri;
		$this->logger = $sources->logger;
	}//end __construct()

	/**
	 * Get the unique key for this calendar
	 *
	 * @return string The calendar key
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getKey(): string {
		return 'openregister-schema-' . $this->schema->getId();
	}//end getKey()

	/**
	 * Get the URI for this calendar
	 *
	 * @return string The calendar URI
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getUri(): string {
		return 'openregister-schema-' . $this->schema->getId();
	}//end getUri()

	/**
	 * Get the display name for this calendar
	 *
	 * @return string|null The display name
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getDisplayName(): ?string {
		return $this->calendarConfig['displayName'] ?? $this->schema->getTitle();
	}//end getDisplayName()

	/**
	 * Get the display color for this calendar
	 *
	 * @return string|null The CSS hex color
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getDisplayColor(): ?string {
		return $this->calendarConfig['color'] ?? self::DEFAULT_COLOR;
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
		try {
			return $this->searchEvents(
				query: new CalendarSearchQuery(pattern: $pattern, options: $options, limit: $limit, offset: $offset)
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				'[RegisterCalendar] Search failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}//end try
	}//end search()

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

		// Build query filters from timerange.
		$filters = $this->buildTimerangeFilters(options: $query->options);

		// Get all registers that use this schema.
		$registers = $this->findRegistersForSchema(schema: $this->schema);

		if (empty($registers) === true) {
			return [];
		}

		$events = [];
		foreach ($registers as $register) {
			$this->collectRegisterEvents(register: $register, filters: $filters, query: $query, events: $events);
		}

		return $events;
	}//end searchEvents()

	/**
	 * Append the events of one register; a failing register is logged and skipped,
	 * keeping the events it produced before the failure.
	 *
	 * @param Register            $register The register.
	 * @param array|null          $filters  The timerange filters.
	 * @param CalendarSearchQuery $query    The search arguments.
	 * @param array               $events   The events collected so far, appended to.
	 *
	 * @return void
	 */
	private function collectRegisterEvents(Register $register, ?array $filters, CalendarSearchQuery $query, array &$events): void {
		try {
			$objects = $this->magicMapper->findAllInRegisterSchemaTable(
				register: $register,
				schema: $this->schema,
				limit: $query->limit,
				offset: $query->offset,
				filters: $filters
			);

			foreach ($objects as $object) {
				$this->appendEvent(object: $object, pattern: $query->pattern, events: $events);
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				'[RegisterCalendar] Failed to query register ' . $register->getId() . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end collectRegisterEvents()

	/**
	 * Transform one object and append its event when it has one and it matches the pattern.
	 *
	 * @param ObjectEntity $object  The object.
	 * @param string       $pattern The summary text pattern ('' matches all).
	 * @param array        $events  The events collected so far, appended to.
	 *
	 * @return void
	 */
	private function appendEvent(ObjectEntity $object, string $pattern, array &$events): void {
		$event = $this->transformer->transform(
			$object,
			$this->schema,
			$this->calendarConfig
		);

		if ($event === null) {
			return;
		}

		// Apply text pattern filter on summary.
		if ($pattern !== '' && $this->matchesPattern(event: $event, pattern: $pattern) === false) {
			return;
		}

		$events[] = $event;
	}//end appendEvent()

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
		$dtstartField = $this->calendarConfig['dtstart'] ?? null;

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
			$allRegisters = $this->registerMapper->findAll();
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
			$this->logger->warning(
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
