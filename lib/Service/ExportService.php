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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
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
     * Constructor for the ExportService
     *
     * @param ObjectEntityMapper $_objectEntityMapper The object entity mapper (unused but kept for future use)
     * @param RegisterMapper     $registerMapper      The register mapper
     * @param IUserManager       $_userManager        The user manager (unused but kept for future use)
     * @param IGroupManager      $groupManager        The group manager
     * @param ObjectService      $objectService       The object service
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        ObjectEntityMapper $_objectEntityMapper,
        RegisterMapper $registerMapper,
        IUserManager $_userManager,
        IGroupManager $groupManager,
        ObjectService $objectService
    ) {
        $this->registerMapper = $registerMapper;
        $this->groupManager   = $groupManager;
        $this->objectService  = $objectService;
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
     */
    public function exportToExcel(?Register $register=null, ?Schema $schema=null, array $filters=[], ?IUser $currentUser=null): Spreadsheet
    {
        // Create new spreadsheet.
        $spreadsheet = new Spreadsheet();

        // Remove default sheet.
        $spreadsheet->removeSheetByIndex(0);

        if ($register !== null && $schema === null) {
            // Export all schemas in register.
            $schemas = $this->getSchemasForRegister($register);
            foreach ($schemas as $schema) {
                $this->populateSheet(spreadsheet: $spreadsheet, register: $register, schema: $schema, filters: $filters, currentUser: $currentUser);
            }
        } else {
            // Export single schema.
            $this->populateSheet(spreadsheet: $spreadsheet, register: $register, schema: $schema, filters: $filters, currentUser: $currentUser);
        }

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
    public function exportToCsv(?Register $register=null, ?Schema $schema=null, array $filters=[], ?IUser $currentUser=null): string
    {
        if ($register !== null && $schema === null) {
            throw new InvalidArgumentException('Cannot export multiple schemas to CSV format.');
        }

        $spreadsheet = $this->exportToExcel(register: $register, schema: $schema, filters: $filters, currentUser: $currentUser);
        $writer      = new Csv($spreadsheet);

        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }//end exportToCsv()

    /**
     * Populate a worksheet with data
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

        if ($schema !== null) {
            $sheet->setTitle($schema->getSlug());
        } else {
            $sheet->setTitle('data');
        }

        $headers = $this->getHeaders(schema: $schema, currentUser: $currentUser);
        $row     = 1;

        // Set headers.
        foreach ($headers as $col => $header) {
            $sheet->setCellValue(coordinate: $col.$row, value: $header);
        }

        $row++;

        // Export data using optimized ObjectEntityMapper query for raw ObjectEntity objects.
        // Build filters for ObjectEntityMapper->findAll() method.
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
                // TODO: Add support for JSON property filtering in ObjectEntityMapper.
                continue;
            } else {
                // Metadata filter - remove @self. prefix.
                $metaField = substr($key, 6);
                $objectFilters[$metaField] = $value;
            }
        }

        // Use ObjectService::searchObjects directly with proper RBAC and multi-tenancy filtering.
        // Set a very high limit to get all objects (export needs all data).
        $query = [
            '@self'           => $objectFilters,
            '_limit'          => 999999,
        // Very high limit to get all objects.
            '_published'      => false,
        // Export all objects, not just published ones.
            '_includeDeleted' => false,
        ];

        $objects = $this->objectService->searchObjects(
            query: $query,
            _rbac: true,
            // Apply RBAC filtering.
            _multitenancy: true,
            // Apply multi-tenancy filtering.
            ids: null,
            uses: null
        );

        foreach ($objects as $object) {
            foreach ($headers as $col => $header) {
                $value = $this->getObjectValue(object: $object, header: $header);
                $sheet->setCellValue(coordinate: $col.$row, value: $value);
            }

            $row++;
        }
    }//end populateSheet()

    /**
     * Get headers for export
     *
     * @param Schema|null $schema      Optional schema to export
     * @param IUser|null  $currentUser Current user for permission checks
     *
     * @return (int|string)[]
     *
     * @psalm-return array<array-key>
     */
    private function getHeaders(?Schema $schema=null, ?IUser $currentUser=null): array
    {
        // Start with id as the first column.
        // Will contain the uuid.
        $headers = [
            'A' => 'id',
        ];

        // Add schema fields from the schema properties.
        if ($schema !== null) {
            // Start after id column.
            $col        = 'B';
            $properties = $schema->getProperties();

            // Sort properties by their order in the schema.
            foreach (array_keys($properties) as $fieldName) {
                // Skip fields that are already in the default headers.
                if (in_array($fieldName, ['id', 'uuid', 'uri', 'register', 'schema', 'created', 'updated']) === true) {
                    continue;
                }

                // Always use the property key as the header to ensure consistent data access.
                $headers[$col] = $fieldName;

                $col++;
            }
        }

        // REQUIREMENT: Add @self metadata fields only if user is admin.
        if ($this->isUserAdmin($currentUser) === true) {
            $metadataFields = [
                'created',
                'updated',
                'published',
                'depublished',
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
                    return $this->convertValueToString($value);
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
                    return $this->convertValueToString($value);
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
                return $this->convertValueToString($value);
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
     * Get all schemas for a register
     *
     * @param Register $register The register to get schemas for
     *
     * @return Schema[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\Schema>
     */
    private function getSchemasForRegister(Register $register): array
    {
        return $this->registerMapper->getSchemasByRegisterId($register->getId());
    }//end getSchemasForRegister()
}//end class
