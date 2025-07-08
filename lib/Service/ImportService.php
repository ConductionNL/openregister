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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use React\Async\PromiseInterface;
use React\Promise\Promise;
use Symfony\Component\Uid\Uuid;

/**
 * Service for importing data from various formats
 *
 * This service handles importing data from CSV and Excel files with automatic
 * array parsing for fields that contain multiple values. Arrays can be provided
 * in various formats including JSON, comma-separated, or quoted values.
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
     * Import data from Excel file asynchronously
     *
     * @param string        $filePath The path to the Excel file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return PromiseInterface<array> Promise that resolves with array of imported object IDs
     */
    public function importFromExcelAsync(string $filePath, ?Register $register=null, ?Schema $schema=null): PromiseInterface
    {
        return new Promise(
                function (callable $resolve, callable $reject) use ($filePath, $register, $schema) {
                    try {
                        $result = $this->importFromExcel($filePath, $register, $schema);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
                );

    }//end importFromExcelAsync()


    /**
     * Import data from Excel file
     *
     * @param string        $filePath The path to the Excel file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return array<string, array> Summary of import: ['created'=>[], 'updated'=>[], 'unchanged'=>[], 'errors'=>[]]
     * @phpstan-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     * @psalm-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     */
    public function importFromExcel(string $filePath, ?Register $register=null, ?Schema $schema=null): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        // If we have a register but no schema, process each sheet as a different schema
        if ($register !== null && $schema === null) {
            return $this->processMultiSchemaSpreadsheet($spreadsheet, $register);
        }

        return $this->processSpreadsheet($spreadsheet, $register, $schema);

    }//end importFromExcel()


    /**
     * Import data from CSV file asynchronously
     *
     * @param string        $filePath The path to the CSV file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return PromiseInterface<array> Promise that resolves with array of imported object IDs
     */
    public function importFromCsvAsync(string $filePath, ?Register $register=null, ?Schema $schema=null): PromiseInterface
    {
        return new Promise(
                function (callable $resolve, callable $reject) use ($filePath, $register, $schema) {
                    try {
                        $result = $this->importFromCsv($filePath, $register, $schema);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
                );

    }//end importFromCsvAsync()


    /**
     * Import data from CSV file
     *
     * @param string        $filePath The path to the CSV file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return array<string, array> Summary of import: ['created'=>[], 'updated'=>[], 'unchanged'=>[], 'errors'=>[]]
     * @phpstan-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     * @psalm-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     */
    public function importFromCsv(string $filePath, ?Register $register=null, ?Schema $schema=null): array
    {
        // CSV can only handle a single schema
        if ($schema === null) {
            throw new \InvalidArgumentException('CSV import requires a specific schema');
        }

        $reader = new Csv();
        $reader->setReadDataOnly(true);
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setLineEnding("\r\n");
        $spreadsheet = $reader->load($filePath);

        return $this->processSpreadsheet($spreadsheet, $register, $schema);

    }//end importFromCsv()


    /**
     * Process spreadsheet with multiple schemas
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to process
     * @param Register    $register    The register to associate with imported objects
     *
     * @return array<string, array> Summary of import: ['created'=>[], 'updated'=>[], 'unchanged'=>[], 'errors'=>[]]
     * @phpstan-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     * @psalm-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     */
    private function processMultiSchemaSpreadsheet(Spreadsheet $spreadsheet, Register $register): array
    {
        $summary = [
            'created' => [],
            'updated' => [],
            'unchanged' => [],
            'errors' => [],
        ];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $schemaSlug = $worksheet->getTitle();
            $schema     = $this->getSchemaBySlug($schemaSlug);

            // Skip sheets that don't correspond to a valid schema.
            if ($schema === null) {
                continue;
            }

            // Set the worksheet as active and process
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($worksheet));
            $sheetSummary = $this->processSpreadsheet($spreadsheet, $register, $schema);
            $summary['created'] = array_merge($summary['created'], $sheetSummary['created']);
            $summary['updated'] = array_merge($summary['updated'], $sheetSummary['updated']);
            $summary['unchanged'] = array_merge($summary['unchanged'], $sheetSummary['unchanged']);
            $summary['errors'] = array_merge($summary['errors'], $sheetSummary['errors']);
        }

        return $summary;

    }//end processMultiSchemaSpreadsheet()


    /**
     * Process spreadsheet data and create/update objects using ObjectService
     *
     * @param Spreadsheet   $spreadsheet The spreadsheet to process
     * @param Register|null $register    Optional register to associate with imported objects
     * @param Schema|null   $schema      Optional schema to associate with imported objects
     *
     * @return array<string, array> Summary of import: ['created'=>[], 'updated'=>[], 'unchanged'=>[], 'errors'=>[]]
     * @phpstan-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     * @psalm-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>, errors: array<mixed>}
     */
    private function processSpreadsheet(Spreadsheet $spreadsheet, ?Register $register=null, ?Schema $schema=null): array
    {
        $sheet         = $spreadsheet->getActiveSheet();
        $sheetTitle    = $sheet->getTitle();
        $highestRow    = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        // Get headers from first row.
        $headers = [];
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $headers[$col] = $sheet->getCell($col.'1')->getValue();
        }

        // Get schema properties for mapping
        $schemaProperties = $schema ? $schema->getProperties() : [];
        $propertyKeys = array_keys($schemaProperties);

        $summary = [
            'created' => [],
            'updated' => [],
            'unchanged' => [],
            'errors' => [],
        ];

        // Track processed objects to detect duplicates and prevent loops
        $processedObjects = [];
        $memoryStart = memory_get_usage();
        
        error_log("[ImportService] Starting row processing. Memory usage: " . round($memoryStart / 1024 / 1024, 2) . " MB");

        // Process each row.
        for ($row = 2; $row <= $highestRow; $row++) {
            $objectData = [];
            $objectFields = [];
            
            error_log("[ImportService] Processing row {$row}");
            error_log("[ImportService] Sheet: $sheetTitle, Register: " . ($register ? $register->getTitle() : 'NULL') . ", Schema: " . ($schema ? $schema->getTitle() : 'NULL'));

            // Collect data for each column.
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $header = $headers[$col];
                $value  = $sheet->getCell($col.$row)->getValue();

                // Skip empty values.
                if ($value === null || $value === '') {
                    continue;
                }

                if (in_array($header, $propertyKeys, true)) {
                    // Check if this property should be treated as an array
                    $propertyDefinition = $schemaProperties[$header] ?? null;
                    
                    if ($propertyDefinition && isset($propertyDefinition['type'])) {
                        // Only parse as array if explicitly defined as array type
                        if ($propertyDefinition['type'] === 'array') {
                            $objectData[$header] = $this->parseArrayFromString($value);
                        } else {
                            $objectData[$header] = $value;
                        }
                    } else {
                        // If no type definition, only parse obvious JSON arrays
                        // Be more conservative to avoid false positives
                        if (is_string($value) && 
                            str_starts_with($value, '[') && 
                            str_ends_with($value, ']') && 
                            strlen($value) > 2) {
                            $objectData[$header] = $this->parseArrayFromString($value);
                        } else {
                            $objectData[$header] = $value;
                        }
                    }
                } else {
                    // Otherwise, treat as a top-level field
                   // $objectData[$header] = $value;
                }
            }

            // Skip empty rows
            if (empty($objectData)) {
                error_log("[ImportService] Skipping empty row $row");
                continue;
            }

            // Check for potential duplicate processing (memory leak prevention)
            $objectKey = $objectData['id'] ?? $objectData['naam'] ?? "row_$row";
            if (isset($processedObjects[$objectKey])) {
                error_log("[ImportService] WARNING: Duplicate object key detected: $objectKey (row $row)");
                error_log("[ImportService] Object data: " . json_encode($objectData));
                $summary['errors'][] = [
                    'row' => $row,
                    'sheet' => $sheetTitle,
                    'register' => $register ? ['id' => $register->getId(), 'slug' => $register->getSlug()] : null,
                    'schema' => $schema ? ['id' => $schema->getId(), 'slug' => $schema->getSlug()] : null,
                    'data' => ['key' => $objectKey], // Reduced data to prevent memory issues
                    'error' => 'Duplicate object detected - skipping to prevent loops',
                    'type' => 'DuplicateObjectException'
                ];
                continue;
            }
            $processedObjects[$objectKey] = true;

            // Get current timestamp before saving
            $beforeSave = new \DateTime();

            // Use ObjectService to save the object (handles create/update/validation)
            try {
                error_log("[ImportService] Saving object with key: $objectKey");
                error_log("[ImportService] Object ID being passed: " . ($objectData['id'] ?? 'NULL'));
                error_log("[ImportService] Object data keys: " . implode(', ', array_keys($objectData)));
                
                $savedObject = $this->objectService->saveObject(
                    $objectData,
                    [],
                    $register,
                    $schema,
                    $objectData['id'] ?? null
                );

                error_log("[ImportService] Successfully saved object ID: " . $savedObject->getId() . ", UUID: " . $savedObject->getUuid());

                // Get the created and updated timestamps from the saved object
                $created = $savedObject->getCreated();
                $updated = $savedObject->getUpdated();

                // Get minimal log info to reduce memory usage
                $logInfo = [
                    'id' => $savedObject->getId(),
                    'uuid' => $savedObject->getUuid(),
                    'row' => $row
                ];

                // If created timestamp is after our beforeSave timestamp, it's a new object
                if ($created && $created > $beforeSave) {
                    $summary['created'][] = $logInfo;
                    error_log("[ImportService] Object created: " . $savedObject->getUuid());
                }
                // If updated timestamp is after our beforeSave timestamp, it's an updated object
                else if ($updated && $updated > $beforeSave) {
                    $summary['updated'][] = $logInfo;
                    error_log("[ImportService] Object updated: " . $savedObject->getUuid());
                }
                // If neither timestamp is after beforeSave, the object was unchanged
                else {
                    $summary['unchanged'][] = $logInfo;
                    error_log("[ImportService] Object unchanged: " . $savedObject->getUuid());
                }
                
                // Force garbage collection every 10 rows to manage memory
                if ($row % 10 === 0) {
                    gc_collect_cycles();
                    $currentMemory = memory_get_usage();
                    error_log("[ImportService] Row $row - Memory usage: " . round($currentMemory / 1024 / 1024, 2) . " MB");
                }
                
            } catch (\Exception $e) {
                error_log("[ImportService] Error saving object: " . $e->getMessage());
                error_log("[ImportService] Exception type: " . get_class($e));
                error_log("[ImportService] Stack trace: " . $e->getTraceAsString());
                
                // Capture the error with minimal data to prevent memory issues
                $summary['errors'][] = [
                    'row' => $row,
                    'sheet' => $sheetTitle,
                    'register' => $register ? [
                        'id' => $register->getId(),
                        'slug' => $register->getSlug(),
                        'title' => $register->getTitle()
                    ] : null,
                    'schema' => $schema ? [
                        'id' => $schema->getId(),
                        'slug' => $schema->getSlug(),
                        'title' => $schema->getTitle()
                    ] : null,
                    'data' => array_slice($objectData, 0, 5, true), // Limit data to first 5 fields to prevent memory issues
                    'error' => $e->getMessage(),
                    'type' => get_class($e)
                ];
            }
            
            // Clear object data to free memory
            unset($objectData, $objectFields);
        }
        
        $memoryEnd = memory_get_usage();
        error_log("[ImportService] Finished row processing. Memory usage: " . round($memoryEnd / 1024 / 1024, 2) . " MB");
        error_log("[ImportService] Memory increase: " . round(($memoryEnd - $memoryStart) / 1024 / 1024, 2) . " MB");
        return $summary;

    }//end processSpreadsheet()


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
            return $this->schemaMapper->find($slug);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }

    }//end getSchemaBySlug()

    /**
     * Parse array from string input
     *
     * This method attempts to parse various array formats commonly found in CSV/Excel imports:
     * - JSON arrays: ["value1", "value2"]
     * - Comma-separated: value1,value2
     * - Quoted comma-separated: "value1","value2"
     * - Mixed quotes: ["value1",'value2']
     *
     * @param mixed $input The input value to parse
     *
     * @return array The parsed array or original value wrapped in array if parsing fails
     *
     * @phpstan-return array<int|string, mixed>
     * @psalm-return array<int|string, mixed>
     */
    private function parseArrayFromString($input): array
    {
        // If already an array, return as is
        if (is_array($input)) {
            return $input;
        }

        // If not a string, return as single-item array
        if (!is_string($input)) {
            return [$input];
        }

        // Trim whitespace
        $input = trim($input);

        // If empty string, return empty array
        if ($input === '') {
            return [];
        }

        // Limit input size to prevent memory issues
        if (strlen($input) > 10000) {
            error_log("[ImportService] parseArrayFromString: Input too large (" . strlen($input) . " chars), truncating");
            $input = substr($input, 0, 10000);
        }

        // Try to parse as JSON first
        if (str_starts_with($input, '[') && str_ends_with($input, ']')) {
            $jsonDecoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonDecoded)) {
                // Limit array size to prevent memory issues
                if (count($jsonDecoded) > 100) {
                    error_log("[ImportService] parseArrayFromString: JSON array too large (" . count($jsonDecoded) . " items), truncating to 100");
                    $jsonDecoded = array_slice($jsonDecoded, 0, 100);
                }
                return $jsonDecoded;
            }
        }

        // Handle comma-separated values
        if (str_contains($input, ',')) {
            // Split by comma and clean up each value
            $values = explode(',', $input);
            
            // Limit array size to prevent memory issues
            if (count($values) > 100) {
                error_log("[ImportService] parseArrayFromString: Comma-separated array too large (" . count($values) . " items), truncating to 100");
                $values = array_slice($values, 0, 100);
            }
            
            $result = [];
            
            foreach ($values as $value) {
                $value = trim($value);
                
                // Remove surrounding quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                
                $result[] = $value;
            }
            
            // Clear variables to free memory
            unset($values);
            
            return $result;
        }

        // If no comma found, return as single-item array
        // But first check if it's a quoted single value
        if ((str_starts_with($input, '"') && str_ends_with($input, '"')) ||
            (str_starts_with($input, "'") && str_ends_with($input, "'"))) {
            $input = substr($input, 1, -1);
        }

        return [$input];

    }//end parseArrayFromString()


}//end class
