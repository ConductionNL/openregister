<?php

/**
 * OpenRegister Export Service
 *
 * This file contains the class for handling data export operations in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use React\Async\PromiseInterface;
use React\Promise\Promise;
use React\EventLoop\Loop;

/**
 * Service for exporting data to various formats
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ExportService
{

    /**
     * Register mapper instance
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Group manager for checking admin group membership
     *
     * @var IGroupManager
     */
    private readonly IGroupManager $groupManager;

    /**
     * Object service for optimized object operations
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Cache handler for UUID-to-name resolution
     *
     * @var CacheHandler
     */
    private readonly CacheHandler $cacheHandler;

    /**
     * Property RBAC handler for property-level authorization checks
     *
     * @var PropertyRbacHandler
     */
    private readonly PropertyRbacHandler $propertyRbacHandler;

    /**
     * Constructor for the ExportService
     *
     * @param RegisterMapper      $registerMapper      The register mapper
     * @param IUserManager        $_userManager        The user manager (unused but kept for future use)
     * @param IGroupManager       $groupManager        The group manager
     * @param ObjectService       $objectService       The object service
     * @param CacheHandler        $cacheHandler        The cache handler for name resolution
     * @param PropertyRbacHandler $propertyRbacHandler The property RBAC handler
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        RegisterMapper $registerMapper,
        IUserManager $_userManager,
        IGroupManager $groupManager,
        ObjectService $objectService,
        CacheHandler $cacheHandler,
        PropertyRbacHandler $propertyRbacHandler
    ) {
        $this->registerMapper      = $registerMapper;
        $this->groupManager        = $groupManager;
        $this->objectService       = $objectService;
        $this->cacheHandler        = $cacheHandler;
        $this->propertyRbacHandler = $propertyRbacHandler;
    }//end __construct()

    /**
     * Check if the given user is in the admin group
     *
     * @param IUser|null $user The user to check (null means anonymous/no user)
     *
     * @return bool True if user is admin, false otherwise
     */
    private function isUserAdmin(?IUser $user): bool
    {
        if ($user === null) {
            return false;
            // Anonymous users are never admin.
        }

        // Check if user is in admin group.
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            return false;
            // Admin group doesn't exist.
        }

        return $adminGroup->inGroup($user);
    }//end isUserAdmin()

    /**
     * Export data to Excel format
     *
     * @param Register|null $register    Optional register to export
     * @param Schema|null   $schema      Optional schema to export
     * @param array         $filters     Optional filters to apply
     * @param IUser|null    $currentUser Current user for permission checks
     *
     * @return Spreadsheet
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Export requires handling multiple input combinations
     */
    public function exportToExcel(
        ?Register $register=null,
        ?Schema $schema=null,
        array $filters=[],
        ?IUser $currentUser=null
    ): Spreadsheet {
        // Create new spreadsheet.
        $spreadsheet = new Spreadsheet();

        // Remove default sheet.
        $spreadsheet->removeSheetByIndex(0);

        if ($register !== null && $schema === null) {
            // Export all schemas in register.
            $schemas = $this->getSchemasForRegister(register: $register);
            foreach ($schemas as $schema) {
                $this->populateSheet(
                    spreadsheet: $spreadsheet,
                    register: $register,
                    schema: $schema,
                    filters: $filters,
                    currentUser: $currentUser
                );
            }

            return $spreadsheet;
        }

        // Export single schema.
        $this->populateSheet(
            spreadsheet: $spreadsheet,
            register: $register,
            schema: $schema,
            filters: $filters,
            currentUser: $currentUser
        );

        return $spreadsheet;
    }//end exportToExcel()

    /**
     * Export data to CSV format
     *
     * @param Register|null $register    Optional register to export
     * @param Schema|null   $schema      Optional schema to export
     * @param array         $filters     Optional filters to apply
     * @param IUser|null    $currentUser Current user for permission checks
     *
     * @return string CSV content
     *
     * @throws \InvalidArgumentException If trying to export multiple schemas to CSV
     */
    public function exportToCsv(
        ?Register $register=null,
        ?Schema $schema=null,
        array $filters=[],
        ?IUser $currentUser=null
    ): string {
        if ($register !== null && $schema === null) {
            throw new InvalidArgumentException('Cannot export multiple schemas to CSV format.');
        }

        $spreadsheet = $this->exportToExcel(
            register: $register,
            schema: $schema,
            filters: $filters,
            currentUser: $currentUser
        );
        $writer      = new Csv($spreadsheet);

        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }//end exportToCsv()

    /**
     * Populate a worksheet with data
     *
     * Uses a two-pass approach for optimal UUID-to-name resolution:
     * 1. First pass: collect all UUIDs from relation columns across all objects
     * 2. One bulk CacheHandler::getMultipleObjectNames() call
     * 3. Second pass: populate the sheet with data and resolved names
     *
     * @param Spreadsheet   $spreadsheet The spreadsheet to populate
     * @param Register|null $register    Optional register to export
     * @param Schema|null   $schema      Optional schema to export
     * @param array         $filters     Optional filters to apply
     * @param IUser|null    $currentUser Current user for permission checks
     *
     * @return void
     */
    private function populateSheet(
        Spreadsheet $spreadsheet,
        ?Register $register=null,
        ?Schema $schema=null,
        array $filters=[],
        ?IUser $currentUser=null
    ): void {
        $sheet = $spreadsheet->createSheet();

        $sheetTitle = 'data';
        if ($schema !== null) {
            $sheetTitle = $schema->getSlug();
        }

        $sheet->setTitle($sheetTitle);

        $headers = $this->getHeaders(schema: $schema, currentUser: $currentUser);
        $row     = 1;

        // Set headers.
        foreach ($headers as $col => $header) {
            $sheet->setCellValue(coordinate: $col.$row, value: $header);
        }

        $row++;

        // Query all matching objects.
        $objects = $this->fetchObjectsForExport(register: $register, schema: $schema, filters: $filters);

        // Identify which headers are name-companion columns (prefixed with _).
        $nameColumns = $this->identifyNameCompanionColumns(headers: $headers);

        // Bulk resolve UUIDs to names if there are relation columns.
        $uuidToNameMap = $this->resolveUuidNameMap(objects: $objects, nameColumns: $nameColumns);

        // Populate the sheet with data and resolved names.
        $this->writeObjectRows(
            sheet: $sheet,
            objects: $objects,
            headers: $headers,
            nameColumns: $nameColumns,
            uuidToNameMap: $uuidToNameMap,
            startRow: $row
        );
    }//end populateSheet()

    /**
     * Fetch all objects matching the given register, schema and filters for export.
     *
     * Builds the query with RBAC, multi-tenancy and metadata filters, then returns
     * the full result set (high limit, no pagination).
     *
     * @param Register|null $register Optional register to filter by.
     * @param Schema|null   $schema   Optional schema to filter by.
     * @param array         $filters  Additional filters from the request.
     *
     * @return ObjectEntity[] Array of matching object entities.
     */
    private function fetchObjectsForExport(?Register $register, ?Schema $schema, array $filters): array
    {
        // Build filters for MagicMapper->findAll() method.
        $objectFilters = [];

        if ($register !== null) {
            $objectFilters['register'] = $register->getId();
        }

        if ($schema !== null) {
            $objectFilters['schema'] = $schema->getId();
        }

        // Apply additional filters.
        foreach ($filters as $key => $value) {
            if (str_starts_with($key, '@self.') === false) {
                // These are JSON object property filters - not supported by findAll.
                // For now, we'll skip them to get basic functionality working.
                // TODO: Add support for JSON property filtering in MagicMapper.
                continue;
            }

            // Metadata filter - remove @self. prefix.
            $metaField = substr($key, 6);
            $objectFilters[$metaField] = $value;
        }

        // Check if multitenancy was explicitly requested via _multi parameter.
        $multiExplicitlySet = isset($filters['_multi']) || isset($filters['multi']);
        $multitenancy       = true;
        if (isset($filters['_multi']) === true) {
            $multitenancy = filter_var($filters['_multi'], FILTER_VALIDATE_BOOLEAN);
        } else if (isset($filters['multi']) === true) {
            $multitenancy = filter_var($filters['multi'], FILTER_VALIDATE_BOOLEAN);
        }

        // Use ObjectService::searchObjects directly with proper RBAC and multi-tenancy filtering.
        // Set a very high limit to get all objects (export needs all data).
        $query = [
            '@self'                  => $objectFilters,
            '_limit'                 => 999999,
            // Very high limit to get all objects.
            '_includeDeleted'        => false,
            '_multitenancy_explicit' => $multiExplicitlySet,
        ];

        return $this->objectService->searchObjects(
            query: $query,
            _rbac: true,
            // Apply RBAC filtering.
            _multitenancy: $multitenancy,
            // Apply multi-tenancy filtering (respects explicit _multi parameter).
            ids: null,
            uses: null
        );
    }//end fetchObjectsForExport()

    /**
     * Identify which header columns are name-companion columns (prefixed with _).
     *
     * @param array $headers The header map keyed by column letter.
     *
     * @return array Map of column letter to source property name for companion columns.
     */
    private function identifyNameCompanionColumns(array $headers): array
    {
        $nameColumns = [];
        foreach ($headers as $col => $header) {
            if (str_starts_with($header, '_') === true && str_starts_with($header, '@') === false) {
                // This is a companion name column; the source property is the header without the _ prefix.
                $nameColumns[$col] = substr($header, 1);
            }
        }

        return $nameColumns;
    }//end identifyNameCompanionColumns()

    /**
     * Bulk resolve UUIDs to human-readable names for relation columns.
     *
     * Pre-seeds the map from already-loaded objects, collects all referenced UUIDs
     * from relation columns, and resolves any remaining via the cache handler.
     *
     * @param ObjectEntity[] $objects     The full set of exported objects.
     * @param array          $nameColumns Map of column letter to source property name.
     *
     * @return array Map of UUID string to human-readable name.
     */
    private function resolveUuidNameMap(array $objects, array $nameColumns): array
    {
        if (empty($nameColumns) === true) {
            return [];
        }

        $uuidToNameMap = [];

        // Pre-seed name map from already-loaded objects (saves DB lookups for self-references).
        foreach ($objects as $object) {
            $uuid = $object->getUuid();
            $name = $object->getName();
            if ($uuid !== null && $name !== null) {
                $uuidToNameMap[$uuid] = $name;
            }
        }

        // Collect all UUIDs from relation columns across all objects.
        $allUuids = [];
        foreach ($objects as $object) {
            $objectData = $object->getObject();
            foreach ($nameColumns as $sourceProperty) {
                $value = $objectData[$sourceProperty] ?? null;
                if ($value === null) {
                    continue;
                }

                $this->collectUuids(value: $value, allUuids: $allUuids);
            }
        }

        // Only resolve UUIDs not already in the pre-seeded map.
        $uniqueUuids   = array_unique($allUuids);
        $externalUuids = array_diff($uniqueUuids, array_keys($uuidToNameMap));

        if (empty($externalUuids) === false) {
            $externalNames = $this->cacheHandler->getMultipleObjectNames(array_values($externalUuids));
            $uuidToNameMap = array_merge($uuidToNameMap, $externalNames);
        }

        return $uuidToNameMap;
    }//end resolveUuidNameMap()

    /**
     * Write object data rows to the spreadsheet.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet to populate.
     * @param ObjectEntity[]                                $objects       The objects to write.
     * @param array                                         $headers       Header map keyed by column letter.
     * @param array                                         $nameColumns   Map of companion name columns.
     * @param array                                         $uuidToNameMap Map of UUID to human-readable name.
     * @param int                                           $startRow      The first data row number.
     *
     * @return void
     */
    private function writeObjectRows(
        $sheet,
        array $objects,
        array $headers,
        array $nameColumns,
        array $uuidToNameMap,
        int $startRow
    ): void {
        $row = $startRow;

        foreach ($objects as $object) {
            $objectData = $object->getObject();

            foreach ($headers as $col => $header) {
                if (isset($nameColumns[$col]) === true) {
                    // This is a companion name column — resolve UUIDs to names.
                    $sourceProperty = $nameColumns[$col];
                    $value          = $objectData[$sourceProperty] ?? null;
                    $sheet->setCellValue(
                        coordinate: $col.$row,
                        value: $this->resolveUuidsToNames(value: $value, uuidToNameMap: $uuidToNameMap)
                    );
                } else {
                    $value = $this->getObjectValue(object: $object, header: $header);
                    $sheet->setCellValue(coordinate: $col.$row, value: $value);
                }
            }

            $row++;
        }
    }//end writeObjectRows()

    /**
     * Get headers for export
     *
     * Detects relation properties (containing UUIDs) from the schema and inserts
     * companion _propertyName columns immediately after each relation column.
     * These companion columns will contain human-readable names resolved from UUIDs.
     *
     * @param Schema|null $schema      Optional schema to export
     * @param IUser|null  $currentUser Current user for permission checks
     *
     * @return (int|string)[]
     *
     * @psalm-return array<array-key>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Header generation has multiple schema and permission conditions
     */
    private function getHeaders(?Schema $schema=null, ?IUser $currentUser=null): array
    {
        // Start with id as the first column.
        // Will contain the uuid.
        $headers = [
            'A' => 'id',
        ];

        // Initialize column pointer before conditional usage.
        $col = 'B';

        // Add schema fields from the schema properties.
        if ($schema !== null) {
            $properties = $schema->getProperties();

            // Sort properties by their order in the schema.
            foreach (array_keys($properties) as $fieldName) {
                // Skip fields that are already in the default headers.
                if (in_array($fieldName, ['id', 'uuid', 'uri', 'register', 'schema', 'created', 'updated']) === true) {
                    continue;
                }

                // Skip properties that are hidden on collection views.
                if (($properties[$fieldName]['hideOnCollection'] ?? false) === true) {
                    continue;
                }

                // Skip properties explicitly marked as not visible.
                if (isset($properties[$fieldName]['visible']) === true
                    && $properties[$fieldName]['visible'] === false
                ) {
                    continue;
                }

                // Skip properties restricted by authorization rules the current user doesn't satisfy.
                // Uses PropertyRbacHandler (the single source of truth for property-level RBAC).
                // Empty object array causes conditional match rules to fail-closed (safe default for headers).
                if ($this->propertyRbacHandler->canReadProperty(
                    schema: $schema,
                    property: $fieldName,
                    object: []
                ) === false
                ) {
                    continue;
                }

                // Always use the property key as the header to ensure consistent data access.
                $headers[$col] = $fieldName;
                $col++;

                // Insert companion _name column if this property contains UUID references.
                if ($this->isRelationProperty(property: $properties[$fieldName]) === true) {
                    $headers[$col] = '_'.$fieldName;
                    $col++;
                }
            }//end foreach
        }//end if

        // REQUIREMENT: Add @self metadata fields only if user is admin.
        if ($this->isUserAdmin(user: $currentUser) === true) {
            $metadataFields = [
                'created',
                'updated',
                'deleted',
                'locked',
                'owner',
                'organisation',
                'application',
                'folder',
                'size',
                'version',
                'schemaVersion',
                'uri',
                'register',
                'schema',
                'name',
                'description',
                'validation',
                'geo',
                'retention',
                'authorization',
                'groups',
            ];

            foreach ($metadataFields as $field) {
                $headers[$col] = '@self.'.$field;
                $col++;
            }
        }//end if

        return $headers;
    }//end getHeaders()

    /**
     * Get value from object for given header
     *
     * @param ObjectEntity $object The object to get value from
     * @param string       $header The header to get value for
     *
     * @return string|null
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complex multi-step value extraction logic
     * @SuppressWarnings(PHPMD.NPathComplexity)       Value extraction requires many conditional type checks
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple header prefix and value type conditions
     */
    private function getObjectValue(ObjectEntity $object, string $header): ?string
    {
        // Handle metadata fields with @self. prefix.
        if (str_starts_with(haystack: $header, needle: '@self.') === true) {
            // Remove the @self. prefix (6 characters).
            $fieldName = substr(string: $header, offset: 6);

            // Get the object array which contains all metadata.
            $objectArray = $object->getObjectArray();

            // Check if the field exists in the object array.
            if (($objectArray[$fieldName] ?? null) !== null) {
                $value = $objectArray[$fieldName];

                // Handle DateTime objects (they come as ISO strings from getObjectArray).
                if (is_string($value) === true
                    && str_contains(haystack: $value, needle: 'T') === true
                    && str_contains(haystack: $value, needle: 'Z') === true
                ) {
                    // Convert ISO 8601 to our preferred format.
                    try {
                        $date = new DateTime($value);
                        return $date->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        // Return as-is if parsing fails.
                        return $value;
                    }
                }

                // Handle arrays and objects.
                if (is_array($value) === true || is_object($value) === true) {
                    return $this->convertValueToString(value: $value);
                }

                // Handle scalar values.
                if ($value !== null) {
                    return (string) $value;
                }

                return null;
            }//end if

            // Fallback for fields that might not exist.
            return null;
        }//end if

        // Handle legacy metadata fields with _ prefix for backward compatibility.
        if (str_starts_with(haystack: $header, needle: '_') === true) {
            // Remove the _ prefix.
            $fieldName = substr(string: $header, offset: 1);

            // Get the object array which contains all metadata.
            $objectArray = $object->getObjectArray();

            // Check if the field exists in the object array.
            if (($objectArray[$fieldName] ?? null) !== null) {
                $value = $objectArray[$fieldName];

                // Handle DateTime objects (they come as ISO strings from getObjectArray).
                if (is_string($value) === true
                    && str_contains(haystack: $value, needle: 'T') === true
                    && str_contains(haystack: $value, needle: 'Z') === true
                ) {
                    // Convert ISO 8601 to our preferred format.
                    try {
                        $date = new DateTime($value);
                        return $date->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        // Return as-is if parsing fails.
                        return $value;
                    }
                }

                // Handle arrays and objects.
                if (is_array($value) === true || is_object($value) === true) {
                    return $this->convertValueToString(value: $value);
                }

                // Handle scalar values.
                if ($value !== null) {
                    return (string) $value;
                }

                return null;
            }//end if

            // Fallback for fields that might not exist.
            return null;
        }//end if

        // Handle regular fields.
        switch ($header) {
            case 'id':
                // Return uuid for id column.
                return $object->getUuid();
            default:
                // Get value from object data and convert to string.
                $objectData = $object->getObject();
                $value      = $objectData[$header] ?? null;
                return $this->convertValueToString(value: $value);
        }
    }//end getObjectValue()

    /**
     * Convert a value to a string representation
     *
     * @param mixed $value The value to convert
     *
     * @return string|null
     */
    private function convertValueToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value) === true) {
            return (string) $value;
        }

        if (is_array($value) === true) {
            // Convert array to JSON string.
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_object($value) === true) {
            if (method_exists(object_or_class: $value, method: '__toString') === true) {
                return (string) $value;
            }

            // Convert object to JSON string.
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Fallback for any other type.
        return (string) $value;
    }//end convertValueToString()

    /**
     * Check if a schema property contains UUID references to other objects
     *
     * Detects relation properties by checking for:
     * - format: 'uuid' (single UUID reference)
     * - $ref field (JSON Schema reference to another schema)
     * - Array items with format: 'uuid' or $ref (array of UUID references)
     *
     * @param array $property The schema property definition
     *
     * @return bool True if the property contains UUID references
     */
    private function isRelationProperty(array $property): bool
    {
        $format = $property['format'] ?? '';
        $ref    = $property['$ref'] ?? '';
        $type   = $property['type'] ?? '';

        // Single UUID reference: format is 'uuid' or has a non-empty $ref.
        if ($format === 'uuid' || (empty($ref) === false)) {
            return true;
        }

        // Array of UUID references: items have format 'uuid' or non-empty $ref.
        if ($type === 'array' && isset($property['items']) === true) {
            $items      = $property['items'];
            $itemFormat = $items['format'] ?? '';
            $itemRef    = $items['$ref'] ?? '';

            if ($itemFormat === 'uuid' || (empty($itemRef) === false)) {
                return true;
            }
        }

        return false;
    }//end isRelationProperty()

    /**
     * Collect UUIDs from a property value into a flat array
     *
     * Handles both single UUID strings and arrays/JSON arrays of UUIDs.
     *
     * @param mixed $value    The property value (string, array, or JSON string).
     * @param array $allUuids The array to collect UUIDs into (passed by reference).
     *
     * @return void
     */
    private function collectUuids(mixed $value, array &$allUuids): void
    {
        if (is_string($value) === true) {
            // Try to decode as JSON array first.
            $decoded = json_decode($value, true);
            if (is_array($decoded) === true) {
                foreach ($decoded as $item) {
                    if (is_string($item) === true && empty($item) === false) {
                        $allUuids[] = $item;
                    }
                }

                return;
            }

            // Single UUID string.
            if (empty($value) === false) {
                $allUuids[] = $value;
            }

            return;
        }

        if (is_array($value) === true) {
            foreach ($value as $item) {
                if (is_string($item) === true && empty($item) === false) {
                    $allUuids[] = $item;
                }
            }
        }
    }//end collectUuids()

    /**
     * Resolve UUIDs in a value to human-readable names
     *
     * Preserves the same format as the input:
     * - Single UUID string → single name string
     * - Array of UUIDs → JSON array of names
     * - JSON-encoded array of UUIDs → JSON-encoded array of names
     *
     * Falls back to the UUID itself if no name is found in the map.
     *
     * @param mixed $value         The original value containing UUID(s)
     * @param array $uuidToNameMap Map of UUID → name from bulk resolution
     *
     * @return string|null The resolved name(s) in the same format as input
     */
    private function resolveUuidsToNames(mixed $value, array $uuidToNameMap): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) === true) {
            // Try to decode as JSON array first.
            $decoded = json_decode($value, true);
            if (is_array($decoded) === true) {
                $names = array_map(
                    function ($item) use ($uuidToNameMap) {
                        if (is_string($item) === true) {
                            return $uuidToNameMap[$item] ?? $item;
                        }

                        return $this->convertValueToString(value: $item);
                    },
                    $decoded
                );

                return json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // Single UUID string → single name.
            return $uuidToNameMap[$value] ?? $value;
        }//end if

        if (is_array($value) === true) {
            $names = array_map(
                function ($item) use ($uuidToNameMap) {
                    if (is_string($item) === true) {
                        return $uuidToNameMap[$item] ?? $item;
                    }

                        return $this->convertValueToString(value: $item);
                },
                $value
            );

            return json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->convertValueToString(value: $value);
    }//end resolveUuidsToNames()

    /**
     * Get all schemas for a register
     *
     * @param Register $register The register to get schemas for
     *
     * @return Schema[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Schema>
     */
    private function getSchemasForRegister(Register $register): array
    {
        return $this->registerMapper->getSchemasByRegisterId($register->getId());
    }//end getSchemasForRegister()
}//end class
