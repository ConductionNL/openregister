<?php

/**
 * OpenRegister Virtual Calendar
 *
 * Implements ICalendar to provide a virtual calendar backed by
 * OpenRegister schema objects with date fields.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-18
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\MagicMapper;
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
class RegisterCalendar implements ICalendar
{

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
     * @var MagicMapper
     */
    private MagicMapper $magicMapper;

    /**
     * The RegisterMapper for loading registers
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

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
     * @param Schema                   $schema         The schema entity
     * @param array                    $calendarConfig The calendar configuration
     * @param MagicMapper              $magicMapper    The MagicMapper for queries
     * @param RegisterMapper           $registerMapper The RegisterMapper
     * @param CalendarEventTransformer $transformer    The event transformer
     * @param string                   $principalUri   The principal URI
     * @param LoggerInterface          $logger         Logger instance
     */
    public function __construct(
        Schema $schema,
        array $calendarConfig,
        MagicMapper $magicMapper,
        RegisterMapper $registerMapper,
        CalendarEventTransformer $transformer,
        string $principalUri,
        LoggerInterface $logger
    ) {
        $this->schema         = $schema;
        $this->calendarConfig = $calendarConfig;
        $this->magicMapper    = $magicMapper;
        $this->registerMapper = $registerMapper;
        $this->transformer    = $transformer;
        $this->principalUri   = $principalUri;
        $this->logger         = $logger;
    }//end __construct()

    /**
     * Get the unique key for this calendar
     *
     * @return string The calendar key
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    public function getKey(): string
    {
        return 'openregister-schema-'.$this->schema->getId();
    }//end getKey()

    /**
     * Get the URI for this calendar
     *
     * @return string The calendar URI
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    public function getUri(): string
    {
        return 'openregister-schema-'.$this->schema->getId();
    }//end getUri()

    /**
     * Get the display name for this calendar
     *
     * @return string|null The display name
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    public function getDisplayName(): ?string
    {
        return $this->calendarConfig['displayName'] ?? $this->schema->getTitle();
    }//end getDisplayName()

    /**
     * Get the display color for this calendar
     *
     * @return string|null The CSS hex color
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    public function getDisplayColor(): ?string
    {
        return $this->calendarConfig['color'] ?? self::DEFAULT_COLOR;
    }//end getDisplayColor()

    /**
     * Get the permissions for this calendar (read-only)
     *
     * @return int The permission bitmask
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    public function getPermissions(): int
    {
        return Constants::PERMISSION_READ;
    }//end getPermissions()

    /**
     * Check if this calendar is deleted
     *
     * @return bool Always false for virtual calendars
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    public function isDeleted(): bool
    {
        return false;
    }//end isDeleted()

    /**
     * Search for events in this virtual calendar
     *
     * Queries OpenRegister objects by date range and text pattern,
     * then transforms them into VEVENT arrays.
     *
     * @param string   $pattern          Text pattern to search for
     * @param array    $searchProperties Properties to search in
     * @param array    $options          Search options (timerange, etc.)
     * @param int|null $limit            Maximum number of results
     * @param int|null $offset           Result offset for pagination
     *
     * @return array Array of VEVENT-compatible arrays
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-18
     */
    public function search(
        string $pattern='',
        array $searchProperties=[],
        array $options=[],
        ?int $limit=null,
        ?int $offset=null
    ): array {
        try {
            // Extract user ID from principal URI for RBAC.
            $userId = $this->extractUserId(principalUri: $this->principalUri);
            if ($userId === null) {
                return [];
            }

            // Build query filters from timerange.
            $filters = $this->buildTimerangeFilters(options: $options);

            // Get all registers that use this schema.
            $registers = $this->findRegistersForSchema(schema: $this->schema);

            if (empty($registers) === true) {
                return [];
            }

            $events = [];

            foreach ($registers as $register) {
                try {
                    $objects = $this->magicMapper->findAllInRegisterSchemaTable(
                        register: $register,
                        schema: $this->schema,
                        limit: $limit,
                        offset: $offset,
                        filters: $filters
                    );

                    foreach ($objects as $object) {
                        $event = $this->transformer->transform(
                            $object,
                            $this->schema,
                            $this->calendarConfig
                        );

                        if ($event === null) {
                            continue;
                        }

                        // Apply text pattern filter on summary.
                        if ($pattern !== '' && $this->matchesPattern(event: $event, pattern: $pattern) === false) {
                            continue;
                        }

                        $events[] = $event;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                        '[RegisterCalendar] Failed to query register '.$register->getId().': '.$e->getMessage(),
                        ['exception' => $e]
                    );
                }//end try
            }//end foreach

            return $events;
        } catch (\Exception $e) {
            $this->logger->warning(
                '[RegisterCalendar] Search failed: '.$e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }//end try
    }//end search()

    /**
     * Extract user ID from a principal URI
     *
     * @param string $principalUri The principal URI (e.g., principals/users/admin)
     *
     * @return string|null The user ID or null if not a valid user principal
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    private function extractUserId(string $principalUri): ?string
    {
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
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    private function buildTimerangeFilters(array $options): ?array
    {
        if (empty($options['timerange']) === true) {
            return null;
        }

        $timerange    = $options['timerange'];
        $dtstartField = $this->calendarConfig['dtstart'] ?? null;

        if ($dtstartField === null) {
            return null;
        }

        // Use canonical operator-filter shape (`field => ['gte' => v, 'lte' => v]`)
        // — the suffix-on-key form (`'field>='`) is not recognised by the
        // magic-table search pipeline and silently filters out everything.
        $rangeOps = [];

        if (isset($timerange['start']) === true) {
            $start = $timerange['start'];
            if ($start instanceof \DateTimeInterface) {
                $start = $start->format(\DateTimeInterface::ATOM);
            }

            $rangeOps['gte'] = (string) $start;
        }

        if (isset($timerange['end']) === true) {
            $end = $timerange['end'];
            if ($end instanceof \DateTimeInterface) {
                $end = $end->format(\DateTimeInterface::ATOM);
            }

            $rangeOps['lte'] = (string) $end;
        }

        if (empty($rangeOps) === true) {
            return null;
        }

        return [$dtstartField => $rangeOps];
    }//end buildTimerangeFilters()

    /**
     * Find all registers that contain the given schema
     *
     * @param Schema $schema The schema to look for
     *
     * @return array Array of Register entities
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    private function findRegistersForSchema(Schema $schema): array
    {
        try {
            $allRegisters      = $this->registerMapper->findAll();
            $matchingRegisters = [];

            foreach ($allRegisters as $register) {
                $schemaIds = $register->getSchemas();
                if (in_array($schema->getId(), $schemaIds, false) === true
                    || in_array((string) $schema->getId(), $schemaIds, false) === true
                ) {
                    $matchingRegisters[] = $register;
                }
            }

            return $matchingRegisters;
        } catch (\Exception $e) {
            $this->logger->warning(
                '[RegisterCalendar] Failed to find registers for schema '.$schema->getId().': '.$e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }//end try
    }//end findRegistersForSchema()

    /**
     * Check if an event matches a text pattern
     *
     * @param array  $event   The VEVENT array
     * @param string $pattern The text pattern
     *
     * @return bool True if the event matches
     *
     * @spec openspec/changes/retrofit-calendar-integration-2026-04-28/tasks.md#task-1
     */
    private function matchesPattern(array $event, string $pattern): bool
    {
        if (empty($event['objects']) === true) {
            return false;
        }

        $vevent  = $event['objects'][0];
        $summary = $vevent['SUMMARY'][0] ?? '';

        return stripos($summary, $pattern) !== false;
    }//end matchesPattern()
}//end class
