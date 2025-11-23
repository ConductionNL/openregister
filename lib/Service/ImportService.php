<?php

declare(strict_types=1);

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
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

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
    private array $schemaPropertiesCache = [];

    /**
     * Logger interface for logging operations
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * User manager for checking user context
     *
     * @var IUserManager
     */
    private readonly IUserManager $userManager;

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
     * @param IUserManager       $userManager        The user manager
     * @param IGroupManager      $groupManager       The group manager
     * @param IJobList           $jobList            The background job list
     */
    public function __construct(
        ObjectEntityMapper $objectEntityMapper,
        SchemaMapper $schemaMapper,
        ObjectService $objectService,
        LoggerInterface $logger,
        IUserManager $userManager,
        IGroupManager $groupManager,
        IJobList $jobList
    ) {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->schemaMapper       = $schemaMapper;
        $this->objectService      = $objectService;
        $this->logger             = $logger;
        $this->userManager        = $userManager;
        $this->groupManager       = $groupManager;
        $this->jobList            = $jobList;

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
     * Import data from Excel file asynchronously.
     *
     * @param string        $filePath  The path to the Excel file.
     * @param Register|null $register  Optional register to associate with imported objects.
     * @param Schema|null   $schema    Optional schema to associate with imported objects.
     * @param int           $chunkSize Number of rows to process in each chunk (default: 100).
     *
     * @return PromiseInterface Promise that resolves to import summary with created/updated/unchanged/errors.
     */
    public function importFromExcelAsync(
        string $filePath,
        ?Register $register=null,
        ?Schema $schema=null,
        int $chunkSize=self::DEFAULT_CHUNK_SIZE
    ): PromiseInterface {
        return new Promise(
            function (callable $resolve, callable $reject) use ($filePath, $register, $schema, $chunkSize) {
                // NO ERROR SUPPRESSION: Let Excel import errors bubble up immediately!
                $result = $this->importFromExcel(filePath: $filePath, register: $register, schema: $schema, chunkSize: $chunkSize);
                $resolve($result);
            }
        );

    }//end importFromExcelAsync()


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
     * @param string        $filePath   The path to the Excel file.
     * @param Register|null $register   Optional register to associate with imported objects.
     * @param Schema|null   $schema     Optional schema to associate with imported objects.
     * @param int           $chunkSize  Number of rows to process in each chunk (default: 100).
     * @param bool          $validation Whether to validate objects against schema definitions (default: false).
     * @param bool          $events     Whether to dispatch object lifecycle events (default: false).
     *
     * @return         array<string, array> Summary of import with sheet-based results.
     * @phpstan-return array<string, array{found: int, created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{found: int, created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */
    public function importFromExcel(string $filePath, ?Register $register=null, ?Schema $schema=null, int $chunkSize=self::DEFAULT_CHUNK_SIZE, bool $validation=false, bool $events=false, bool $rbac=true, bool $multi=true, bool $publish=false, ?IUser $currentUser=null): array
    {
        // Clear caches at the start of each import to prevent stale data issues.
        $this->clearCaches();

        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        // If we have a register but no schema, process each sheet as a different schema.
        if ($register !== null && $schema === null) {
            return $this->processMultiSchemaSpreadsheetAsync($spreadsheet, $register, $chunkSize, $validation, $events, $rbac, $multi, $publish, $currentUser);
        }

        // Single schema processing - use batch processing for better performance.
        $sheetTitle = $spreadsheet->getActiveSheet()->getTitle();
        $sheetSummary = $this->processSpreadsheetBatch($spreadsheet, $register, $schema, $chunkSize, $validation, $events, $rbac, $multi, $publish, $currentUser);

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
     * Import data from CSV file asynchronously.
     *
     * @param string        $filePath  The path to the CSV file.
     * @param Register|null $register  Optional register to associate with imported objects.
     * @param Schema|null   $schema    Optional schema to associate with imported objects.
     * @param int           $chunkSize Number of rows to process in each chunk (default: 100).
     *
     * @return PromiseInterface Promise that resolves to import summary with created/updated/unchanged/errors.
     */
    public function importFromCsvAsync(
        string $filePath,
        ?Register $register=null,
        ?Schema $schema=null,
        int $chunkSize=self::DEFAULT_CHUNK_SIZE
    ): PromiseInterface {
        return new Promise(
            function (callable $resolve, callable $reject) use ($filePath, $register, $schema, $chunkSize) {
                // NO ERROR SUPPRESSION: Let CSV import errors bubble up immediately!
                $result = $this->importFromCsv($filePath, $register, $schema, $chunkSize);
                $resolve($result);
            }
        );

    }//end importFromCsvAsync()


    /**
     * Import data from CSV file.
     *
     * @param string        $filePath   The path to the CSV file.
     * @param Register|null $register   Optional register to associate with imported objects.
     * @param Schema|null   $schema     Optional schema to associate with imported objects.
     * @param int           $chunkSize  Number of rows to process in each chunk (default: 100).
     * @param bool          $validation Whether to validate objects against schema definitions (default: false).
     * @param bool          $events     Whether to dispatch object lifecycle events (default: false).
     * @param bool          $rbac       Whether to enforce RBAC checks (default: true).
     * @param bool          $multi      Whether to enable multi-tenancy (default: true).
     * @param bool          $publish    Whether to publish objects immediately (default: false).
     * @param IUser|null    $currentUser Current user for RBAC checks (default: null).
     *
     * @return         array<string, array> Summary of import with sheet-based results.
     * @phpstan-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */
    public function importFromCsv(
        string $filePath,
        ?Register $register=null,
        ?Schema $schema=null,
        int $chunkSize=self::DEFAULT_CHUNK_SIZE,
        bool $validation=false,
        bool $events=false,
        bool $rbac=true,
        bool $multi=true,
        bool $publish=false,
        ?IUser $currentUser=null
    ): array {
        // Clear caches at the start of each import to prevent stale data issues.
        $this->clearCaches();

        // CSV can only handle a single schema.
        if ($schema === null) {
            throw new \InvalidArgumentException('CSV import requires a specific schema');
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
            $spreadsheet->getActiveSheet(),
            $register,
            $schema,
            $chunkSize,
            $validation,
            $events,
            $rbac,
            $multi,
            $publish,
            $currentUser
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
     * @param Spreadsheet $spreadsheet The spreadsheet to process
     * @param Register    $register    The register to associate with imported objects
     * @param int         $chunkSize   Number of rows to process in each chunk
     * @param bool        $validation  Whether to validate objects against schema definitions
     * @param bool        $events      Whether to dispatch object lifecycle events
     *
     * @return         array<string, array> Summary of import with sheet-based results
     * @phpstan-return array<string, array{found: int, created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{found: int, created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */
    private function processMultiSchemaSpreadsheetAsync(Spreadsheet $spreadsheet, Register $register, int $chunkSize, bool $validation=false, bool $events=false, bool $rbac=true, bool $multi=true, bool $publish=false, ?IUser $currentUser=null): array
    {
        $summary = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $schemaSlug = $worksheet->getTitle();
            $schema     = $this->getSchemaBySlug($schemaSlug);

            // Initialize sheet summary even if no schema found.
            $summary[$schemaSlug] = [
                'found'     => 0,
                'created'   => [],
                'updated'   => [],
                'unchanged' => [],  // TODO: Renamed from 'skipped' - more descriptive (objects skipped because content was unchanged)
                'errors'    => [],
                'schema'    => null,
                'debug'     => [
                    'headers'            => [],
                    'schemaProperties'   => [],
                    'processableHeaders' => [],
                ],
            ];

            // Skip sheets that don't correspond to a valid schema.
            if ($schema === null) {
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
            }

            // Add schema information to the summary.
            $summary[$schemaSlug]['schema'] = [
                'id'    => $schema->getId(),
                'title' => $schema->getTitle(),
                'slug'  => $schema->getSlug(),
            ];

            // Update debug information with schema properties.
            $schemaProperties = $schema->getProperties();
            $propertyKeys     = array_keys($schemaProperties);
            $summary[$schemaSlug]['debug']['schemaProperties'] = $propertyKeys;

            // Set the worksheet as active and process using batch saving for better performance.
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($worksheet));
            $sheetSummary = $this->processSpreadsheetBatch($spreadsheet, $register, $schema, $chunkSize, $validation, $events, $rbac, $multi, $publish, $currentUser);

            // Merge the sheet summary with the existing summary (preserve debug info).
            $summary[$schemaSlug] = array_merge($summary[$schemaSlug], $sheetSummary);
        }//end foreach

        // Schedule SOLR warmup job after successful multi-schema import.
        $this->scheduleSmartSolrWarmup($summary);

        return $summary;

    }//end processMultiSchemaSpreadsheetAsync()


    /**
     * Process spreadsheet data asynchronously with chunked processing
     *
     * @param Spreadsheet   $spreadsheet The spreadsheet to process
     * @param Register|null $register    Optional register to associate with imported objects
     * @param Schema|null   $schema      Optional schema to associate with imported objects
     * @param int           $chunkSize   Number of rows to process in each chunk
     *
     * @return         array<string, array> Summary of import: ['created'=>[], 'updated'=>[], 'unchanged'=>[], 'errors'=>[]]
     * @phpstan-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     * @psalm-return   array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     */
    private function processSpreadsheetAsync(
        Spreadsheet $spreadsheet,
        ?Register $register=null,
        ?Schema $schema=null,
        int $chunkSize=self::DEFAULT_CHUNK_SIZE
    ): array {
        $sheet      = $spreadsheet->getActiveSheet();
        $sheetTitle = $sheet->getTitle();
        $highestRow = $sheet->getHighestRow();

        // Step 1: Build column mapping array using PhpSpreadsheet built-in methods.
        $columnMapping = $this->buildColumnMapping($sheet);

        // Get schema properties for reference.
        if ($schema !== null) {
            $schemaProperties = $schema->getProperties();
        } else {
            $schemaProperties = [];
        }

        // Step 2: Process data in chunks to prevent memory overflow.
        $summary = [
            'found'     => 0,
            'created'   => [],
            'updated'   => [],
            'unchanged' => [],
            'errors'    => [],
        ];

        // Process rows in chunks.
        for ($startRow = 2; $startRow <= $highestRow; $startRow += $chunkSize) {
            $endRow       = min($startRow + $chunkSize - 1, $highestRow);
            $chunkSummary = $this->processChunk($sheet, $columnMapping, $startRow, $endRow, $register, $schema, $schemaProperties);

            // Merge chunk results into main summary.
            $summary['found']    += $chunkSummary['found'];
            $summary['created']   = array_merge($summary['created'], $chunkSummary['created']);
            $summary['updated']   = array_merge($summary['updated'], $chunkSummary['updated']);
            $summary['unchanged'] = array_merge($summary['unchanged'], $chunkSummary['unchanged']);
            $summary['errors']    = array_merge($summary['errors'], $chunkSummary['errors']);

            // Force garbage collection after each chunk to prevent memory leaks.
            if (function_exists('gc_collect_cycles') === true) {
                gc_collect_cycles();
            }
        }

        return $summary;

    }//end processSpreadsheetAsync()


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
     * @param Spreadsheet      $spreadsheet The spreadsheet to process
     * @param Register|null    $register    Optional register to associate with imported objects
     * @param Schema|null      $schema      Optional schema to associate with imported objects
     * @param int              $chunkSize   Number of rows to process in each chunk
     * @param bool             $validation  Whether to validate objects against schema definitions
     * @param bool             $events      Whether to dispatch object lifecycle events
     *
     * @return array<string, array> Sheet processing summary
     * @phpstan-return array<string, array{found: int, created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{found: int, created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */
    private function processSpreadsheetBatch(
        Spreadsheet $spreadsheet,
        ?Register $register=null,
        ?Schema $schema=null,
        int $chunkSize=self::DEFAULT_CHUNK_SIZE,
        bool $validation=false,
        bool $events=false,
        bool $rbac=true,
        bool $multi=true,
        bool $publish=false,
        ?IUser $currentUser=null
    ): array {
        $summary = [
            'found'     => 0,
            'created'   => [],
            'updated'   => [],
            'unchanged' => [],  // TODO: Renamed from 'skipped' - more descriptive
            'unchanged' => [],
            'errors'    => [],
        ];

        // REMOVED ERROR SUPPRESSION: Let bulk save errors bubble up immediately!

        $startTime = microtime(true);

        // Get the active sheet.
        $sheet = $spreadsheet->getActiveSheet();
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
            $rowData = $this->extractRowData($sheet, $columnMapping, $row);

            if (empty($rowData) === true) {
                continue; // Skip empty rows.
            }

            // Transform row data to object format.
            $object = $this->transformExcelRowToObject($rowData, $register, $schema, $row, $currentUser);

            if ($object !== null) {
                $allObjects[] = $object;
            }
        }

        $summary['found'] = count($allObjects);

        // Call saveObjects ONCE with all objects - NO ERROR SUPPRESSION!
        // This will reveal the real bulk save problem immediately.
        if (!empty($allObjects) && $register !== null && $schema !== null) {
            // Add publish date to all objects if publish is enabled.
            if ($publish === true) {
                $publishDate = (new \DateTime(datetime: 'now'))->format(format: 'c'); // ISO 8601 format.
                $allObjects = $this->addPublishedDateToObjects($allObjects, $publishDate);
            }

            $saveResult = $this->objectService->saveObjects($allObjects, $register, $schema, $rbac, $multi, $validation, $events);

            // Use the structured return from saveObjects with smart deduplication.
            // saveObjects returns ObjectEntity->jsonSerialize() arrays where UUID is in @self.id.
            $summary['created'] = array_map(fn($obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null, $saveResult['saved'] ?? []);
            $summary['updated'] = array_map(fn($obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null, $saveResult['updated'] ?? []);

            // TODO: Handle unchanged objects from smart deduplication (renamed from 'skipped')
            $summary['unchanged'] = array_map(fn($obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null, $saveResult['unchanged'] ?? []);

            // Add efficiency metrics from smart deduplication.
            $totalProcessed = count($summary['created']) + count($summary['updated']) + count($summary['unchanged']);
            if ($totalProcessed > 0 && count($summary['unchanged']) > 0) {
                $summary['deduplication_efficiency'] = round((count($summary['unchanged']) / $totalProcessed) * 100, 1) . '% operations avoided';
            }

            // Handle validation errors if validation was enabled.
            if ($validation === true && empty($saveResult['invalid'] ?? []) === false) {
                foreach (($saveResult['invalid'] ?? []) as $invalidItem) {
                    $summary['errors'][] = [
                        'sheet' => $sheetTitle,
                        'object' => $invalidItem['object'] ?? $invalidItem,
                        'error' => $invalidItem['error'] ?? 'Validation failed',
                        'type'  => $invalidItem['type'] ?? 'ValidationException',
                    ];
                }
            }
        }

        // NO ERROR SUPPRESSION: Row parsing errors will bubble up immediately - no need to collect them.

        $totalImportTime = microtime(true) - $startTime;
        $overallRowsPerSecond = count($allObjects) / max($totalImportTime, 0.001);

        return $summary;

    }//end processSpreadsheetBatch()


    /**
     * Process CSV sheet and import all objects in batches
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet      The worksheet to process
     * @param Register                                        $register   The register to associate with imported objects
     * @param Schema                                          $schema     The schema to associate with imported objects
     * @param int                                             $chunkSize  Number of rows to process in each chunk
     * @param bool                                            $validation Whether to validate objects against schema definitions
     * @param bool                                            $events     Whether to dispatch object lifecycle events
     *
     * @return array<string, array> Sheet processing summary
     * @phpstan-return array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */
    private function processCsvSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, Register $register, Schema $schema, int $chunkSize, bool $validation=false, bool $events=false, bool $rbac=true, bool $multi=true, bool $publish=false, ?IUser $currentUser=null): array
    {
        $summary = [
            'found'     => 0,
            'created'   => [],
            'updated'   => [],
            'unchanged' => [],  // TODO: Renamed from 'skipped' - more descriptive
            'unchanged' => [],
            'errors'    => [],
        ];

        // REMOVED ERROR SUPPRESSION: Let CSV bulk save errors bubble up immediately!

        $startTime = microtime(true);

        // Build column mapping from headers.
        $columnMapping = $this->buildColumnMapping($sheet);

        if (empty($columnMapping) === true) {
            $summary['errors'][] = [
                'row'   => 1,
                'object' => [],
                'error' => 'No valid headers found in CSV file',
            ];
            return $summary;
        }

        // Get total rows in the sheet.
        $highestRow = $sheet->getHighestRow();

        if ($highestRow <= 1) {
            $summary['errors'][] = [
                'row'   => 1,
                'object' => [],
                'error' => 'No data rows found in CSV file',
            ];
            return $summary;
        }

        // Parse ALL rows into objects array (no chunking here!).
        $allObjects = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            // NO ERROR SUPPRESSION: Let CSV row processing errors bubble up immediately!
            $rowData = $this->extractRowData($sheet, $columnMapping, $row);

            if (empty($rowData) === true) {
                continue; // Skip empty rows.
            }

            // Transform row data to object format.
            $object = $this->transformCsvRowToObject($rowData, $register, $schema, $row, $currentUser);

            if ($object !== null) {
                $allObjects[] = $object;
            }
        }

        $summary['found'] = count($allObjects);

        // Call saveObjects ONCE with all objects - NO ERROR SUPPRESSION!
        // This will reveal the real bulk save problem immediately.
        if (!empty($allObjects)) {
            // Log publish processing for debugging.
            $this->logger->debug(message: 'CSV import processing objects', context: [
                'objectCount' => count($allObjects),
                'publish' => $publish
            ]);

            // Add publish date to all objects if publish is enabled.
            if ($publish === true) {
                $publishDate = (new \DateTime(datetime: 'now'))->format(format: 'c'); // ISO 8601 format.
                $this->logger->debug(message: 'Adding publish date to CSV import objects', context: [
                    'publishDate' => $publishDate,
                    'objectCount' => count($allObjects)
                ]);
                $allObjects = $this->addPublishedDateToObjects($allObjects, $publishDate);

                // Log first object structure for debugging.
                if (!empty($allObjects[0]['@self'])) {
                    $this->logger->debug(message: 'First object @self structure after adding publish date', context: [
                        'selfData' => $allObjects[0]['@self']
                    ]);
                }
            } else {
                $this->logger->debug(message: 'Publish disabled for CSV import, not adding publish dates');
            }


            $saveResult = $this->objectService->saveObjects($allObjects, $register, $schema, $rbac, $multi, $validation, $events);

            // Use the structured return from saveObjects with smart deduplication.
            // saveObjects returns ObjectEntity->jsonSerialize() arrays where UUID is in @self.id.
            $summary['created'] = array_map(fn($obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null, $saveResult['saved'] ?? []);
            $summary['updated'] = array_map(fn($obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null, $saveResult['updated'] ?? []);

            // TODO: Handle unchanged objects from smart deduplication (renamed from 'skipped')
            $summary['unchanged'] = array_map(fn($obj) => $obj['@self']['id'] ?? $obj['uuid'] ?? $obj['id'] ?? null, $saveResult['unchanged'] ?? []);

            // Add efficiency metrics from smart deduplication.
            $totalProcessed = count($summary['created']) + count($summary['updated']) + count($summary['unchanged']);
            if ($totalProcessed > 0 && count($summary['unchanged']) > 0) {
                $summary['deduplication_efficiency'] = round((count($summary['unchanged']) / $totalProcessed) * 100, 1) . '% operations avoided';
            }

            // Handle validation errors if validation was enabled.
            if ($validation === true && empty($saveResult['invalid'] ?? []) === false) {
                foreach (($saveResult['invalid'] ?? []) as $invalidItem) {
                    $summary['errors'][] = [
                        'object' => $invalidItem['object'] ?? $invalidItem,
                        'error' => $invalidItem['error'] ?? 'Validation failed',
                        'type' => $invalidItem['type'] ?? 'ValidationException',
                    ];
                }
            }
        }

        // NO ERROR SUPPRESSION: Row parsing errors will bubble up immediately - no need to collect them.

        $totalImportTime = microtime(true) - $startTime;
        $overallRowsPerSecond = count($allObjects) / max($totalImportTime, 0.001);

        // ADD PERFORMANCE METRICS: Include timing and speed metrics like SaveObjects does.
        $summary['performance'] = [
            'totalTime'        => round($totalImportTime, 3),
            'totalTimeMs'      => round($totalImportTime * 1000, 2),
            'objectsPerSecond' => round($overallRowsPerSecond, 2),
            'totalProcessed'   => count($allObjects),
            'totalFound'       => $summary['found'],
            'efficiency'       => $summary['found'] > 0 ? round((count($allObjects) / $summary['found']) * 100, 1) : 0,
        ];

        return $summary;

    }//end processCsvSheet()


    /**
     * Process spreadsheet chunks concurrently using ReactPHP for better performance
     *
     * This method uses ReactPHP promises to process multiple chunks concurrently,
     * which can significantly improve performance for large imports while maintaining
     * memory efficiency through smaller chunks.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet to process
     * @param array<string, string>                         $columnMapping Column mapping
     * @param int                                           $startRow      Starting row number
     * @param int                                           $endRow        Ending row number
     * @param Register                                      $register      The register
     * @param Schema                                        $schema        The schema
     * @param int                                           $chunkSize     Size of each processing chunk
     * @param bool                                          $validation    Whether to validate objects
     * @param bool                                          $events        Whether to dispatch events
     *
     * @return array<string, array> Processing summary
     * @phpstan-return array{found: int, created: array<string>, updated: array<string>, errors: array<mixed>}
     * @psalm-return   array{found: int, created: array<string>, updated: array<string>, errors: array<mixed>}
     */
    private function processSpreadsheetConcurrent(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $columnMapping,
        int $startRow,
        int $endRow,
        Register $register,
        Schema $schema,
        int $chunkSize = self::MIN_CONCURRENT_CHUNK_SIZE,
        bool $validation = false,
        bool $events = false
    ): array {
        $summary = [
            'found'   => 0,
            'created' => [],
            'updated' => [],
            'errors'  => [],
        ];

        // Create chunks for concurrent processing.
        $chunks = [];
        for ($chunkStart = $startRow; $chunkStart <= $endRow; $chunkStart += $chunkSize) {
            $chunkEnd = min($chunkStart + $chunkSize - 1, $endRow);
            $chunks[] = ['start' => $chunkStart, 'end' => $chunkEnd];
        }


        // Process chunks in concurrent batches.
        $batchSize = self::MAX_CONCURRENT;
        for ($i = 0; $i < count($chunks); $i += $batchSize) {
            $batch = array_slice($chunks, $i, $batchSize);
            $promises = [];

            // Create promises for concurrent chunk processing.
            foreach ($batch as $chunk) {
                $promises[] = new Promise(function (callable $resolve, callable $reject) use ($sheet, $columnMapping, $chunk, $register, $schema, $validation, $events) {
                    // NO ERROR SUPPRESSION: Let Excel chunk processing errors bubble up immediately!
                    // Process chunk.
                    $chunkResult = $this->processExcelChunk($sheet, $columnMapping, $chunk['start'], $chunk['end'], $register, $schema);

                    if (!empty($chunkResult['objects'])) {
                        // Save objects for this chunk.
                        $saveResult = $this->objectService->saveObjects(
                            $chunkResult['objects'],
                            $register,
                            $schema,
                            true,
                            true,
                            $validation,
                            $events
                        );

                        $result = [
                            'found'   => count($chunkResult['objects']),
                            'created' => array_map(fn($obj) => $obj['uuid'] ?? $obj['id'] ?? null, $saveResult['saved'] ?? []),
                            'updated' => array_map(fn($obj) => $obj['uuid'] ?? $obj['id'] ?? null, $saveResult['updated'] ?? []),
                            'errors'  => $chunkResult['errors'] ?? [],
                        ];

                        // Add validation errors if any.
                        if ($validation === true && empty($saveResult['invalid'] ?? []) === false) {
                            foreach ($saveResult['invalid'] as $invalidItem) {
                                $result['errors'][] = [
                                    'rows'  => $chunk['start'] . '-' . $chunk['end'],
                                    'object' => $invalidItem['object'] ?? $invalidItem,
                                    'error' => $invalidItem['error'] ?? 'Validation failed',
                                    'type'  => $invalidItem['type'] ?? 'ValidationException',
                                ];
                            }
                        }
                    } else {
                        $result = [
                            'found'   => 0,
                            'created' => [],
                            'updated' => [],
                            'errors'  => $chunkResult['errors'] ?? [],
                        ];
                    }

                    $resolve($result);
                });
            }

            // Process batch of promises concurrently.
            // NO ERROR SUPPRESSION: Let concurrent processing errors bubble up immediately!
            $batchResults = \React\Async\await(\React\Promise\all($promises));

            // Merge results from concurrent processing.
            foreach ($batchResults as $result) {
                $summary['found'] += $result['found'];
                $summary['created'] = array_merge($summary['created'], $result['created']);
                $summary['updated'] = array_merge($summary['updated'], $result['updated']);
                $summary['errors'] = array_merge($summary['errors'], $result['errors']);
            }


            // Memory cleanup after each batch.
            unset($batchResults, $promises);
            gc_collect_cycles();
        }

        return $summary;
    }//end processSpreadsheetConcurrent()


    /**
     * Process a chunk of CSV rows and prepare objects for batch saving
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet
     * @param array<string, string>                         $columnMapping Column mapping
     * @param int                                           $startRow      Starting row number
     * @param int                                           $endRow        Ending row number
     * @param Register                                      $register      The register
     * @param Schema                                        $schema        The schema
     *
     * @return array<string, array> Chunk processing result
     * @phpstan-return array{objects: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     * @psalm-return   array{objects: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     */
    private function processCsvChunk(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $columnMapping,
        int $startRow,
        int $endRow,
        Register $register,
        Schema $schema
    ): array {
        $objects = [];
        $startMemory = memory_get_usage(true);

        for ($row = $startRow; $row <= $endRow; $row++) {
            // NO ERROR SUPPRESSION: Let CSV chunk processing errors bubble up immediately!
            $rowData = $this->extractRowData($sheet, $columnMapping, $row);

            if (empty($rowData)) {
                // Skip empty rows.
                continue;
            }

            // Transform row data to object format.
            $object = $this->transformCsvRowToObject($rowData, $register, $schema, $row);

            if ($object !== null) {
                $objects[] = $object;
            }

            // Memory management: check memory usage every 10 rows.
            if ($row % 10 === 0) {
                $currentMemory = memory_get_usage(true);
                $memoryIncrease = $currentMemory - $startMemory;

                // Log memory usage for monitoring.
                if ($memoryIncrease > 50 * 1024 * 1024) { // 50MB threshold
                }

                // Force garbage collection if memory usage is high.
                if ($memoryIncrease > 100 * 1024 * 1024) { // 100MB threshold
                    gc_collect_cycles();
                }
            }
        }

        // Final memory cleanup.
        $finalMemory = memory_get_usage(true);
        $totalMemoryUsed = $finalMemory - $startMemory;

        return [
            'objects' => $objects,
        ];

    }//end processCsvChunk()


    /**
     * Transform CSV row data to object format for batch saving
     *
     * @param array    $rowData  Row data from CSV
     * @param Register $register The register
     * @param Schema   $schema   The schema
     * @param int      $rowIndex Row index for error reporting
     *
     * @return array<string, mixed>|null Object data or null if transformation fails
     */
    private function transformCsvRowToObject(array $rowData, Register $register, Schema $schema, int $rowIndex, ?IUser $currentUser=null): ?array
    {
        // Use instance cache instead of static to prevent issues between requests.
        $schemaId = $schema->getId();

        if (!isset($this->schemaPropertiesCache[$schemaId])) {
            $this->schemaPropertiesCache[$schemaId] = $schema->getProperties();
        }
        $schemaProperties = $this->schemaPropertiesCache[$schemaId];

        // Pre-allocate arrays for better performance.
        $objectData = [];
        $selfData = [
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

            $firstChar = $key[0] ?? '';

            if ($firstChar === '_') {
                // REQUIREMENT: Columns starting with _ are completely ignored.
                continue;
            } else if ($firstChar === '@') {
                // REQUIREMENT: @ columns only processed if user is admin.
                if ($isAdmin === false) {
                    continue; // Skip @ columns for non-admin users.
                }

                if (str_starts_with($key, '@self.')) {
                    // Move properties starting with @self. to @self array and remove the @self. prefix.
                    $selfPropertyName = substr($key, 6);

                    // Transform special @self properties.
                    $selfData[$selfPropertyName] = $this->transformSelfProperty($selfPropertyName, $value);
                }
                // Note: Other @ columns that don't start with @self. are ignored
            } else {
                // Regular properties - transform based on schema if needed.
                if (isset($schemaProperties[$key])) {
                    $objectData[$key] = $this->transformValueByType($value, $schemaProperties[$key]);
                } else {
                    $objectData[$key] = $value;
                }
            }
        }

        // Add ID if present in the data (for updates) - check once at the end.
        if (!empty($rowData['id'])) {
            $selfData['id'] = $rowData['id'];
        }

        // Add @self array to object data.
        $objectData['@self'] = $selfData;

        // Validate that we're not accidentally creating invalid properties.
        $this->validateObjectProperties($objectData, $schemaId);

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
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value)) {
            try {
                $dateTime = new \DateTime(datetime: $value);
                return $dateTime->format(format: 'Y-m-d H:i:s');
            } catch (\Exception $e) {
                // Fallback to original value if parsing fails.
                return $value;
            }
        }

        // Handle ISO 8601 format without timezone (e.g., "2025-01-01T00:00:00").
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value)) {
            try {
                $dateTime = new \DateTime(datetime: $value);
                return $dateTime->format(format: 'Y-m-d H:i:s');
            } catch (\Exception $e) {
                // Fallback to original value if parsing fails.
                return $value;
            }
        }

        // Handle date-only format (e.g., "2025-01-01").
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' 00:00:00';
        }

        // Return original value if no transformation needed.
        return $value;
    }


    /**
     * Transform @self properties based on their type
     *
     * @param string $propertyName The name of the @self property
     * @param string $value The value to transform
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
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
                return $value;
            }
            // If not a valid UUID, return as-is (might be a slug that needs resolution).
            return $value;
        }

        // Return original value for other properties.
        return $value;
    }


    /**
     * Process a chunk of Excel rows and prepare objects for batch saving
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet
     * @param array<string, string>                         $columnMapping Column mapping
     * @param int                                           $startRow      Starting row number
     * @param int                                           $endRow        Ending row number
     * @param Register|null                                 $register      Optional register
     * @param Schema|null                                   $schema        Optional schema
     *
     * @return array<string, array> Chunk processing result
     * @phpstan-return array{objects: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     * @psalm-return   array{objects: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     */
    private function processExcelChunk(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $columnMapping,
        int $startRow,
        int $endRow,
        ?Register $register,
        ?Schema $schema
    ): array {
        $objects = [];

        for ($row = $startRow; $row <= $endRow; $row++) {
            // NO ERROR SUPPRESSION: Let Excel chunk processing errors bubble up immediately!
            $rowData = $this->extractRowData($sheet, $columnMapping, $row);

            if (empty($rowData)) {
                // Skip empty rows.
                continue;
            }

            // Transform row data to object format.
            $object = $this->transformExcelRowToObject($rowData, $register, $schema, $row);

            if ($object !== null) {
                $objects[] = $object;
            }
        }

        return [
            'objects' => $objects,
        ];

    }//end processExcelChunk()


    /**
     * Transform Excel row data to object format for batch saving
     *
     * @param array         $rowData  Row data from Excel
     * @param Register|null $register Optional register
     * @param Schema|null   $schema   Optional schema
     * @param int           $rowIndex Row index for error reporting
     *
     * @return array<string, mixed>|null Object data or null if transformation fails
     */
    private function transformExcelRowToObject(array $rowData, ?Register $register, ?Schema $schema, int $rowIndex, ?IUser $currentUser=null): ?array
    {
        // Separate regular properties from system properties.
        $objectData = [];
        $selfData = [];

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
                    continue; // Skip @ columns for non-admin users.
                }

                if (str_starts_with($key, '@self.') === true) {
                    // Move properties starting with @self. to @self array and remove the @self. prefix.
                    $selfPropertyName = substr($key, 6);

                    // Transform special @self properties.
                    $selfData[$selfPropertyName] = $this->transformSelfProperty($selfPropertyName, $value);
                }
                // Note: Other @ columns that don't start with @self. are ignored
            } else {
                // Regular properties go to main object data.
                $objectData[$key] = $value;
            }
        }

        // Build @self section with metadata if available.
        if ($register !== null) {
            $selfData['register'] = $register->getId();
        }
        if ($schema !== null) {
            $selfData['schema'] = $schema->getId();
        }

        // Add ID if present in the data (for updates).
        if (isset($rowData['id']) && !empty($rowData['id'])) {
            $selfData['id'] = $rowData['id'];
        }

        // Add @self array to object data if we have self properties.
        if (!empty($selfData)) {
            $objectData['@self'] = $selfData;
        }

        // Transform object data based on schema property types if schema is available.
        if ($schema !== null) {
            $transformedData = $this->transformObjectBySchema($objectData, $schema);
        } else {
            $transformedData = $objectData;
        }

        return $transformedData;

    }//end transformExcelRowToObject()


    /**
     * Build column mapping from spreadsheet headers
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet The worksheet
     *
     * @return array<string, string> Column mapping (column letter -> column name)
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

            if ($cellValue !== null && trim($cellValue) !== '') {
                $cleanColumnName = trim((string) $cellValue);
                $columnMapping[$columnLetter] = $cleanColumnName;
            } else {
                // Found empty column, stop here.
                break;
            }

            $columnIndex++;
        }

        return $columnMapping;

    }//end buildColumnMapping()


    /**
     * Process a chunk of rows asynchronously
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet            The worksheet
     * @param array<string, string>                         $columnMapping    Column mapping
     * @param int                                           $startRow         Starting row number
     * @param int                                           $endRow           Ending row number
     * @param Register|null                                 $register         Optional register
     * @param Schema|null                                   $schema           Optional schema
     * @param array                                         $schemaProperties Schema properties
     *
     * @return array<string, array> Chunk processing summary
     */
    private function processChunk(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $columnMapping,
        int $startRow,
        int $endRow,
        ?Register $register,
        ?Schema $schema,
        array $schemaProperties
    ): array {
        $chunkSummary = [
            'found'     => 0,
            'created'   => [],
            'updated'   => [],
            'unchanged' => [],
            'errors'    => [],
        ];

        // Extract row data for this chunk.
        $processedRows = [];
        for ($row = $startRow; $row <= $endRow; $row++) {
            $rowData = $this->extractRowData($sheet, $columnMapping, $row);
            if (empty($rowData) === false) {
                $processedRows[] = $rowData;
            }
        }

        $chunkSummary['found'] = count($processedRows);

        // Process rows using ReactPHP promises for concurrent operations.
        if ($register !== null && $schema !== null && empty($processedRows) === false) {
            $promises = [];

            foreach ($processedRows as $index => $rowData) {
                $promises[] = new Promise(
                        function (callable $resolve, callable $reject) use ($rowData, $index, $register, $schema, $startRow) {
                            // NO ERROR SUPPRESSION: Let processRow errors bubble up immediately!
                            $result = $this->processRow($rowData, $register, $schema, $startRow + $index);
                            $resolve($result);
                        }
                        );
            }

            // Process promises in batches to limit concurrency.
            $batchSize = self::MAX_CONCURRENT;
            for ($i = 0; $i < count($promises); $i += $batchSize) {
                $batch   = array_slice($promises, $i, $batchSize);
                $results = \React\Async\await(\React\Promise\all($batch));

                foreach ($results as $result) {
                    if (isset($result['error']) === true) {
                        $chunkSummary['errors'][] = $result['error'];
                    } else {
                        if ($result['wasExisting'] === true) {
                            $chunkSummary['updated'][] = $result['uuid'];
                        } else {
                            $chunkSummary['created'][] = $result['uuid'];
                        }
                    }
                }
            }
        }//end if

        return $chunkSummary;

    }//end processChunk()


    /**
     * Extract data from a single row
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet
     * @param array<string, string>                         $columnMapping Column mapping
     * @param int                                           $row           Row number
     *
     * @return array<string, string> Row data
     */
    private function extractRowData(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $columnMapping, int $row): array
    {
        $rowData = [];
        // Name -> value.
        $hasData = false;

        // Loop through each column in the mapping.
        foreach ($columnMapping as $columnLetter => $columnName) {
            $cellValue = $sheet->getCell($columnLetter.$row)->getValue();

            // Convert cell value to string and trim whitespace.
            if ($cellValue !== null) {
                $cleanCellValue = trim((string) $cellValue);
            } else {
                $cleanCellValue = '';
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
     * Process a single row
     *
     * @param array    $rowData  Row data
     * @param Register $register Register
     * @param Schema   $schema   Schema
     * @param int      $rowIndex Row index for error reporting
     *
     * @return array<string, mixed> Processing result
     */
    private function processRow(array $rowData, Register $register, Schema $schema, int $rowIndex): array
    {
        // NO ERROR SUPPRESSION: Let processRow errors bubble up immediately!
        // Separate regular properties from system properties starting with _ or @self.
        $objectData = [];
        $selfData   = [];

        foreach ($rowData as $key => $value) {
            if (str_starts_with($key, '_') === true) {
                // Move properties starting with _ to @self array and remove the _.
                $selfPropertyName = substr($key, 1);
                // Remove the _ prefix.
                $selfData[$selfPropertyName] = $value;
            } else if (str_starts_with($key, '@self.') === true) {
                // Move properties starting with @self. to @self array and remove the @self. prefix.
                $selfPropertyName = substr($key, 6);
                // Remove the @self. prefix (6 characters).
                $selfData[$selfPropertyName] = $value;
            } else {
                // Regular properties go to main object data.
                $objectData[$key] = $value;
            }
        }

        // Add @self array to object data if we have self properties.
        if (empty($selfData) === false) {
            $objectData['@self'] = $selfData;
        }

        // Transform object data based on schema property types.
        $objectData = $this->transformObjectBySchema($objectData, $schema);

        // Get the object ID for tracking updates vs creates.
        $objectId    = $rowData['id'] ?? null;
        $wasExisting = false;

        // Check if object exists (for reporting purposes only).
        if ($objectId !== null) {
            // NO ERROR SUPPRESSION: Let object find errors bubble up immediately!
            $existingObject = $this->objectEntityMapper->find($objectId);
            $wasExisting    = true;
        }

        // Save the object (ObjectService handles create vs update logic).
        $savedObject = $this->objectService->saveObject(
            $objectData,
            null,
            $register,
            $schema,
            $objectId
        );

        return [
            'uuid'        => $savedObject->getUuid(),
            'wasExisting' => $wasExisting,
        ];
    }//end processRow()


    /**
     * Get schema by slug
     *
     * @param string $slug The schema slug
     *
     * @return Schema|null The schema or null if not found
     */
    private function getSchemaBySlug(string $slug): ?Schema
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
            $transformedData[$propertyName] = $this->transformValueByType($value, $propertyDef);
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
                if (isset($propertyDef['objectConfiguration']['handling'])
                    && $propertyDef['objectConfiguration']['handling'] === 'related-object') {
                    // For related objects, store UUID strings directly instead of wrapping in objects.
                    return (string) $value;
                }
                return $this->stringToObject($value);

            default:
                return (string) $value;
        }

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
     * Estimate data complexity by analyzing a sample of rows
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet
     * @param array<string, string>                         $columnMapping Column mapping
     * @param int                                           $sampleSize    Number of rows to sample
     *
     * @return float Average field length across sampled rows
     */
    private function estimateDataComplexity(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $columnMapping, int $sampleSize): float
    {
        $totalLength = 0;
        $fieldCount = 0;
        $startRow = 2; // Skip header

        for ($row = $startRow; $row < $startRow + $sampleSize; $row++) {
            foreach ($columnMapping as $columnLetter => $columnName) {
                $cellValue = $sheet->getCell($columnLetter . $row)->getValue();
                if ($cellValue !== null) {
                    $totalLength += strlen((string) $cellValue);
                    $fieldCount++;
                }
            }
        }

        return $fieldCount > 0 ? $totalLength / $fieldCount : 50; // Default to 50 if no data
    }//end estimateDataComplexity()


    /**
     * Calculate optimal chunk size based on data complexity
     *
     * @param int   $baseChunkSize     Base chunk size
     * @param float $avgFieldLength    Average field length
     * @param int   $columnCount       Number of columns
     *
     * @return int Optimized chunk size
     */
    private function calculateOptimalChunkSize(int $baseChunkSize, float $avgFieldLength, int $columnCount): int
    {
        // Calculate complexity score.
        $complexityScore = ($avgFieldLength * $columnCount) / 1000; // Normalize to reasonable range

        // Adjust chunk size based on complexity.
        if ($complexityScore > 10) {
            // Very complex data - use minimal chunk size.
            return max(self::MINIMAL_CHUNK_SIZE, intval($baseChunkSize / 4));
        } else if ($complexityScore > 5) {
            // Moderately complex data - reduce chunk size.
            return max(self::MINIMAL_CHUNK_SIZE, intval($baseChunkSize / 2));
        } else if ($complexityScore > 2) {
            // Slightly complex data - minor reduction.
            return max(self::MINIMAL_CHUNK_SIZE, intval($baseChunkSize * 0.8));
        }

        // Simple data - use base chunk size.
        return $baseChunkSize;
    }//end calculateOptimalChunkSize()


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
     * @param string $schemaId   Schema ID for debugging
     *
     * @return void
     */
    private function validateObjectProperties(array $objectData, string $schemaId): void
    {
        // Valid ObjectEntity properties (excluding @self which is handled separately).
        $validProperties = [
            'uuid', 'slug', 'uri', 'version', 'register', 'schema', 'object',
            'files', 'relations', 'locked', 'owner', 'authorization', 'folder',
            'application', 'organisation', 'validation', 'deleted', 'geo',
            'retention', 'size', 'schemaVersion', 'updated', 'created',
            'published', 'depublished', 'name', 'description', 'summary',
            'image', 'groups', 'expires', '@self'
        ];

        // Check for invalid properties (common mistakes).
        $invalidProperties = ['data', 'content', 'body', 'payload'];

        foreach ($objectData as $key => $value) {
            // Skip @self as it's handled separately.
            if ($key === '@self') {
                continue;
            }

            // Check for invalid properties that commonly cause issues.
            if (in_array($key, $invalidProperties)) {
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
            if (!isset($object['@self'])) {
                $object['@self'] = [];
            }

            // Only add published date if not already set (from @self.published column).
            if (!isset($object['@self']['published']) || empty($object['@self']['published'])) {
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
        int $delaySeconds = 30,
        string $mode = 'serial',
        int $maxObjects = 5000
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
                'maxObjects' => $maxObjects,
                'mode' => $mode,
                'collectErrors' => false, // Keep it fast for post-import warmup
                'triggeredBy' => 'import_completion',
                'importSummary' => [
                    'totalImported' => $totalImported,
                    'sheetsProcessed' => count($importSummary),
                    'importTimestamp' => date('c')
                ]
            ];

            // Schedule the job with delay.
            $executeAfter = time() + $delaySeconds;
            $this->jobList->add(SolrWarmupJob::class, $jobArguments, $executeAfter);

            $this->logger->info(message: '🔥 SOLR Warmup Job Scheduled', context: [
                'total_imported' => $totalImported,
                'warmup_mode' => $mode,
                'max_objects' => $maxObjects,
                'delay_seconds' => $delaySeconds,
                'execute_after' => date('Y-m-d H:i:s', $executeAfter),
                'triggered_by' => 'import_completion'
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to schedule SOLR warmup job', context: [
                'error' => $e->getMessage(),
                'import_summary' => $importSummary
            ]);

            return false;
        }
    }//end scheduleSolrWarmup()

    /**
     * Calculate total objects imported from import summary
     *
     * @param array $importSummary Import summary from Excel/CSV import
     *
     * @return int Total number of objects imported
     */
    private function calculateTotalImported(array $importSummary): int
    {
        $total = 0;

        foreach ($importSummary as $sheetName => $sheetSummary) {
            if (is_array($sheetSummary)) {
                $created = count($sheetSummary['created'] ?? []);
                $updated = count($sheetSummary['updated'] ?? []);
                $total += $created + $updated;
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
     */
    public function getRecommendedWarmupMode(int $totalImported): string
    {
        if ($totalImported > 10000) {
            return 'hyper'; // Fast mode for large imports
        } elseif ($totalImported > 1000) {
            return 'parallel'; // Balanced mode for medium imports
        } else {
            return 'serial'; // Safe mode for small imports
        }
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
     */
    public function scheduleSmartSolrWarmup(array $importSummary, bool $immediate = false): bool
    {
        $totalImported = $this->calculateTotalImported($importSummary);

        if ($totalImported === 0) {
            return false;
        }

        // Smart configuration based on import size.
        $mode = $this->getRecommendedWarmupMode($totalImported);
        $maxObjects = min($totalImported * 2, 15000); // Index up to 2x imported objects, max 15k
        $delay = $immediate ? 0 : 30; // 30 second delay by default

        $this->logger->info(message: 'Scheduling smart SOLR warmup', context: [
            'total_imported' => $totalImported,
            'recommended_mode' => $mode,
            'max_objects' => $maxObjects,
            'delay_seconds' => $delay
        ]);

        return $this->scheduleSolrWarmup($importSummary, $delay, $mode, $maxObjects);
    }//end scheduleSmartSolrWarmup()


}//end class
