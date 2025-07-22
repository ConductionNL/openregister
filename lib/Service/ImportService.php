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
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
    private const DEFAULT_CHUNK_SIZE = 100;

    /**
     * Maximum concurrent operations
     *
     * @var int
     */
    private const MAX_CONCURRENT = 50;


    /**
     * Constructor for the ImportService
     *
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param SchemaMapper       $schemaMapper       The schema mapper
     * @param ObjectService      $objectService      The object service
     */
    public function __construct(ObjectEntityMapper $objectEntityMapper, SchemaMapper $schemaMapper, ObjectService $objectService)
    {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->schemaMapper       = $schemaMapper;
        $this->objectService      = $objectService;

    }//end __construct()


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
    public function importFromExcelAsync(string $filePath, ?Register $register=null, ?Schema $schema=null, int $chunkSize=self::DEFAULT_CHUNK_SIZE): PromiseInterface
    {
        return new Promise(
            function (callable $resolve, callable $reject) use ($filePath, $register, $schema, $chunkSize) {
                try {
                    $result = $this->importFromExcel($filePath, $register, $schema, $chunkSize);
                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject($e);
                }
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
     * @phpstan-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */
    public function importFromExcel(string $filePath, ?Register $register=null, ?Schema $schema=null, int $chunkSize=self::DEFAULT_CHUNK_SIZE): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        // If we have a register but no schema, process each sheet as a different schema.
        if ($register !== null && $schema === null) {
            return $this->processMultiSchemaSpreadsheetAsync($spreadsheet, $register, $chunkSize);
        }

        // Single schema processing - return in sheet-based format for consistency.
        $sheetTitle   = $spreadsheet->getActiveSheet()->getTitle();
        $sheetSummary = $this->processSpreadsheetAsync($spreadsheet, $register, $schema, $chunkSize);

        // Add schema information to the summary (consistent with multi-sheet Excel import).
        if ($schema !== null) {
            $sheetSummary['schema'] = [
                'id'    => $schema->getId(),
                'title' => $schema->getTitle(),
                'slug'  => $schema->getSlug(),
            ];
        }

        // Return in sheet-based format for consistency.
        return [$sheetTitle => $sheetSummary];

    }//end importFromExcel()


    /**
     * Import data from CSV file asynchronously.
     *
     * @param string        $filePath  The path to the CSV file.
     * @param Register|null $register  Optional register to associate with imported objects.
     * @param Schema|null   $schema    Optional schema to associate with imported objects.
     * @param int           $chunkSize Number of rows to process in each chunk (default: 100).
     *
     * @return PromiseInterface<array<string, array>> Promise that resolves to import summary.
     */
    public function importFromCsvAsync(string $filePath, ?Register $register=null, ?Schema $schema=null, int $chunkSize=self::DEFAULT_CHUNK_SIZE): PromiseInterface
    {
        return new Promise(
            function (callable $resolve, callable $reject) use ($filePath, $register, $schema, $chunkSize) {
                try {
                    $result = $this->importFromCsv($filePath, $register, $schema, $chunkSize);
                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );

    }//end importFromCsvAsync()


    /**
     * Import data from CSV file.
     *
     * @param string        $filePath  The path to the CSV file.
     * @param Register|null $register  Optional register to associate with imported objects.
     * @param Schema|null   $schema    Optional schema to associate with imported objects.
     * @param int           $chunkSize Number of rows to process in each chunk (default: 100).
     *
     * @return         array<string, array> Summary of import with sheet-based results.
     * @phpstan-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */
    public function importFromCsv(string $filePath, ?Register $register=null, ?Schema $schema=null, int $chunkSize=self::DEFAULT_CHUNK_SIZE): array
    {
        // CSV can only handle a single schema.
        if ($schema === null) {
            throw new \InvalidArgumentException('CSV import requires a specific schema');
        }

        $reader = new Csv();
        $reader->setReadDataOnly(true);
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $spreadsheet = $reader->load($filePath);

        // Get the sheet title for CSV (usually just 'Worksheet' or similar).
        $sheetTitle   = $spreadsheet->getActiveSheet()->getTitle();
        $sheetSummary = $this->processSpreadsheetAsync($spreadsheet, $register, $schema, $chunkSize);

        // Add schema information to the summary (consistent with Excel import).
        $sheetSummary['schema'] = [
            'id'    => $schema->getId(),
            'title' => $schema->getTitle(),
            'slug'  => $schema->getSlug(),
        ];

        // Return in sheet-based format for consistency.
        return [$sheetTitle => $sheetSummary];

    }//end importFromCsv()


    /**
     * Process spreadsheet with multiple schemas asynchronously
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to process
     * @param Register    $register    The register to associate with imported objects
     * @param int         $chunkSize   Number of rows to process in each chunk
     *
     * @return         array<string, array> Summary of import with sheet-based results
     * @phpstan-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return   array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */
    private function processMultiSchemaSpreadsheetAsync(Spreadsheet $spreadsheet, Register $register, int $chunkSize): array
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
                'unchanged' => [],
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

            // Set the worksheet as active and process.
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($worksheet));
            $sheetSummary = $this->processSpreadsheetAsync($spreadsheet, $register, $schema, $chunkSize);

            // Merge the sheet summary with the existing summary (preserve debug info).
            $summary[$schemaSlug] = array_merge($summary[$schemaSlug], $sheetSummary);
        }//end foreach

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
    private function processSpreadsheetAsync(Spreadsheet $spreadsheet, ?Register $register=null, ?Schema $schema=null, int $chunkSize=self::DEFAULT_CHUNK_SIZE): array
    {
        $sheet      = $spreadsheet->getActiveSheet();
        $sheetTitle = $sheet->getTitle();
        $highestRow = $sheet->getHighestRow();

        // Step 1: Build column mapping array using PhpSpreadsheet built-in methods.
        $columnMapping = $this->buildColumnMapping($sheet);

        // Get schema properties for reference.
        $schemaProperties = ($schema !== null) ? $schema->getProperties() : [];

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
    private function processChunk(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $columnMapping, int $startRow, int $endRow, ?Register $register, ?Schema $schema, array $schemaProperties): array
    {
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
                            try {
                                $result = $this->processRow($rowData, $register, $schema, $startRow + $index);
                                $resolve($result);
                            } catch (\Throwable $e) {
                                $reject($e);
                            }
                        }
                        );
            }

            // Process promises in batches to limit concurrency.
            $batchSize = self::MAX_CONCURRENT;
            for ($i = 0; $i < count($promises); $i += $batchSize) {
                $batch   = array_slice($promises, $i, $batchSize);
                $results = \React\Async\await(\React\Promise\all($batch));

                foreach ($results as $result) {
                    if (isset($result['error'])) {
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
        // name -> value
        $hasData = false;

        // Loop through each column in the mapping.
        foreach ($columnMapping as $columnLetter => $columnName) {
            $cellValue = $sheet->getCell($columnLetter.$row)->getValue();

            // Convert cell value to string and trim whitespace.
            $cleanCellValue = $cellValue !== null ? trim((string) $cellValue) : '';

            if ($cleanCellValue !== '') {
                $rowData[$columnName] = $cleanCellValue;
                $hasData = true;
            }
        }

        return ($hasData === true) ? $rowData : [];

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
        try {
            // Separate regular properties from system properties (starting with _)
            $objectData = [];
            $selfData   = [];

            foreach ($rowData as $key => $value) {
                if (str_starts_with($key, '_')) {
                    // Move properties starting with _ to @self array and remove the _
                    $selfPropertyName = substr($key, 1);
                    // Remove the _ prefix
                    $selfData[$selfPropertyName] = $value;
                } else {
                    // Regular properties go to main object data
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
                try {
                    $existingObject = $this->objectEntityMapper->find($objectId);
                    $wasExisting    = true;
                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                    // Object not found, will create new.
                    $wasExisting = false;
                } catch (\Exception $e) {
                    // Other errors - assume it doesn't exist.
                    $wasExisting = false;
                }
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
        } catch (\Exception $e) {
            error_log("[ImportService] Error processing row ".$rowIndex.": ".$e->getMessage());
            return [
                'error' => [
                    'row'   => $rowIndex,
                    'data'  => $rowData,
                    'error' => $e->getMessage(),
                ],
            ];
        }//end try

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
        try {
            $schema = $this->schemaMapper->find($slug);
            return $schema;
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Fallback: Search all schemas for case-insensitive match
            try {
                $allSchemas = $this->schemaMapper->findAll();

                foreach ($allSchemas as $schema) {
                    // Try exact match first
                    if ($schema->getSlug() === $slug) {
                        return $schema;
                    }

                    // Try case-insensitive match
                    if (strtolower($schema->getSlug()) === strtolower($slug)) {
                        return $schema;
                    }
                }

                return null;
            } catch (\Exception $fallbackException) {
                return null;
            }
        } catch (\Exception $e) {
            return null;
        }//end try

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
        try {
            $schemaProperties = $schema->getProperties();
            $transformedData  = [];

            foreach ($objectData as $propertyName => $value) {
                // Skip @self array - it's handled separately
                if ($propertyName === '@self') {
                    $transformedData[$propertyName] = $value;
                    continue;
                }

                // Get property definition from schema
                $propertyDef = $schemaProperties[$propertyName] ?? null;

                if ($propertyDef === null) {
                    // Property not in schema, keep as is
                    $transformedData[$propertyName] = $value;
                    continue;
                }

                // Transform based on type
                $transformedData[$propertyName] = $this->transformValueByType($value, $propertyDef);
            }

            return $transformedData;
        } catch (\Exception $e) {
            return $objectData;
            // Return original data if transformation fails
        }//end try

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
        // If value is empty or null, return as is
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
        if (is_bool($value)) {
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
        if (is_array($value) || is_object($value)) {
            return $value;
        }

        $value = trim((string) $value);

        // Try to parse as JSON first
        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // If not JSON, return as single-key object
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
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) === false) {
            return [$value];
        }

        $value = trim($value);

        // Empty string returns empty array
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

        // Single value - return as array with one element
        return [$value];

    }//end stringToArray()


}//end class
