<?php

/**
 * OpenRegister Import Service
 *
 * This file contains the class for handling data import operations in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\BackgroundJob\SolrWarmupJob;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\BackgroundJob\IJobList;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use DateTime;
use InvalidArgumentException;
use Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Psr\Log\LoggerInterface;
use React\Async\PromiseInterface;
use React\Promise\Promise;
use React\EventLoop\Loop;

/**
 * Service for importing data from various formats with ReactPHP optimization
 *
 * This service handles importing data from CSV and Excel files with automatic
 * array parsing for fields that contain multiple values. Arrays can be provided
 * in various formats including JSON, comma-separated, or quoted values.
 *
 * ### Performance Optimizations
 *
 * - **Chunked Processing**: Processes data in configurable chunks to prevent memory overflow
 * - **Concurrent Operations**: Uses ReactPHP promises for concurrent object creation/updates
 * - **Memory Management**: Clears processed data after each chunk to prevent memory leaks
 * - **Progress Tracking**: Provides real-time progress updates during import
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Import service requires comprehensive data transformation methods
 * @SuppressWarnings(PHPMD.TooManyMethods)           Many methods required for multi-format import support
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex import logic with multiple data formats
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Requires many dependencies for import operations
 * @SuppressWarnings(PHPMD.UnusedPrivateField)       schemaPropsCache reserved for future schema property caching
 * @SuppressWarnings(PHPMD.LongVariable)             Descriptive variable names improve code readability
 */

class ImportService
{

    /**
     * Object entity mapper instance
     *
     * @var ObjectEntityMapper
     */
    private readonly ObjectEntityMapper $objectEntityMapper;

    /**
     * Schema mapper instance
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Object service instance
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Default chunk size for processing
     *
     * @var int
     */
    private const DEFAULT_CHUNK_SIZE = 5;

    /**
     * Minimum chunk size for very complex data
     *
     * @var int
     */
    private const MINIMAL_CHUNK_SIZE = 2;

    /**
     * Maximum concurrent operations
     *
     * @var int
     */
    private const MAX_CONCURRENT = 5;

    /**
     * Minimum chunk size for concurrent processing
     *
     * @var int
     */
    private const MIN_CONCURRENT_CHUNK_SIZE = 5;

    /**
     * Instance cache for schema properties to avoid static cache issues
     *
     * @var array<string, array>
     */
    private array $schemaPropsCache = [];

    /**
     * Cache for schema properties during import operations
     *
     * @var array<string, array>
     */
    private array $schemaPropertiesCache = [];

    /**
     * Logger interface for logging operations
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Group manager for checking admin group membership
     *
     * @var IGroupManager
     */
    private readonly IGroupManager $groupManager;

    /**
     * Background job list for scheduling SOLR warmup jobs
     *
     * @var IJobList
     */
    private readonly IJobList $jobList;

    /**
     * Constructor for the ImportService
     *
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param SchemaMapper       $schemaMapper       The schema mapper
     * @param ObjectService      $objectService      The object service
     * @param LoggerInterface    $logger             The logger interface
     * @param IGroupManager      $groupManager       The group manager
     * @param IJobList           $jobList            The background job list
     */
    public function __construct(
        ObjectEntityMapper $objectEntityMapper,
        SchemaMapper $schemaMapper,
        ObjectService $objectService,
        LoggerInterface $logger,
        IGroupManager $groupManager,
        IJobList $jobList
    ) {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->schemaMapper       = $schemaMapper;
        $this->objectService      = $objectService;
        $this->logger       = $logger;
        $this->groupManager = $groupManager;
        $this->jobList      = $jobList;

        // Initialize cache arrays to prevent issues.
        $this->schemaPropertiesCache = [];
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
            // Anonymous users are never admin.
            return false;
        }

        // Check if user is in admin group.
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            // Admin group doesn't exist.
            return false;
        }

        return $adminGroup->inGroup($user);
    }//end isUserAdmin()

    /**
     * Import data from Excel file asynchronously.
     *
     * @param string        $filePath  The path to the Excel file.
     * @param Register|null $register  Optional register to associate with imported objects.
     * @param Schema|null   $schema    Optional schema to associate with imported objects.
     * @param int           $chunkSize Number of rows to process in each chunk (default: 100).
     *
     * @return PromiseInterface<array<string, array>> Promise that resolves to import summary.
     */



    /**
     * Import data from Excel file.
     *
     * @param string        $filePath  The path to the Excel file.
     * @param Register|null $register  Optional register to associate with imported objects.
     * @param Schema|null   $schema    Optional schema to associate with imported objects.
     * @param int           $chunkSize Number of rows to process in each chunk (default: 100).
     *
     * @return         array<string, array> Summary of import with sheet-based results.
     * @phpstan-return array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */

    /**
     * Import data from Excel file.
     *
     * @param string        $filePath      The path to the Excel file.
     * @param Register|null $register      Optional register to associate with imported objects.
     * @param Schema|null   $schema        Optional schema to associate with imported objects.
     * @param bool          $validation    Whether to validate objects against schema definitions (default: false).
     * @param bool          $events        Whether to dispatch object lifecycle events (default: false).
     * @param bool          $_rbac         Whether to apply RBAC checks (default: true, unused).
     * @param bool          $_multitenancy Whether to apply multitenancy checks (default: true, unused).
     * @param bool          $publish       Whether to publish objects after import (default: false).
     * @param IUser|null    $currentUser   The current user performing the import (optional).
     *
     * @return (array|int|null|string)[][]
     *
     * @phpstan-return array<string, array{
     *     found: int,
     *     created: array<mixed>,
     *     updated: array<mixed>,
     *     unchanged: array<mixed>,
     *     errors: array<mixed>,
     *     debug?: array,
     *     schema?: array{id: int, slug: null|string, title: null|string},
     *     deduplication_efficiency?: string
     * }>
     *
     * @psalm-return array<string, array{
     *     created: array,
     *     errors: array,
     *     found: int,
     *     unchanged?: array,
     *     updated: array,
     *     deduplication_efficiency?: string,
     *     schema?: array{id: int, title: null|string, slug: null|string}|null,
     *     debug?: array{headers: array<never, never>, processableHeaders: array<never, never>,
     *             schemaProperties: list<array-key>}
     * }>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flags control import behavior options
     */
    public function importFromExcel(
        string $filePath,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null
    ): array {
        // Clear caches at the start of each import to prevent stale data issues.
        $this->clearCaches();

        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        // If we have a register but no schema, process each sheet as a different schema.
        if ($register !== null && $schema === null) {
            return $this->processMultiSchemaSpreadsheetAsync(
                spreadsheet: $spreadsheet,
                register: $register,
                validation: $validation,
                events: $events,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                publish: $publish,
                currentUser: $currentUser
            );
        }

        // Single schema processing - use batch processing for better performance.
        $sheetTitle   = $spreadsheet->getActiveSheet()->getTitle();
        $sheetSummary = $this->processSpreadsheetBatch(
            spreadsheet: $spreadsheet,
            register: $register,
            schema: $schema,
            validation: $validation,
            events: $events,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            publish: $publish,
            currentUser: $currentUser
        );

        // Add schema information to the summary (consistent with multi-sheet Excel import).
        if ($schema !== null) {
            $sheetSummary['schema'] = [
                'id'    => $schema->getId(),
                'title' => $schema->getTitle(),
                'slug'  => $schema->getSlug(),
            ];
        }

        // Schedule SOLR warmup job after successful Excel import.
        $finalResult = [$sheetTitle => $sheetSummary];
        $this->scheduleSmartSolrWarmup($finalResult);

        // Return in sheet-based format for consistency.
        return $finalResult;
    }//end importFromExcel()

    /**
     * Import data from CSV file.
     *
     * @param string        $filePath      The path to the CSV file.
     * @param Register|null $register      Optional register to associate with imported objects.
     * @param Schema|null   $schema        Optional schema to associate with imported objects.
     * @param bool          $validation    Whether to validate objects against schema definitions (default: false).
     * @param bool          $events        Whether to dispatch object lifecycle events (default: false).
     * @param bool          $_rbac         Whether to enforce RBAC checks (default: true, unused).
     * @param bool          $_multitenancy Whether to enable multi-tenancy (default: true, unused).
     * @param bool          $publish       Whether to publish objects immediately (default: false).
     * @param IUser|null    $currentUser   Current user for RBAC checks (default: null).
     *
     * @return array Import results by schema
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flags control import behavior options
     */
    public function importFromCsv(
        string $filePath,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null
    ): array {
        // Clear caches at the start of each import to prevent stale data issues.
        $this->clearCaches();

        // CSV can only handle a single schema.
        if ($schema === null) {
            throw new InvalidArgumentException('CSV import requires a specific schema');
        }

        // Use PhpSpreadsheet CSV reader (works perfectly for multiline fields).
        $reader = new Csv();
        $reader->setReadDataOnly(true);
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $spreadsheet = $reader->load($filePath);

        // Get the sheet title for CSV (usually just 'Worksheet' or similar).
        $sheetTitle   = $spreadsheet->getActiveSheet()->getTitle();
        $sheetSummary = $this->processCsvSheet(
            sheet: $spreadsheet->getActiveSheet(),
            register: $register,
            schema: $schema,
            validation: $validation,
            events: $events,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            publish: $publish,
            currentUser: $currentUser
        );

        // Add schema information to the summary (consistent with Excel import).
        $sheetSummary['schema'] = [
            'id'    => $schema->getId(),
            'title' => $schema->getTitle(),
            'slug'  => $schema->getSlug(),
        ];

        // Schedule SOLR warmup job after successful CSV import.
        $finalResult = [$sheetTitle => $sheetSummary];
        $this->scheduleSmartSolrWarmup($finalResult);

        // Return in sheet-based format for consistency.
        return $finalResult;
    }//end importFromCsv()

    /**
     * Process spreadsheet with multiple schemas using batch saving for better performance
     *
     * @param Spreadsheet $spreadsheet   The spreadsheet to process
     * @param Register    $register      The register to associate with imported objects
     * @param bool        $validation    Whether to validate objects against schema definitions
     * @param bool        $events        Whether to dispatch object lifecycle events
     * @param bool        $_rbac         Whether to apply RBAC permissions
     * @param bool        $_multitenancy Whether to apply multi-tenancy filtering
     * @param bool        $publish       Whether to publish objects after import
     * @param IUser|null  $currentUser   The current user performing the import
     *
     * @return         array<string, array> Summary of import with sheet-based results
     * @phpstan-return array<string, array{
     *     found: int,
     *     created: array<mixed>,
     *     updated: array<mixed>,
     *     unchanged: array<mixed>,
     *     errors: array<mixed>,
     *     schema?: array{id: int, slug: null|string, title: null|string},
     *     debug?: array,
     *     deduplication_efficiency?: string
     * }>
     * @psalm-return   array<string, array{
     *     created: array<array-key, mixed>,
     *     errors: array<array-key, mixed>,
     *     found: int,
     *     unchanged?: array<array-key, mixed>,
     *     updated: array<array-key, mixed>,
     *     debug: array{
     *         headers: array<never, never>,
     *         processableHeaders: array<never, never>,
     *         schemaProperties: list<array-key>
     *     },
     *     deduplication_efficiency?: non-empty-lowercase-string,
     *     schema: array{id: int, slug: null|string, title: null|string}|null
     * }>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flags control import behavior options
     */
    private function processMultiSchemaSpreadsheetAsync(
        Spreadsheet $spreadsheet,
        Register $register,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null
    ): array {
        $summary = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $schemaSlug = $worksheet->getTitle();
            $schema     = $this->getSchemaBySlug($schemaSlug);

            // Initialize sheet summary even if no schema found.
            $summary[$schemaSlug] = [
                'found'   => 0,
                'created' => [],
                'updated' => [],
            // TODO: Renamed from 'skipped' - more descriptive (objects skipped because content was unchanged).
                'errors'  => [],
                'schema'  => null,
                'debug'   => [
                    'headers'            => [],
                    'schemaProperties'   => [],
                    'processableHeaders' => [],
                ],
            ];

            // Skip sheets that don't correspond to a valid schema.
            // Note: getSchemaBySlug() returns Schema (non-nullable) or throws exception.
            try {
                $schema = $this->getSchemaBySlug($schemaSlug);
                // Schema is guaranteed to be non-null if we reach here (exception thrown otherwise)
                // Add schema information to the summary.
                $summary[$schemaSlug]['schema'] = [
                    'id'    => $schema->getId(),
                    'title' => $schema->getTitle(),
                    'slug'  => $schema->getSlug(),
                ];
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                $summary[$schemaSlug]['errors'][] = [
                    'sheet'    => $schemaSlug,
                    'register' => [
                        'id'   => $register->getId(),
                        'name' => $register->getTitle(),
                    ],
                    'schema'   => null,
                    'error'    => 'No matching schema found for sheet: '.$schemaSlug,
                    'type'     => 'SchemaNotFoundException',
                ];
                continue;
            }//end try

            // Update debug information with schema properties.
            $schemaProperties = $schema->getProperties();
            $propertyKeys     = array_keys($schemaProperties);
            $summary[$schemaSlug]['debug']['schemaProperties'] = $propertyKeys;

            // Set the worksheet as active and process using batch saving for better performance.
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($worksheet));
            $sheetSummary = $this->processSpreadsheetBatch(
                spreadsheet: $spreadsheet,
                register: $register,
                schema: $schema,
                validation: $validation,
                events: $events,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                publish: $publish,
                currentUser: $currentUser
            );

            // Merge the sheet summary with the existing summary (preserve debug info).
            $summary[$schemaSlug] = array_merge($summary[$schemaSlug], $sheetSummary);
        }//end foreach

        // Schedule SOLR warmup job after successful multi-schema import.
        $this->scheduleSmartSolrWarmup($summary);

        return $summary;
    }//end processMultiSchemaSpreadsheetAsync()

    /**
     * Process spreadsheet with single schema using batch saving for better performance
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to process
     * @param Register|null $register  Optional register to associate with imported objects
     * @param Schema|null   $schema    Optional schema to associate with imported objects
     * @param int           $chunkSize Number of rows to process in each chunk
     *
     * @return         array<string, array> Summary of import with sheet-based results
     * @phpstan-return array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */

    /**
     * Process a single spreadsheet sheet using batch saving for better performance
     *
     * @param Spreadsheet   $spreadsheet   The spreadsheet to process
     * @param Register|null $register      Optional register to associate with imported objects
     * @param Schema|null   $schema        Optional schema to associate with imported objects
     * @param bool          $validation    Whether to validate objects against schema definitions
     * @param bool          $events        Whether to dispatch object lifecycle events
     * @param bool          $_rbac         Whether to apply RBAC permissions
     * @param bool          $_multitenancy Whether to apply multi-tenancy filtering
     * @param bool          $publish       Whether to publish objects after import
     * @param IUser|null    $currentUser   The current user performing the import
     *
     * @return array Batch processing results
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flags control import behavior options
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Spreadsheet batch processing requires many validation branches
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple row/column validation paths needed for data integrity
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Batch processing consolidates related operations for performance
     */
    private function processSpreadsheetBatch(
        Spreadsheet $spreadsheet,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null
    ): array {
        $summary = [
            'found'     => 0,
            'created'   => [],
            'updated'   => [],
        // TODO: Renamed from 'skipped' - more descriptive.
            'unchanged' => [],
            'errors'    => [],
        ];

        // Get the active sheet.
        $sheet      = $spreadsheet->getActiveSheet();
        $sheetTitle = $sheet->getTitle();

        // Build column mapping from headers.
        $columnMapping = $this->buildColumnMapping($sheet);

        if (empty($columnMapping) === true) {
            $summary['errors'][] = [
                'sheet'  => $sheetTitle,
                'row'    => 1,
                'object' => [],
                'error'  => 'No valid headers found in sheet',
            ];
            return $summary;
        }

        // Get total rows in the sheet.
        $highestRow = $sheet->getHighestRow();

        if ($highestRow <= 1) {
            $summary['errors'][] = [
                'sheet'  => $sheetTitle,
                'row'    => 1,
                'object' => [],
                'error'  => 'No data rows found in sheet',
            ];
            return $summary;
        }

        // Parse ALL rows into objects array (no chunking here!).
        $allObjects = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            // NO ERROR SUPPRESSION: Let row processing errors bubble up immediately!
            $rowData = $this->extractRowData(sheet: $sheet, columnMapping: $columnMapping, row: $row);

            if (empty($rowData) === true) {
                continue;
                // Skip empty rows.
            }

            // Transform row data to object format.
            $object = $this->transformExcelRowToObject(
                rowData: $rowData,
                register: $register,
                schema: $schema,
                currentUser: $currentUser
            );

            if ($object !== null) {
                $allObjects[] = $object;
            }
        }//end for

        $summary['found'] = count($allObjects);

        // Call saveObjects ONCE with all objects - NO ERROR SUPPRESSION!
        // This will reveal the real bulk save problem immediately.
        if ((empty($allObjects) === false) && $register !== null && $schema !== null) {
            // Add publish date to all objects if publish is enabled.
            if ($publish === true) {
                $publishDate = (new DateTime('now'))->format('c');
                // ISO 8601 format.
                $allObjects = $this->addPublishedDateToObjects(objects: $allObjects, publishDate: $publishDate);
            }

            $saveResult = $this->objectService->saveObjects(
                objects: $allObjects,
                register: $register,
                schema: $schema,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                validation: $validation,
                events: $events
            );

            // Use the structured return from saveObjects with smart deduplication.
            // SaveObjects returns ObjectEntity->jsonSerialize() arrays where UUID is in @self.id.
            $summary['created'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['saved'] ?? []
            );
            $summary['updated'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['updated'] ?? []
            );

            // TODO: Handle unchanged objects from smart deduplication (renamed from 'skipped').
            $summary['unchanged'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['unchanged'] ?? []
            );

            // Add efficiency metrics from smart deduplication.
            $createdCount   = count($summary['created']);
            $updatedCount   = count($summary['updated']);
            $unchangedCount = count($summary['unchanged']);
            $totalProcessed = $createdCount + $updatedCount + $unchangedCount;
            if ($totalProcessed > 0 && $unchangedCount > 0) {
                $efficiency = round(($unchangedCount / $totalProcessed) * 100, 1);
                $summary['deduplication_efficiency'] = $efficiency.'% operations avoided';
            }

            // Handle validation errors if validation was enabled.
            if ($validation === true && empty($saveResult['invalid'] ?? []) === false) {
                foreach (($saveResult['invalid'] ?? []) as $invalidItem) {
                    $summary['errors'][] = [
                        'sheet'  => $sheetTitle,
                        'object' => $invalidItem['object'] ?? $invalidItem,
                        'error'  => $invalidItem['error'] ?? 'Validation failed',
                        'type'   => $invalidItem['type'] ?? 'ValidationException',
                    ];
                }
            }
        }//end if

        // NO ERROR SUPPRESSION: Row parsing errors will bubble up immediately - no need to collect them.
        // Note: Processing time calculation removed as it was unused.
        // $processingTime = microtime(true) - $startTime;.
        return $summary;
    }//end processSpreadsheetBatch()

    /**
     * Process CSV sheet and import all objects in batches
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet to process
     * @param Register                                      $register      The register to associate with imported objects
     * @param Schema                                        $schema        The schema to associate with imported objects
     * @param bool                                          $validation    Whether to validate objects
     * @param bool                                          $events        Whether to dispatch events
     * @param bool                                          $_rbac         Whether to apply RBAC
     * @param bool                                          $_multitenancy Multi-tenancy filtering
     * @param bool                                          $publish       Whether to publish objects after import
     * @param IUser|null                                    $currentUser   The current user performing the import
     *
     * @return array CSV sheet processing results
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flags control import behavior options
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  CSV processing requires many conditional branches for data handling
     * @SuppressWarnings(PHPMD.NPathComplexity)       CSV processing requires many conditional row/column handling
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) CSV processing consolidates related operations for performance
     */
    private function processCsvSheet(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        Register $register,
        Schema $schema,
        bool $validation=false,
        bool $events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null
    ): array {
        $summary = [
            'found'     => 0,
            'created'   => [],
            'updated'   => [],
        // TODO: Renamed from 'skipped' - more descriptive.
            'unchanged' => [],
            'errors'    => [],
        ];

        // REMOVED ERROR SUPPRESSION: Let CSV bulk save errors bubble up immediately!
        $startTime = microtime(true);

        // Build column mapping from headers.
        $columnMapping = $this->buildColumnMapping($sheet);

        if (empty($columnMapping) === true) {
            $summary['errors'][] = [
                'row'    => 1,
                'object' => [],
                'error'  => 'No valid headers found in CSV file',
            ];
            return $summary;
        }

        // Get total rows in the sheet.
        $highestRow = $sheet->getHighestRow();

        if ($highestRow <= 1) {
            $summary['errors'][] = [
                'row'    => 1,
                'object' => [],
                'error'  => 'No data rows found in CSV file',
            ];
            return $summary;
        }

        // Parse ALL rows into objects array (no chunking here!).
        $allObjects = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            // NO ERROR SUPPRESSION: Let CSV row processing errors bubble up immediately!
            $rowData = $this->extractRowData(sheet: $sheet, columnMapping: $columnMapping, row: $row);

            if (empty($rowData) === true) {
                continue;
                // Skip empty rows.
            }

            // Transform row data to object format.
            $object = $this->transformCsvRowToObject(
                rowData: $rowData,
                register: $register,
                schema: $schema,
                currentUser: $currentUser
            );

            if ($object !== null) {
                $allObjects[] = $object;
            }
        }//end for

        $summary['found'] = count($allObjects);

        // Call saveObjects ONCE with all objects - NO ERROR SUPPRESSION!
        // This will reveal the real bulk save problem immediately.
        if (empty($allObjects) === false) {
            // Log publish processing for debugging.
            $this->logger->debug(
                message: 'CSV import processing objects',
                context: [
                    'objectCount' => count($allObjects),
                    'publish'     => $publish,
                ]
            );

            // Add publish date to all objects if publish is enabled.
            if ($publish !== true) {
                $this->logger->debug(message: 'Publish disabled for CSV import, not adding publish dates');
            }

            if ($publish === true) {
                $publishDate = (new DateTime('now'))->format('c');
                // ISO 8601 format.
                $this->logger->debug(
                    message: 'Adding publish date to CSV import objects',
                    context: [
                        'publishDate' => $publishDate,
                        'objectCount' => count($allObjects),
                    ]
                );
                $allObjects = $this->addPublishedDateToObjects(objects: $allObjects, publishDate: $publishDate);

                // Log first object structure for debugging.
                if (empty($allObjects[0]['@self']) === false) {
                    $this->logger->debug(
                        message: 'First object @self structure after adding publish date',
                        context: [
                            'selfData' => $allObjects[0]['@self'],
                        ]
                    );
                }
            }//end if

            $saveResult = $this->objectService->saveObjects(
                objects: $allObjects,
                register: $register,
                schema: $schema,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                validation: $validation,
                events: $events
            );

            // Use the structured return from saveObjects with smart deduplication.
            // SaveObjects returns ObjectEntity->jsonSerialize() arrays where UUID is in @self.id.
            $summary['created'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['saved'] ?? []
            );
            $summary['updated'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['updated'] ?? []
            );

            // TODO: Handle unchanged objects from smart deduplication (renamed from 'skipped').
            $summary['unchanged'] = array_map(
                fn(array $obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null,
                $saveResult['unchanged'] ?? []
            );

            // Add efficiency metrics from smart deduplication.
            $createdCount   = count($summary['created']);
            $updatedCount   = count($summary['updated']);
            $unchangedCount = count($summary['unchanged']);
            $totalProcessed = $createdCount + $updatedCount + $unchangedCount;
            if ($totalProcessed > 0 && $unchangedCount > 0) {
                $efficiency = round(($unchangedCount / $totalProcessed) * 100, 1);
                $summary['deduplication_efficiency'] = $efficiency.'% operations avoided';
            }

            // Handle validation errors if validation was enabled.
            if ($validation === true && empty($saveResult['invalid'] ?? []) === false) {
                foreach (($saveResult['invalid'] ?? []) as $invalidItem) {
                    $summary['errors'][] = [
                        'object' => $invalidItem['object'] ?? $invalidItem,
                        'error'  => $invalidItem['error'] ?? 'Validation failed',
                        'type'   => $invalidItem['type'] ?? 'ValidationException',
                    ];
                }
            }
        }//end if

        // NO ERROR SUPPRESSION: Row parsing errors will bubble up immediately - no need to collect them.
        $totalImportTime      = microtime(true) - $startTime;
        $overallRowsPerSecond = count($allObjects) / max($totalImportTime, 0.001);

        // Calculate efficiency.
        $efficiency = 0;
        if ($summary['found'] > 0) {
            $efficiency = round((count($allObjects) / $summary['found']) * 100, 1);
        }

        // ADD PERFORMANCE METRICS: Include timing and speed metrics like SaveObjects does.
        $summary['performance'] = [
            'totalTime'        => round($totalImportTime, 3),
            'totalTimeMs'      => round($totalImportTime * 1000, 2),
            'objectsPerSecond' => round($overallRowsPerSecond, 2),
            'totalProcessed'   => count($allObjects),
            'totalFound'       => $summary['found'],
            'efficiency'       => $efficiency,
        ];

        return $summary;
    }//end processCsvSheet()

    /**
     * Transform CSV row data to object format for batch saving
     *
     * @param array      $rowData     Row data from CSV
     * @param Register   $register    The register
     * @param Schema     $schema      The schema
     * @param IUser|null $currentUser The current user performing the import
     *
     * @return ((int|mixed|string)[]|mixed)[]
     *
     * @psalm-return array{'@self': array<string, int|mixed|string>,...}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Row transformation requires many type-specific branches
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple column types and transformations create execution paths
     */
    private function transformCsvRowToObject(
        array $rowData,
        Register $register,
        Schema $schema,
        ?IUser $currentUser=null
    ): array {
        // Use instance cache instead of static to prevent issues between requests.
        $schemaId = $schema->getId();
        // Ensure schemaId is string for array key.
        $schemaIdKey = (string) $schemaId;

        if (isset($this->schemaPropertiesCache[$schemaIdKey]) === false) {
            $properties = $schema->getProperties();
            $this->schemaPropertiesCache[$schemaIdKey] = $properties ?? [];
        }

        $schemaProperties = $this->schemaPropertiesCache[$schemaIdKey];

        // Pre-allocate arrays for better performance.
        $objectData = [];
        $selfData   = [
            'register' => $register->getId(),
            'schema'   => $schemaId,
        ];

        // Single pass through row data with proper column filtering.
        $isAdmin = $this->isUserAdmin($currentUser);

        foreach ($rowData as $key => $value) {
            // Skip empty values early.
            if ($value === null || $value === '') {
                continue;
            }

            // Ensure $key is a string before accessing as array.
            $keyString = (string) $key;
            if (is_string($key) === true) {
                $keyString = $key;
            }

            $firstChar = $keyString[0] ?? '';

            if ($firstChar === '_') {
                // REQUIREMENT: Columns starting with _ are completely ignored.
                continue;
            } else if ($firstChar === '@') {
                // REQUIREMENT: @ columns only processed if user is admin.
                if ($isAdmin === false) {
                    continue;
                    // Skip @ columns for non-admin users.
                }

                if (str_starts_with($key, '@self.') === true) {
                    // Move properties starting with @self. to @self array and remove the @self. prefix.
                    $selfPropertyName = substr($key, 6);

                    // Transform special @self properties.
                    $selfData[$selfPropertyName] = $this->transformSelfProperty(
                        propertyName: $selfPropertyName,
                        value: $value
                    );
                }

                // Note: Other @ columns that don't start with @self. are ignored.
                continue;
            }//end if

            // Regular properties - transform based on schema if needed.
            $objectData[$key]  = $value;
            $hasSchemaProperty = ($schemaProperties[$key] ?? null) !== null;
            if (is_array($schemaProperties) === true && $hasSchemaProperty === true) {
                $objectData[$key] = $this->transformValueByType(value: $value, propertyDef: $schemaProperties[$key]);
            }
        }//end foreach

        // Add ID if present in the data (for updates) - check once at the end.
        if (empty($rowData['id']) === false) {
            $selfData['id'] = $rowData['id'];
        }

        // Add @self array to object data.
        $objectData['@self'] = $selfData;

        // Validate that we're not accidentally creating invalid properties.
        $this->validateObjectProperties(objectData: $objectData, _schemaId: (string) $schemaId);

        return $objectData;
    }//end transformCsvRowToObject()

    /**
     * Transform datetime values from various formats to MySQL datetime format
     *
     * @param string $value The datetime value to transform
     *
     * @return string The transformed datetime value in MySQL format
     */
    private function transformDateTimeValue(string $value): string
    {
        // Handle ISO 8601 format with timezone (e.g., "2025-01-01T00:00:00+00:00").
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === true) {
            try {
                $dateTime = new DateTime($value);
                return $dateTime->format(format: 'Y-m-d H:i:s');
            } catch (Exception $e) {
                // Fallback to original value if parsing fails.
                return $value;
            }
        }

        // Handle ISO 8601 format without timezone (e.g., "2025-01-01T00:00:00").
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) === true) {
            try {
                $dateTime = new DateTime($value);
                return $dateTime->format(format: 'Y-m-d H:i:s');
            } catch (Exception $e) {
                // Fallback to original value if parsing fails.
                return $value;
            }
        }

        // Handle date-only format (e.g., "2025-01-01").
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === true) {
            return $value.' 00:00:00';
        }

        // Return original value if no transformation needed.
        return $value;
    }//end transformDateTimeValue()

    /**
     * Transform @self properties based on their type
     *
     * @param string $propertyName The name of the @self property
     * @param string $value        The value to transform
     *
     * @return string The transformed value
     */
    private function transformSelfProperty(string $propertyName, string $value): string
    {
        // Transform published property to MySQL datetime format.
        if ($propertyName === 'published') {
            return $this->transformDateTimeValue($value);
        }

        // Transform organisation property - ensure it's a valid UUID.
        if ($propertyName === 'organisation') {
            // Validate UUID format.
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === true) {
                return $value;
            }

            // If not a valid UUID, return as-is (might be a slug that needs resolution).
            return $value;
        }

        // Return original value for other properties.
        return $value;
    }//end transformSelfProperty()

    /**
     * Transform Excel row data to object format for batch saving
     *
     * @param array         $rowData     Row data from Excel
     * @param Register|null $register    Optional register
     * @param Schema|null   $schema      Optional schema
     * @param IUser|null    $currentUser The current user performing the import
     *
     * @return array<string, mixed>|null Object data or null if transformation fails
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Excel row transformation requires many type-specific branches
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple column types and transformations create execution paths
     */
    private function transformExcelRowToObject(
        array $rowData,
        ?Register $register,
        ?Schema $schema,
        ?IUser $currentUser=null
    ): ?array {
        // Separate regular properties from system properties.
        $objectData = [];
        $selfData   = [];

        // Check if current user is admin for column filtering.
        $isAdmin = $this->isUserAdmin($currentUser);

        foreach ($rowData as $key => $value) {
            // Skip empty values.
            if ($value === null || $value === '') {
                continue;
            }

            if (str_starts_with($key, '_') === true) {
                // REQUIREMENT: Columns starting with _ are completely ignored.
                continue;
            } else if (str_starts_with($key, '@') === true) {
                // REQUIREMENT: @ columns only processed if user is admin.
                if ($isAdmin === false) {
                    continue;
                    // Skip @ columns for non-admin users.
                }

                if (str_starts_with($key, '@self.') === true) {
                    // Move properties starting with @self. to @self array and remove the @self. prefix.
                    $selfPropertyName = substr($key, 6);

                    // Transform special @self properties.
                    $selfData[$selfPropertyName] = $this->transformSelfProperty(
                        propertyName: $selfPropertyName,
                        value: $value
                    );
                }

                // Note: Other @ columns that don't start with @self. are ignored.
                continue;
            }//end if

            // Regular properties go to main object data.
            $objectData[$key] = $value;
        }//end foreach

        // Build @self section with metadata if available.
        if ($register !== null) {
            $selfData['register'] = $register->getId();
        }

        if ($schema !== null) {
            $selfData['schema'] = $schema->getId();
        }

        // Add ID if present in the data (for updates).
        if (($rowData['id'] ?? null) !== null && empty($rowData['id']) === false) {
            $selfData['id'] = $rowData['id'];
        }

        // Add @self array to object data if we have self properties.
        if (empty($selfData) === false) {
            $objectData['@self'] = $selfData;
        }

        // Transform object data based on schema property types if schema is available.
        $transformedData = $objectData;
        if ($schema !== null) {
            $transformedData = $this->transformObjectBySchema(objectData: $objectData, schema: $schema);
        }

        return $transformedData;
    }//end transformExcelRowToObject()

    /**
     * Build column mapping from spreadsheet headers
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet The worksheet
     *
     * @return array<string, string> Column mapping (column letter -> column name)
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Coordinate::stringFromColumnIndex is standard PhpSpreadsheet pattern
     */
    private function buildColumnMapping(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $columnMapping = [];
        // Column letter -> column name.
        $columnIndex = 1;

        // Use PhpSpreadsheet built-in method to get column letters.
        while ($columnIndex <= 50) {
            // Check up to 50 columns.
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $cellValue    = $sheet->getCell($columnLetter.'1')->getValue();

            if ($cellValue === null || trim($cellValue) === '') {
                // Found empty column, stop here.
                break;
            }

            $cleanColumnName = trim($cellValue);
            $columnMapping[$columnLetter] = $cleanColumnName;

            $columnIndex++;
        }

        return $columnMapping;
    }//end buildColumnMapping()

    /**
     * Extract data from a single row
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet
     * @param array<string, string>                         $columnMapping Column mapping
     * @param int                                           $row           Row number
     *
     * @return string[]
     *
     * @psalm-return array<string, string>
     */
    private function extractRowData(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $columnMapping,
        int $row
    ): array {
        $rowData = [];
        // Name -> value.
        $hasData = false;

        // Loop through each column in the mapping.
        foreach ($columnMapping as $columnLetter => $columnName) {
            $cellValue = $sheet->getCell($columnLetter.$row)->getValue();

            // Convert cell value to string and trim whitespace.
            $cleanCellValue = '';
            if ($cellValue !== null) {
                $cleanCellValue = trim((string) $cellValue);
            }

            if ($cleanCellValue !== '') {
                $rowData[$columnName] = $cleanCellValue;
                $hasData = true;
            }
        }

        if ($hasData === true) {
            return $rowData;
        }

        return [];
    }//end extractRowData()

    /**
     * Get schema by slug
     *
     * @param string $slug The schema slug
     *
     * @return Schema The schema or null if not found
     */
    private function getSchemaBySlug(string $slug): Schema
    {
        // NO ERROR SUPPRESSION: Let schema lookup errors bubble up immediately!
        $schema = $this->schemaMapper->find($slug);
        return $schema;
    }//end getSchemaBySlug()

    /**
     * Transform object data based on schema property definitions
     *
     * This method transforms string values from Excel to the expected types defined in the schema.
     * It handles type conversion for integers, numbers, booleans, arrays, and objects.
     *
     * @param array  $objectData The object data to transform
     * @param Schema $schema     The schema containing property definitions
     *
     * @return array The transformed object data
     *
     * @phpstan-return array<string, mixed>
     * @psalm-return   array<string, mixed>
     */
    private function transformObjectBySchema(array $objectData, Schema $schema): array
    {
        // NO ERROR SUPPRESSION: Let schema transformation errors bubble up immediately!
        $schemaProperties = $schema->getProperties();
        $transformedData  = [];

        foreach ($objectData as $propertyName => $value) {
            // Skip @self array - it's handled separately.
            if ($propertyName === '@self') {
                $transformedData[$propertyName] = $value;
                continue;
            }

            // Get property definition from schema.
            $propertyDef = $schemaProperties[$propertyName] ?? null;

            if ($propertyDef === null) {
                // Property not in schema, keep as is.
                $transformedData[$propertyName] = $value;
                continue;
            }

            // Transform based on type.
            $transformedData[$propertyName] = $this->transformValueByType(value: $value, propertyDef: $propertyDef);
        }

        return $transformedData;
    }//end transformObjectBySchema()

    /**
     * Transform a value based on its property definition type
     *
     * @param mixed $value       The value to transform
     * @param array $propertyDef The property definition from the schema
     *
     * @return mixed The transformed value
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Type transformation switch requires branches for each data type
     */
    private function transformValueByType($value, array $propertyDef)
    {
        // If value is empty or null, return as is.
        if ($value === null || $value === '') {
            return $value;
        }

        $type = $propertyDef['type'] ?? 'string';

        switch ($type) {
            case 'integer':
                return (int) $value;

            case 'number':
                return (float) $value;

            case 'boolean':
                return $this->stringToBoolean($value);

            case 'array':
                return $this->stringToArray($value);

            case 'object':
                // Check if this is a related-object that should store UUID strings directly.
                if (($propertyDef['objectConfiguration']['handling'] ?? null) !== null
                    && ($propertyDef['objectConfiguration']['handling'] === 'related-object') === true
                ) {
                    // For related objects, store UUID strings directly instead of wrapping in objects.
                    return (string) $value;
                }
                return $this->stringToObject($value);

            default:
                return (string) $value;
        }//end switch
    }//end transformValueByType()

    /**
     * Convert string to boolean
     *
     * @param mixed $value The value to convert
     *
     * @return bool The boolean value
     */
    private function stringToBoolean($value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, ['true', '1', 'yes', 'on', 'enabled']);
    }//end stringToBoolean()

    /**
     * Convert string to object
     *
     * @param mixed $value The value to convert
     *
     * @return array|object The object value
     */
    private function stringToObject($value)
    {
        if (is_array($value) === true || is_object($value) === true) {
            return $value;
        }

        $value = trim((string) $value);

        // Try to parse as JSON first.
        if (str_starts_with($value, '{') === true && str_ends_with($value, '}') === true) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // If not JSON, return as single-key object.
        return ['value' => $value];
    }//end stringToObject()

    /**
     * Convert string to array handling multiple formats
     *
     * This method handles various array formats:
     * - Comma-separated: 1,2,3
     * - Quoted comma-separated: "1","2","3"
     * - JSON arrays: ["1","2","3"]
     * - Mixed formats
     *
     * @param mixed $value The value to convert
     *
     * @return array The array value
     *
     * @phpstan-return array<int|string, mixed>
     * @psalm-return   array<int|string, mixed>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Array parsing requires branches for JSON, CSV, quoted formats
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple array format detection paths needed
     */
    private function stringToArray($value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_string($value) === false) {
            return [$value];
        }

        $value = trim($value);

        // Empty string returns empty array.
        if ($value === '') {
            return [];
        }

        // Try JSON first.
        if (str_starts_with($value, '[') === true && str_ends_with($value, ']') === true) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
                return $decoded;
            }
        }

        // Handle comma-separated values.
        if (str_contains($value, ',') === true) {
            $parts  = explode(',', $value);
            $result = [];

            foreach ($parts as $part) {
                $part = trim($part);

                // Remove surrounding quotes.
                if ((str_starts_with($part, '"') === true && str_ends_with($part, '"') === true)
                    || (str_starts_with($part, "'") === true && str_ends_with($part, "'") === true)
                ) {
                    $part = substr($part, 1, -1);
                }

                $result[] = $part;
            }

            return $result;
        }

        // Single value - return as array with one element.
        return [$value];
    }//end stringToArray()

    /**
     * Clear all internal caches to prevent issues between imports
     *
     * @return void
     */
    public function clearCaches(): void
    {
        $this->schemaPropertiesCache = [];
    }//end clearCaches()

    /**
     * Validate that object data only contains valid ObjectEntity properties
     *
     * @param array  $objectData The object data to validate
     * @param string $_schemaId  Schema ID for debugging (unused, for future use)
     *
     * @return void
     */
    private function validateObjectProperties(array $objectData, string $_schemaId): void
    {
        // Check for invalid properties (common mistakes).
        $invalidProperties = ['data', 'content', 'body', 'payload'];

        foreach (array_keys($objectData) as $key) {
            // Skip @self as it's handled separately.
            if ($key === '@self') {
                continue;
            }

            // Check for invalid properties that commonly cause issues.
            if (in_array($key, $invalidProperties) === true) {
            }
        }
    }//end validateObjectProperties()

    /**
     * Add published date to all objects in the @self section
     *
     * @param array  $objects     Array of object data
     * @param string $publishDate Published date in ISO 8601 format
     *
     * @return array Modified objects with published date
     */
    private function addPublishedDateToObjects(array $objects, string $publishDate): array
    {
        foreach ($objects as &$object) {
            // Ensure @self section exists.
            if (isset($object['@self']) === false) {
                $object['@self'] = [];
            }

            // Only add published date if not already set (from @self.published column).
            if (($object['@self']['published'] ?? null) === null || empty($object['@self']['published']) === true) {
                $object['@self']['published'] = $publishDate;
            }
        }

        return $objects;
    }//end addPublishedDateToObjects()

    /**
     * Schedule SOLR warmup job after successful import
     *
     * This method schedules a one-time background job to warm up the SOLR index
     * after import operations complete. The warmup runs in the background to avoid
     * impacting import performance while ensuring optimal search performance.
     *
     * @param array  $importSummary Summary of the import operation
     * @param int    $delaySeconds  Delay before running the warmup (default: 30 seconds)
     * @param string $mode          Warmup mode - 'serial', 'parallel', or 'hyper' (default: 'serial')
     * @param int    $maxObjects    Maximum objects to index during warmup (default: 5000)
     *
     * @return bool True if job was scheduled successfully
     */
    public function scheduleSolrWarmup(
        array $importSummary,
        int $delaySeconds=30,
        string $mode='serial',
        int $maxObjects=5000
    ): bool {
        try {
            // Calculate total objects imported across all sheets.
            $totalImported = $this->calculateTotalImported($importSummary);

            if ($totalImported === 0) {
                $this->logger->info(message: 'Skipping SOLR warmup - no objects were imported');
                return false;
            }

            // Prepare job arguments.
            $jobArguments = [
                'maxObjects'    => $maxObjects,
                'mode'          => $mode,
            // Keep it fast for post-import warmup.
                'triggeredBy'   => 'import_completion',
                'importSummary' => [
                    'totalImported'   => $totalImported,
                    'sheetsProcessed' => count($importSummary),
                    'importTimestamp' => date('c'),
                ],
            ];

            // Schedule the job with delay.
            $executeAfter = time() + $delaySeconds;
            $this->jobList->scheduleAfter(SolrWarmupJob::class, $executeAfter, $jobArguments);

            $this->logger->info(
                message: '🔥 SOLR Warmup Job Scheduled',
                context: [
                    'total_imported' => $totalImported,
                    'warmup_mode'    => $mode,
                    'max_objects'    => $maxObjects,
                    'delay_seconds'  => $delaySeconds,
                    'execute_after'  => date('Y-m-d H:i:s', $executeAfter),
                    'triggered_by'   => 'import_completion',
                ]
            );

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to schedule SOLR warmup job',
                context: [
                    'error'          => $e->getMessage(),
                    'import_summary' => $importSummary,
                ]
            );

            return false;
        }//end try
    }//end scheduleSolrWarmup()

    /**
     * Calculate total objects imported from import summary
     *
     * @param array $importSummary Import summary from Excel/CSV import
     *
     * @return int Total number of objects imported
     *
     * @psalm-return int<0, max>
     */
    private function calculateTotalImported(array $importSummary): int
    {
        $total = 0;

        foreach ($importSummary as $sheetSummary) {
            if (is_array($sheetSummary) === true) {
                $created = count($sheetSummary['created'] ?? []);
                $updated = count($sheetSummary['updated'] ?? []);
                $total  += $created + $updated;
            }
        }

        return $total;
    }//end calculateTotalImported()

    /**
     * Determine optimal warmup mode based on import size
     *
     * @param int $totalImported Total objects imported
     *
     * @return string Recommended warmup mode
     *
     * @psalm-return 'balanced'|'fast'|'safe'
     */
    public function getRecommendedWarmupMode(int $totalImported): string
    {
        if ($totalImported > 10000) {
            // Fast mode for large imports.
            return 'fast';
        }

        if ($totalImported > 1000) {
            // Balanced mode for medium imports.
            return 'balanced';
        }

        // Safe mode for small imports.
        return 'safe';
    }//end getRecommendedWarmupMode()

    /**
     * Schedule SOLR warmup with smart configuration based on import results
     *
     * This is a convenience method that automatically determines the best warmup
     * configuration based on the import results.
     *
     * @param array $importSummary Import summary
     * @param bool  $immediate     Whether to run immediately (default: false, 30s delay)
     *
     * @return bool True if job was scheduled successfully
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Immediate flag controls scheduling timing
     */
    public function scheduleSmartSolrWarmup(array $importSummary, bool $immediate=false): bool
    {
        $totalImported = $this->calculateTotalImported($importSummary);

        if ($totalImported === 0) {
            return false;
        }

        // Smart configuration based on import size.
        $mode = $this->getRecommendedWarmupMode($totalImported);
        // Index up to 2x imported objects, max 15k.
        $maxObjects = min($totalImported * 2, 15000);
        $delay      = 30;
        if ($immediate === true) {
            $delay = 0;
        }

        // 30 second delay by default
        $this->logger->info(
            message: 'Scheduling smart SOLR warmup',
            context: [
                'total_imported'   => $totalImported,
                'recommended_mode' => $mode,
                'max_objects'      => $maxObjects,
                'delay_seconds'    => $delay,
            ]
        );

        return $this->scheduleSolrWarmup(
            importSummary: $importSummary,
            delaySeconds: $delay,
            mode: $mode,
            maxObjects: $maxObjects
        );
    }//end scheduleSmartSolrWarmup()
}//end class
