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
     * Import data from Excel file
     *
     * @param string        $filePath The path to the Excel file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return array<string, array> Summary of import with sheet-based results
     * @phpstan-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
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

        // Single schema processing - return in sheet-based format for consistency
        $sheetTitle = $spreadsheet->getActiveSheet()->getTitle();
        $sheetSummary = $this->processSpreadsheet($spreadsheet, $register, $schema);
        
        // Add schema information to the summary (consistent with multi-sheet Excel import)
        if ($schema !== null) {
            $sheetSummary['schema'] = [
                'id' => $schema->getId(),
                'title' => $schema->getTitle(),
                'slug' => $schema->getSlug(),
            ];
        }
        
        // Return in sheet-based format for consistency
        return [$sheetTitle => $sheetSummary];

    }//end importFromExcel()


    /**
     * Import data from CSV file
     *
     * @param string        $filePath The path to the CSV file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return array<string, array> Summary of import with sheet-based results
     * @phpstan-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
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
        $spreadsheet = $reader->load($filePath);

        // Get the sheet title for CSV (usually just 'Worksheet' or similar)
        $sheetTitle = $spreadsheet->getActiveSheet()->getTitle();
        $sheetSummary = $this->processSpreadsheet($spreadsheet, $register, $schema);
        
        // Add schema information to the summary (consistent with Excel import)
        $sheetSummary['schema'] = [
            'id' => $schema->getId(),
            'title' => $schema->getTitle(),
            'slug' => $schema->getSlug(),
        ];
        
        // Return in sheet-based format for consistency
        return [$sheetTitle => $sheetSummary];

    }//end importFromCsv()


    /**
     * Process spreadsheet with multiple schemas
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to process
     * @param Register    $register    The register to associate with imported objects
     *
     * @return array<string, array> Summary of import with sheet-based results
     * @phpstan-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     * @psalm-return array<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>
     */
    private function processMultiSchemaSpreadsheet(Spreadsheet $spreadsheet, Register $register): array
    {
        $summary = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $schemaSlug = $worksheet->getTitle();
            $schema     = $this->getSchemaBySlug($schemaSlug);

            // Initialize sheet summary even if no schema found
            $summary[$schemaSlug] = [
                'found' => 0,
                'created' => [],
                'updated' => [],
                'unchanged' => [],
                'errors' => [],
                'schema' => null,
                'debug' => [
                    'headers' => [],
                    'schemaProperties' => [],
                    'processableHeaders' => [],
                ],
            ];

            // Skip sheets that don't correspond to a valid schema.
            if ($schema === null) {
                $summary[$schemaSlug]['errors'][] = [
                    'sheet' => $schemaSlug,
                    'register' => [
                        'id' => $register->getId(),
                        'name' => $register->getTitle()
                    ],
                    'schema' => null,
                    'error' => 'No matching schema found for sheet: ' . $schemaSlug,
                    'type' => 'SchemaNotFoundException'
                ];
                continue;
            }

            // Add schema information to the summary
            $summary[$schemaSlug]['schema'] = [
                'id' => $schema->getId(),
                'title' => $schema->getTitle(),
                'slug' => $schema->getSlug(),
            ];
            
            // Update debug information with schema properties
            $schemaProperties = $schema->getProperties();
            $propertyKeys = array_keys($schemaProperties);
            $summary[$schemaSlug]['debug']['schemaProperties'] = $propertyKeys;

            // Set the worksheet as active and process
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($worksheet));
            $sheetSummary = $this->processSpreadsheet($spreadsheet, $register, $schema);
            
            // Merge the sheet summary with the existing summary (preserve debug info)
            $summary[$schemaSlug] = array_merge($summary[$schemaSlug], $sheetSummary);
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
        $sheet = $spreadsheet->getActiveSheet();
        $sheetTitle = $sheet->getTitle();
        $highestRow = $sheet->getHighestRow();

        // Step 1: Build column mapping array using PhpSpreadsheet built-in methods
        $columnMapping = []; // column letter -> column name
        $columnIndex = 1;
        
        // Use PhpSpreadsheet built-in method to get column letters
        while ($columnIndex <= 50) { // Check up to 50 columns
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $cellValue = $sheet->getCell($columnLetter . '1')->getValue();
            
            if ($cellValue !== null && trim($cellValue) !== '') {
                $cleanColumnName = trim((string)$cellValue);
                $columnMapping[$columnLetter] = $cleanColumnName;
            } else {
                // Found empty column, stop here
                break;
            }
            
            $columnIndex++;
        }
        
        // Get schema properties for reference
        $schemaProperties = $schema ? $schema->getProperties() : [];
        $propertyKeys = array_keys($schemaProperties);
        
        // Step 2: Process each data row (starting from row 2)
        $processedRows = [];
        
        for ($row = 2; $row <= $highestRow; $row++) {
            $rowData = []; // name -> value
            $hasData = false;
            
            // Loop through each column in the mapping
            foreach ($columnMapping as $columnLetter => $columnName) {
                $cellValue = $sheet->getCell($columnLetter . $row)->getValue();
                
                // Convert cell value to string and trim whitespace
                $cleanCellValue = $cellValue !== null ? trim((string)$cellValue) : '';
                
                if ($cleanCellValue !== '') {
                    $rowData[$columnName] = $cleanCellValue;
                    $hasData = true;
                }
            }
            
            // Only include rows that have data
            if ($hasData) {
                $processedRows[] = $rowData;
            }
        }
        
        // Initialize summary
        $summary = [
            'found' => count($processedRows),
            'created' => [],
            'updated' => [],
            'unchanged' => [],
            'errors' => [],
        ];
        
        // Process rows and create objects using ObjectService
        if ($register && $schema) {
            foreach ($processedRows as $index => $rowData) {
                try {
                    // Separate regular properties from system properties (starting with _)
                    $objectData = [];
                    $selfData = [];
                    
                    foreach ($rowData as $key => $value) {
                        if (str_starts_with($key, '_')) {
                            // Move properties starting with _ to @self array and remove the _
                            $selfPropertyName = substr($key, 1); // Remove the _ prefix
                            $selfData[$selfPropertyName] = $value;
                        } else {
                            // Regular properties go to main object data
                            $objectData[$key] = $value;
                        }
                    }
                    
                    // Add @self array to object data if we have self properties
                    if (!empty($selfData)) {
                        $objectData['@self'] = $selfData;
                    }
                    
                    // Transform object data based on schema property types
                    $objectData = $this->transformObjectBySchema($objectData, $schema);
                    
                    // Get the object ID for tracking updates vs creates
                    $objectId = $rowData['id'] ?? null;
                    $wasExisting = false;
                    
                    // Check if object exists (for reporting purposes only)
                    if ($objectId) {
                        try {
                            $existingObject = $this->objectEntityMapper->find($objectId);
                            $wasExisting = true;
                        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                            // Object not found, will create new
                            $wasExisting = false;
                        } catch (\Exception $e) {
                            // Other errors - assume it doesn't exist
                            $wasExisting = false;
                        }
                    }
                    
                    // Save the object (ObjectService handles create vs update logic)
                    $savedObject = $this->objectService->saveObject(
                        $objectData,
                        null,
                        $register,
                        $schema,
                        $objectId
                    );
                    
                    // Track whether it was an update or create for reporting
                    if ($wasExisting) {
                        $summary['updated'][] = $savedObject->getUuid();
                    } else {
                        $summary['created'][] = $savedObject->getUuid();
                    }
                    
                } catch (\Exception $e) {
                    error_log("[ImportService] Error processing row " . ($index + 1) . ": " . $e->getMessage());
                    $summary['errors'][] = [
                        'row' => ($index + 1),
                        'data' => $rowData,
                        'error' => $e->getMessage()
                    ];
                }
            }
        }
        
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
        }

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
     * @psalm-return array<string, mixed>
     */
    private function transformObjectBySchema(array $objectData, Schema $schema): array
    {
        try {
            $schemaProperties = $schema->getProperties();
            $transformedData = [];
            
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
            return $objectData; // Return original data if transformation fails
        }
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
     * @psalm-return array<int|string, mixed>
     */
    private function stringToArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        
        if (!is_string($value)) {
            return [$value];
        }
        
        $value = trim($value);
        
        // Empty string returns empty array
        if ($value === '') {
            return [];
        }
        
        // Try JSON first
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        
        // Handle comma-separated values
        if (str_contains($value, ',')) {
            $parts = explode(',', $value);
            $result = [];
            
            foreach ($parts as $part) {
                $part = trim($part);
                
                // Remove surrounding quotes
                if ((str_starts_with($part, '"') && str_ends_with($part, '"')) ||
                    (str_starts_with($part, "'") && str_ends_with($part, "'"))) {
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
